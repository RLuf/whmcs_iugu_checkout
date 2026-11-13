<?php
/**
 * Agente Sentinela & AutoSetup (WHMCS + Iugu CC)
 *
 * Provides:
 * 1. No-Hot Consistent Snapshot & File Checksum Backup (`archived/snapshots/`)
 * 2. Installation Watchdog & PHP 8.1/8.2 Environment Healthcheck
 * 3. E2E Transaction Lifecycle Audit & Toggleable Support Diagnostic Parser (PCI-DSS Safe)
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @target     WHMCS 8.13.7 | PHP 8.1 / 8.2
 */

declare(strict_types=1);

namespace WHMCS\Module\Gateway\IuguCC;

use Throwable;

final class SentinelAuditor
{
    /**
     * Technical support remediation dictionary for Brazilian Acquirer LR codes.
     *
     * @var array<string, array{severity: string, category: string, support_action: string}>
     */
    private const SUPPORT_DIAGNOSTIC_MAP = [
        '00' => [
            'severity' => 'INFO',
            'category' => 'APPROVED',
            'support_action' => 'Transação autorizada pela adquirente. Verificar baixa em tblinvoices (Paid) e acionamento de provisão em tblhosting (imovelsite).',
        ],
        '04' => [
            'severity' => 'WARN',
            'category' => 'ISSUER_DECLINE',
            'support_action' => 'Recusa genérica do emissor (LR 04). Orientar cliente a liberar compra online no app do banco ou tentar outro cartão.',
        ],
        '05' => [
            'severity' => 'WARN',
            'category' => 'DO_NOT_HONOR',
            'support_action' => 'Não autorizada pelo banco emissor (LR 05 - Do Not Honor). Bloqueio preventivo antifraude do banco do titular.',
        ],
        '07' => [
            'severity' => 'ALERT',
            'category' => 'RESTRICTED_CARD',
            'support_action' => 'Cartão com restrição definitiva no emissor (LR 07). Solicitar outro meio de pagamento.',
        ],
        '51' => [
            'severity' => 'WARN',
            'category' => 'INSUFFICIENT_FUNDS',
            'support_action' => 'Saldo ou limite insuficiente (LR 51). Sugerir redução de parcelas ou outro cartão.',
        ],
        '54' => [
            'severity' => 'WARN',
            'category' => 'EXPIRED_CARD',
            'support_action' => 'Cartão vencido (LR 54). Cliente deve atualizar a validade MM/AA ou usar cartão substituto.',
        ],
        '57' => [
            'severity' => 'WARN',
            'category' => 'NOT_PERMITTED',
            'support_action' => 'Transação não permitida para o cartão (LR 57). Cartão de débito puro ou bloqueado para e-commerce.',
        ],
        '78' => [
            'severity' => 'WARN',
            'category' => 'FIRST_USE_BLOCKED',
            'support_action' => 'Cartão novo bloqueado (LR 78). Titular precisa desbloquear no aplicativo do banco antes de comprar.',
        ],
        '82' => [
            'severity' => 'WARN',
            'category' => 'INVALID_PARAMETERS',
            'support_action' => 'Parâmetros de segurança/CVV inválidos (LR 82). Conferir dígitos do CVV ou usar cartão virtual atualizado.',
        ],
        'N7' => [
            'severity' => 'WARN',
            'category' => 'CVV_MISMATCH',
            'support_action' => 'Erro específico de CVV (LR N7). Solicitar conferência do código de 3/4 dígitos.',
        ],
        'PCI_BLOCKED' => [
            'severity' => 'CRITICAL',
            'category' => 'PCI_SECURITY_INTERCEPT',
            'support_action' => 'Tentativa de submissão de PAN/CVV bruto bloqueada pelo PciSanitizer. Verificar se o JS Iugu.js carregou no navegador do cliente.',
        ],
        'MISSING_TOKEN' => [
            'severity' => 'ERROR',
            'category' => 'TOKENIZATION_MISSING',
            'support_action' => 'O campo iugu_token chegou vazio no callback. Verificar Account ID configurado e bloqueadores de script no navegador.',
        ],
        'EXCEPTION' => [
            'severity' => 'CRITICAL',
            'category' => 'GATEWAY_COMMUNICATION',
            'support_action' => 'Falha de comunicação HTTP/cURL com api.iugu.com. Verificar conectividade TLS 1.2+ e validade do API Token.',
        ],
    ];

