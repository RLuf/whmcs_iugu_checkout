<?php
/**
 * Native PHP 8.1 / 8.2 Unit & Integration Test Suite for WHMCS Iugu Credit Card Gateway (`iugucc`)
 *
 * Can be executed directly with `php tests/phpunit_runner.php` in any PHP 8.1+ environment.
 *
 * @package    WHMCS\Module\Gateway\IuguCC\Tests
 */

declare(strict_types=1);

define('WHMCS', true);

require_once __DIR__ . '/../modules/gateways/iugucc.php';

use WHMCS\Module\Gateway\IuguCC\ChargeProcessor;
use WHMCS\Module\Gateway\IuguCC\Config;
use WHMCS\Module\Gateway\IuguCC\IuguHttpClient;
use WHMCS\Module\Gateway\IuguCC\PayloadBuilder;
use WHMCS\Module\Gateway\IuguCC\PciSanitizer;
use WHMCS\Module\Gateway\IuguCC\WebhookHandler;

$fixturesJson = (string) file_get_contents(__DIR__ . '/fixtures/iugu_mock_responses.json');
/** @var array<string, mixed> $fixtures */
$fixtures = json_decode($fixturesJson, true);

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
}

// 1. Metadata & Config tests
$meta = iugucc_MetaData();
assertCheck(($meta['DisableLocalCreditCardInput'] ?? false) === true, 'iugucc_MetaData disables local CC input for PCI-DSS');
$cfgFields = iugucc_config();
assertCheck(isset($cfgFields['accountId'], $cfgFields['apiToken'], $cfgFields['sandboxMode']), 'iugucc_config exposes accountId, apiToken, and sandboxMode');

// 2. PCI Sanitizer tests
$dirty = [
    'number' => '4111 1111 1111 1111',
    'verification_value' => '123',
    'apiToken' => 'live_secret_token_1234567890abcdef',
    'note' => 'Raw PAN 4111111111111111 in string',
];
$clean = PciSanitizer::sanitize($dirty);
assertCheck($clean['number'] === '[REDACTED_PCI_SENSITIVE]', 'PciSanitizer redacts card number key');
assertCheck($clean['verification_value'] === '[REDACTED_PCI_SENSITIVE]', 'PciSanitizer redacts CVV key');
assertCheck(!str_contains((string) $clean['note'], '4111111111111111'), 'PciSanitizer masks inline PAN strings');
assertCheck(PciSanitizer::containsRawCardData(['number' => '4111111111111111']) === true, 'PciSanitizer detects raw card data');

// 3. ChargeProcessor Approved & Declined tests
$config = new Config([
    'accountId' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
    'sandboxApiToken' => 'test_token_sandbox_1234567890',
    'sandboxMode' => 'on',
    'maxInstallments' => '6',
    'minInstallmentValue' => '10.00',
    'webhookSecret' => 'whsec_test_123',
]);

$mockClientApproved = new IuguHttpClient(
    $config->getActiveApiToken(),
    Config::API_BASE_URL,
    10,
    static function (string $method, string $url) use ($fixtures): array {
        if ($method === 'POST' && str_ends_with($url, '/charge')) {
            return [
                'status' => 200,
                'body' => (string) json_encode($fixtures['charge_responses']['approved_lr00']['body']),
            ];
        }
        return [
            'status' => 200,
            'body' => (string) json_encode($fixtures['invoice_details']['IUGU_INV_99887766554433221100AABB']['body']),
        ];
    }
);

$paymentsRecorded = [];
$processor = new ChargeProcessor($config, $mockClientApproved);
$res = $processor->processCharge(
    ['iugu_token' => 'tok_test_approved_001', 'installments' => 3],
    1024,
    150.00,
    ['userid' => 42, 'firstname' => 'João', 'lastname' => 'Silva', 'email' => 'joao@imovelsite.com.br', 'cpf_cnpj' => '123.456.789-09'],
    [
        'addInvoicePayment' => static function (int $invId, string $transId, float $amt, float $fee, string $gw) use (&$paymentsRecorded): void {
            $paymentsRecorded[] = compact('invId', 'transId', 'amt', 'fee', 'gw');
        },
    ]
);

assertCheck($res['success'] === true && $res['lr_code'] === '00', 'ChargeProcessor approves LR 00 charge');
assertCheck(count($paymentsRecorded) === 1 && $paymentsRecorded[0]['invId'] === 1024, 'ChargeProcessor invokes addInvoicePayment on approval');
assertCheck($paymentsRecorded[0]['fee'] === 4.49, 'ChargeProcessor records gateway fee from Iugu invoice');

// 4. WebhookHandler Idempotency test
$webhookHandler = new WebhookHandler($config, $mockClientApproved);
$whResult = $webhookHandler->handle(
    $fixtures['webhooks']['invoice_paid'],
    ['Authorization' => 'Bearer whsec_test_123'],
    [
        'isTransactionRecorded' => static fn(string $tx): bool => false,
        'addInvoicePayment' => static function (int $invId, string $transId, float $amt, float $fee, string $gw) use (&$paymentsRecorded): void {
            $paymentsRecorded[] = compact('invId', 'transId', 'amt', 'fee', 'gw');
        },
    ]
);
assertCheck($whResult['http_code'] === 200 && $whResult['action'] === 'payment_recorded', 'WebhookHandler records unpaid invoice payment');

// Sandbox / production token selection (no silent fallback to the LIVE token)
$sandboxNoToken = new Config(['accountId' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90', 'apiToken' => 'live_token_abc', 'sandboxMode' => 'on']);
assertCheck($sandboxNoToken->getActiveApiToken() === '' && $sandboxNoToken->isConfigured() === false && $sandboxNoToken->isSandboxTokenMissing() === true, 'Sandbox Mode without a TEST token is not configured and never falls back to the LIVE token');
$sandboxWithToken = new Config(['accountId' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90', 'apiToken' => 'live_token_abc', 'sandboxApiToken' => 'test_token_xyz', 'sandboxMode' => 'on']);
assertCheck($sandboxWithToken->getActiveApiToken() === 'test_token_xyz' && $sandboxWithToken->isConfigured() === true, 'Sandbox Mode uses the TEST token');
$liveConfig = new Config(['accountId' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90', 'apiToken' => 'live_token_abc', 'sandboxApiToken' => 'test_token_xyz', 'sandboxMode' => '']);
assertCheck($liveConfig->getActiveApiToken() === 'live_token_abc' && $liveConfig->isSandboxTokenMissing() === false, 'Production mode uses the LIVE token');
assertCheck(str_contains(ChargeProcessor::translateMessage('', ['errors' => 'token is invalid'], false), 'Recarregue'), 'Iugu "token is invalid" is shown to the customer as a clear reload instruction');

echo "\nTotal: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
