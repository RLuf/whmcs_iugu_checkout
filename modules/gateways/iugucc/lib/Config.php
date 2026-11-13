<?php
/**
 * Gateway Configuration & Security Helper
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\IuguCC;

final class Config
{
    public const GATEWAY_NAME = 'iugucc';
    public const API_BASE_URL = 'https://api.iugu.com/v1';
    public const IUGU_JS_URL  = 'https://js.iugu.com/v2';

    private string $accountId;
    private string $apiToken;
    private string $sandboxApiToken;
    private bool $sandboxMode;
    private int $maxInstallments;
    private float $minInstallmentValue;
    private string $invoiceDescription;
    private string $statementDescriptor;
    private string $webhookSecret;
    private string $cpfCustomFieldName;
    private bool $debugLogging;
    private bool $sentinelMode;
    private string $language;

    /**
     * @param array<string, mixed> $params WHMCS gateway configuration parameters
     */
    public function __construct(array $params)
    {
        $this->accountId = trim((string) ($params['accountId'] ?? ''));
        $this->apiToken = trim((string) ($params['apiToken'] ?? ''));
        $this->sandboxApiToken = trim((string) ($params['sandboxApiToken'] ?? ''));
        $this->sandboxMode = $this->parseBool($params['sandboxMode'] ?? false);

        $maxInst = (int) ($params['maxInstallments'] ?? 1);
        $this->maxInstallments = max(1, min(12, $maxInst));

        $minVal = (float) str_replace(',', '.', (string) ($params['minInstallmentValue'] ?? '5.00'));
        $this->minInstallmentValue = max(1.0, $minVal);

        $this->invoiceDescription = trim((string) ($params['invoiceDescription'] ?? 'Fatura #{invoice_id} - imovelsite.com.br'));
        $this->statementDescriptor = substr(trim((string) ($params['statementDescriptor'] ?? 'IMOVELSITE')), 0, 12); // Iugu soft_descriptor_light: max 12 chars
        $this->webhookSecret = trim((string) ($params['webhookSecret'] ?? ''));
        $this->cpfCustomFieldName = trim((string) ($params['cpfCustomField'] ?? 'CPF/CNPJ'));
        $this->debugLogging = $this->parseBool($params['debugLogging'] ?? true);
        $this->sentinelMode = $this->parseBool($params['sentinelMode'] ?? true);
        $this->language = strtolower(trim((string) ($params['language'] ?? $params['clientdetails']['language'] ?? 'portuguese-br')));
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function isSandbox(): bool
    {
        return $this->sandboxMode;
    }

    /**
     * Returns the active API token depending on whether Sandbox Mode is enabled.
     *
     * Sandbox Mode uses ONLY the Iugu TEST token. There is no fallback to the LIVE token: Iugu keeps test and
     * production isolated, so a card tokenized with Iugu.setTestMode(true) is rejected by a LIVE token with
     * HTTP 400 {"errors":"token is invalid"} (measured against the Iugu API). Falling back would make every
     * sandbox checkout fail with a misleading card error.
     */
    public function getActiveApiToken(): string
    {
        return $this->sandboxMode ? $this->sandboxApiToken : $this->apiToken;
    }

    /**
     * True when Sandbox Mode is enabled but the Iugu TEST API token was not provided.
     */
    public function isSandboxTokenMissing(): bool
    {
        return $this->sandboxMode && $this->sandboxApiToken === '';
    }

    public function isConfigured(): bool
    {
        return $this->accountId !== '' && $this->getActiveApiToken() !== '';
    }

    public function getMaxInstallments(): int
    {
        return $this->maxInstallments;
    }

    public function getMinInstallmentValue(): float
    {
        return $this->minInstallmentValue;
    }

    public function getStatementDescriptor(): string
    {
        return $this->statementDescriptor;
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function getCpfCustomFieldName(): string
    {
        return $this->cpfCustomFieldName;
    }

    public function isDebugLoggingEnabled(): bool
    {
        return $this->debugLogging;
    }

    public function isSentinelModeEnabled(): bool
    {
        return $this->sentinelMode;
    }

    public function isEnglish(): bool
    {
        return str_contains($this->language, 'english') || $this->language === 'en';
    }

    /**
     * Formats the invoice item description using the configured template.
     */
    public function formatInvoiceDescription(int|string $invoiceId, string $companyName = 'imovelsite.com.br'): string
    {
        $template = $this->invoiceDescription !== ''
            ? $this->invoiceDescription
            : 'Fatura #{invoice_id} - ' . $companyName;

        return str_replace(
            ['{invoice_id}', '{company_name}'],
            [(string) $invoiceId, $companyName],
            $template
        );
    }

    /**
     * Computes valid installment options for a given invoice total.
     *
     * @param float $amount Invoice total in BRL (e.g., 150.00)
     * @return array<int, array{installments: int, installment_amount: float, formatted: string}>
     */
    public function computeInstallmentOptions(float $amount): array
    {
        $options = [];
        $total = round(max(0.0, $amount), 2);

        for ($i = 1; $i <= $this->maxInstallments; $i++) {
            $perInstallment = round($total / $i, 2);
            if ($i > 1 && $perInstallment < $this->minInstallmentValue) {
                break;
            }
            $formattedValue = number_format($perInstallment, 2, ',', '.');
            $options[$i] = [
                'installments' => $i,
                'installment_amount' => $perInstallment,
                'formatted' => sprintf('%dx de R$ %s sem juros', $i, $formattedValue),
            ];
        }

        if (empty($options)) {
            $options[1] = [
                'installments' => 1,
                'installment_amount' => $total,
                'formatted' => sprintf('1x de R$ %s à vista', number_format($total, 2, ',', '.')),
            ];
        }

        return $options;
    }

    /**
     * Generates an HMAC-SHA256 signature binding the checkout form to a specific invoice and amount.
     */
    public function generateCheckoutSignature(int|string $invoiceId, int|string $clientId, string $amount): string
    {
        $secret = $this->getActiveApiToken() . '|' . $this->accountId;
        $normalizedAmount = number_format((float) $amount, 2, '.', '');
        $payload = sprintf('%s|%s|%s', (string) $invoiceId, (string) $clientId, $normalizedAmount);

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verifies the checkout HMAC-SHA256 signature in constant time.
     */
    public function verifyCheckoutSignature(
        int|string $invoiceId,
        int|string $clientId,
        string $amount,
        string $providedSignature
    ): bool {
        if ($providedSignature === '') {
            return false;
        }
        $expected = $this->generateCheckoutSignature($invoiceId, $clientId, $amount);
        return hash_equals($expected, $providedSignature);
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['on', 'yes', 'true', '1'], true);
    }
}
