#!/usr/bin/env node
/**
 * Semantic Version Bumping Utility for RLuf/whmcs_iugu_checkout
 * Follows the mandatory archive policy (copies modified files to archived/ before writing).
 *
 * Usage: node scripts/bump-version.js <patch|minor|major|x.y.z>
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const ARCHIVED_DIR = path.join(ROOT, 'archived');

function archiveFile(filePath) {
    if (!fs.existsSync(filePath)) return;
    if (!fs.existsSync(ARCHIVED_DIR)) {
        fs.mkdirSync(ARCHIVED_DIR, { recursive: true });
    }
    const ts = new Date().toISOString().replace(/[:.]/g, '-');
    const baseName = path.basename(filePath);
    const dest = path.join(ARCHIVED_DIR, `${ts}_${baseName}`);
    fs.copyFileSync(filePath, dest);
}

function parseSemver(versionStr) {
    const match = String(versionStr).trim().match(/^(\d+)\.(\d+)\.(\d+)$/);
    if (!match) {
        throw new Error(`Invalid semantic version: "${versionStr}"`);
    }
    return {
        major: parseInt(match[1], 10),
        minor: parseInt(match[2], 10),
        patch: parseInt(match[3], 10)
    };
}

function computeNextVersion(current, arg) {
    const v = parseSemver(current);
    if (arg === 'major') return `${v.major + 1}.0.0`;
    if (arg === 'minor') return `${v.major}.${v.minor + 1}.0`;
    if (arg === 'patch' || !arg) return `${v.major}.${v.minor}.${v.patch + 1}`;
    parseSemver(arg); // validate explicit x.y.z
    return arg.trim();
}

function main() {
    const arg = process.argv[2] || 'patch';
    const versionFile = path.join(ROOT, 'VERSION');
    const currentVersion = fs.existsSync(versionFile)
        ? fs.readFileSync(versionFile, 'utf8').trim()
        : '1.0.0';

    const nextVersion = computeNextVersion(currentVersion, arg);

    // 1. Update VERSION
    archiveFile(versionFile);
    fs.writeFileSync(versionFile, `${nextVersion}\n`, 'utf8');

    // 2. Update composer.json
    const composerFile = path.join(ROOT, 'composer.json');
    if (fs.existsSync(composerFile)) {
        archiveFile(composerFile);
        const composerData = JSON.parse(fs.readFileSync(composerFile, 'utf8'));
        composerData.version = nextVersion;
        fs.writeFileSync(composerFile, JSON.stringify(composerData, null, 4) + '\n', 'utf8');
    }

    // 3. Update module version constant in modules/gateways/iugucc.php if present
    const gatewayFile = path.join(ROOT, 'modules', 'gateways', 'iugucc.php');
    if (fs.existsSync(gatewayFile)) {
        archiveFile(gatewayFile);
        let content = fs.readFileSync(gatewayFile, 'utf8');
        content = content.replace(
            /define\('IUGUCC_MODULE_VERSION',\s*'[^']+'\);/,
            `define('IUGUCC_MODULE_VERSION', '${nextVersion}');`
        );
        content = content.replace(
            /@version\s+\d+\.\d+\.\d+/,
            `@version    ${nextVersion}`
        );
        fs.writeFileSync(gatewayFile, content, 'utf8');
    }

    // 4. Update modules/gateways/iugucc/whmcs.json if present
    const whmcsJsonFile = path.join(ROOT, 'modules', 'gateways', 'iugucc', 'whmcs.json');
    if (fs.existsSync(whmcsJsonFile)) {
        archiveFile(whmcsJsonFile);
        const whmcsData = JSON.parse(fs.readFileSync(whmcsJsonFile, 'utf8'));
        whmcsData.version = nextVersion;
        fs.writeFileSync(whmcsJsonFile, JSON.stringify(whmcsData, null, 4) + '\n', 'utf8');
    }

    // 5. Update User-Agent in modules/gateways/iugucc/lib/IuguHttpClient.php
    const httpClientFile = path.join(ROOT, 'modules', 'gateways', 'iugucc', 'lib', 'IuguHttpClient.php');
    if (fs.existsSync(httpClientFile)) {
        archiveFile(httpClientFile);
        let content = fs.readFileSync(httpClientFile, 'utf8');
        content = content.replace(
            /WHMCS-IuguCC-Gateway\/\d+\.\d+\.\d+/,
            `WHMCS-IuguCC-Gateway/${nextVersion}`
        );
        fs.writeFileSync(httpClientFile, content, 'utf8');
    }

    // 6. Update asset cache busters in modules/gateways/iugucc/templates/checkout.php
    const checkoutTplFile = path.join(ROOT, 'modules', 'gateways', 'iugucc', 'templates', 'checkout.php');
    if (fs.existsSync(checkoutTplFile)) {
        archiveFile(checkoutTplFile);
        let content = fs.readFileSync(checkoutTplFile, 'utf8');
        content = content.replace(/\?v=\d+\.\d+\.\d+/g, `?v=${nextVersion}`);
        fs.writeFileSync(checkoutTplFile, content, 'utf8');
    }

    console.log(`Version bumped: ${currentVersion} -> ${nextVersion}`);
}

if (require.main === module) {
    main();
}

module.exports = { parseSemver, computeNextVersion };
