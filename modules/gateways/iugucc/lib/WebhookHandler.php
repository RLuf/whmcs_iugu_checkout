<?php
/**
 * Asynchronous Iugu Webhook / Callback Handler
 *
 * Processes Iugu webhook notifications (`invoice.status_changed`, `invoice.refund`,
 * `invoice.payment_failed`), verifies authenticity by querying the authoritative
 * invoice state directly from the Iugu API (`GET /v1/invoices/{id}`), and idempotently
 * records invoice payment (`addInvoicePayment`) to trigger WHMCS service provisioning.
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\IuguCC;

use Throwable;

final class WebhookHandler
{
    private Config $config;
    private IuguHttpClient $httpClient;

    public function __construct(Config $config, IuguHttpClient $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    /**
     * Handles an incoming Iugu webhook notification.
     *
     * @param array<string, mixed> $payload        Parsed POST or JSON webhook payload (`event`, `data`)
     * @param array<string, mixed> $headers        HTTP request headers / query tokens
     * @param array{
     *   logTransaction?: callable,
     *   checkCbInvoiceID?: callable,
     *   checkCbTransID?: callable,
     *   addInvoicePayment?: callable,
     *   isTransactionRecorded?: callable,
     *   isInvoicePaid?: callable
     * } $whmcsCallbacks Injectable WHMCS functions
     *
     * @return array{
     *   http_code: int,
     *   processed: bool,
     *   action: string,
     *   whmcs_invoice_id: string,
     *   iugu_invoice_id: string,
     *   message: string
     * }
     */
    public function handle(array $payload, array $headers = [], array $whmcsCallbacks = []): array
    {
        // 1. Verify optional webhook secret if configured in WHMCS Admin
        $expectedSecret = $this->config->getWebhookSecret();
        if ($expectedSecret !== '') {
            $providedSecret = $this->extractWebhookSecret($headers, $payload);
            if ($providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
                $this->logToWhmcs(
                    $whmcsCallbacks,
                    [
                        'error' => 'Invalid webhook secret token.',
                        'payload' => PciSanitizer::sanitize($payload),
                    ],
                    'Webhook Unauthorized'
                );

                return [
                    'http_code' => 401,
                    'processed' => false,
                    'action' => 'rejected_unauthorized',
                    'whmcs_invoice_id' => '',
                    'iugu_invoice_id' => '',
                    'message' => 'Unauthorized webhook secret.',
                ];
            }
        }

        $event = trim((string) ($payload['event'] ?? ''));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // Support flat form-urlencoded keys like `data[id]` if needed
        $iuguInvoiceId = trim((string) ($data['id'] ?? $payload['data_id'] ?? $payload['id'] ?? ''));
        $webhookAccountId = trim((string) ($data['account_id'] ?? $payload['data_account_id'] ?? ''));

        if ($iuguInvoiceId === '') {
            return [
                'http_code' => 400,
                'processed' => false,
                'action' => 'invalid_payload',
                'whmcs_invoice_id' => '',
                'iugu_invoice_id' => '',
                'message' => 'Missing data[id] (Iugu invoice ID) in webhook payload.',
            ];
        }

        // 2. Verify account_id matches configured Iugu Account ID (when provided in webhook)
        $configuredAccountId = strtoupper(str_replace('-', '', $this->config->getAccountId()));
        $incomingAccountId = strtoupper(str_replace('-', '', $webhookAccountId));
        if ($configuredAccountId !== '' && $incomingAccountId !== '' && !hash_equals($configuredAccountId, $incomingAccountId)) {
            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'error' => 'Webhook account_id mismatch.',
                    'expected_account_id' => $this->config->getAccountId(),
                    'received_account_id' => $webhookAccountId,
                ],
                'Webhook Account Mismatch'
            );

            return [
                'http_code' => 403,
                'processed' => false,
                'action' => 'rejected_account_mismatch',
                'whmcs_invoice_id' => '',
                'iugu_invoice_id' => $iuguInvoiceId,
                'message' => 'Webhook account_id does not match configured Account ID.',
            ];
        }

        // 3. Query authoritative invoice status directly from Iugu API (prevents spoofed webhooks)
        try {
            $invoiceResp = $this->httpClient->getInvoice($iuguInvoiceId);
            if ($invoiceResp['http_code'] !== 200 || empty($invoiceResp['body']['id'])) {
                $this->logToWhmcs(
                    $whmcsCallbacks,
                    [
                        'event' => $event,
                        'iugu_invoice_id' => $iuguInvoiceId,
                        'api_http_code' => $invoiceResp['http_code'],
                    ],
                    'Webhook Verification Failed'
                );

                return [
                    'http_code' => 404,
                    'processed' => false,
                    'action' => 'invoice_not_found_on_iugu',
                    'whmcs_invoice_id' => '',
                    'iugu_invoice_id' => $iuguInvoiceId,
                    'message' => 'Could not verify invoice on Iugu API.',
                ];
            }

            $authoritativeInvoice = $invoiceResp['body'];
        } catch (Throwable $e) {
            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'event' => $event,
                    'iugu_invoice_id' => $iuguInvoiceId,
                    'exception' => $e->getMessage(),
                ],
                'Webhook API Error'
            );

            return [
                'http_code' => 502,
                'processed' => false,
                'action' => 'api_error',
                'whmcs_invoice_id' => '',
                'iugu_invoice_id' => $iuguInvoiceId,
                'message' => 'Temporary failure querying Iugu API.',
            ];
        }

        // 4. Resolve WHMCS Invoice ID from authoritative Iugu invoice metadata
        $whmcsInvoiceId = self::extractWhmcsInvoiceId($authoritativeInvoice, $data);
        if ($whmcsInvoiceId === '') {
            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'event' => $event,
                    'iugu_invoice_id' => $iuguInvoiceId,
                    'note' => 'Ignored Iugu invoice without whmcs_invoice_id (likely belongs to another integration).',
                ],
                'Webhook Ignored (Foreign Invoice)'
            );

            return [
                'http_code' => 200,
                'processed' => false,
                'action' => 'ignored_foreign_invoice',
                'whmcs_invoice_id' => '',
                'iugu_invoice_id' => $iuguInvoiceId,
                'message' => 'Invoice is not linked to a WHMCS invoice ID.',
            ];
        }

        $authoritativeStatus = strtolower(trim((string) ($authoritativeInvoice['status'] ?? '')));

        // 5. Handle Paid status idempotently
        if ($authoritativeStatus === 'paid') {
            // Check if transaction was already recorded synchronously
            if (isset($whmcsCallbacks['isTransactionRecorded']) && is_callable($whmcsCallbacks['isTransactionRecorded'])) {
                if (($whmcsCallbacks['isTransactionRecorded'])($iuguInvoiceId) === true) {
                    $this->logToWhmcs(
                        $whmcsCallbacks,
                        [
                            'event' => $event,
                            'whmcs_invoice_id' => $whmcsInvoiceId,
                            'iugu_invoice_id' => $iuguInvoiceId,
                            'status' => 'paid (already recorded)',
                        ],
                        'Webhook Duplicate Ignored'
                    );

                    return [
                        'http_code' => 200,
                        'processed' => true,
                        'action' => 'noop_already_recorded',
                        'whmcs_invoice_id' => $whmcsInvoiceId,
                        'iugu_invoice_id' => $iuguInvoiceId,
                        'message' => 'Transaction already recorded in WHMCS.',
                    ];
                }
            }

            if (isset($whmcsCallbacks['isInvoicePaid']) && is_callable($whmcsCallbacks['isInvoicePaid'])) {
                if (($whmcsCallbacks['isInvoicePaid'])((int) $whmcsInvoiceId) === true) {
                    return [
                        'http_code' => 200,
                        'processed' => true,
                        'action' => 'noop_invoice_already_paid',
                        'whmcs_invoice_id' => $whmcsInvoiceId,
                        'iugu_invoice_id' => $iuguInvoiceId,
                        'message' => 'WHMCS invoice is already marked as Paid.',
                    ];
                }
            }

            if (isset($whmcsCallbacks['checkCbInvoiceID']) && is_callable($whmcsCallbacks['checkCbInvoiceID'])) {
                ($whmcsCallbacks['checkCbInvoiceID'])((int) $whmcsInvoiceId, Config::GATEWAY_NAME);
            }

            if (isset($whmcsCallbacks['checkCbTransID']) && is_callable($whmcsCallbacks['checkCbTransID'])) {
                ($whmcsCallbacks['checkCbTransID'])($iuguInvoiceId);
            }

            $paidCents = (int) ($authoritativeInvoice['total_paid_cents'] ?? $authoritativeInvoice['total_cents'] ?? 0);
            $feeCents = (int) ($authoritativeInvoice['taxes_paid_cents'] ?? 0);
            $paidAmount = $paidCents > 0 ? round($paidCents / 100, 2) : 0.0;
            $feeAmount = $feeCents > 0 ? round($feeCents / 100, 2) : 0.0;

            if (isset($whmcsCallbacks['addInvoicePayment']) && is_callable($whmcsCallbacks['addInvoicePayment'])) {
                ($whmcsCallbacks['addInvoicePayment'])(
                    (int) $whmcsInvoiceId,
                    $iuguInvoiceId,
                    $paidAmount,
                    $feeAmount,
                    Config::GATEWAY_NAME
                );
            }

            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'event' => $event,
                    'whmcs_invoice_id' => $whmcsInvoiceId,
                    'iugu_invoice_id' => $iuguInvoiceId,
                    'paid_amount' => $paidAmount,
                    'fee_amount' => $feeAmount,
                    'status' => $authoritativeStatus,
                ],
                'Webhook Payment Recorded'
            );

            return [
                'http_code' => 200,
                'processed' => true,
                'action' => 'payment_recorded',
                'whmcs_invoice_id' => $whmcsInvoiceId,
                'iugu_invoice_id' => $iuguInvoiceId,
                'message' => 'Invoice marked as Paid via Iugu webhook.',
            ];
        }

        // 6. Log non-paid lifecycle events (refunded, canceled, expired, payment_failed)
        $this->logToWhmcs(
            $whmcsCallbacks,
            [
                'event' => $event,
                'whmcs_invoice_id' => $whmcsInvoiceId,
                'iugu_invoice_id' => $iuguInvoiceId,
                'status' => $authoritativeStatus,
            ],
            'Webhook Status: ' . ucfirst($authoritativeStatus ?: 'Unknown')
        );

        return [
            'http_code' => 200,
            'processed' => true,
            'action' => 'status_logged_' . ($authoritativeStatus ?: 'unknown'),
            'whmcs_invoice_id' => $whmcsInvoiceId,
            'iugu_invoice_id' => $iuguInvoiceId,
            'message' => sprintf('Webhook event (%s / %s) logged.', $event, $authoritativeStatus),
        ];
    }

    /**
     * Resolves the WHMCS Invoice ID from Iugu invoice `custom_variables` or `order_id`.
     *
     * @param array<string, mixed> $authoritativeInvoice
     * @param array<string, mixed> $webhookData
     */
    public static function extractWhmcsInvoiceId(array $authoritativeInvoice, array $webhookData = []): string
    {
        // 1. Check custom_variables array on the authoritative Iugu invoice
        if (!empty($authoritativeInvoice['custom_variables']) && is_array($authoritativeInvoice['custom_variables'])) {
            foreach ($authoritativeInvoice['custom_variables'] as $var) {
                if (is_array($var) && ($var['name'] ?? '') === 'whmcs_invoice_id') {
                    $digits = preg_replace('/\D/', '', (string) ($var['value'] ?? '')) ?? '';
                    if ($digits !== '') {
                        return $digits;
                    }
                }
            }
        }

        // 2. Check order_id (`whmcs_1024`)
        $orderId = trim((string) ($authoritativeInvoice['order_id'] ?? $webhookData['order_id'] ?? ''));
        if (preg_match('/^whmcs_(\d+)$/i', $orderId, $matches) === 1) {
            return $matches[1];
        }

        // 3. Fallback to explicit whmcs_invoice_id in webhook data
        $direct = preg_replace('/\D/', '', (string) ($webhookData['whmcs_invoice_id'] ?? '')) ?? '';
        return $direct;
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $payload
     */
    private function extractWebhookSecret(array $headers, array $payload): string
    {
        foreach ($headers as $k => $v) {
            $lower = strtolower((string) $k);
            if (in_array($lower, ['x-iugu-token', 'x-webhook-secret', 'authorization'], true)) {
                $val = trim((string) $v);
                if (stripos($val, 'Bearer ') === 0) {
                    return trim(substr($val, 7));
                }
                if (stripos($val, 'Basic ') === 0) {
                    $decoded = base64_decode(trim(substr($val, 6)), true);
                    if (is_string($decoded) && str_contains($decoded, ':')) {
                        return explode(':', $decoded, 2)[0];
                    }
                }
                return $val;
            }
        }

        return trim((string) ($payload['webhook_token'] ?? $payload['token'] ?? ''));
    }

    /**
     * @param array<string, mixed> $whmcsCallbacks
     * @param array<string, mixed> $data
     */
    private function logToWhmcs(array $whmcsCallbacks, array $data, string $status): void
    {
        if (isset($whmcsCallbacks['logTransaction']) && is_callable($whmcsCallbacks['logTransaction'])) {
            ($whmcsCallbacks['logTransaction'])(Config::GATEWAY_NAME, PciSanitizer::sanitize($data), $status);
        }
    }
}
