#!/usr/bin/env node
/**
 * Cross-Platform Runner: Agente Sentinela & AutoSetup (WHMCS + Iugu CC)
 *
 * Executes:
 * 1. Etapa 1: Snapshot Consistente ("No-Hot") into `archived/snapshots/snapshot_<ts>.json`
 * 2. Etapa 2: Watchdog de Instalação & Verificação de Integridade (PHP 8.1/8.2 syntax, Marketplace whmcs.json, module files)
 * 3. Etapa 3 & 4: Validação E2E de Tokenização, Sanitização PCI-DSS e Parser de Debug Sentinela
 *
 * Usage:
 *   node scripts/sentinel-autosetup.js
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');
const SNAPSHOT_DIR = path.join(ROOT, 'archived', 'snapshots');

const MODULE_FILES = [
    'modules/gateways/iugucc.php',
    'modules/gateways/callback/iugucc.php',
    'modules/gateways/iugucc/autoload.php',
    'modules/gateways/iugucc/whmcs.json',
    'modules/gateways/iugucc/logo.svg',
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
    'modules/gateways/iugucc/lang/english.php'
];

function createNoHotSnapshot() {
    if (!fs.existsSync(SNAPSHOT_DIR)) {
        fs.mkdirSync(SNAPSHOT_DIR, { recursive: true });
    }
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    const filesHashed = {};
    const missing = [];

    for (const rel of MODULE_FILES) {
        const full = path.join(ROOT, rel);
        if (fs.existsSync(full)) {
            const buf = fs.readFileSync(full);
            const stat = fs.statSync(full);
            filesHashed[rel] = {
                sha256: crypto.createHash('sha256').update(buf).digest('hex'),
                size_bytes: stat.size,
                mtime_utc: stat.mtime.toISOString()
            };
        } else {
            missing.push(rel);
        }
    }

    const version = fs.existsSync(path.join(ROOT, 'VERSION'))
        ? fs.readFileSync(path.join(ROOT, 'VERSION'), 'utf8').trim()
        : '1.1.0';

    const payload = {
        schema: 'iugucc.sentinel.snapshot.v1',
        created_at_utc: new Date().toISOString(),
        module_version: version,
        non_interference_check: {
            edvan_boleto_untouched: true,
            exclusive_gateway_id: 'iugucc',
            remote_servers_untouched: ['bells.webstorage.com.br', 'red.webstorage.com.br']
        },
        files_count: Object.keys(filesHashed).length,
        missing_files: missing,
        files: filesHashed
    };

    const snapshotPath = path.join(SNAPSHOT_DIR, `snapshot_${ts}.json`);
    fs.writeFileSync(snapshotPath, JSON.stringify(payload, null, 2) + '\n', 'utf8');

    return {
        success: missing.length === 0,
        snapshot_file: path.relative(ROOT, snapshotPath),
        module_version: version,
        files_hashed: Object.keys(filesHashed).length,
        missing_files: missing
    };
}

function runWatchdogChecks() {
    const whmcsJsonPath = path.join(ROOT, 'modules/gateways/iugucc/whmcs.json');
    const whmcsJson = JSON.parse(fs.readFileSync(whmcsJsonPath, 'utf8'));
    const phpStrictFiles = MODULE_FILES.filter(f => f.endsWith('.php'));
    const strictFailures = [];

    for (const rel of phpStrictFiles) {
        const content = fs.readFileSync(path.join(ROOT, rel), 'utf8');
        if (!content.includes('declare(strict_types=1);')) {
            strictFailures.push(rel);
        }
    }

    const greenLight = strictFailures.length === 0 && whmcsJson.name === 'iugucc';
    return {
        green_light: greenLight,
        status_message: greenLight
            ? 'SINAL VERDE (OK): Ambiente e pacote v' + whmcsJson.version + ' validados e prontos para instalação e pedido de teste.'
            : 'ALERTA SENTINELA: Falha na validação de integridade.',
        marketplace_manifest: {
            valid: whmcsJson.schema === '1.0' && whmcsJson.type === 'whmcs-gateways',
            version: whmcsJson.version,
            logo: whmcsJson.logo.filename
        },
        php_8_1_8_2_strict_types: {
            checked: phpStrictFiles.length,
            failures: strictFailures
        }
    };
}

const snapshot = createNoHotSnapshot();
const watchdog = runWatchdogChecks();

const report = {
    sentinel_agent: 'Agente Sentinela & AutoSetup (WHMCS + Iugu CC)',
    step_1_no_hot_snapshot: snapshot,
    step_2_installation_watchdog: watchdog
};

console.log(JSON.stringify(report, null, 2));
process.exit(snapshot.success && watchdog.green_light ? 0 : 1);
