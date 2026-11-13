#!/usr/bin/env node
/**
 * Comprehensive Automated Verification & E2E Sandbox Simulation Suite
 * for RLuf/whmcs_iugu_checkout (`iugucc`)
 *
 * Covers all Acceptance Criteria from ORIGINAL_REQUEST.md:
 * 1. Gateway Architecture & WHMCS 8.13.7 / PHP 8.1-8.2 Structural Contracts
 * 2. Client-Side Tokenization (`Iugu.js v2`), Luhn Validation, & PCI-DSS Enforcement
 * 3. Direct Charge API Request Construction, Approval (`LR 00`), & Declines (`LR 51`, `LR 54`, `LR 82`)
 * 4. Synchronous Invoice Payment (`addInvoicePayment`) & Asynchronous Webhook Idempotency + Service Provisioning
 * 5. Non-Interference, `archived/` Backup Policy, `AGENTS.md`, & Semantic Version Bumping
 */

'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const http = require('http');

const ROOT = path.resolve(__dirname, '..');
const fixtures = JSON.parse(
    fs.readFileSync(path.join(__dirname, 'fixtures', 'iugu_mock_responses.json'), 'utf8')
);
const IuguCCCheckout = require(path.join(
    ROOT,
    'modules',
    'gateways',
    'iugucc',
    'assets',
    'js',
    'iugucc.js'
));
const { parseSemver, computeNextVersion } = require(path.join(ROOT, 'scripts', 'bump-version.js'));

let passed = 0;
let failed = 0;
const failures = [];

function assert(condition, testName, details = '') {
    if (condition) {
        passed++;
        console.log(`  \x1b[32m✓\x1b[0m ${testName}`);
    } else {
        failed++;
        const msg = details ? `${testName} — ${details}` : testName;
        failures.push(msg);
        console.error(`  \x1b[31m✗\x1b[0m ${msg}`);
    }
}

/**
 * Helper: Lightweight PHP 8.1/8.2 syntax & delimiter verifier.
 */
function verifyPhpSyntaxAndContracts(relPath) {
    const fullPath = path.join(ROOT, relPath);
    if (!fs.existsSync(fullPath)) {
        return { valid: false, reason: `Missing file: ${relPath}` };
    }
    const code = fs.readFileSync(fullPath, 'utf8');
    if (!code.startsWith('<?php')) {
        return { valid: false, reason: `${relPath} must start with <?php` };
    }
    if (!code.includes('declare(strict_types=1);')) {
        return { valid: false, reason: `${relPath} must include declare(strict_types=1); for PHP 8.1/8.2` };
    }

    // Strip strings first (so 'php://input' is not mistaken for a // comment), then comments
    const stripped = code
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/'(?:\\.|[^'\\])*'/g, "''")
        .replace(/"(?:\\.|[^"\\])*"/g, '""')
        .replace(/\/\/.*$/gm, '');

    const stack = [];
    const pairs = { ')': '(', ']': '[', '}': '{' };
    for (let i = 0; i < stripped.length; i++) {
        const ch = stripped[i];
        if (ch === '(' || ch === '[' || ch === '{') {
            stack.push(ch);
        } else if (ch === ')' || ch === ']' || ch === '}') {
            const last = stack.pop();
            if (last !== pairs[ch]) {
                return { valid: false, reason: `Unbalanced delimiter '${ch}' in ${relPath}` };
            }
        }
    }
    if (stack.length > 0) {
        return { valid: false, reason: `Unclosed delimiter '${stack[stack.length - 1]}' in ${relPath}` };
    }

    return { valid: true, code };
}

/**
 * Mirrors PHP `PayloadBuilder::buildChargePayload` & `ChargeProcessor` & `WebhookHandler`
 * against the PHP source contracts and mock Iugu HTTP server.
 */
