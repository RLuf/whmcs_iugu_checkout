<?php
/**
 * Empirical Adversarial Stress Test Suite for WHMCS Iugu Credit Card Gateway (`iugucc`)
 *
 * Verifies extreme edge cases, acquirer declines (LR codes), network timeouts,
 * cURL failures, duplicate charge prevention, zero/negative amounts, missing client
 * details, and PCI-DSS / HMAC / Webhook security attacks.
 *
 * @package    WHMCS\Module\Gateway\IuguCC\Tests
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2 / 8.3
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
$failures = [];

function assertTest(bool $condition, string $testName, string $details = ''): void
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  \033[32m✓\033[0m {$testName}\n";
    } else {
        $failed++;
        $msg = $details !== '' ? "{$testName} — {$details}" : $testName;
        $failures[] = $msg;
        echo "  \033[31m✗\033[0m {$msg}\n";
    }
}

echo "======================================================================\n";
echo " WHMCS Iugu CC Gateway — Empirical Adversarial Challenge Suite\n";
echo "======================================================================\n\n";

// ============================================================================
// SUITE A: Acquirer Declines (LR Codes) & Localized Messages (PT-BR and English)
// ============================================================================
echo "Section A: Acquirer Declines (LR Codes) & Localization\n";

$lrExpectedPt = [
    '00' => 'Pagamento aprovado com sucesso.',
    '04' => 'Transação não autorizada pela operadora do cartão (LR 04). Verifique com o banco emissor ou utilize outro cartão.',
    '05' => 'Transação não autorizada pelo banco emissor (LR 05). Entre em contato com a central do seu cartão.',
    '07' => 'Cartão com restrição no emissor (LR 07). Utilize outro cartão.',
    '51' => 'Saldo ou limite insuficiente no cartão de crédito (LR 51).',
    '54' => 'Cartão de crédito vencido (LR 54). Verifique a data de validade.',
    '57' => 'Transação não permitida para este cartão (LR 57). Verifique se a função compra online está habilitada.',
    '78' => 'Cartão bloqueado ou aguardando desbloqueio de primeiro uso (LR 78).',
    '82' => 'Código de segurança (CVV) ou dados do cartão inválidos (LR 82).',
    'N7' => 'Código de segurança (CVV) inválido (LR N7). Confira os dígitos no verso do cartão.',
];

foreach ($lrExpectedPt as $lr => $expectedText) {
    $lrStr = (string) $lr;
    $msgPt = ChargeProcessor::translateMessage($lrStr, [], false);
    assertTest(
        $msgPt === $expectedText,
        "LR {$lrStr} maps to localized PT-BR message: '{$expectedText}'"
    );

    $msgEn = ChargeProcessor::translateMessage($lrStr, [], true);
    assertTest(
        str_contains($msgEn, "(LR {$lrStr})") || $lrStr === '00',
        "LR {$lrStr} maps to localized English message (contains LR {$lrStr})"
    );
}

// Test unmapped LR code
$unmappedMsg = ChargeProcessor::translateMessage('99', ['message' => 'Erro desconhecido'], false);
assertTest(
    $unmappedMsg === 'Erro desconhecido (LR 99)',
    "Unmapped LR code formats with acquirer message and code suffix: '{$unmappedMsg}'"
);

// Test structured validation errors from Iugu API
$validationErrorBody = [
    'errors' => [
        'token' => ['não é válido ou já foi utilizado'],
        'cpf_cnpj' => ['número do documento inválido'],
    ],
];
$valMsgPt = ChargeProcessor::translateMessage('', $validationErrorBody, false);
assertTest(
    str_contains($valMsgPt, 'Erro de validação:') &&
    str_contains($valMsgPt, 'token: não é válido') &&
    str_contains($valMsgPt, 'cpf_cnpj: número do documento inválido'),
    'Multi-field validation error array is flattened into customer-friendly message'
);

$valMsgEn = ChargeProcessor::translateMessage('', $validationErrorBody, true);
assertTest(
    str_contains($valMsgEn, 'Validation Error:'),
    'Multi-field validation error uses English prefix when language is English'
);

// Test string error in body['errors']
$stringErrorBody = ['errors' => 'Token expirado'];
$strErrMsg = ChargeProcessor::translateMessage('', $stringErrorBody, false);
assertTest(
    $strErrMsg === 'Não foi possível processar o pagamento: Token expirado',
    'String error in body[errors] is shown with a clear localized prefix'
);
assertTest(
    str_contains(ChargeProcessor::translateMessage('', ['errors' => 'token is invalid'], false), 'Recarregue a página da fatura')
        && str_contains(ChargeProcessor::translateMessage('', ['errors' => 'token is invalid'], true), 'Reload the invoice page'),
    'Iugu "token is invalid" (expired/used token, or test token with a LIVE key) becomes a reload instruction in PT and EN'
);

// Test completely empty body fallback
$emptyBodyMsg = ChargeProcessor::translateMessage('', [], false);
assertTest(
    str_contains($emptyBodyMsg, 'Transação não autorizada pela operadora'),
    'Empty API body falls back to safe generic refusal message'
);

// ============================================================================
// SUITE B: Network Failures, Timeouts, cURL Errors & HTTP Status Resilience
// ============================================================================
echo "\nSection B: Network Failures, Timeouts & Transport Resilience\n";

$baseConfigParams = [
    'accountId' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
    'apiToken' => 'live_token_secret_1234567890',
    'sandboxApiToken' => 'test_token_sandbox_1234567890',
    'sandboxMode' => 'off',
    'maxInstallments' => '6',
    'minInstallmentValue' => '10.00',
    'webhookSecret' => 'whsec_secret_token_123',
    'language' => 'portuguese-br',
];
$baseConfig = new Config($baseConfigParams);

// Scenario B.1: cURL timeout during ChargeProcessor::processCharge
$clientWithTimeout = new IuguHttpClient(
    $baseConfig->getActiveApiToken(),
    Config::API_BASE_URL,
    10,
    static function (): array {
        throw new RuntimeException('Iugu API connection error (28): Operation timed out after 30000 milliseconds');
    }
);

$loggedTransactions = [];
$mockWhmcsCallbacks = [
    'logTransaction' => static function (string $gateway, mixed $data, string $status) use (&$loggedTransactions): void {
        $loggedTransactions[] = compact('gateway', 'data', 'status');
    },
    'checkCbTransID' => static function (string $tx): void {},
    'addInvoicePayment' => static function (int $id, string $tx, float $amt, float $fee, string $gw): void {
        throw new LogicException('addInvoicePayment should never be called on network failure');
    },
    'isInvoicePaid' => static fn(): bool => false,
    'isTransactionRecorded' => static fn(): bool => false,
];

$processorTimeout = new ChargeProcessor($baseConfig, $clientWithTimeout);
$resTimeout = $processorTimeout->processCharge(
    ['iugu_token' => 'tok_timeout_test'],
    1024,
    150.00,
    ['firstname' => 'Teste', 'lastname' => 'Rede', 'email' => 'teste@imovelsite.com.br'],
    $mockWhmcsCallbacks
);

assertTest(
    $resTimeout['success'] === false && $resTimeout['lr_code'] === 'EXCEPTION',
    'ChargeProcessor catches cURL timeout and returns success=false with lr_code=EXCEPTION'
);
assertTest(
    str_contains($resTimeout['message'], 'Não foi possível comunicar com a processadora de pagamentos'),
    'Returns clean customer-facing message on network timeout without leaking internal stack trace'
);
assertTest(
    count($loggedTransactions) === 1 && $loggedTransactions[0]['status'] === 'Error',
    'Logs network exception to WHMCS Gateway Log with Error status'
);

// Scenario B.2: Network failure during WebhookHandler invoice verification
$webhookHandlerTimeout = new WebhookHandler($baseConfig, $clientWithTimeout);
$webhookTimeoutResult = $webhookHandlerTimeout->handle(
    $fixtures['webhooks']['invoice_paid'],
    ['Authorization' => 'Bearer whsec_secret_token_123'],
    $mockWhmcsCallbacks
);

assertTest(
    $webhookTimeoutResult['http_code'] === 502 && $webhookTimeoutResult['processed'] === false,
    'WebhookHandler returns HTTP 502 Bad Gateway on network failure so Iugu will retry delivery'
);

// Scenario B.3: Iugu API returns 404 Not Found for invoice
$clientWith404 = new IuguHttpClient(
    $baseConfig->getActiveApiToken(),
    Config::API_BASE_URL,
    10,
    static fn(): array => ['status' => 404, 'body' => (string) json_encode(['errors' => 'Invoice not found'])]
);
$webhookHandler404 = new WebhookHandler($baseConfig, $clientWith404);
$webhook404Result = $webhookHandler404->handle(
    $fixtures['webhooks']['invoice_paid'],
    ['Authorization' => 'Bearer whsec_secret_token_123'],
    $mockWhmcsCallbacks
);

assertTest(
    $webhook404Result['http_code'] === 404 && $webhook404Result['processed'] === false,
    'WebhookHandler rejects spoofed invoice with HTTP 404 when invoice does not exist on Iugu'
);

// ============================================================================
// SUITE C: Duplicate Charges & Idempotency Enforcement
// ============================================================================
echo "\nSection C: Duplicate Charges & Idempotency Enforcement\n";

$chargeCallCount = 0;
$mockClientSpy = new IuguHttpClient(
    $baseConfig->getActiveApiToken(),
    Config::API_BASE_URL,
    10,
    static function (string $method, string $url) use (&$chargeCallCount, $fixtures): array {
        if ($method === 'POST' && str_ends_with($url, '/charge')) {
            $chargeCallCount++;
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

// Scenario C.1: Synchronous charge submitted for invoice ALREADY marked as Paid in WHMCS
$processorPaidGuard = new ChargeProcessor($baseConfig, $mockClientSpy);
$chargeCallCount = 0;
$resAlreadyPaid = $processorPaidGuard->processCharge(
    ['iugu_token' => 'tok_already_paid_test'],
    1024,
    150.00,
    ['firstname' => 'João', 'email' => 'joao@imovelsite.com.br'],
    [
        'isInvoicePaid' => static fn(int|string $invId): bool => true, // Invoice is ALREADY paid!
        'addInvoicePayment' => static function (): void {
            throw new LogicException('addInvoicePayment must NOT be called for already paid invoice');
        },
    ]
);

assertTest(
    $resAlreadyPaid['success'] === true &&
    $resAlreadyPaid['transaction_id'] === 'ALREADY_PAID' &&
    $chargeCallCount === 0,
    'ChargeProcessor aborts BEFORE calling Iugu charge API when invoice is already Paid in WHMCS'
);

// Scenario C.2: Webhook idempotency when transaction already recorded in tblaccounts
$webhookHandlerIdempotent = new WebhookHandler($baseConfig, $mockClientSpy);
$paymentCalls = 0;
$resWebhookDuplicate = $webhookHandlerIdempotent->handle(
    $fixtures['webhooks']['invoice_paid'],
    ['Authorization' => 'Bearer whsec_secret_token_123'],
    [
        'isTransactionRecorded' => static fn(string $tx): bool => true, // Already recorded!
        'addInvoicePayment' => static function () use (&$paymentCalls): void {
            $paymentCalls++;
        },
    ]
);

assertTest(
    $resWebhookDuplicate['http_code'] === 200 &&
    $resWebhookDuplicate['action'] === 'noop_already_recorded' &&
    $paymentCalls === 0,
    'WebhookHandler is strictly idempotent and does not invoke addInvoicePayment when transaction already exists'
);

// Scenario C.3: Webhook idempotency when invoice is already paid in WHMCS tblinvoices
$resWebhookInvoicePaid = $webhookHandlerIdempotent->handle(
    $fixtures['webhooks']['invoice_paid'],
    ['Authorization' => 'Bearer whsec_secret_token_123'],
    [
        'isTransactionRecorded' => static fn(string $tx): bool => false,
        'isInvoicePaid' => static fn(int $id): bool => true, // Invoice status = Paid
        'addInvoicePayment' => static function () use (&$paymentCalls): void {
            $paymentCalls++;
        },
    ]
);

assertTest(
    $resWebhookInvoicePaid['http_code'] === 200 &&
    $resWebhookInvoicePaid['action'] === 'noop_invoice_already_paid' &&
    $paymentCalls === 0,
    'WebhookHandler does not duplicate payment when WHMCS invoice is already marked as Paid'
);

// ============================================================================
// SUITE D: Boundary Edge Cases (Zero, Negative, Missing Data)
// ============================================================================
echo "\nSection D: Boundary Edge Cases (Zero Amount, Negative Amount, Missing Data)\n";

// Scenario D.1: Zero Amount ($0.00) in PayloadBuilder
$zeroAmountCaught = false;
try {
    PayloadBuilder::buildChargePayload($baseConfig, 'tok_123', 1024, 0.00, []);
} catch (InvalidArgumentException $e) {
    $zeroAmountCaught = true;
    assertTest(
        str_contains($e->getMessage(), 'at least R$ 1,00'),
        "PayloadBuilder rejects zero amount: '{$e->getMessage()}'"
    );
}
assertTest($zeroAmountCaught, 'PayloadBuilder throws InvalidArgumentException on 0.00 amount');

// Scenario D.2: Negative Amount (-$50.00) in PayloadBuilder
$negativeAmountCaught = false;
try {
    PayloadBuilder::buildChargePayload($baseConfig, 'tok_123', 1024, -50.00, []);
} catch (InvalidArgumentException $e) {
    $negativeAmountCaught = true;
    assertTest(
        str_contains($e->getMessage(), 'at least R$ 1,00'),
        "PayloadBuilder rejects negative amount: '{$e->getMessage()}'"
    );
}
assertTest($negativeAmountCaught, 'PayloadBuilder throws InvalidArgumentException on negative amount');

// Scenario D.2b: Below Iugu minimum (R$ 0,99 → price_cents 99 < 100) must be rejected before the API call
$belowMinCaught = false;
try {
    PayloadBuilder::buildChargePayload($baseConfig, 'tok_123', 1024, 0.99, []);
} catch (InvalidArgumentException $e) {
    $belowMinCaught = true;
}
assertTest($belowMinCaught, 'PayloadBuilder rejects amounts below the Iugu minimum of 100 price_cents (R$ 1,00)');
$minOk = PayloadBuilder::buildChargePayload($baseConfig, 'tok_123', 1024, 1.00, []);
assertTest($minOk['items'][0]['price_cents'] === 100, 'PayloadBuilder accepts exactly R$ 1,00 (price_cents = 100)');

// Scenario D.3: Zero amount handled gracefully in Config::computeInstallmentOptions
$zeroInstOptions = $baseConfig->computeInstallmentOptions(0.00);
assertTest(
    isset($zeroInstOptions[1]) && $zeroInstOptions[1]['installment_amount'] === 0.0,
    'Config::computeInstallmentOptions handles 0.00 without division by zero'
);

// Scenario D.4: Missing token in ChargeProcessor
$resMissingToken = $processorPaidGuard->processCharge(
    ['iugu_token' => '   '],
    1024,
    150.00,
    [],
    []
);
assertTest(
    $resMissingToken['success'] === false && $resMissingToken['lr_code'] === 'MISSING_TOKEN',
    'ChargeProcessor rejects missing/blank iugu_token immediately'
);

// Scenario D.5: Empty client details gracefully fall back to defaults
$payloadDefaultClient = PayloadBuilder::buildChargePayload(
    $baseConfig,
    'tok_valid_token_123',
    1024,
    150.00,
    [], // Totally empty client array
    1
);
assertTest(
    $payloadDefaultClient['payer']['name'] === 'Cliente WHMCS' &&
    $payloadDefaultClient['payer']['email'] === 'financeiro@imovelsite.com.br' &&
    !isset($payloadDefaultClient['payer']['address']),
    'PayloadBuilder uses robust default payer name & email and omits optional address when empty'
);

// Scenario D.5b: Partial client address populates defaults for missing sub-fields
$payloadPartialAddress = PayloadBuilder::buildChargePayload(
    $baseConfig,
    'tok_valid_token_123',
    1024,
    150.00,
    ['address1' => 'Rua das Flores, 123'],
    1
);
assertTest(
    isset($payloadPartialAddress['payer']['address']) &&
    $payloadPartialAddress['payer']['address']['street'] === 'Rua das Flores' &&
    $payloadPartialAddress['payer']['address']['number'] === '123' &&
    $payloadPartialAddress['payer']['address']['city'] === 'São Paulo' &&
    $payloadPartialAddress['payer']['address']['zip_code'] === '01001000',
    'PayloadBuilder correctly splits street/number and populates missing address sub-fields'
);

// Scenario D.6: Brazilian Phone Normalization
assertTest(
    PayloadBuilder::extractPhone('+55 (11) 98765-4321') === ['11', '987654321'],
    'extractPhone normalizes +55 (11) 98765-4321 to prefix 11, number 987654321'
);
assertTest(
    PayloadBuilder::extractPhone('2133445566') === ['21', '33445566'],
    'extractPhone handles 10-digit landline without country code'
);
assertTest(
    PayloadBuilder::extractPhone('') === ['', ''],
    'extractPhone returns empty strings on missing phone'
);

// Scenario D.7: Brazilian CPF vs CNPJ Extraction
$cpfClient = ['tax_id' => '123.456.789-09'];
assertTest(
    PayloadBuilder::extractCpfCnpj($cpfClient) === '12345678909',
    'extractCpfCnpj extracts 11-digit CPF from tax_id'
);
$cnpjClient = ['customfields' => [['name' => 'CPF/CNPJ', 'value' => '12.345.678/0001-99']]];
assertTest(
    PayloadBuilder::extractCpfCnpj($cnpjClient) === '12345678000199',
    'extractCpfCnpj extracts 14-digit CNPJ from customfields array'
);

// Scenario D.8: Installments clamping
$payloadClamped = PayloadBuilder::buildChargePayload(
    $baseConfig,
    'tok_123',
    1024,
    150.00,
    ['email' => 'teste@imovelsite.com.br'],
    24 // User requests 24x but config max is 6
);
assertTest(
    $payloadClamped['months'] === 6,
    'PayloadBuilder clamps installment request from 24 to configured maxInstallments (6)'
);

$payloadMinValClamped = PayloadBuilder::buildChargePayload(
    $baseConfig,
    'tok_123',
    1024,
    15.00, // Total 15.00 with minInstallmentValue=10.00 -> 2x would be 7.50 < 10.00
    ['email' => 'teste@imovelsite.com.br'],
    3
);
assertTest(
    $payloadMinValClamped['months'] === 1,
    'PayloadBuilder clamps installments to 1x when installment value falls below minInstallmentValue'
);

// ============================================================================
// SUITE E: Security & PCI-DSS Enforcement Under Adversarial Attacks
// ============================================================================
echo "\nSection E: Security & PCI-DSS Enforcement Under Adversarial Attacks\n";

// Attack E.1: Raw PAN / CVV injection in request payload
$hostileRequest = [
    'iugu_token' => 'tok_123',
    'number' => '4111 1111 1111 1111',
    'cvv' => '123',
];
$pciBlockedResult = $processorPaidGuard->processCharge(
    $hostileRequest,
    1024,
    150.00,
    [],
    $mockWhmcsCallbacks
);
assertTest(
    $pciBlockedResult['success'] === false && $pciBlockedResult['lr_code'] === 'PCI_BLOCKED',
    'ChargeProcessor immediately detects and blocks raw card data submission (PCI_BLOCKED)'
);

// Attack E.2: PciSanitizer deep nested array & mixed string sanitization
$deeplyNested = [
    'level1' => [
        'level2' => [
            'level3' => [
                'card_number' => '4111111111111111',
                'verification_value' => '999',
                'apiToken' => 'live_secret_token_abcdef123456',
                'raw_log_message' => 'Processed card 4111 1111 1111 1111 with result approved',
            ],
        ],
    ],
];
$sanitizedDeep = PciSanitizer::sanitize($deeplyNested);
assertTest(
    $sanitizedDeep['level1']['level2']['level3']['card_number'] === '[REDACTED_PCI_SENSITIVE]' &&
    $sanitizedDeep['level1']['level2']['level3']['verification_value'] === '[REDACTED_PCI_SENSITIVE]',
    'PciSanitizer recursively traverses nested structures and redacts card_number and verification_value'
);
assertTest(
    !str_contains((string) $sanitizedDeep['level1']['level2']['level3']['apiToken'], 'secret'),
    'PciSanitizer masks apiToken'
);
assertTest(
    str_contains((string) $sanitizedDeep['level1']['level2']['level3']['raw_log_message'], '****-****-****-1111'),
    'PciSanitizer masks inline PAN inside arbitrary log strings'
);

// Attack E.3: HMAC Signature Tampering
$sigInvoice = 1024;
$sigClient = 42;
$sigAmount = '150.00';
$validSignature = $baseConfig->generateCheckoutSignature($sigInvoice, $sigClient, $sigAmount);

assertTest(
    $baseConfig->verifyCheckoutSignature($sigInvoice, $sigClient, $sigAmount, $validSignature) === true,
    'HMAC signature verifies with genuine invoice parameters'
);
assertTest(
    $baseConfig->verifyCheckoutSignature(9999, $sigClient, $sigAmount, $validSignature) === false,
    'HMAC verification rejects tampered invoice ID (9999)'
);
assertTest(
    $baseConfig->verifyCheckoutSignature($sigInvoice, 9999, $sigAmount, $validSignature) === false,
    'HMAC verification rejects tampered client ID (9999)'
);
assertTest(
    $baseConfig->verifyCheckoutSignature($sigInvoice, $sigClient, '1.00', $validSignature) === false,
    'HMAC verification rejects tampered invoice amount (1.00 vs 150.00)'
);
assertTest(
    $baseConfig->verifyCheckoutSignature($sigInvoice, $sigClient, $sigAmount, '') === false,
    'HMAC verification rejects empty signature'
);

// Attack E.4: Webhook Secret Enforcement
$resBadSecret = $webhookHandlerIdempotent->handle(
    $fixtures['webhooks']['invoice_paid'],
    ['Authorization' => 'Bearer wrong_secret_token'],
    $mockWhmcsCallbacks
);
assertTest(
    $resBadSecret['http_code'] === 401 && $resBadSecret['action'] === 'rejected_unauthorized',
    'WebhookHandler rejects webhook with incorrect secret token (HTTP 401)'
);

// Attack E.5: Webhook Account ID Mismatch
$forgedAccountPayload = $fixtures['webhooks']['invoice_paid'];
$forgedAccountPayload['data']['account_id'] = 'DIFFERENT_ACCOUNT_00000000000000';
$resBadAccount = $webhookHandlerIdempotent->handle(
    $forgedAccountPayload,
    ['Authorization' => 'Bearer whsec_secret_token_123'],
    $mockWhmcsCallbacks
);
assertTest(
    $resBadAccount['http_code'] === 403 && $resBadAccount['action'] === 'rejected_account_mismatch',
    'WebhookHandler rejects webhook with account_id mismatch (HTTP 403)'
);

// Attack E.6: Foreign Invoice Webhook (no WHMCS invoice link)
$foreignInvoicePayload = [
    'event' => 'invoice.status_changed',
    'data' => [
        'id' => 'IUGU_FOREIGN_INV_123',
        'status' => 'paid',
        'account_id' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
    ],
];
$clientForeign = new IuguHttpClient(
    $baseConfig->getActiveApiToken(),
    Config::API_BASE_URL,
    10,
    static fn(): array => [
        'status' => 200,
        'body' => (string) json_encode([
            'id' => 'IUGU_FOREIGN_INV_123',
            'status' => 'paid',
            'custom_variables' => [], // NO whmcs_invoice_id!
            'order_id' => 'shopify_9999', // Foreign order_id
        ]),
    ]
);
$webhookHandlerForeign = new WebhookHandler($baseConfig, $clientForeign);
$resForeign = $webhookHandlerForeign->handle(
    $foreignInvoicePayload,
    ['Authorization' => 'Bearer whsec_secret_token_123'],
    $mockWhmcsCallbacks
);
assertTest(
    $resForeign['http_code'] === 200 && $resForeign['action'] === 'ignored_foreign_invoice',
    'WebhookHandler safely ignores invoices belonging to other systems without altering WHMCS state'
);

echo "\n======================================================================\n";
echo " ADVERSARIAL STRESS TEST SUMMARY: {$passed} passed, {$failed} failed\n";
echo "======================================================================\n\n";

if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

exit(0);
