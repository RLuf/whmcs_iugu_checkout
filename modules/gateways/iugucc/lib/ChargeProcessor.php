<?php
/**
 * Synchronous Credit Card Charge Processor
 *
 * Processes client-side Iugu.js tokens via `POST /v1/charge`, maps acquirer
 * LR codes to clear localized messages, sanitizes logs for PCI-DSS compliance,
 * and marks WHMCS invoices as Paid (`addInvoicePayment`) to trigger service provisioning.
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\IuguCC;

use Throwable;

final class ChargeProcessor
{
    /**
     * Localized mapping of common Brazilian Acquirer Return Codes (LR).
     *
     * @var array<string, array{pt: string, en: string}>
     */
    private const LR_MESSAGES = [
        '00' => [
            'pt' => 'Pagamento aprovado com sucesso.',
            'en' => 'Payment approved successfully.',
        ],
        '04' => [
            'pt' => 'Transação não autorizada pela operadora do cartão (LR 04). Verifique com o banco emissor ou utilize outro cartão.',
            'en' => 'Transaction declined by card issuer (LR 04). Please contact your bank or try another card.',
        ],
        '05' => [
            'pt' => 'Transação não autorizada pelo banco emissor (LR 05). Entre em contato com a central do seu cartão.',
            'en' => 'Transaction not authorized by card issuer (LR 05). Please contact your bank.',
        ],
        '07' => [
            'pt' => 'Cartão com restrição no emissor (LR 07). Utilize outro cartão.',
            'en' => 'Card restricted by issuer (LR 07). Please try another card.',
        ],
        '51' => [
            'pt' => 'Saldo ou limite insuficiente no cartão de crédito (LR 51).',
            'en' => 'Insufficient funds or credit limit on card (LR 51).',
        ],
        '54' => [
            'pt' => 'Cartão de crédito vencido (LR 54). Verifique a data de validade.',
            'en' => 'Expired credit card (LR 54). Please check the expiration date.',
        ],
        '57' => [
            'pt' => 'Transação não permitida para este cartão (LR 57). Verifique se a função compra online está habilitada.',
            'en' => 'Transaction not permitted for this card (LR 57). Ensure online purchases are enabled.',
        ],
        '78' => [
            'pt' => 'Cartão bloqueado ou aguardando desbloqueio de primeiro uso (LR 78).',
            'en' => 'Card blocked or awaiting first-use activation (LR 78).',
        ],
        '82' => [
            'pt' => 'Código de segurança (CVV) ou dados do cartão inválidos (LR 82).',
            'en' => 'Invalid security code (CVV) or card parameters (LR 82).',
        ],
        'N7' => [
            'pt' => 'Código de segurança (CVV) inválido (LR N7). Confira os dígitos no verso do cartão.',
            'en' => 'Invalid CVV security code (LR N7). Please check the digits on the back of your card.',
        ],
    ];

    private Config $config;
    private IuguHttpClient $httpClient;

    public function __construct(Config $config, IuguHttpClient $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    /**
     * Processes a tokenized credit card checkout submission.
     *
     * @param array<string, mixed> $requestData   Incoming POST parameters (`iugu_token`, `installments`, etc.)
     * @param int|string           $invoiceId     WHMCS Invoice ID
     * @param float|string         $amount        WHMCS Invoice Balance/Total in BRL
     * @param array<string, mixed> $clientDetails WHMCS clientdetails array
     * @param array{
     *   logTransaction?: callable,
     *   checkCbTransID?: callable,
     *   addInvoicePayment?: callable,
     *   isInvoicePaid?: callable
     * } $whmcsCallbacks Callbacks for WHMCS integration (injectable for automated tests)
     *
     * @return array{
     *   success: bool,
     *   invoice_id: string,
     *   transaction_id: string,
     *   lr_code: string,
     *   message: string,
     *   fee: float
     * }
     */
    public function processCharge(
        array $requestData,
        int|string $invoiceId,
        float|string $amount,
        array $clientDetails,
        array $whmcsCallbacks = []
    ): array {
        $isEn = $this->config->isEnglish();

        // 1. Enforce PCI-DSS: Reject request immediately if raw card numbers or CVV were submitted
        if (PciSanitizer::containsRawCardData($requestData)) {
            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'invoice_id' => (string) $invoiceId,
                    'error' => 'Rejected request containing raw card fields (PCI-DSS protection).',
                    'request' => PciSanitizer::sanitize($requestData),
                ],
                'PCI Violation Blocked'
            );

            return [
                'success' => false,
                'invoice_id' => (string) $invoiceId,
                'transaction_id' => '',
                'lr_code' => 'PCI_BLOCKED',
                'message' => $isEn
                    ? 'Security Error: Raw card details must be tokenized in the browser via Iugu.js.'
                    : 'Erro de Segurança: Os dados do cartão devem ser tokenizados no navegador via Iugu.js.',
                'fee' => 0.0,
            ];
        }

        // 2. Prevent charging an already paid invoice
        if (isset($whmcsCallbacks['isInvoicePaid']) && is_callable($whmcsCallbacks['isInvoicePaid'])) {
            if (($whmcsCallbacks['isInvoicePaid'])($invoiceId) === true) {
                return [
                    'success' => true,
                    'invoice_id' => (string) $invoiceId,
                    'transaction_id' => 'ALREADY_PAID',
                    'lr_code' => '00',
                    'message' => $isEn ? 'This invoice is already paid.' : 'Esta fatura já consta como paga.',
                    'fee' => 0.0,
                ];
            }
        }

        $iuguToken = trim((string) ($requestData['iugu_token'] ?? ''));
        if ($iuguToken === '') {
            return [
                'success' => false,
                'invoice_id' => (string) $invoiceId,
                'transaction_id' => '',
                'lr_code' => 'MISSING_TOKEN',
                'message' => $isEn
                    ? 'Payment token was not generated. Please verify your card details and try again.'
                    : 'O token de pagamento não foi gerado. Verifique os dados do cartão e tente novamente.',
                'fee' => 0.0,
            ];
        }

        $installments = (int) ($requestData['installments'] ?? 1);
        if (!empty($requestData['cpf_cnpj'])) {
            $clientDetails['cpf_cnpj'] = (string) $requestData['cpf_cnpj'];
        }

        try {
            $chargePayload = PayloadBuilder::buildChargePayload(
                $this->config,
                $iuguToken,
                $invoiceId,
                $amount,
                $clientDetails,
                $installments
            );

            $apiResponse = $this->httpClient->charge($chargePayload);
            $httpCode = $apiResponse['http_code'];
            $body = $apiResponse['body'];

            $chargeSuccess = (bool) ($body['success'] ?? false);
            $lrCode = trim((string) ($body['LR'] ?? ''));
            $iuguInvoiceId = trim((string) ($body['invoice_id'] ?? ''));

            if ($chargeSuccess && ($httpCode >= 200 && $httpCode < 300) && $iuguInvoiceId !== '') {
                $gatewayFee = $this->resolveGatewayFee($iuguInvoiceId);

                $this->logToWhmcs(
                    $whmcsCallbacks,
                    [
                        'whmcs_invoice_id' => (string) $invoiceId,
                        'iugu_invoice_id' => $iuguInvoiceId,
                        'amount' => (float) $amount,
                        'fee' => $gatewayFee,
                        'installments' => $chargePayload['months'],
                        'lr_code' => $lrCode !== '' ? $lrCode : '00',
                        'request' => PciSanitizer::sanitize($chargePayload),
                        'response' => PciSanitizer::sanitize($body),
                    ],
                    'Success'
                );

                // Ensure transaction uniqueness and record payment in WHMCS (triggers service provisioning)
                if (isset($whmcsCallbacks['checkCbTransID']) && is_callable($whmcsCallbacks['checkCbTransID'])) {
                    ($whmcsCallbacks['checkCbTransID'])($iuguInvoiceId);
                }

                if (isset($whmcsCallbacks['addInvoicePayment']) && is_callable($whmcsCallbacks['addInvoicePayment'])) {
                    ($whmcsCallbacks['addInvoicePayment'])(
                        (int) $invoiceId,
                        $iuguInvoiceId,
                        (float) $amount,
                        $gatewayFee,
                        Config::GATEWAY_NAME
                    );
                }

                return [
                    'success' => true,
                    'invoice_id' => (string) $invoiceId,
                    'transaction_id' => $iuguInvoiceId,
                    'lr_code' => $lrCode !== '' ? $lrCode : '00',
                    'message' => self::translateMessage('00', $body, $isEn),
                    'fee' => $gatewayFee,
                ];
            }

            // Declined or API validation error
            $errorMessage = self::translateMessage($lrCode, $body, $isEn);
            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'whmcs_invoice_id' => (string) $invoiceId,
                    'iugu_invoice_id' => $iuguInvoiceId,
                    'http_code' => $httpCode,
                    'lr_code' => $lrCode,
                    'translated_error' => $errorMessage,
                    'request' => PciSanitizer::sanitize($chargePayload),
                    'response' => PciSanitizer::sanitize($body),
                ],
                'Declined'
            );

            return [
                'success' => false,
                'invoice_id' => (string) $invoiceId,
                'transaction_id' => $iuguInvoiceId,
                'lr_code' => $lrCode !== '' ? $lrCode : 'DECLINED',
                'message' => $errorMessage,
                'fee' => 0.0,
            ];
        } catch (Throwable $e) {
            $this->logToWhmcs(
                $whmcsCallbacks,
                [
                    'whmcs_invoice_id' => (string) $invoiceId,
                    'exception' => $e->getMessage(),
                ],
                'Error'
            );

            return [
                'success' => false,
                'invoice_id' => (string) $invoiceId,
                'transaction_id' => '',
                'lr_code' => 'EXCEPTION',
                'message' => $isEn
                    ? 'Unable to communicate with the payment processor at this moment. Please try again.'
                    : 'Não foi possível comunicar com a processadora de pagamentos no momento. Tente novamente em instantes.',
                'fee' => 0.0,
            ];
        }
    }

    /**
     * Translates an Iugu LR code or validation error object into a customer-friendly string.
     *
     * @param array<string, mixed> $body
     */
    public static function translateMessage(string $lrCode, array $body, bool $isEnglish = false): string
    {
        $langKey = $isEnglish ? 'en' : 'pt';
        $normalizedLr = strtoupper(trim($lrCode));

        if ($normalizedLr !== '' && isset(self::LR_MESSAGES[$normalizedLr])) {
            return self::LR_MESSAGES[$normalizedLr][$langKey];
        }

        // Check if Iugu returned specific field errors
        if (!empty($body['errors'])) {
            if (is_string($body['errors'])) {
                return $body['errors'];
            }
            if (is_array($body['errors'])) {
                $flatErrors = [];
                foreach ($body['errors'] as $field => $messages) {
                    if (is_array($messages)) {
                        $flatErrors[] = $field . ': ' . implode(', ', array_map('strval', $messages));
                    } else {
                        $flatErrors[] = $field . ': ' . (string) $messages;
                    }
                }
                if (!empty($flatErrors)) {
                    $prefix = $isEnglish ? 'Validation Error: ' : 'Erro de validação: ';
                    return $prefix . implode(' | ', $flatErrors);
                }
            }
        }

        if (!empty($body['message']) && is_string($body['message'])) {
            if ($normalizedLr !== '' && $normalizedLr !== '00') {
                return sprintf('%s (LR %s)', $body['message'], $normalizedLr);
            }
            return $body['message'];
        }

        return $isEnglish
            ? 'Transaction was not authorized by the card issuer. Please check your card details or use another card.'
            : 'Transação não autorizada pela operadora do cartão. Verifique os dados informados ou utilize outro cartão.';
    }

    /**
     * Queries the generated Iugu invoice to determine the exact gateway fee (`taxes_paid_cents`),
     * defaulting gracefully to 0.00 if unavailable.
     */
    private function resolveGatewayFee(string $iuguInvoiceId): float
    {
        try {
            $invoiceResp = $this->httpClient->getInvoice($iuguInvoiceId);
            if ($invoiceResp['http_code'] === 200 && isset($invoiceResp['body']['taxes_paid_cents'])) {
                $cents = (int) $invoiceResp['body']['taxes_paid_cents'];
                if ($cents > 0) {
                    return round($cents / 100, 2);
                }
            }
        } catch (Throwable) {
            // Non-blocking: if fee lookup fails, proceed with 0.00 so payment marking never fails
        }
        return 0.0;
    }

    /**
     * @param array<string, mixed> $whmcsCallbacks
     * @param array<string, mixed> $data
     */
    private function logToWhmcs(array $whmcsCallbacks, array $data, string $status): void
    {
        if (isset($whmcsCallbacks['logTransaction']) && is_callable($whmcsCallbacks['logTransaction'])) {
            $sanitized = PciSanitizer::sanitize($data);
            ($whmcsCallbacks['logTransaction'])(Config::GATEWAY_NAME, $sanitized, $status);
        }
    }
}