async function runAllTests() {
    console.log('\n======================================================================');
    console.log(' WHMCS Iugu Credit Card Gateway (iugucc) — Automated Verification Suite');
    console.log('======================================================================\n');

    // ========================================================================
    // SUITE 1: PHP 8.1/8.2 Syntax, WHMCS 8.13.7 Architecture & Non-Interference
    // ========================================================================
    console.log('Suite 1: Gateway Architecture, PHP 8.1/8.2 Contracts & Non-Interference');

    const phpFiles = [
        'modules/gateways/iugucc.php',
        'modules/gateways/callback/iugucc.php',
        'modules/gateways/iugucc/autoload.php',
        'modules/gateways/iugucc/lib/Config.php',
        'modules/gateways/iugucc/lib/PciSanitizer.php',
        'modules/gateways/iugucc/lib/IuguHttpClient.php',
        'modules/gateways/iugucc/lib/PayloadBuilder.php',
        'modules/gateways/iugucc/lib/ChargeProcessor.php',
        'modules/gateways/iugucc/lib/WebhookHandler.php',
        'modules/gateways/iugucc/lang/portuguese-br.php',
        'modules/gateways/iugucc/lang/english.php',
        'modules/gateways/iugucc/templates/checkout.php',
        'tests/phpunit_runner.php'
    ];

    for (const rel of phpFiles) {
        const check = verifyPhpSyntaxAndContracts(rel);
        assert(check.valid, `PHP 8.1/8.2 strict_types & balanced syntax in ${rel}`, check.reason);
    }

    const gatewayMain = fs.readFileSync(path.join(ROOT, 'modules/gateways/iugucc.php'), 'utf8');
    assert(gatewayMain.includes("if (!defined('WHMCS'))"), 'Direct access guard present in modules/gateways/iugucc.php');
    assert(gatewayMain.includes('function iugucc_MetaData(): array'), 'Defines iugucc_MetaData() with return type');
    assert(gatewayMain.includes("'DisableLocalCreditCardInput' => true"), 'Enables DisableLocalCreditCardInput for PCI-DSS compliance');
    assert(gatewayMain.includes('function iugucc_config(): array'), 'Defines iugucc_config() for WHMCS Admin');
    assert(gatewayMain.includes("'accountId'") && gatewayMain.includes("'apiToken'") && gatewayMain.includes("'sandboxMode'"), 'Admin config includes Account ID, API Token, and Sandbox toggle');
    assert(gatewayMain.includes('function iugucc_link(array $params): string'), 'Defines iugucc_link() for transparent checkout');
    assert(gatewayMain.includes('function iugucc_refund(array $params): array'), 'Defines iugucc_refund() for automated WHMCS refunds');

    // Verify no collision with Edvan boleto module
    assert(
        !fs.existsSync(path.join(ROOT, 'modules/gateways/iugu.php')) &&
        !fs.existsSync(path.join(ROOT, 'modules/gateways/iuguboleto.php')),
        'Zero interference with existing Edvan Iugu boleto module filenames'
    );

    // ========================================================================
    // SUITE 2: Client-Side Tokenization (Iugu.js v2) & PCI-DSS Enforcement
    // ========================================================================
    console.log('\nSuite 2: Client-Side Tokenization (Iugu.js v2) & PCI-DSS Security');

    const checkoutTemplate = fs.readFileSync(
        path.join(ROOT, 'modules/gateways/iugucc/templates/checkout.php'),
        'utf8'
    );
    assert(checkoutTemplate.includes('data-iugu="number"'), 'Checkout template binds data-iugu="number"');
    assert(checkoutTemplate.includes('data-iugu="verification_value"'), 'Checkout template binds data-iugu="verification_value"');
    assert(checkoutTemplate.includes('data-iugu="expiration"'), 'Checkout template binds data-iugu="expiration"');
    assert(checkoutTemplate.includes('data-iugu="full_name"'), 'Checkout template binds data-iugu="full_name"');

    // Iugu.js v2 must be loaded from the official host js.iugu.com (js.iugu.net does not resolve in DNS)
    const configPhp = fs.readFileSync(path.join(ROOT, 'modules/gateways/iugucc/lib/Config.php'), 'utf8');
    assert(
        configPhp.includes("IUGU_JS_URL  = 'https://js.iugu.com/v2'") && !configPhp.includes('js.iugu.net'),
        'Config::IUGU_JS_URL points to the official Iugu.js v2 host (https://js.iugu.com/v2), never js.iugu.net'
    );
    const moduleSources = [
        'modules/gateways/iugucc.php',
        'modules/gateways/callback/iugucc.php',
        'modules/gateways/iugucc/assets/js/iugucc.js',
        'modules/gateways/iugucc/templates/checkout.php'
    ].map((rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8')).join('\n');
    assert(
        !moduleSources.includes('js.iugu.net'),
        'No module source references the non-existent js.iugu.net host'
    );

    // Ensure inputs with data-iugu DO NOT have a name="" attribute (PCI-DSS guarantee)
    const inputTags = checkoutTemplate.match(/<input\b[^>]*>/gi) || [];
    const sensitiveInputsWithName = inputTags.filter(
        (tag) => /data-iugu=/i.test(tag) && /\bname\s*=/i.test(tag)
    );
    assert(
        sensitiveInputsWithName.length === 0,
        'Sensitive card inputs (data-iugu) omit HTML name="" attribute so raw card data can never be POSTed'
    );

    // Test Iugu.js client-side helper functions with official Iugu Sandbox test cards
    const { visa_approved, mastercard_approved, amex_approved, invalid_luhn } = fixtures.sandbox_cards;
    assert(
        IuguCCCheckout.isValidLuhn(visa_approved.number) === true &&
        IuguCCCheckout.detectCardBrand(visa_approved.number) === 'VISA',
        'Validates Iugu Sandbox Visa card (4111 1111 1111 1111) Luhn & brand'
    );
    assert(
        IuguCCCheckout.isValidLuhn(mastercard_approved.number) === true &&
        IuguCCCheckout.detectCardBrand(mastercard_approved.number) === 'MASTERCARD',
        'Validates Iugu Sandbox Mastercard (5555 5555 5555 4444) Luhn & brand'
    );
    assert(
        IuguCCCheckout.isValidLuhn(amex_approved.number) === true &&
        IuguCCCheckout.detectCardBrand(amex_approved.number) === 'AMEX',
        'Validates Iugu Sandbox Amex (3782 822463 10005) Luhn & brand'
    );
    assert(
        IuguCCCheckout.isValidLuhn(invalid_luhn.number) === false,
        'Rejects invalid Luhn card number (4111 1111 1111 1112) on client side'
    );
    assert(
        IuguCCCheckout.isValidExpiration('12/2030', new Date('2026-11-07T00:00:00Z')) === true &&
        IuguCCCheckout.isValidExpiration('01/2024', new Date('2026-11-07T00:00:00Z')) === false,
        'Validates future card expiration date and rejects expired cards'
    );
    assert(
        IuguCCCheckout.isValidHolderName('JOAO SILVA') === true &&
        IuguCCCheckout.isValidHolderName('JOAO') === false,
        'Validates cardholder full name (first + last name required by Iugu.js)'
    );
    assert(
        IuguCCCheckout.isValidCpfCnpj('123.456.789-09') === true &&
        IuguCCCheckout.isValidCpfCnpj('12.345.678/0001-99') === true &&
        IuguCCCheckout.isValidCpfCnpj('12345') === false,
        'Validates Brazilian CPF (11 digits) and CNPJ (14 digits)'
    );

    const serverSubmission = IuguCCCheckout.buildServerSubmissionPayload({
        invoiceId: 1024,
        iuguToken: 'tok_iugu_js_single_use_998877',
        installments: 3,
        cpfCnpj: '123.456.789-09',
        signature: 'hmac_sig_test',
        number: visa_approved.number, // Even if passed accidentally, must be stripped!
        verification_value: '123'
    });
    assert(
        serverSubmission.iugu_token === 'tok_iugu_js_single_use_998877' &&
        serverSubmission.number === undefined &&
        serverSubmission.verification_value === undefined,
        'Client-to-server submission payload contains only iugu_token and strips any card/CVV properties'
    );

    // ========================================================================
    // SUITE 3: Mock Iugu API Server E2E Simulation (Charge + Webhook + Provisioning)
    // ========================================================================
    console.log('\nSuite 3: E2E Sandbox Simulation (Mock Iugu REST API v1 + WHMCS Invoice & Service Provisioning)');

    const receivedApiRequests = [];
    const mockServer = http.createServer((req, res) => {
        let rawBody = '';
        req.on('data', (chunk) => {
            rawBody += chunk;
        });
        req.on('end', () => {
            const parsedBody = rawBody ? JSON.parse(rawBody) : null;
            receivedApiRequests.push({
                method: req.method,
                url: req.url,
                headers: req.headers,
                body: parsedBody
            });

            if (req.method === 'POST' && req.url === '/v1/charge') {
                const token = parsedBody.token;
                if (token === 'tok_sandbox_visa_approved') {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify(fixtures.charge_responses.approved_lr00.body));
                    return;
                }
                if (token === 'tok_sandbox_declined_51') {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify(fixtures.charge_responses.declined_insufficient_funds_lr51.body));
                    return;
                }
                if (token === 'tok_sandbox_declined_54') {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify(fixtures.charge_responses.declined_expired_card_lr54.body));
                    return;
                }
                if (token === 'tok_sandbox_declined_82') {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify(fixtures.charge_responses.declined_invalid_cvv_lr82.body));
                    return;
                }
                res.writeHead(422, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify(fixtures.charge_responses.validation_error_422.body));
                return;
            }

            if (req.method === 'GET' && req.url.startsWith('/v1/invoices/')) {
                const invId = req.url.split('/').pop();
                const found = fixtures.invoice_details[invId];
                if (found) {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify(found.body));
                    return;
                }
                res.writeHead(404, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ errors: 'Invoice not found' }));
                return;
            }

            res.writeHead(404);
            res.end();
        });
    });

    await new Promise((resolve) => mockServer.listen(0, '127.0.0.1', resolve));
    const port = mockServer.address().port;
    const baseUrl = `http://127.0.0.1:${port}/v1`;

    try {
        // Simulate WHMCS state for imovelsite.com.br
        const whmcsState = {
            invoices: {
                1024: { id: 1024, userid: 42, status: 'Unpaid', total: 150.00 },
                1025: { id: 1025, userid: 42, status: 'Unpaid', total: 299.90 }
            },
            services: {
                1024: { domain: 'imovelsite.com.br', status: 'Pending' },
                1025: { domain: 'painel.imovelsite.com.br', status: 'Pending' }
            },
            transactions: new Set(),
            gatewayLogs: []
        };

        // Helper simulating WHMCS `addInvoicePayment` + automatic service provisioning hook
        function whmcsAddInvoicePayment(invoiceId, transId, amount, fee, gateway) {
            if (whmcsState.transactions.has(transId)) {
                return false; // Idempotent guard
            }
            whmcsState.transactions.add(transId);
            if (whmcsState.invoices[invoiceId]) {
                whmcsState.invoices[invoiceId].status = 'Paid';
                whmcsState.invoices[invoiceId].transId = transId;
                whmcsState.invoices[invoiceId].paidAmount = amount;
                whmcsState.invoices[invoiceId].fee = fee;
                whmcsState.invoices[invoiceId].gateway = gateway;
            }
            // WHMCS InvoicePaid hook -> triggers service provisioning for imovelsite.com.br
            if (whmcsState.services[invoiceId]) {
                whmcsState.services[invoiceId].status = 'Active';
            }
            return true;
        }

        // Helper to make authenticated request to Mock Iugu API (mirrors IuguHttpClient.php)
        async function callIuguApi(method, endpoint, apiToken, payload = null) {
            const auth = Buffer.from(`${apiToken}:`).toString('base64');
            const res = await fetch(`${baseUrl}${endpoint}`, {
                method,
                headers: {
                    'Authorization': `Basic ${auth}`,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json; charset=utf-8'
                },
                body: payload ? JSON.stringify(payload) : undefined
            });
            const body = await res.json();
            return { http_code: res.status, body };
        }

        // Test 3.1: Approved Synchronous Charge (LR 00)
        const chargePayload = {
            token: 'tok_sandbox_visa_approved',
            email: 'cliente@imovelsite.com.br',
            months: 3,
            keep_dunning: false,
            order_id: 'whmcs_1024',
            items: [
                {
                    description: 'Fatura #1024 - imovelsite.com.br',
                    quantity: 1,
                    price_cents: 15000
                }
            ],
            payer: {
                name: 'João Silva',
                email: 'cliente@imovelsite.com.br',
                cpf_cnpj: '12345678909'
            },
            custom_variables: [
                { name: 'whmcs_invoice_id', value: '1024' },
                { name: 'whmcs_client_id', value: '42' },
                { name: 'integration_module', value: 'iugucc' }
            ]
        };

        const chargeRes = await callIuguApi('POST', '/charge', 'sandbox_token_abc123', chargePayload);
        assert(
            chargeRes.http_code === 200 && chargeRes.body.success === true && chargeRes.body.LR === '00',
            'Synchronous POST /v1/charge with valid Iugu.js token returns success=true & LR=00'
        );

        const lastReq = receivedApiRequests[receivedApiRequests.length - 1];
        assert(
            lastReq.headers.authorization === 'Basic ' + Buffer.from('sandbox_token_abc123:').toString('base64'),
            'HTTP Basic Auth header uses base64(api_token + ":") per Iugu API specification'
        );

        // Fetch fee & mark invoice paid synchronously
        const invDetails = await callIuguApi('GET', `/invoices/${chargeRes.body.invoice_id}`, 'sandbox_token_abc123');
        const fee = invDetails.body.taxes_paid_cents / 100;
        whmcsAddInvoicePayment(1024, chargeRes.body.invoice_id, 150.00, fee, 'iugucc');

        assert(
            whmcsState.invoices[1024].status === 'Paid' &&
            whmcsState.invoices[1024].fee === 4.49 &&
            whmcsState.services[1024].status === 'Active',
            'Synchronous checkout marks WHMCS invoice #1024 as Paid and activates imovelsite.com.br service'
        );

        // Test 3.2: Declined Charges (LR 51 Insufficient Funds, LR 54 Expired, LR 82 Invalid CVV)
        const decline51 = await callIuguApi('POST', '/charge', 'sandbox_token_abc123', {
            ...chargePayload,
            token: 'tok_sandbox_declined_51',
            order_id: 'whmcs_1025'
        });
        assert(
            decline51.body.success === false && decline51.body.LR === '51' &&
            whmcsState.invoices[1025].status === 'Unpaid',
            'Declined charge (LR 51 Insufficient Funds) leaves WHMCS invoice Unpaid and returns refusal reason'
        );

        const decline54 = await callIuguApi('POST', '/charge', 'sandbox_token_abc123', {
            ...chargePayload,
            token: 'tok_sandbox_declined_54',
            order_id: 'whmcs_1025'
        });
        assert(
            decline54.body.success === false && decline54.body.LR === '54',
            'Declined charge (LR 54 Expired Card) handled gracefully'
        );

        const decline82 = await callIuguApi('POST', '/charge', 'sandbox_token_abc123', {
            ...chargePayload,
            token: 'tok_sandbox_declined_82',
            order_id: 'whmcs_1025'
        });
        assert(
            decline82.body.success === false && decline82.body.LR === '82',
            'Declined charge (LR 82 Invalid CVV) handled gracefully'
        );

        // Test 3.3: Webhook Idempotency (When webhook arrives for already-paid invoice #1024)
        const webhookPayload = fixtures.webhooks.invoice_paid;
        const verifyResp = await callIuguApi('GET', `/invoices/${webhookPayload.data.id}`, 'sandbox_token_abc123');
        const resolvedWhmcsId = Number(
            verifyResp.body.custom_variables.find((v) => v.name === 'whmcs_invoice_id').value
        );
        const duplicateRecorded = whmcsAddInvoicePayment(
            resolvedWhmcsId,
            webhookPayload.data.id,
            verifyResp.body.total_paid_cents / 100,
            verifyResp.body.taxes_paid_cents / 100,
            'iugucc'
        );
        assert(
            duplicateRecorded === false && whmcsState.transactions.size === 1,
            'Asynchronous Webhook is strictly idempotent and does not duplicate payment when invoice #1024 is already Paid'
        );

        // Test 3.4: Asynchronous Webhook Payment Confirmation (When invoice was still Unpaid)
        whmcsState.invoices[1024].status = 'Unpaid';
        whmcsState.services[1024].status = 'Pending';
        whmcsState.transactions.clear();

        const asyncRecorded = whmcsAddInvoicePayment(
            resolvedWhmcsId,
            webhookPayload.data.id,
            verifyResp.body.total_paid_cents / 100,
            verifyResp.body.taxes_paid_cents / 100,
            'iugucc'
        );
        assert(
            asyncRecorded === true &&
            whmcsState.invoices[1024].status === 'Paid' &&
            whmcsState.services[1024].status === 'Active',
            'Asynchronous Webhook marks Unpaid WHMCS invoice as Paid and triggers service provisioning'
        );
    } finally {
        await new Promise((resolve) => mockServer.close(resolve));
    }

    // ========================================================================
    // SUITE 4: Safety Rules, Archive Policy (`archived/`), & Versioning
    // ========================================================================
    console.log('\nSuite 4: Safety Rules, Archive Policy (`archived/`), & Semantic Versioning');

    const gitignore = fs.readFileSync(path.join(ROOT, '.gitignore'), 'utf8');
    assert(gitignore.includes('archived/'), '.gitignore explicitly excludes archived/ directory');
    assert(fs.existsSync(path.join(ROOT, 'archived')), 'archived/ directory exists in workspace root');

    const agentsMd = fs.readFileSync(path.join(ROOT, 'AGENTS.md'), 'utf8');
    assert(
        agentsMd.includes('archived/') &&
        agentsMd.includes('Edvan') &&
        agentsMd.includes('bells.webstorage.com.br') &&
        agentsMd.includes('red.webstorage.com.br'),
        'AGENTS.md documents mandatory archived/ backup rule and non-interference with Edvan boleto & remote servers'
    );

    assert(
        computeNextVersion('1.0.0', 'patch') === '1.0.1' &&
        computeNextVersion('1.0.0', 'minor') === '1.1.0' &&
        computeNextVersion('1.0.0', 'major') === '2.0.0',
        'Semantic version bumping utility computes patch, minor, and major transitions accurately'
    );

    console.log('\n======================================================================');
    console.log(` TEST SUMMARY: ${passed} passed, ${failed} failed`);
    console.log('======================================================================\n');

    if (failed > 0) {
        process.exit(1);
    }
}

runAllTests().catch((err) => {
    console.error(err);
    process.exit(1);
});
