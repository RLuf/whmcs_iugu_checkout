<?php
/**
 * PCI-DSS Sanitizer for WHMCS Gateway Logs
 *
 * Guarantees that sensitive credit card numbers (PAN), CVVs, and API secrets
 * are NEVER written to WHMCS Gateway Logs (`tblgatewaylog`) or error logs.
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\IuguCC;

final class PciSanitizer
{
    /**
     * Keys that must always be redacted if they ever appear in an array or payload.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_CARD_KEYS = [
        'number',
        'card_number',
        'ccnumber',
        'cardnumber',
        'pan',
        'verification_value',
        'cvv',
        'cvc',
        'cccvv',
        'security_code',
        'card_cvv',
        'expiration',
        'card_expiry',
    ];

    /**
     * Secret configuration keys that must be masked in logs.
     *
     * @var array<int, string>
     */
    private const SECRET_KEYS = [
        'apitoken',
        'api_token',
        'sandboxapitoken',
        'sandbox_api_token',
        'webhooksecret',
        'webhook_secret',
        'authorization',
    ];

    /**
     * Recursively sanitizes an array or scalar value for safe logging in WHMCS.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            $clean = [];
            foreach ($data as $key => $value) {
                $normalizedKey = strtolower((string) $key);

                if (in_array($normalizedKey, self::FORBIDDEN_CARD_KEYS, true)) {
                    $clean[$key] = '[REDACTED_PCI_SENSITIVE]';
                    continue;
                }

                if (in_array($normalizedKey, self::SECRET_KEYS, true)) {
                    $clean[$key] = self::maskSecret((string) $value);
                    continue;
                }

                $clean[$key] = self::sanitize($value);
            }
            return $clean;
        }

        if (is_string($data)) {
            // Redact any raw 13-to-19 digit sequence that resembles a PAN
            return preg_replace_callback(
                '/\b(?:\d[ -]*?){13,19}\b/',
                static function (array $matches): string {
                    $digitsOnly = preg_replace('/\D/', '', $matches[0]) ?? '';
                    if (strlen($digitsOnly) >= 13 && strlen($digitsOnly) <= 19) {
                        $last4 = substr($digitsOnly, -4);
                        return '****-****-****-' . $last4;
                    }
                    return $matches[0];
                },
                $data
            ) ?? $data;
        }

        return $data;
    }

    /**
     * Inspects incoming request parameters and returns true if raw credit card data
     * was erroneously sent to the server, allowing the gateway to reject it immediately.
     *
     * @param array<string, mixed> $request
     */
    public static function containsRawCardData(array $request): bool
    {
        foreach ($request as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, ['number', 'card_number', 'ccnumber', 'verification_value', 'cvv', 'cvc'], true)) {
                if (trim((string) $value) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    private static function maskSecret(string $secret): string
    {
        $trimmed = trim($secret);
        $len = strlen($trimmed);
        if ($len <= 8) {
            return '********';
        }
        return substr($trimmed, 0, 4) . str_repeat('*', min(12, $len - 8)) . substr($trimmed, -4);
    }
}
