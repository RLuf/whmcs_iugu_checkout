<?php
/**
 * WHMCS Hooks & Shared Vault / Auto-Renewal Helpers for `iugucc`
 *
 * Responsibilities:
 * 1. Maintains `mod_iugucc_cards` (customer_id + reusable customer_payment_method_id)
 *    and `mod_iugucc_autorenew_log` tables for recurring automated 1x charges.
 * 2. Vaults customer cards via Iugu Customers & Payment Methods API (strictly tokenized, PCI-safe).
 * 3. Renders instant "Service Active / Access Panel" liberation banner on Paid `viewinvoice.php`.
 * 4. Executes automatic 1x renewal charges on recurring invoices (`InvoiceCreationPreEmail` & `DailyCronJob`).
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\IuguCC\ChargeProcessor;
use WHMCS\Module\Gateway\IuguCC\Config;
use WHMCS\Module\Gateway\IuguCC\IuguHttpClient;
use WHMCS\Module\Gateway\IuguCC\PayloadBuilder;
use WHMCS\Module\Gateway\IuguCC\PciSanitizer;

if (!function_exists('iugucc_hasRealCapsuleSchema')) {
    /**
     * True when running inside full WHMCS runtime (`WHMCS\Database\Capsule::schema()` available),
     * false under minimal unit-test / simulator stubs.
     */
    function iugucc_hasRealCapsuleSchema(): bool
    {
        return class_exists(Capsule::class) && method_exists(Capsule::class, 'schema');
    }
}

if (!function_exists('iugucc_ensureSchema')) {
    /**
     * Ensures `mod_iugucc_cards` and `mod_iugucc_autorenew_log` tables exist in WHMCS DB.
     */
    function iugucc_ensureSchema(): void
    {
        if (!iugucc_hasRealCapsuleSchema()) {
            return;
        }

        $schema = Capsule::schema();
        if (!$schema->hasTable('mod_iugucc_cards')) {
            $schema->create('mod_iugucc_cards', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('userid');
                $table->tinyInteger('is_sandbox')->default(0);
                $table->string('iugu_customer_id', 64);
                $table->string('iugu_payment_method_id', 64);
                $table->string('brand', 32)->nullable();
                $table->string('last4', 4)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['userid', 'is_sandbox'], 'uniq_iugucc_user_mode');
            });
        }

        if (!$schema->hasTable('mod_iugucc_autorenew_log')) {
            $schema->create('mod_iugucc_autorenew_log', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('invoiceid')->unique('uniq_iugucc_autorenew_invoice');
                $table->unsignedInteger('attempts')->default(0);
                $table->string('last_status', 32)->default('');
                $table->string('last_lr', 16)->nullable();
                $table->string('last_error', 255)->nullable();
                $table->dateTime('last_attempt_at')->nullable();
            });
        }
    }
}

if (!function_exists('iugucc_enrichClientRowWithCustomFields')) {
    /**
     * Attaches WHMCS client custom fields (`tblcustomfieldsvalues` + `tblcustomfields`) to a client row array.
     *
     * @param array<string, mixed> $clientRow
     * @return array<string, mixed>
     */
    function iugucc_enrichClientRowWithCustomFields(array $clientRow, int $clientId): array
    {
        if (!iugucc_hasRealCapsuleSchema() || $clientId <= 0) {
            return $clientRow;
        }

        try {
            $rows = Capsule::table('tblcustomfieldsvalues as v')
                ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                ->where('v.relid', $clientId)
                ->where('f.type', 'client')
                ->select(['f.id', 'f.fieldname', 'v.value'])
                ->get();

            $customFields = is_array($clientRow['customfields'] ?? null) ? $clientRow['customfields'] : [];
            foreach ($rows as $row) {
                $customFields[] = [
                    'id' => (int) $row->id,
                    'name' => (string) $row->fieldname,
                    'fieldname' => (string) $row->fieldname,
                    'value' => (string) $row->value,
                ];
            }
            $clientRow['customfields'] = $customFields;
        } catch (Throwable $e) {
            // Non-fatal lookup error
        }

        return $clientRow;
    }
}

