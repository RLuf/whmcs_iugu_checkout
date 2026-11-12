<?php
/**
 * WHMCS Iugu Credit Card Payment Gateway Module (`iugucc`)
 *
 * Commercial-ready, PCI-DSS compliant transparent credit card checkout gateway
 * powered by Iugu.js v2 client-side tokenization and Iugu REST API v1.
 *
 * Target Compatibility:
 * - WHMCS 8.13.7
 * - PHP 8.1 / 8.2
 * - Non-Interference: Uses exclusive module identifier `iugucc` so it never
 *   collides with the existing Edvan Iugu Boleto module.
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @author     RLuf / ImovelSite <https://imovelsite.com.br>
 * @copyright  2026 ImovelSite
 * @license    MIT
 * @version    1.1.0
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('IUGUCC_MODULE_VERSION')) {
    define('IUGUCC_MODULE_VERSION', '1.1.0');
}

require_once __DIR__ . '/iugucc/autoload.php';

use WHMCS\Module\Gateway\IuguCC\Config;
use WHMCS\Module\Gateway\IuguCC\IuguHttpClient;
use WHMCS\Module\Gateway\IuguCC\PayloadBuilder;
use WHMCS\Module\Gateway\IuguCC\PciSanitizer;

/**
 * Defines gateway module metadata for WHMCS 8.13.7.
 *
 * @return array<string, mixed>
 */
function iugucc_MetaData(): array
{
    return [
        'DisplayName' => 'Iugu - Cartão de Crédito (Checkout Transparente)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Defines configurable settings in WHMCS Admin (Setup > Payments > Payment Gateways).
 *
 * @return array<string, array<string, mixed>>
 */
function iugucc_config(): array
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Cartão de Crédito (Iugu)',
        ],
        'accountId' => [
            'FriendlyName' => 'ID da Conta Iugu (Account ID)',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Código hexadecimal de 32 caracteres da sua conta Iugu (obrigatório para tokenização Iugu.js).',
        ],
        'apiToken' => [
            'FriendlyName' => 'Token de API (Produção / Live)',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Token de API privado de Produção gerado no painel da Iugu.',
        ],
        'sandboxApiToken' => [
            'FriendlyName' => 'Token de API (Teste / Sandbox)',
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Token de API de Teste gerado no painel da Iugu (utilizado quando o Modo Sandbox está ativo).',
        ],
        'sandboxMode' => [
            'FriendlyName' => 'Modo Sandbox (Ambiente de Teste)',
            'Type' => 'yesno',
            'Description' => 'Ative para processar cobranças de teste com cartões de simulação da Iugu (Iugu.setTestMode(true)).',
        ],
        'maxInstallments' => [
            'FriendlyName' => 'Parcelamento Máximo',
            'Type' => 'dropdown',
            'Options' => [
                '1' => '1x (Somente à vista)',
                '2' => 'Até 2x',
                '3' => 'Até 3x',
                '4' => 'Até 4x',
                '5' => 'Até 5x',
                '6' => 'Até 6x',
                '7' => 'Até 7x',
                '8' => 'Até 8x',
                '9' => 'Até 9x',
                '10' => 'Até 10x',
                '11' => 'Até 11x',
                '12' => 'Até 12x',
            ],
            'Default' => '1',
            'Description' => 'Número máximo de parcelas exibidas ao cliente no checkout.',
        ],
        'minInstallmentValue' => [
            'FriendlyName' => 'Valor Mínimo da Parcela (R$)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '5.00',
            'Description' => 'Valor mínimo permitido por parcela em Reais (ex: 5.00).',
        ],
        'invoiceDescription' => [
            'FriendlyName' => 'Descrição da Fatura na Iugu',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'Fatura #{invoice_id} - imovelsite.com.br',
            'Description' => 'Variáveis suportadas: {invoice_id}, {company_name}.',
        ],
        'statementDescriptor' => [
            'FriendlyName' => 'Identificação na Fatura do Cartão',
            'Type' => 'text',
            'Size' => '12',
            'Default' => 'IMOVELSITE',
            'Description' => 'Texto curto exibido na fatura do cartão do cliente (máx. 12 caracteres, limite oficial do campo soft_descriptor_light da Iugu).',
        ],
        'cpfCustomField' => [
            'FriendlyName' => 'Nome do Campo Personalizado de CPF/CNPJ',
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'CPF/CNPJ',
            'Description' => 'Nome do Custom Field do cliente no WHMCS caso não use o campo nativo Tax ID.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Segredo de Validação do Webhook (Opcional)',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Se preenchido, exige este token no cabeçalho Authorization ou parâmetro ?webhook_token= na URL de Gatilho da Iugu.',
        ],
        'debugLogging' => [
            'FriendlyName' => 'Log Detalhado no WHMCS (PCI-Safe)',
            'Type' => 'yesno',
            'Description' => 'Registra payloads sanitizados (sem dados sensíveis de cartão) em Billing > Gateway Log.',
        ],
        'sentinelMode' => [
            'FriendlyName' => 'Agente Sentinela & Diagnóstico E2E',
            'Type' => 'yesno',
            'Default' => 'yes',
            'Description' => 'Ativa rastreamento E2E, trace_id de auditoria e dicas de resolução de códigos LR para o suporte técnico no Gateway Log.',
        ],
    ];
}

