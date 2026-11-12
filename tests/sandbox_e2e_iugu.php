<?php
/**
 * Sandbox E2E against the REAL Iugu API (test mode) using the module's own classes.
 *
 * Flow (official Iugu docs — dev.iugu.com/reference):
 *   1. POST /v1/payment_token   (account_id, method=credit_card, test=true, data{...})  -> single-use token
 *   2. ChargeProcessor::processCharge  -> POST /v1/charge (token, months, items, payer, order_id, custom_variables)
 *   3. GET  /v1/invoices/{id}   -> authoritative status/paid/fee; custom_variables carry whmcs_invoice_id
 *   4. WebhookHandler::handle   -> idempotency against the real invoice (noop when already recorded)
 *   5. iugucc_refund-equivalent -> POST /v1/invoices/{id}/refund (partial then full)
 *
 * Requires environment variables (never written to files or logs):
 *   IUGU_ACCOUNT_ID   32-hex Account ID (public, used by Iugu.js)
 *   IUGU_API_TOKEN    Iugu TEST API token (or the placeholder "proxy-injected" when a network proxy injects it)
 * Optional:
 *   IUGU_E2E_DECLINE_CARD  card number that the Iugu sandbox declines (to exercise the LR path)
 *
 * Exit code 0 only when every assertion passes. Skips (exit 0) when credentials are absent so CI stays green.
 *
 * @target WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

require_once __DIR__ . '/../modules/gateways/iugucc/autoload.php';

use WHMCS\Module\Gateway\IuguCC\ChargeProcessor;
use WHMCS\Module\Gateway\IuguCC\Config;
use WHMCS\Module\Gateway\IuguCC\IuguHttpClient;
use WHMCS\Module\Gateway\IuguCC\PciSanitizer;
use WHMCS\Module\Gateway\IuguCC\WebhookHandler;

$accountId = trim((string) (getenv('IUGU_ACCOUNT_ID') ?: ''));
$apiToken = trim((string) (getenv('IUGU_API_TOKEN') ?: ''));
$declineCard = trim((string) (getenv('IUGU_E2E_DECLINE_CARD') ?: ''));

if ($accountId === '' || $apiToken === '') {
    echo "SKIP: IUGU_ACCOUNT_ID / IUGU_API_TOKEN not set — sandbox E2E not executed.\n";
    exit(0);
}

$passed = 0;
$failed = 0;
$failures = [];

function assertTrue(bool $cond, string $name, string $details = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $failed++;
        $failures[] = $name . ($details !== '' ? " — {$details}" : '');
        echo "  \033[31m✗\033[0m {$name}" . ($details !== '' ? " — {$details}" : '') . "\n";
    }
}

/**
 * Generates a random CPF that passes the official Mod-11 check (Iugu rejects well-known sequences).
 */
