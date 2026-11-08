<?php
/**
 * Iugu REST API v1 HTTP Client
 *
 * Handles authenticated communication with https://api.iugu.com/v1
 * Supports dependency-injected transport for deterministic unit/mock testing.
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\IuguCC;

use RuntimeException;

final class IuguHttpClient
{
    private string $apiToken;
    private string $baseUrl;
    private int $timeoutSeconds;

    /**
     * Optional custom HTTP transport callable for testing:
     * fn(string $method, string $url, array $headers, ?string $body): array{status: int, body: string}
     *
     * @var callable|null
     */
    private $transport;

    public function __construct(
        string $apiToken,
        string $baseUrl = Config::API_BASE_URL,
        int $timeoutSeconds = 30,
        ?callable $transport = null
    ) {
        $this->apiToken = trim($apiToken);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = max(5, $timeoutSeconds);
        $this->transport = $transport;
    }

    /**
     * Executes a direct credit card charge using a client-side Iugu.js token (`POST /v1/charge`).
     *
     * @param array<string, mixed> $payload
     * @return array{http_code: int, body: array<string, mixed>, raw: string}
     */
    public function charge(array $payload): array
    {
        return $this->request('POST', '/charge', $payload);
    }

    /**
     * Fetches authoritative invoice details from Iugu (`GET /v1/invoices/{id}`).
     *
     * @return array{http_code: int, body: array<string, mixed>, raw: string}
     */
    public function getInvoice(string $iuguInvoiceId): array
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $iuguInvoiceId) ?? '';
        if ($cleanId === '') {
            throw new RuntimeException('Invalid Iugu invoice ID.');
        }

        return $this->request('GET', '/invoices/' . $cleanId);
    }

    /**
     * Refunds a paid Iugu invoice (`POST /v1/invoices/{id}/refund`).
     *
     * @param string   $iuguInvoiceId Iugu Invoice ID
     * @param int|null $partialCents  Optional partial refund amount in cents
     * @return array{http_code: int, body: array<string, mixed>, raw: string}
     */
    public function refundInvoice(string $iuguInvoiceId, ?int $partialCents = null): array
    {
        $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $iuguInvoiceId) ?? '';
        if ($cleanId === '') {
            throw new RuntimeException('Invalid Iugu invoice ID for refund.');
        }

        $payload = [];
        if ($partialCents !== null && $partialCents > 0) {
            $payload['partial_value_refund_cents'] = $partialCents;
        }

        return $this->request('POST', '/invoices/' . $cleanId . '/refund', $payload);
    }

    /**
     * Performs an HTTP request to the Iugu REST API v1.
     *
     * @param string                    $method  GET, POST, PUT, DELETE
     * @param string                    $path    Relative API path (e.g. '/charge')
     * @param array<string, mixed>|null $payload JSON body payload
     * @return array{http_code: int, body: array<string, mixed>, raw: string}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        if ($this->apiToken === '') {
            throw new RuntimeException('Iugu API Token is not configured.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $authHeader = 'Basic ' . base64_encode($this->apiToken . ':');

        $headers = [
            'Authorization: ' . $authHeader,
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: WHMCS-IuguCC-Gateway/1.1.0 (WHMCS 8.13.7; PHP ' . PHP_VERSION . ')',
        ];

        $jsonBody = null;
        if ($payload !== null && $method !== 'GET') {
            $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonBody === false) {
                throw new RuntimeException('Failed to encode Iugu request payload as JSON.');
            }
        }

        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $headers, $jsonBody);
            $statusCode = (int) ($response['status'] ?? 0);
            $rawBody = (string) ($response['body'] ?? '');
        } else {
            [$statusCode, $rawBody] = $this->executeCurl($method, $url, $headers, $jsonBody);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'http_code' => $statusCode,
            'body' => $decoded,
            'raw' => $rawBody,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: int, 1: string}
     */
    private function executeCurl(string $method, string $url, array $headers, ?string $jsonBody): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL session.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
        }

        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false || $curlErrno !== 0) {
            throw new RuntimeException(sprintf('Iugu API connection error (%d): %s', $curlErrno, $curlError));
        }

        return [$httpCode, (string) $rawResponse];
    }
}