/**
 * Renders the client-side tokenized credit card checkout form on WHMCS `viewinvoice.php`.
 *
 * @param array<string, mixed> $params WHMCS gateway parameters
 */
function iugucc_link(array $params): string
{
    $config = new Config($params);
    $langFile = $config->isEnglish()
        ? __DIR__ . '/iugucc/lang/english.php'
        : __DIR__ . '/iugucc/lang/portuguese-br.php';

    /** @var array<string, string> $lang */
    $lang = is_file($langFile) ? require $langFile : require __DIR__ . '/iugucc/lang/portuguese-br.php';

    if (!$config->isConfigured()) {
        return '<div class="alert alert-warning" style="margin:15px 0;">'
            . htmlspecialchars($lang['err_not_configured'], ENT_QUOTES, 'UTF-8')
            . '</div>';
    }

    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $amount = (float) ($params['amount'] ?? 0.0);
    $client = is_array($params['clientdetails'] ?? null) ? $params['clientdetails'] : [];
    $clientId = (int) ($client['userid'] ?? $client['id'] ?? 0);

    $systemUrl = rtrim((string) ($params['systemurl'] ?? ''), '/');
    $callbackUrl = $systemUrl . '/modules/gateways/callback/iugucc.php';
    $assetBaseUrl = $systemUrl . '/modules/gateways/iugucc';

    $signature = $config->generateCheckoutSignature($invoiceId, $clientId, (string) $amount);
    $installmentOptions = $config->computeInstallmentOptions($amount);

    $defaultHolderName = strtoupper(trim(
        ((string) ($client['firstname'] ?? '')) . ' ' . ((string) ($client['lastname'] ?? ''))
    ));
    $defaultCpfCnpj = PayloadBuilder::extractCpfCnpj($client, $config->getCpfCustomFieldName());
    $accountId = $config->getAccountId();
    $isSandbox = $config->isSandbox();
    $iuguJsUrl = Config::IUGU_JS_URL;

    ob_start();
    require __DIR__ . '/iugucc/templates/checkout.php';
    return (string) ob_get_clean();
}

/**
 * Processes automated full or partial refunds directly from WHMCS Admin via Iugu API.
 *
 * @param array<string, mixed> $params WHMCS refund parameters
 * @return array<string, mixed>
 */
function iugucc_refund(array $params): array
{
    $config = new Config($params);
    $transactionId = trim((string) ($params['transid'] ?? ''));
    $refundAmount = (float) ($params['amount'] ?? 0.0);

    if (!$config->isConfigured() || $transactionId === '') {
        return [
            'status' => 'error',
            'rawdata' => 'Missing Iugu API credentials or transaction ID for refund.',
        ];
    }

    try {
        $client = new IuguHttpClient($config->getActiveApiToken());
        $partialCents = $refundAmount > 0 ? (int) round($refundAmount * 100) : null;
        $response = $client->refundInvoice($transactionId, $partialCents);

        $status = ($response['http_code'] >= 200 && $response['http_code'] < 300) ? 'success' : 'declined';

        return [
            'status' => $status,
            'transid' => (string) ($response['body']['id'] ?? $transactionId),
            'rawdata' => PciSanitizer::sanitize($response['body']),
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'rawdata' => ['exception' => $e->getMessage()],
        ];
    }
}