function generateValidCpf(): string
{
    do {
        $d = [];
        for ($i = 0; $i < 9; $i++) {
            $d[] = random_int(0, 9);
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $d[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $d[] = $r < 2 ? 0 : 11 - $r;
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $d[$i] * (11 - $i);
        }
        $r = $sum % 11;
        $d[] = $r < 2 ? 0 : 11 - $r;
        $cpf = implode('', $d);
    } while (preg_match('/^(\d)\1{10}$/', $cpf) === 1);
    return $cpf;
}

/**
 * Creates a single-use payment token in Iugu TEST mode (no api_token auth, per official docs).
 *
 * @return array{http_code:int, body:array<string,mixed>}
 */
function createTestPaymentToken(string $accountId, string $number, string $cvv, string $first, string $last, string $month, string $year): array
{
    $payload = [
        'account_id' => $accountId,
        'method' => 'credit_card',
        'test' => true,
        'data' => [
            'number' => $number,
            'verification_value' => $cvv,
            'first_name' => $first,
            'last_name' => $last,
            'month' => $month,
            'year' => $year,
        ],
    ];
    $ch = curl_init('https://api.iugu.com/v1/payment_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($raw, true);
    return ['http_code' => $code, 'body' => is_array($body) ? $body : []];
}

echo "\n======================================================================\n";
echo " iugucc — Sandbox E2E against api.iugu.com (test mode, real HTTP)\n";
echo "======================================================================\n\n";

$config = new Config([
    'accountId' => $accountId,
    'apiToken' => $apiToken,
    'sandboxApiToken' => $apiToken,
    'sandboxMode' => 'on',
    'maxInstallments' => '12',
    'minInstallmentValue' => '5.00',
    'statementDescriptor' => 'IMOVELSITE',
    'debugLogging' => 'on',
    'sentinelMode' => 'on',
    'language' => 'portuguese-br',
]);
// Transport: IUGU_AUTH_MODE=query uses the third official auth method (api_token query parameter)
// for environments where an HTTPS proxy rewrites the Authorization header. Default: Basic header (module path).
$authMode = strtolower(trim((string) (getenv('IUGU_AUTH_MODE') ?: 'header')));
$transport = null;
if ($authMode === 'query') {
    $transport = static function (string $method, string $url, array $headers, ?string $body) use ($apiToken): array {
        $sep = str_contains($url, '?') ? '&' : '?';
        $url .= $sep . 'api_token=' . rawurlencode($apiToken);
        $headers = array_values(array_filter($headers, static fn(string $h): bool => stripos($h, 'Authorization:') !== 0));
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('cURL error: ' . $err);
        }
        return ['status' => $status, 'body' => (string) $raw];
    };
}
$http = new IuguHttpClient($config->getActiveApiToken(), Config::API_BASE_URL, 30, $transport);
echo "auth mode: {$authMode}\n";

// Fake WHMCS state (what tblinvoices / tblaccounts would hold)
$whmcsInvoiceId = 900000 + random_int(1000, 9999);
$whmcs = ['paid' => false, 'transactions' => [], 'logs' => []];
$callbacks = [
    'logTransaction' => static function (string $gw, mixed $data, string $status) use (&$whmcs): void {
        $whmcs['logs'][] = ['gw' => $gw, 'status' => $status, 'data' => $data];
    },
    'checkCbInvoiceID' => static function (int $id, string $gw): void {},
    'checkCbTransID' => static function (string $t) use (&$whmcs): void {
        if (isset($whmcs['transactions'][$t])) {
            throw new RuntimeException('Duplicate transaction: ' . $t);
        }
    },
    'addInvoicePayment' => static function (int $id, string $t, float $amt, float $fee, string $gw) use (&$whmcs): void {
        $whmcs['transactions'][$t] = ['invoice' => $id, 'amount' => $amt, 'fee' => $fee, 'gw' => $gw];
        $whmcs['paid'] = true;
    },
    'isInvoicePaid' => static function (int|string $id) use (&$whmcs): bool {
        return $whmcs['paid'];
    },
    'isTransactionRecorded' => static function (string $t) use (&$whmcs): bool {
        return isset($whmcs['transactions'][$t]);
    },
];

$cpf = generateValidCpf();
$client = [
    'userid' => 42,
    'firstname' => 'Joao',
    'lastname' => 'Silva',
    'email' => 'cliente-e2e@imovelsite.com.br',
    'phonenumber' => '+55.11987654321',
    'address1' => 'Rua Exemplo, 123',
    'address2' => 'Centro',
    'city' => 'Sao Paulo',
    'state' => 'SP',
    'postcode' => '01001-000',
    'country' => 'BR',
    'cpf_cnpj' => $cpf,
];

// ---------------------------------------------------------------------------
echo "Suite A: Handshake & token creation (official test card Visa 4111...)\n";
$hs = $http->request('GET', '/invoices?limit=1');
assertTrue($hs['http_code'] === 200, 'GET /v1/invoices?limit=1 authenticates with Basic api_token', 'http ' . $hs['http_code']);

$tok = createTestPaymentToken($accountId, '4111111111111111', '123', 'Joao', 'Silva', '12', '2030');
$tokenId = (string) ($tok['body']['id'] ?? '');
assertTrue($tok['http_code'] === 200 && $tokenId !== '', 'POST /v1/payment_token (test=true) returns token id', 'http ' . $tok['http_code'] . ' ' . json_encode(PciSanitizer::sanitize($tok['body'])));
assertTrue(($tok['body']['test'] ?? null) === true, 'Token is flagged test=true by Iugu');
assertTrue(($tok['body']['extra_info']['brand'] ?? '') === 'VISA', 'Token brand detected as VISA');

// ---------------------------------------------------------------------------
echo "\nSuite B: Synchronous charge via ChargeProcessor (POST /v1/charge, 3x)\n";
$amount = 150.00;
$processor = new ChargeProcessor($config, $http);
$result = $processor->processCharge(
    ['action' => 'checkout', 'invoice_id' => (string) $whmcsInvoiceId, 'iugu_token' => $tokenId, 'installments' => 3, 'cpf_cnpj' => $cpf],
    $whmcsInvoiceId,
    $amount,
    $client,
    $callbacks
);
$iuguInvoiceId = $result['transaction_id'];
assertTrue($result['success'] === true, 'Charge approved (success=true)', json_encode(PciSanitizer::sanitize($result)));
assertTrue($result['lr_code'] === '00', 'LR code is 00', 'lr=' . $result['lr_code']);
assertTrue($iuguInvoiceId !== '' && $iuguInvoiceId !== 'ALREADY_PAID', 'Iugu invoice_id returned as transaction id', $iuguInvoiceId);
assertTrue($whmcs['paid'] === true && isset($whmcs['transactions'][$iuguInvoiceId]), 'addInvoicePayment called once with Iugu invoice id as transid');
assertTrue(($whmcs['transactions'][$iuguInvoiceId]['amount'] ?? 0) === 150.0, 'Payment amount recorded equals invoice amount (150.00)');
assertTrue(($whmcs['transactions'][$iuguInvoiceId]['gw'] ?? '') === 'iugucc', 'Gateway module name passed to addInvoicePayment is iugucc');

$logJson = json_encode($whmcs['logs']);
assertTrue(!str_contains($logJson, '4111111111111111') && !str_contains($logJson, $apiToken), 'Gateway log contains neither PAN nor API token (PciSanitizer)');
assertTrue(str_contains($logJson, 'sentinel_audit'), 'Sentinel audit block present in gateway log');

if ($iuguInvoiceId === '' || $iuguInvoiceId === 'ALREADY_PAID') {
    echo "\n\033[31mABORT\033[0m: charge did not produce an Iugu invoice id; remaining suites depend on it.\n";
    echo " SANDBOX E2E SUMMARY: {$passed} passed, {$failed} failed\n";
    exit(1);
}

// ---------------------------------------------------------------------------
echo "\nSuite C: Authoritative invoice (GET /v1/invoices/{id})\n";
$inv = $http->getInvoice($iuguInvoiceId);
$invBody = $inv['body'];
assertTrue($inv['http_code'] === 200, 'Invoice fetched', 'http ' . $inv['http_code']);
assertTrue(($invBody['status'] ?? '') === 'paid', 'Invoice status is paid', 'status=' . ($invBody['status'] ?? 'n/a'));
assertTrue((int) ($invBody['total_cents'] ?? 0) === 15000, 'total_cents = 15000', 'total_cents=' . ($invBody['total_cents'] ?? 'n/a'));
assertTrue(($invBody['order_id'] ?? '') === 'whmcs_' . $whmcsInvoiceId, 'order_id = whmcs_<invoice>', (string) ($invBody['order_id'] ?? ''));
$cv = [];
foreach (($invBody['custom_variables'] ?? []) as $v) {
    if (is_array($v)) {
        $cv[$v['name'] ?? ''] = $v['value'] ?? '';
    }
}
assertTrue(($cv['whmcs_invoice_id'] ?? '') === (string) $whmcsInvoiceId, 'custom_variables.whmcs_invoice_id round-trips', json_encode($cv));
assertTrue(($cv['integration_module'] ?? '') === 'iugucc', 'custom_variables.integration_module = iugucc');
assertTrue(WebhookHandler::extractWhmcsInvoiceId($invBody) === (string) $whmcsInvoiceId, 'WebhookHandler resolves WHMCS invoice id from real Iugu invoice');
$payerCpf = (string) ($invBody['payer_cpf_cnpj'] ?? '');
assertTrue(preg_replace('/\D/', '', $payerCpf) === $cpf, 'payer.cpf_cnpj persisted on Iugu invoice', 'payer_cpf_cnpj=' . $payerCpf);

// ---------------------------------------------------------------------------
echo "\nSuite D: Webhook idempotency against the real invoice\n";
$wh = new WebhookHandler($config, $http);
$hook = $wh->handle(['event' => 'invoice.status_changed', 'data' => ['id' => $iuguInvoiceId, 'status' => 'paid', 'account_id' => $accountId]], [], $callbacks);
assertTrue($hook['http_code'] === 200 && $hook['action'] === 'noop_already_recorded', 'Webhook for already-recorded invoice is a no-op (no double payment)', json_encode($hook));
assertTrue(count($whmcs['transactions']) === 1, 'Exactly one payment transaction recorded after webhook replay');

// Fresh WHMCS state: webhook arrives before sync return (async path)
$whmcs2 = ['paid' => false, 'transactions' => [], 'logs' => []];
$callbacks2 = $callbacks;
$callbacks2['addInvoicePayment'] = static function (int $id, string $t, float $amt, float $fee, string $gw) use (&$whmcs2): void {
    $whmcs2['transactions'][$t] = ['invoice' => $id, 'amount' => $amt, 'fee' => $fee];
    $whmcs2['paid'] = true;
};
$callbacks2['isInvoicePaid'] = static fn(int|string $id): bool => $whmcs2['paid'];
$callbacks2['isTransactionRecorded'] = static fn(string $t): bool => isset($whmcs2['transactions'][$t]);
$callbacks2['checkCbTransID'] = static function (string $t): void {};
$hook2 = $wh->handle(['event' => 'invoice.status_changed', 'data' => ['id' => $iuguInvoiceId, 'account_id' => $accountId]], [], $callbacks2);
assertTrue($hook2['action'] === 'payment_recorded' && $whmcs2['paid'] === true, 'Webhook records payment when WHMCS invoice still unpaid', json_encode($hook2));
assertTrue(($whmcs2['transactions'][$iuguInvoiceId]['amount'] ?? 0) === 150.0, 'Webhook payment amount from total_paid_cents = 150.00', json_encode($whmcs2['transactions']));

// Spoofed webhook (account mismatch) and foreign invoice
$spoof = $wh->handle(['event' => 'invoice.status_changed', 'data' => ['id' => $iuguInvoiceId, 'account_id' => str_repeat('A', 32)]], [], $callbacks);
assertTrue($spoof['http_code'] === 403, 'Webhook with wrong account_id rejected (403)');
$ghost = $wh->handle(['event' => 'invoice.status_changed', 'data' => ['id' => 'DEADBEEF00000000000000000000FFFF', 'account_id' => $accountId]], [], $callbacks);
assertTrue($ghost['http_code'] === 404 && $ghost['action'] === 'invoice_not_found_on_iugu', 'Webhook for non-existent invoice rejected (404)', json_encode($ghost));

// ---------------------------------------------------------------------------
echo "\nSuite E: Refund (POST /v1/invoices/{id}/refund) partial then full\n";
$partial = $http->refundInvoice($iuguInvoiceId, 5000);
assertTrue($partial['http_code'] >= 200 && $partial['http_code'] < 300, 'Partial refund R$50,00 accepted', 'http ' . $partial['http_code'] . ' ' . json_encode(PciSanitizer::sanitize($partial['body'])));
$afterPartial = $http->getInvoice($iuguInvoiceId)['body'];
$full = $http->refundInvoice($iuguInvoiceId, null);
assertTrue($full['http_code'] >= 200 && $full['http_code'] < 300, 'Full refund of remaining balance accepted', 'http ' . $full['http_code'] . ' ' . json_encode(PciSanitizer::sanitize($full['body'])));
$afterFull = $http->getInvoice($iuguInvoiceId)['body'];
assertTrue(($afterFull['status'] ?? '') === 'refunded', 'Invoice status is refunded after full refund', 'status=' . ($afterFull['status'] ?? 'n/a') . ' after_partial=' . ($afterPartial['status'] ?? 'n/a'));

// ---------------------------------------------------------------------------
echo "\nSuite F: Reuse of single-use token & declined paths\n";
$reuse = $processor->processCharge(
    ['action' => 'checkout', 'invoice_id' => (string) ($whmcsInvoiceId + 1), 'iugu_token' => $tokenId, 'installments' => 1],
    $whmcsInvoiceId + 1,
    10.00,
    $client,
    ['logTransaction' => $callbacks['logTransaction']]
);
assertTrue($reuse['success'] === false, 'Re-using a consumed token is rejected by Iugu and surfaces as declined', json_encode(PciSanitizer::sanitize($reuse)));

if ($declineCard !== '') {
    $tokDecl = createTestPaymentToken($accountId, $declineCard, '123', 'Joao', 'Silva', '12', '2030');
    $declTokenId = (string) ($tokDecl['body']['id'] ?? '');
    assertTrue($declTokenId !== '', 'Decline test card tokenized', json_encode(PciSanitizer::sanitize($tokDecl['body'])));
    $decl = $processor->processCharge(
        ['action' => 'checkout', 'invoice_id' => (string) ($whmcsInvoiceId + 2), 'iugu_token' => $declTokenId, 'installments' => 1],
        $whmcsInvoiceId + 2,
        20.00,
        $client,
        ['logTransaction' => $callbacks['logTransaction']]
    );
    assertTrue($decl['success'] === false && $decl['lr_code'] !== '00', 'Declined test card yields success=false with LR/decline reason', json_encode(PciSanitizer::sanitize($decl)));
} else {
    echo "  (decline card not provided via IUGU_E2E_DECLINE_CARD — skipped)\n";
}

echo "\n======================================================================\n";
echo " SANDBOX E2E SUMMARY: {$passed} passed, {$failed} failed  (WHMCS invoice #{$whmcsInvoiceId} ↔ Iugu {$iuguInvoiceId})\n";
echo "======================================================================\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit($failed === 0 ? 0 : 1);
