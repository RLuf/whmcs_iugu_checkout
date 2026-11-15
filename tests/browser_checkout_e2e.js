#!/usr/bin/env node
/**
 * Browser E2E of the REAL checkout (template + iugucc.js + official Iugu.js v2) in headless Chromium.
 *
 * What it proves, with the module's own front-end files:
 *   1. The template renders and Iugu.js v2 loads from the official host (window.Iugu present).
 *   2. Client-side validation (Luhn, name, expiry, CVV, CPF) runs before any network call.
 *   3. On submit, the browser talks ONLY to api.iugu.com for tokenization; the module callback
 *      never receives PAN/CVV (PCI-DSS SAQ A guarantee) — asserted even when Iugu is unavailable.
 *   4. When Iugu returns a token, the callback receives exactly {action, invoice_id, iugu_token,
 *      installments, cpf_cnpj, signature} and the token is printed for a follow-up PHP charge test.
 *
 * Env: IUGU_ACCOUNT_ID (required). Exit 0 only when all assertions pass; tokenization outcome is reported.
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');
const ACCOUNT_ID = (process.env.IUGU_ACCOUNT_ID || '').trim();
if (!ACCOUNT_ID) {
    console.log('SKIP: IUGU_ACCOUNT_ID not set.');
    process.exit(0);
}

let passed = 0, failed = 0;
const fails = [];
function assert(c, name, d = '') {
    if (c) { passed++; console.log(`  \x1b[32m✓\x1b[0m ${name}`); }
    else { failed++; fails.push(name); console.error(`  \x1b[31m✗\x1b[0m ${name}${d ? ' — ' + d : ''}`); }
}

(async () => {
    // Mock WHMCS: serves the rendered checkout + static assets, records callback POSTs.
    const callbackHits = [];
    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', (c) => { body += c; });
        req.on('end', () => {
            if (req.url.startsWith('/modules/gateways/callback/iugucc.php')) {
                callbackHits.push({ headers: req.headers, raw: body });
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: true, message: 'mock ok', redirect_url: '/paid.html' }));
                return;
            }
            if (req.url.startsWith('/modules/gateways/iugucc/assets/')) {
                const rel = req.url.split('?')[0].replace('/modules/gateways/iugucc/', '');
                const file = path.join(ROOT, 'modules', 'gateways', 'iugucc', rel);
                if (fs.existsSync(file)) {
                    res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'application/javascript' : 'text/css' });
                    res.end(fs.readFileSync(file));
                    return;
                }
            }
            if (req.url === '/paid.html') { res.writeHead(200); res.end('<html><body id="paid">paid</body></html>'); return; }
            if (req.url.startsWith('/viewinvoice.php')) {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(html);
                return;
            }
            res.writeHead(404); res.end();
        });
    });
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    const base = `http://127.0.0.1:${server.address().port}`;
    const html = execFileSync('php', [
        path.join(ROOT, 'tests', 'browser', 'render_checkout.php'),
        ACCOUNT_ID, `${base}/modules/gateways/callback/iugucc.php`, `${base}/modules/gateways/iugucc`, '150.00', '1024',
    ], { encoding: 'utf8' });

    console.log('\nBrowser E2E: real checkout template + iugucc.js + Iugu.js v2 (test mode)\n');
    const browser = await chromium.launch();
    const page = await browser.newPage();
    const iuguRequests = [];
    const otherRequests = [];
    page.on('request', (r) => {
        const u = r.url();
        if (u.includes('api.iugu.com')) iuguRequests.push(r.method() + ' ' + u);
        else if (!u.startsWith(base) && !u.startsWith('https://js.iugu.com')) otherRequests.push(u);
    });
    const iuguResponses = [];
    page.on('response', (r) => { if (r.url().includes('api.iugu.com')) iuguResponses.push(r.status() + ' ' + r.url().split('?')[0]); });

    await page.goto(`${base}/viewinvoice.php?id=1024`, { waitUntil: 'load' });
    await page.waitForTimeout(1500);

    assert(await page.locator('#iugucc-payment-form').count() === 1, 'Checkout form rendered from the module template');
    assert(await page.evaluate(() => typeof window.Iugu === 'object' && typeof window.Iugu.createPaymentToken === 'function'), 'Official Iugu.js v2 loaded (window.Iugu.createPaymentToken)');
    assert(await page.evaluate(() => typeof window.IuguCCCheckout === 'object'), 'Module controller iugucc.js loaded');
    assert(await page.locator('.iugucc-sandbox-banner').count() === 1, 'Sandbox banner visible in test mode');
    const sensitiveWithName = await page.evaluate(() => Array.from(document.querySelectorAll('input[data-iugu]')).filter((i) => i.hasAttribute('name')).length);
    assert(sensitiveWithName === 0, 'No data-iugu input carries a name attribute (never POSTed)');

    // Client-side validation: invalid Luhn must be blocked before any network call
    await page.fill('#iugucc-card-number', '4111 1111 1111 1112');
    await page.fill('#iugucc-card-holder', 'JOAO SILVA');
    await page.fill('#iugucc-card-exp', '12/30');
    await page.fill('#iugucc-card-cvv', '123');
    await page.fill('#iugucc-cpf-cnpj', '529.982.247-25');
    await page.click('#iugucc-submit-btn');
    await page.waitForTimeout(500);
    assert((await page.locator('#iugucc-alert-box').textContent()).trim().length > 0, 'Invalid Luhn blocked with a visible message');
    assert(iuguRequests.length === 0 && callbackHits.length === 0, 'No network call made for an invalid card');

    // Valid official test card → tokenization at Iugu
    await page.fill('#iugucc-card-number', '4111 1111 1111 1111');
    await page.selectOption('#iugucc-installments', '3');
    await page.click('#iugucc-submit-btn');
    // Wait until the callback is hit, or the module's tokenization watchdog (30s) releases the form.
    const started = Date.now();
    while (Date.now() - started < 45000) {
        if (callbackHits.length > 0) break;
        const txt = (await page.locator('#iugucc-alert-box').textContent()).trim();
        if (txt.length > 0) break;
        await page.waitForTimeout(500);
    }
    await page.waitForTimeout(300);

    assert(iuguRequests.length >= 1 && iuguRequests.every((u) => u.includes('/v1/payment_token')), 'Browser called only api.iugu.com/v1/payment_token for tokenization', JSON.stringify(iuguRequests.map((u) => u.slice(0, 80))));
    assert(iuguRequests.every((u) => u.includes('test=1') && u.toUpperCase().includes(ACCOUNT_ID)), 'Tokenization request carries test=1 and the configured account_id');
    assert(otherRequests.length === 0, 'No third-party hosts contacted', JSON.stringify(otherRequests));

    const leaked = callbackHits.some((h) => h.raw.includes('4111111111111111') || /"number"|verification_value|cvv/i.test(h.raw));
    assert(!leaked, 'Callback never received PAN or CVV');

    let tokenId = '';
    if (callbackHits.length === 1) {
        const payload = JSON.parse(callbackHits[0].raw);
        const keys = Object.keys(payload).sort().join(',');
        assert(keys === 'action,cpf_cnpj,installments,invoice_id,iugu_token,signature', 'Callback payload has exactly the allowed keys', keys);
        assert(payload.installments === 3 && payload.invoice_id === '1024' && payload.cpf_cnpj === '52998224725', 'installments/invoice_id/cpf_cnpj transmitted correctly');
        assert(typeof payload.iugu_token === 'string' && payload.iugu_token.length >= 32, 'iugu_token present (single-use token)');
        assert(callbackHits[0].headers['x-requested-with'] === 'XMLHttpRequest', 'Callback sent as JSON XHR with X-Requested-With');
        tokenId = payload.iugu_token;
        await page.waitForTimeout(1200);
        assert(page.url().endsWith('/paid.html'), 'Redirected to redirect_url after success');
    } else {
        const alert = (await page.locator('#iugucc-alert-box').textContent()).trim();
        console.log(`  (tokenization did not complete: Iugu responses = ${JSON.stringify(iuguResponses)}; UI message = "${alert}")`);
        assert(alert.length > 0, 'Customer sees a clear error when Iugu tokenization fails (no silent failure)');
        assert(!(await page.locator('#iugucc-submit-btn').isDisabled()), 'Submit button re-enabled after tokenization failure');
    }

    await browser.close();
    await new Promise((r) => server.close(r));
    console.log(`\n BROWSER E2E SUMMARY: ${passed} passed, ${failed} failed${tokenId ? `\n IUGU_TOKEN=${tokenId}` : '\n IUGU_TOKEN=(none — Iugu tokenization unavailable)'}\n`);
    process.exit(failed > 0 ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