if (!function_exists('iugucc_resolveInvoiceServicePanel')) {
    /**
     * Resolves the provisioned Hosting service & panel URLs linked to a WHMCS invoice.
     *
     * @return array<string, mixed>
     */
    function iugucc_resolveInvoiceServicePanel(int $invoiceId, string $systemUrl = ''): array
    {
        if (!iugucc_hasRealCapsuleSchema() || $invoiceId <= 0) {
            return [];
        }

        try {
            $service = Capsule::table('tblinvoiceitems as ii')
                ->join('tblhosting as h', 'h.id', '=', 'ii.relid')
                ->where('ii.invoiceid', $invoiceId)
                ->where('ii.type', 'Hosting')
                ->orderBy('h.id', 'asc')
                ->select([
                    'h.id as service_id',
                    'h.userid',
                    'h.packageid',
                    'h.domain',
                    'h.domainstatus',
                ])
                ->first();

            if ($service === null) {
                return [];
            }

            $serviceId = (int) $service->service_id;
            $domain = trim((string) ($service->domain ?? ''));
            $status = trim((string) ($service->domainstatus ?? ''));

            if ($domain === '' && Capsule::schema()->hasTable('mod_imovelsite_sites')) {
                $siteRow = Capsule::table('mod_imovelsite_sites')
                    ->where('service_id', $serviceId)
                    ->first();
                if ($siteRow !== null && !empty($siteRow->domain)) {
                    $domain = trim((string) $siteRow->domain);
                }
            }

            $base = $systemUrl !== '' ? rtrim($systemUrl, '/') . '/' : '';
            $panelUrl = $base . 'clientarea.php?action=productdetails&id=' . $serviceId;
            $siteUrl = $domain !== '' ? 'https://' . ltrim($domain, '/') : '';
            $wpAdminUrl = $siteUrl !== '' ? rtrim($siteUrl, '/') . '/wp-admin' : '';

            return [
                'service_id' => $serviceId,
                'domain' => $domain,
                'status' => $status,
                'panel_url' => $panelUrl,
                'site_url' => $siteUrl,
                'wp_admin_url' => $wpAdminUrl,
            ];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('iugucc_prepareVaultedPaymentMethod')) {
    /**
     * Exchanges a single-use Iugu.js token for a reusable Iugu customer payment method
     * so recurring plan renewals can be charged automatically on future billing cycles.
     * Fails open cleanly if customer creation fails before consuming the token.
     *
     * @param array<string, mixed> $clientDetails
     * @return array<string, string>
     */
    function iugucc_prepareVaultedPaymentMethod(
        Config $config,
        IuguHttpClient $httpClient,
        int $clientId,
        array $clientDetails,
        string $iuguToken
    ): array {
        $cleanToken = trim($iuguToken);
        if (!iugucc_hasRealCapsuleSchema() || $clientId <= 0 || $cleanToken === '') {
            return [];
        }

        iugucc_ensureSchema();
        $isSandbox = $config->isSandbox() ? 1 : 0;
        $existingCustomerId = '';

        if (class_exists(Capsule::class)) {
            $saved = Capsule::table('mod_iugucc_cards')
                ->where('userid', $clientId)
                ->where('is_sandbox', $isSandbox)
                ->first();
            if ($saved !== null && !empty($saved->iugu_customer_id)) {
                $existingCustomerId = trim((string) $saved->iugu_customer_id);
            }
        }

        $createCustomer = static function () use ($config, $httpClient, $clientId, $clientDetails): string {
            $email = trim((string) ($clientDetails['email'] ?? ''));
            if ($email === '') {
                $email = 'cliente' . $clientId . '@imovelsite.com.br';
            }
            $name = trim(((string) ($clientDetails['firstname'] ?? '')) . ' ' . ((string) ($clientDetails['lastname'] ?? '')));
            if ($name === '') {
                $name = trim((string) ($clientDetails['companyname'] ?? 'Cliente WHMCS #' . $clientId));
            }
            $customerPayload = [
                'email' => $email,
                'name' => $name,
                'notes' => 'WHMCS Client #' . $clientId,
                'custom_variables' => [
                    ['name' => 'whmcs_client_id', 'value' => (string) $clientId],
                ],
            ];
            $cpfCnpj = PayloadBuilder::extractCpfCnpj($clientDetails, $config->getCpfCustomFieldName());
            if ($cpfCnpj !== '') {
                $customerPayload['cpf_cnpj'] = $cpfCnpj;
            }
            $resp = $httpClient->createCustomer($customerPayload);
            if ($resp['http_code'] >= 200 && $resp['http_code'] < 300 && !empty($resp['body']['id'])) {
                return trim((string) $resp['body']['id']);
            }
            return '';
        };

        $customerId = $existingCustomerId !== '' ? $existingCustomerId : $createCustomer();
        if ($customerId === '') {
            return [];
        }

        $pmPayload = [
            'token' => $cleanToken,
            'description' => 'Cartao principal WHMCS #' . $clientId,
            'set_as_default' => true,
        ];

        $pmResp = $httpClient->createCustomerPaymentMethod($customerId, $pmPayload);
        if ($pmResp['http_code'] === 404 && $existingCustomerId !== '') {
            // Recreate customer if stored ID no longer exists in Iugu
            $customerId = $createCustomer();
            if ($customerId !== '') {
                $pmResp = $httpClient->createCustomerPaymentMethod($customerId, $pmPayload);
            }
        }

        if ($pmResp['http_code'] >= 200 && $pmResp['http_code'] < 300 && !empty($pmResp['body']['id'])) {
            $pmData = is_array($pmResp['body']['data'] ?? null) ? $pmResp['body']['data'] : [];
            $brand = trim((string) ($pmData['brand'] ?? ''));
            $rawDigits = preg_replace('/\D/', '', (string) ($pmData['last_digits'] ?? $pmData['display_number'] ?? '')) ?? '';
            $last4 = $rawDigits !== '' ? substr($rawDigits, -4) : '';

            return [
                'customer_id' => $customerId,
                'customer_payment_method_id' => trim((string) $pmResp['body']['id']),
                'brand' => $brand,
                'last4' => $last4,
            ];
        }

        return [];
    }
}

if (!function_exists('iugucc_saveVaultedCard')) {
    /**
     * Persists a verified Iugu customer + payment method ID for recurring plan renewals.
     */
    function iugucc_saveVaultedCard(
        int $clientId,
        bool $isSandbox,
        string $customerId,
        string $paymentMethodId,
        string $brand = '',
        string $last4 = ''
    ): void {
        if (!iugucc_hasRealCapsuleSchema() || $clientId <= 0 || $customerId === '' || $paymentMethodId === '') {
            return;
        }

        iugucc_ensureSchema();
        $modeFlag = $isSandbox ? 1 : 0;
        $now = date('Y-m-d H:i:s');

        $existing = Capsule::table('mod_iugucc_cards')
            ->where('userid', $clientId)
            ->where('is_sandbox', $modeFlag)
            ->first();

        $record = [
            'iugu_customer_id' => substr(trim($customerId), 0, 64),
            'iugu_payment_method_id' => substr(trim($paymentMethodId), 0, 64),
            'brand' => $brand !== '' ? substr(trim($brand), 0, 32) : null,
            'last4' => $last4 !== '' ? substr(preg_replace('/\D/', '', $last4) ?? '', -4) : null,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            Capsule::table('mod_iugucc_cards')
                ->where('id', (int) $existing->id)
                ->update($record);
        } else {
            Capsule::table('mod_iugucc_cards')->insert($record + [
                'userid' => $clientId,
                'is_sandbox' => $modeFlag,
                'created_at' => $now,
            ]);
        }
    }
}

if (!function_exists('iugucc_attemptAutoRenewal')) {
    /**
     * Automatically charges a recurring `Unpaid` WHMCS invoice in 1x using the customer's
     * vaulted `mod_iugucc_cards` payment method. Skips initial order invoices.
     *
     * @return array<string, mixed>
     */
    function iugucc_attemptAutoRenewal(int $invoiceId): array
    {
        if (!iugucc_hasRealCapsuleSchema() || $invoiceId <= 0) {
            return ['skipped' => true, 'reason' => 'invalid_context'];
        }

        iugucc_ensureSchema();

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice === null) {
            return ['skipped' => true, 'reason' => 'invoice_not_found'];
        }
        if (strtolower((string) $invoice->status) !== 'unpaid' || (string) $invoice->paymentmethod !== Config::GATEWAY_NAME) {
            return ['skipped' => true, 'reason' => 'not_unpaid_iugucc'];
        }

        $clientId = (int) $invoice->userid;
        $paidIn = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
        $paidOut = (float) Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountout');
        $balance = round(max(0.0, ((float) $invoice->total) - max(0.0, $paidIn - $paidOut)), 2);
        if ($balance < 1.00) {
            return ['skipped' => true, 'reason' => 'below_minimum_amount'];
        }

        // Guard: Never auto-charge an initial signup order invoice; only charge active/suspended plan renewals
        $isInitialOrder = Capsule::table('tblorders')->where('invoiceid', $invoiceId)->exists();
        if ($isInitialOrder) {
            return ['skipped' => true, 'reason' => 'initial_order_invoice'];
        }

        $hasRenewableService = Capsule::table('tblinvoiceitems as ii')
            ->join('tblhosting as h', 'h.id', '=', 'ii.relid')
            ->where('ii.invoiceid', $invoiceId)
            ->where('ii.type', 'Hosting')
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->exists();

        if (!$hasRenewableService) {
            return ['skipped' => true, 'reason' => 'no_renewable_service'];
        }

        // Rate-limit & retry cap guard (max 3 attempts total, >= 20h apart)
        $logRow = Capsule::table('mod_iugucc_autorenew_log')->where('invoiceid', $invoiceId)->first();
        if ($logRow !== null) {
            if ($logRow->last_status === 'Success' || (int) $logRow->attempts >= 3) {
                return ['skipped' => true, 'reason' => 'max_attempts_or_completed'];
            }
            if (!empty($logRow->last_attempt_at) && (time() - strtotime((string) $logRow->last_attempt_at)) < 72000) {
                return ['skipped' => true, 'reason' => 'cooldown_active'];
            }
        }

        if (!function_exists('getGatewayVariables')) {
            $gwFile = dirname(__DIR__, 3) . '/includes/gatewayfunctions.php';
            if (is_file($gwFile)) {
                require_once $gwFile;
            }
        }
        if (!function_exists('addInvoicePayment')) {
            $invFile = dirname(__DIR__, 3) . '/includes/invoicefunctions.php';
            if (is_file($invFile)) {
                require_once $invFile;
            }
        }

        $gatewayParams = function_exists('getGatewayVariables') ? getGatewayVariables(Config::GATEWAY_NAME) : [];
        if (empty($gatewayParams['type'])) {
            return ['skipped' => true, 'reason' => 'gateway_inactive'];
        }

        $config = new Config($gatewayParams);
        if (!$config->isConfigured()) {
            return ['skipped' => true, 'reason' => 'gateway_not_configured'];
        }

        $savedCard = Capsule::table('mod_iugucc_cards')
            ->where('userid', $clientId)
            ->where('is_sandbox', $config->isSandbox() ? 1 : 0)
            ->first();

        if ($savedCard === null || empty($savedCard->iugu_payment_method_id)) {
            return ['skipped' => true, 'reason' => 'no_saved_card'];
        }

        $clientObj = Capsule::table('tblclients')->where('id', $clientId)->first();
        $clientRow = $clientObj !== null ? (array) $clientObj : [];
        $clientRow = iugucc_enrichClientRowWithCustomFields($clientRow, $clientId);

        $httpClient = new IuguHttpClient($config->getActiveApiToken());
        $processor = new ChargeProcessor($config, $httpClient);

        $whmcsCallbacks = [
            'logTransaction' => static function (string $gateway, mixed $data, string $status): void {
                if (function_exists('logTransaction')) {
                    logTransaction($gateway, PciSanitizer::sanitize($data), $status);
                }
            },
            'checkCbInvoiceID' => static function (int $invId, string $gateway): void {
                if (function_exists('checkCbInvoiceID')) {
                    checkCbInvoiceID($invId, $gateway);
                }
            },
            'checkCbTransID' => static function (string $txId): void {
                if (function_exists('checkCbTransID')) {
                    checkCbTransID($txId);
                }
            },
            'addInvoicePayment' => static function (int $invId, string $txId, float $amt, float $fee, string $gw): void {
                if (function_exists('addInvoicePayment')) {
                    addInvoicePayment($invId, $txId, $amt, $fee, $gw);
                }
            },
            'isInvoicePaid' => static function (int|string $invId): bool {
                $status = Capsule::table('tblinvoices')->where('id', (int) $invId)->value('status');
                return is_string($status) && strtolower($status) === 'paid';
            },
            'isTransactionRecorded' => static function (string $txId): bool {
                return $txId !== '' && Capsule::table('tblaccounts')->where('transid', $txId)->exists();
            },
        ];

        $result = $processor->processCharge(
            [
                'customer_id' => (string) $savedCard->iugu_customer_id,
                'customer_payment_method_id' => (string) $savedCard->iugu_payment_method_id,
                'installments' => 1,
            ],
            $invoiceId,
            $balance,
            $clientRow,
            $whmcsCallbacks
        );

        $now = date('Y-m-d H:i:s');
        $attemptCount = ($logRow !== null ? (int) $logRow->attempts : 0) + 1;
        $statusLabel = $result['success'] ? 'Success' : 'Failed';
        $errMsg = $result['success'] ? null : substr((string) ($result['message'] ?? ''), 0, 250);

        if ($logRow !== null) {
            Capsule::table('mod_iugucc_autorenew_log')
                ->where('invoiceid', $invoiceId)
                ->update([
                    'attempts' => $attemptCount,
                    'last_status' => $statusLabel,
                    'last_lr' => substr((string) ($result['lr_code'] ?? ''), 0, 16),
                    'last_error' => $errMsg,
                    'last_attempt_at' => $now,
                ]);
        } else {
            Capsule::table('mod_iugucc_autorenew_log')->insert([
                'invoiceid' => $invoiceId,
                'attempts' => $attemptCount,
                'last_status' => $statusLabel,
                'last_lr' => substr((string) ($result['lr_code'] ?? ''), 0, 16),
                'last_error' => $errMsg,
                'last_attempt_at' => $now,
            ]);
        }

        return $result;
    }
}

if (function_exists('add_hook') && !defined('IUGUCC_HOOKS_LOADED')) {
    define('IUGUCC_HOOKS_LOADED', true);

    add_hook('ClientAreaPageViewInvoice', 1, static function (array $vars): array {
        $invoiceId = (int) ($vars['invoiceid'] ?? ($_GET['id'] ?? 0));
        if ($invoiceId <= 0 || !class_exists(Capsule::class)) {
            return [];
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice === null || strtolower((string) $invoice->status) !== 'paid' || (string) $invoice->paymentmethod !== Config::GATEWAY_NAME) {
            return [];
        }

        $systemUrl = rtrim((string) ($vars['systemurl'] ?? ''), '/');
        $panel = iugucc_resolveInvoiceServicePanel($invoiceId, $systemUrl);
        if (empty($panel)) {
            return [];
        }

        return [
            'iugucc_panel_url' => $panel['panel_url'],
            'iugucc_site_url' => $panel['site_url'],
            'iugucc_wp_admin_url' => $panel['wp_admin_url'],
            'iugucc_site_domain' => $panel['domain'],
            'iugucc_service_id' => $panel['service_id'],
        ];
    });

    add_hook('ClientAreaFooterOutput', 1, static function (array $vars): string {
        $filename = basename((string) ($vars['filename'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), '.php');
        if ($filename !== 'viewinvoice' || !class_exists(Capsule::class)) {
            return '';
        }

        $invoiceId = (int) ($_GET['id'] ?? 0);
        if ($invoiceId <= 0) {
            return '';
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice === null || strtolower((string) $invoice->status) !== 'paid' || (string) $invoice->paymentmethod !== Config::GATEWAY_NAME) {
            return '';
        }

        // Authorization guard: render service details only to the invoice owner or logged-in admin
        $sessionUid = (int) ($_SESSION['uid'] ?? 0);
        $isAdmin = !empty($_SESSION['adminid']);
        if (!$isAdmin && $sessionUid !== (int) $invoice->userid) {
            return '';
        }

        $systemUrl = rtrim((string) ($vars['systemurl'] ?? ''), '/');
        $panel = iugucc_resolveInvoiceServicePanel($invoiceId, $systemUrl);
        if (empty($panel) || empty($panel['panel_url'])) {
            return '';
        }

        $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $domainHtml = $panel['domain'] !== ''
            ? '<p style="margin:6px 0 12px;">Ambiente liberado: <strong>' . $esc($panel['domain']) . '</strong></p>'
            : '<p style="margin:6px 0 12px;">Seu serviço já está ativo e pronto para uso.</p>';

        $wpBtn = $panel['wp_admin_url'] !== ''
            ? ' <a href="' . $esc($panel['wp_admin_url']) . '" target="_blank" rel="noopener noreferrer" class="btn btn-default" style="margin-left:8px;">Acessar Painel do Site (WP-Admin)</a>'
            : '';

        $banner = '<div id="iugucc-panel-release-banner" class="alert alert-success text-center" style="margin:15px auto;max-width:720px;border-radius:8px;padding:18px 20px;">'
            . '<h4 style="margin-top:0;font-weight:700;color:#155724;">&#10004; Pagamento Confirmado e Serviço Liberado!</h4>'
            . $domainHtml
            . '<a href="' . $esc($panel['panel_url']) . '" class="btn btn-success btn-lg">Acessar Painel do Serviço</a>'
            . $wpBtn
            . '</div>';

        return '<script>(function(){'
            . 'var box=' . json_encode($banner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
            . 'var target=document.querySelector(".invoice-container")||document.querySelector(".panel")||document.body;'
            . 'if(target){var wrap=document.createElement("div");wrap.innerHTML=box;target.insertBefore(wrap.firstChild,target.firstChild);}'
            . '})();</script>';
    });

    add_hook('InvoiceCreationPreEmail', 1, static function (array $vars): void {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId > 0) {
            iugucc_attemptAutoRenewal($invoiceId);
        }
    });

    add_hook('DailyCronJob', 1, static function (): void {
        if (!iugucc_hasRealCapsuleSchema()) {
            return;
        }
        iugucc_ensureSchema();

        $dueWindow = date('Y-m-d', strtotime('+1 day'));
        $candidates = Capsule::table('tblinvoices as i')
            ->join('mod_iugucc_cards as c', 'c.userid', '=', 'i.userid')
            ->where('i.status', 'Unpaid')
            ->where('i.paymentmethod', Config::GATEWAY_NAME)
            ->where('i.duedate', '<=', $dueWindow)
            ->orderBy('i.id', 'asc')
            ->limit(25)
            ->select(['i.id'])
            ->distinct()
            ->get();

        foreach ($candidates as $row) {
            iugucc_attemptAutoRenewal((int) $row->id);
        }
    });
}
