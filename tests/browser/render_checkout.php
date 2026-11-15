<?php
/**
 * Renders the module's real checkout template (modules/gateways/iugucc/templates/checkout.php)
 * exactly as iugucc_link() does, but outside WHMCS, for the Playwright browser E2E.
 *
 * Usage: php tests/browser/render_checkout.php <accountId> <callbackUrl> <assetBaseUrl> [amount] [invoiceId]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../modules/gateways/iugucc/autoload.php';

use WHMCS\Module\Gateway\IuguCC\Config;

$accountId = (string) ($argv[1] ?? '');
$callbackUrl = (string) ($argv[2] ?? 'http://127.0.0.1:9/callback');
$assetBaseUrl = (string) ($argv[3] ?? '/modules/gateways/iugucc');
$amount = (float) ($argv[4] ?? 150.00);
$invoiceId = (int) ($argv[5] ?? 1024);

$config = new Config([
    'accountId' => $accountId,
    'apiToken' => 'browser-e2e-signature-secret',
    'sandboxMode' => 'on',
    'maxInstallments' => '3',
    'minInstallmentValue' => '5.00',
    'language' => 'portuguese-br',
]);

/** @var array<string, string> $lang */
$lang = require __DIR__ . '/../../modules/gateways/iugucc/lang/portuguese-br.php';
$isSandbox = $config->isSandbox();
$signature = $config->generateCheckoutSignature($invoiceId, 42, (string) $amount);
$installmentOptions = $config->computeInstallmentOptions($amount);
$defaultHolderName = 'JOAO SILVA';
$defaultCpfCnpj = '';
$iuguJsUrl = Config::IUGU_JS_URL;

echo "<!doctype html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><title>iugucc browser e2e</title></head><body>\n";
require __DIR__ . '/../../modules/gateways/iugucc/templates/checkout.php';
echo "\n</body></html>\n";