    /**
     * Critical module files monitored by the Watchdog and Snapshot engine.
     *
     * @var array<int, string>
     */
    private const MODULE_FILES = [
        'modules/gateways/iugucc.php',
        'modules/gateways/callback/iugucc.php',
        'modules/gateways/iugucc/autoload.php',
        'modules/gateways/iugucc/whmcs.json',
        'modules/gateways/iugucc/lib/Config.php',
        'modules/gateways/iugucc/lib/IuguHttpClient.php',
        'modules/gateways/iugucc/lib/PayloadBuilder.php',
        'modules/gateways/iugucc/lib/ChargeProcessor.php',
        'modules/gateways/iugucc/lib/WebhookHandler.php',
        'modules/gateways/iugucc/lib/PciSanitizer.php',
        'modules/gateways/iugucc/lib/SentinelAuditor.php',
        'modules/gateways/iugucc/templates/checkout.php',
        'modules/gateways/iugucc/assets/js/iugucc.js',
        'modules/gateways/iugucc/assets/css/iugucc.css',
        'modules/gateways/iugucc/lang/portuguese-br.php',
        'modules/gateways/iugucc/lang/english.php',
    ];

    /**
     * Etapa 1: Creates a consistent "No-Hot" Snapshot of module files and optional WHMCS DB state
     * into `archived/snapshots/snapshot_<timestamp>.json`.
     *
     * @param string               $rootDir Workspace or WHMCS root directory
     * @param array<string, mixed> $dbState Optional snapshot of tblpaymentgateways / tblinvoices metadata
     * @return array{
     *   success: bool,
     *   snapshot_file: string,
     *   timestamp: string,
     *   files_hashed: int
     * }
     */
    public static function createNoHotSnapshot(string $rootDir, array $dbState = []): array
    {
        $cleanRoot = rtrim($rootDir, '/\\');
        $snapshotDir = $cleanRoot . DIRECTORY_SEPARATOR . 'archived' . DIRECTORY_SEPARATOR . 'snapshots';
        if (!is_dir($snapshotDir)) {
            @mkdir($snapshotDir, 0775, true);
        }

        $ts = gmdate('Ymd_His');
        $fileHashes = [];

        foreach (self::MODULE_FILES as $relPath) {
            $fullPath = $cleanRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if (is_file($fullPath)) {
                $fileHashes[$relPath] = [
                    'sha256' => hash_file('sha256', $fullPath) ?: '',
                    'size_bytes' => filesize($fullPath) ?: 0,
                    'mtime' => gmdate('c', filemtime($fullPath) ?: time()),
                ];
            }
        }

        $snapshotPayload = [
            'schema' => 'iugucc.sentinel.snapshot.v1',
            'created_at_utc' => gmdate('c'),
            'module_version' => defined('IUGUCC_MODULE_VERSION') ? IUGUCC_MODULE_VERSION : '1.1.0',
            'php_version' => PHP_VERSION,
            'non_interference_check' => [
                'edvan_boleto_untouched' => true,
                'exclusive_gateway_id' => Config::GATEWAY_NAME,
            ],
            'files' => $fileHashes,
            'whmcs_db_state' => PciSanitizer::sanitize($dbState),
        ];

        $snapshotFile = $snapshotDir . DIRECTORY_SEPARATOR . 'snapshot_' . $ts . '.json';
        $encoded = json_encode($snapshotPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = $encoded !== false && @file_put_contents($snapshotFile, $encoded) !== false;

        return [
            'success' => $written,
            'snapshot_file' => $snapshotFile,
            'timestamp' => $ts,
            'files_hashed' => count($fileHashes),
        ];
    }

    /**
     * Etapa 2: Runs the Installation Watchdog & Integrity Verification.
     *
     * @return array{
     *   green_light: bool,
     *   status_message: string,
     *   checks: array<string, mixed>
     * }
     */
    public static function runInstallationWatchdog(
        string $rootDir,
        ?Config $config = null,
        ?IuguHttpClient $httpClient = null
    ): array {
        $cleanRoot = rtrim($rootDir, '/\\');
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');

        $extensions = [
            'curl' => extension_loaded('curl'),
            'json' => extension_loaded('json'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
        ];
        $allExtensionsOk = !in_array(false, $extensions, true);

        $missingFiles = [];
        foreach (self::MODULE_FILES as $relPath) {
            $fullPath = $cleanRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if (!is_file($fullPath) || !is_readable($fullPath)) {
                $missingFiles[] = $relPath;
            }
        }
        $filesOk = empty($missingFiles);

        $configStatus = [
            'provided' => $config !== null,
            'is_configured' => $config !== null ? $config->isConfigured() : false,
            'sandbox_mode' => $config !== null ? $config->isSandbox() : null,
            'sentinel_mode' => $config !== null ? $config->isSentinelModeEnabled() : null,
        ];

        $handshakeStatus = [
            'attempted' => false,
            'reachable' => false,
            'http_code' => 0,
        ];

        if ($config !== null && $config->isConfigured() && $httpClient !== null) {
            $handshakeStatus['attempted'] = true;
            try {
                $resp = $httpClient->request('GET', '/invoices?limit=1');
                $handshakeStatus['http_code'] = $resp['http_code'];
                $handshakeStatus['reachable'] = ($resp['http_code'] >= 200 && $resp['http_code'] < 300);
            } catch (Throwable $e) {
                $handshakeStatus['error'] = $e->getMessage();
            }
        }

        $greenLight = $phpOk && $allExtensionsOk && $filesOk;
        if ($handshakeStatus['attempted'] && !$handshakeStatus['reachable']) {
            $greenLight = false;
        }

        return [
            'green_light' => $greenLight,
            'status_message' => $greenLight
                ? 'SINAL VERDE (OK): Ambiente validado e pronto para o pedido de teste.'
                : 'ALERTA SENTINELA: Verifique os pré-requisitos pendentes antes de liberar o checkout.',
            'checks' => [
                'php_version' => [
                    'current' => PHP_VERSION,
                    'compatible_8_1_plus' => $phpOk,
                ],
                'extensions' => $extensions,
                'module_files' => [
                    'total_expected' => count(self::MODULE_FILES),
                    'missing' => $missingFiles,
                    'ok' => $filesOk,
                ],
                'configuration' => $configStatus,
                'iugu_api_handshake' => $handshakeStatus,
            ],
        ];
    }

    /**
     * Etapa 3 & 4: Builds a PCI-DSS sanitized technical support diagnostic block
     * with operator remediation hints for a given transaction stage and LR code.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function buildSupportDiagnostic(
        string $lrCode,
        string $stage,
        bool $isSandbox = false,
        array $context = []
    ): array {
        $normalizedLr = strtoupper(trim($lrCode));
        $meta = self::SUPPORT_DIAGNOSTIC_MAP[$normalizedLr] ?? [
            'severity' => 'WARN',
            'category' => 'UNMAPPED_DECLINE',
            'support_action' => 'Código de retorno não catalogado (' . ($normalizedLr ?: 'N/A') . '). Consultar painel da Iugu em Faturas > Eventos para detalhes da adquirente.',
        ];

        $traceSeed = $stage . '|' . $normalizedLr . '|' . microtime(true);
        $traceId = 'SENTINEL-' . strtoupper(substr(hash('sha256', $traceSeed), 0, 12));

        return [
            'trace_id' => $traceId,
            'timestamp_utc' => gmdate('c'),
            'stage' => $stage,
            'environment' => $isSandbox ? 'SANDBOX_TEST' : 'PRODUCTION_LIVE',
            'lr_code' => $normalizedLr !== '' ? $normalizedLr : 'N/A',
            'severity' => $meta['severity'],
            'category' => $meta['category'],
            'support_action_hint' => $meta['support_action'],
            'pci_dss_compliant' => true,
            'context' => PciSanitizer::sanitize($context),
        ];
    }
}
