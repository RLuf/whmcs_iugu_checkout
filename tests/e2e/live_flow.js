#!/usr/bin/env node
/**
 * Live end-to-end flow on the real WHMCS storefront, driven in Chromium exactly as a customer would:
 *
 *   stage=preflight  read-only: is iugucc deployed and offered at checkout? is Iugu.js reachable?
 *   stage=order      Imóvel Site product -> configure -> checkout -> create account -> choose gateway -> place order
 *   stage=login      log in to the client area with the account created by stage=order
 *   stage=pay        open the invoice, choose "Cartão de Crédito (Iugu)" and pay with an official Iugu TEST card
 *   stage=verify     invoice Paid, service Active, provisioned site reachable
 *   stage=full       the definitive run in ONE browser session, as a real customer: imovelsite.com.br ->
 *                    CONTRATE AGORA -> account -> card gateway -> pay on the invoice with a test card ->
 *                    invoice Paid -> service Active -> e-mails without duplicates -> provisioned site ->
 *                    site admin login -> register a property
 *
 * WHMCS ties the client session to the visitor IP, and the store protects login with an image captcha.
 * stage=full never logs in again (the session created by the order is used to the end), so it needs a
 * machine with a stable egress IP (a workstation, or the "E2E live" GitHub Actions workflow).
 *
 * Nothing here talks to the Iugu API directly: the browser talks to WHMCS, and the gateway module (configured
 * in WHMCS) talks to Iugu. The only browser->Iugu call is Iugu.js card tokenization, as designed for PCI.
 *
 * Environment:
 *   E2E_BASE        WHMCS client URL        (default https://www.webstorage.net.br/financeiro/)
 *   E2E_PID         product id              (default 177 — Imóvel Site)
 *   E2E_EMAIL       mailbox for the test customer (required for stage=order)
 *   E2E_PREFIX      site prefix, 3-20 lowercase letters/digits (required for stage=order)
 *   E2E_GATEWAY     gateway chosen at checkout (default iugucc)
 *   E2E_STATE       JSON file with the test account (default archived/e2e/state.json — never committed)
 *   E2E_CARD        test card number (default 4111111111111111 — Iugu "Sucesso")
 *   E2E_SHOTS       screenshot directory (default archived/e2e/shots)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..', '..');
const BASE = (process.env.E2E_BASE || 'https://www.webstorage.net.br/financeiro/').replace(/\/?$/, '/');
const PID = process.env.E2E_PID || '177';
const GATEWAY = process.env.E2E_GATEWAY || 'iugucc';
const STATE_FILE = process.env.E2E_STATE || path.join(ROOT, 'archived', 'e2e', 'state.json');
const SHOTS = process.env.E2E_SHOTS || path.join(ROOT, 'archived', 'e2e', 'shots');
const CARD = process.env.E2E_CARD || '4111111111111111';
const SESSION_FILE = path.join(path.dirname(STATE_FILE), 'session.json');
const stage = (process.argv[2] || 'preflight').replace(/^stage=/, '');

fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
fs.mkdirSync(SHOTS, { recursive: true });
const loadState = () => (fs.existsSync(STATE_FILE) ? JSON.parse(fs.readFileSync(STATE_FILE, 'utf8')) : {});
const saveState = (patch) => { const s = { ...loadState(), ...patch }; fs.writeFileSync(STATE_FILE, JSON.stringify(s, null, 2)); return s; };

const report = { stage, base: BASE, started_at: new Date().toISOString(), checks: [] };
const check = (ok, name, details = '') => { report.checks.push({ ok: !!ok, name, details }); console.log(`  ${ok ? '\x1b[32m✓\x1b[0m' : '\x1b[31m✗\x1b[0m'} ${name}${details ? ' — ' + details : ''}`); return !!ok; };
let shotN = 0;
const shot = async (page, label) => { const f = path.join(SHOTS, `${stage}_${String(++shotN).padStart(2, '0')}_${label}.png`); await page.screenshot({ path: f, fullPage: true }).catch(() => {}); return f; };
// The server answers some requests with a browser-verification page ("Aguarde enquanto sua solicitação está
// sendo verificada…" / "One moment, please...") that reloads the real page by itself; wait for it to pass.
const CHALLENGE = /aguarde enquanto sua solicita|one moment, please|just a moment/i;
const settle = async (page, ms = 2500) => {
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForTimeout(ms);
    for (const t0 = Date.now(); Date.now() - t0 < 90000;) {
        let text = null;
        try { text = await page.evaluate(() => (document.body ? document.body.innerText.slice(0, 400) : '')); } catch (e) { /* navigating */ }
        if (text !== null && text.trim() !== '' && !CHALLENGE.test(text)) break;
        await page.waitForTimeout(1500);
    }
};
const alerts = (page) => page.evaluate(() => Array.from(document.querySelectorAll('.alert-danger, .alert-warning, .checkout-error-feedback, .field-error-msg'))
    .filter((e) => e.offsetParent !== null).map((e) => e.innerText.trim().replace(/\s+/g, ' ').slice(0, 300)).filter(Boolean));

async function gatewaysAtCheckout(page, fromStorefront = false) {
    if (fromStorefront) {
        // Start where the customer starts: the Imóvel Site home page, clicking "CONTRATE AGORA".
        const storefront = process.env.E2E_STOREFRONT || 'https://imovelsite.com.br/';
        await page.goto(storefront, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await settle(page, 2500);
        const cta = page.locator(`a[href*="cart.php?a=add&pid=${PID}"]`).filter({ hasText: /contrate agora/i }).first();
        const ctaText = (await cta.innerText()).trim();
        await cta.evaluate((a) => a.removeAttribute('target'));
        await shot(page, 'storefront');
        await Promise.all([page.waitForURL(/cart\.php/, { timeout: 90000 }), cta.click()]);
        check(/cart\.php\?a=(add|confproduct)/.test(page.url()), `Botão "${ctaText}" do ${storefront} leva ao carrinho do WHMCS`, page.url());
    } else {
        await page.goto(`${BASE}cart.php?a=add&pid=${PID}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    }
    await settle(page);
    // The server's anti-bot page ("One moment, please...") and the store redirects can delay the product
    // configuration form; wait for it and re-open the cart link when it does not show up.
    for (let attempt = 0; attempt < 4 && !(await page.locator('#btnCompleteProductConfig').count()); attempt++) {
        await page.waitForSelector('#btnCompleteProductConfig', { timeout: 20000 }).catch(() => {});
        if (await page.locator('#btnCompleteProductConfig').count()) break;
        await page.goto(`${BASE}cart.php?a=add&pid=${PID}`, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => {});
        await settle(page, 4000);
    }
    const prefixField = page.locator('[name="customfield[22]"]');
    if (await prefixField.count()) await prefixField.fill(process.env.E2E_PREFIX || 'preflight' + crypto.randomBytes(3).toString('hex'));
    await page.click('#btnCompleteProductConfig');
    await page.waitForURL(/a=view|a=checkout/, { timeout: 60000 }).catch(() => {});
    await settle(page, 2000);
    await page.goto(`${BASE}cart.php?a=checkout`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await settle(page, 3000);
    return page.evaluate(() => Array.from(document.querySelectorAll('input[name=paymentmethod]')).map((e) => ({ value: e.value, label: ((e.closest('label') || e.parentElement).innerText || '').trim().replace(/\s+/g, ' ') })));
}

async function preflight(page) {
    console.log(`\nPreflight (read-only) — ${BASE}`);
    await page.goto(`${BASE}cart.php?a=view`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await settle(page, 6000);
    const probe = (rel) => page.evaluate(async (u) => { const r = await fetch(u, { credentials: 'include' }); const t = await r.text(); return { status: r.status, text: t.slice(0, 4000) }; }, BASE + rel);
    // A deployed gateway file answers a direct request with a short plain body ("This file cannot be accessed
    // directly"); the WHMCS 404 page and the server's anti-bot page are full HTML documents.
    const isHtmlPage = (r) => /<html|<!doctype/i.test(r.text);
    const gw = await probe('modules/gateways/iugucc.php');
    check(gw.status === 200 && !isHtmlPage(gw), 'Arquivo modules/gateways/iugucc.php presente no WHMCS', `HTTP ${gw.status}${isHtmlPage(gw) ? ' (página HTML, não o arquivo do módulo)' : ''}`);
    const cb = await probe('modules/gateways/callback/iugucc.php');
    check(cb.status !== 404 && !isHtmlPage(cb), 'Callback modules/gateways/callback/iugucc.php presente', `HTTP ${cb.status} ${cb.text.slice(0, 80).replace(/\s+/g, ' ')}`);
    const js = await probe('modules/gateways/iugucc/assets/js/iugucc.js');
    check(js.status === 200 && js.text.includes('IuguCCCheckout'), 'Assets do módulo publicados (iugucc.js)', `HTTP ${js.status}`);
    if (js.status === 200) check(js.text.includes('js.iugu.com/v2') && !js.text.includes('js.iugu.net'), 'Versão publicada usa o host oficial js.iugu.com (não js.iugu.net)');
    const gateways = await gatewaysAtCheckout(page);
    report.gateways = gateways;
    check(gateways.some((g) => g.value === 'iugucc'), 'Gateway iugucc ativo e exibido no formulário de pedido', 'oferecidos: ' + gateways.map((g) => `${g.value} (${g.label})`).join(', '));
    await shot(page, 'checkout');
    const iuguJs = await page.evaluate(() => new Promise((resolve) => { const s = document.createElement('script'); s.src = 'https://js.iugu.com/v2'; s.onload = () => resolve(typeof window.Iugu === 'object'); s.onerror = () => resolve(false); document.head.appendChild(s); setTimeout(() => resolve(false), 20000); }));
    check(iuguJs, 'Iugu.js v2 carrega neste navegador (https://js.iugu.com/v2)');
    // WHMCS binds the client session to the visitor IP: the logged-in stages need one stable egress IP.
    const ips = [];
    for (let i = 0; i < 5; i++) { ips.push(await page.evaluate(() => fetch('https://api.ipify.org?format=json', { cache: 'no-store' }).then((r) => r.json()).then((j) => j.ip).catch(() => 'erro'))); await page.waitForTimeout(400); }
    report.egress_ips = [...new Set(ips)].length;
    check(new Set(ips).size === 1 && /^[0-9a-f.:]+$/i.test(ips[0]), 'IP de saída estável (necessário para manter a sessão do cliente no WHMCS)', `${new Set(ips).size} valor(es) distintos em 5 consultas${ips.includes('erro') ? ' (consulta de IP falhou)' : ''}`);
}

async function order(page) {
    const email = process.env.E2E_EMAIL, prefix = process.env.E2E_PREFIX;
    if (!email || !/^[a-z0-9]{3,20}$/.test(prefix || '')) throw new Error('E2E_EMAIL and E2E_PREFIX (3-20 lowercase letters/digits) are required');
    const password = process.env.E2E_PASSWORD || crypto.randomBytes(9).toString('base64').replace(/[+/=]/g, 'x') + 'aA1!';
    console.log(`\nPedido real — produto ${PID}, prefixo ${prefix}, gateway ${GATEWAY}`);

    const offered = await gatewaysAtCheckout(page, true);
    report.gateways = offered;
    if (!check(offered.some((g) => g.value === GATEWAY), `Gateway "${GATEWAY}" disponível no checkout`, offered.map((g) => g.value).join(', '))) return;

    const fill = async (sel, val) => { const l = page.locator(sel); if (await l.count()) { await l.first().fill(val); return true; } return false; };
    const newAccountBtn = page.locator('#btnNewUserSignup');
    if (await newAccountBtn.count() && await newAccountBtn.isVisible().catch(() => false)) await newAccountBtn.click().catch(() => {});
    await fill('#inputCompanyName', process.env.E2E_COMPANY || 'TESTE AUTOMATIZADO IUGUCC - NAO ATENDER');
    await fill('#inputFirstName', process.env.E2E_FIRSTNAME || 'Teste');
    await fill('#inputLastName', process.env.E2E_LASTNAME || 'Automatizado Iugucc');
    await fill('#inputEmail', email);
    await fill('#inputPhone', process.env.E2E_PHONE || '51999990000');
    await fill('#inputAddress1', 'Rua de Teste Automatizado, 100');
    await fill('#inputAddress2', 'Centro');
    await fill('#inputCity', 'Novo Hamburgo');
    if (await page.locator('#inputCountry').count()) await page.selectOption('#inputCountry', 'BR').catch(() => {});
    await page.waitForTimeout(800);
    if (await page.locator('#stateselect').count()) await page.selectOption('#stateselect', 'RS').catch(() => {});
    await fill('#inputPostcode', '93510-000');
    await fill('#customfield3', process.env.E2E_CPF || '529.982.247-25');
    await fill('#inputNewPassword1', password);
    await fill('#inputNewPassword2', password);
    const skipCredit = page.locator('#skipCreditOnCheckout');
    if (await skipCredit.count() && await skipCredit.isVisible().catch(() => false)) await skipCredit.check().catch(() => {});
    await page.locator(`input[name=paymentmethod][value="${GATEWAY}"]`).check({ force: true });
    for (const sel of ['#accepttos', 'input[name=accepttos]', 'input[name=marketingoptin]']) {
        const l = page.locator(sel); if (await l.count() && sel.includes('tos')) await l.first().check({ force: true }).catch(() => {});
    }
    await shot(page, 'checkout_filled');
    saveState({ email, prefix, password, gateway_at_order: GATEWAY, base: BASE, created_at: new Date().toISOString() });

    await page.click('#btnCompleteOrder');
    // WHMCS chains several redirects after the order (complete -> invoice -> gateway); wait until it settles.
    for (let i = 0; i < 30; i++) { await page.waitForTimeout(1500); try { await page.waitForLoadState('domcontentloaded', { timeout: 5000 }); } catch (e) { /* still navigating */ } if (!/a=checkout/.test(page.url())) break; }
    await page.waitForTimeout(4000);
    const url = page.url();
    const snap = async () => { for (let i = 0; i < 5; i++) { try { return await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 1500)); } catch (e) { await page.waitForTimeout(1500); } } return ''; };
    const body = await snap();
    const errs = await alerts(page).catch(() => []);
    await shot(page, 'after_submit');
    const orderNumber = (body.match(/(?:n[úu]mero do (?:seu )?pedido|order number)[^0-9]{0,40}(\d{6,})/i) || [])[1] || null;
    const invoiceId = (url.match(/viewinvoice\.php\?id=(\d+)/) || body.match(/fatura\s*(?:n[ºo.]*|#)?\s*(\d{3,})/i) || [])[1] || null;
    report.after_submit = { url, errors: errs, orderNumber, invoiceId, text: body.slice(0, 600) };
    const placed = !/a=checkout/.test(url);
    check(placed, 'Pedido aceito pelo WHMCS', errs.length ? 'erros: ' + errs.join(' | ') : url);
    if (placed) { saveState({ order_number: orderNumber, invoice_id: invoiceId, order_url: url }); await page.context().storageState({ path: SESSION_FILE }); }
}

async function login(page) {
    const s = loadState();
    if (!s.email || !s.password) throw new Error('No test account in ' + STATE_FILE);
    console.log(`\nLogin na área do cliente — ${s.email}`);
    // Reuse the saved browser session when it is still valid (avoids a new login and its captcha).
    await page.goto(`${BASE}clientarea.php`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await settle(page, 2500);
    if (/login/i.test(page.url())) {
        const captchaImg = page.locator('#inputCaptchaImage, img[src*="captcha"], img[src*="verifyimage"]').first();
        const hasCaptcha = await captchaImg.count() > 0;
        report.login_captcha = hasCaptcha;
        await page.fill('#inputEmail', s.email);
        await page.fill('#inputPassword', s.password);
        if (hasCaptcha) {
            // The store protects login with an image captcha. The characters are supplied by whoever runs the
            // test (file archived/e2e/captcha_answer.txt), exactly as a person would type them.
            const img = path.join(SHOTS, 'captcha.png');
            const answerFile = path.join(path.dirname(STATE_FILE), 'captcha_answer.txt');
            if (fs.existsSync(answerFile)) fs.unlinkSync(answerFile);
            await captchaImg.screenshot({ path: img });
            console.log(`    captcha salvo em ${img}; aguardando ${answerFile}`);
            const t0 = Date.now();
            while (!fs.existsSync(answerFile) && Date.now() - t0 < 240000) await page.waitForTimeout(1000);
            if (!fs.existsSync(answerFile)) throw new Error('captcha answer not provided');
            await page.fill('#inputCaptcha', fs.readFileSync(answerFile, 'utf8').trim());
        }
        await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), page.click('#login')]);
        await settle(page, 4000);
    }
    const info = await page.evaluate(() => ({ url: location.href, text: document.body.innerText.replace(/\s+/g, ' ').slice(0, 900) }));
    await shot(page, 'clientarea');
    report.login = info;
    const ok = check(/clientarea\.php/i.test(info.url) && !/login/i.test(info.url), 'Login na área do cliente', info.url + (/n[ãa]o correspondem/i.test(info.text) ? ' — captcha incorreto' : ''));
    if (!ok) return false;
    await page.context().storageState({ path: SESSION_FILE });
    for (const rel of ['clientarea.php?action=services', 'clientarea.php?action=invoices', 'clientarea.php?action=emails']) {
        await page.goto(BASE + rel, { waitUntil: 'domcontentloaded', timeout: 90000 }); await settle(page, 2500);
        const rows = await page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).map((tr) => tr.innerText.trim().replace(/\s+/g, ' ').slice(0, 200)).filter(Boolean).slice(0, 12));
        report[rel.split('=')[1]] = rows;
        console.log(`    ${rel.split('=')[1]}: ${JSON.stringify(rows)}`);
        await shot(page, rel.split('=')[1]);
    }
    return true;
}

async function pay(page) {
    const s = loadState();
    if (!s.invoice_id) throw new Error('No invoice id in state; run stage=login first');
    if (!(await login(page))) return false;
    return payInvoice(page, s.invoice_id);
}

async function payInvoice(page, invoiceId) {
    const s = { invoice_id: invoiceId };
    console.log(`\nPagamento da fatura #${invoiceId} com cartão de teste`);
    await page.goto(`${BASE}viewinvoice.php?id=${s.invoice_id}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await settle(page, 3000);
    const gwSelect = page.locator('select[name=gateway]');
    if (await gwSelect.count()) {
        const opts = await gwSelect.evaluate((el) => Array.from(el.options).map((o) => o.value));
        report.invoice_gateways = opts;
        if (!check(opts.includes('iugucc'), 'Gateway iugucc selecionável na fatura', opts.join(', '))) { await shot(page, 'invoice'); return false; }
        if ((await gwSelect.inputValue()) !== 'iugucc') { await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), gwSelect.selectOption('iugucc')]); await settle(page, 3500); }
    }
    if (!check(await page.locator('#iugucc-payment-form').count() === 1, 'Formulário de cartão do iugucc exibido na fatura')) { await shot(page, 'invoice'); return false; }
    check(await page.evaluate(() => typeof window.Iugu === 'object' && typeof window.Iugu.createPaymentToken === 'function'), 'Iugu.js v2 carregado na fatura');
    check(await page.locator('.iugucc-sandbox-banner').count() === 1, 'Fatura em Modo Sandbox (aviso de teste visível)');
    const iuguCalls = [];
    page.on('response', (r) => { if (r.url().includes('api.iugu.com')) iuguCalls.push(`${r.status()} ${r.url().split('?')[0]}`); });
    let callback = null;
    page.on('response', async (r) => { if (r.url().includes('/modules/gateways/callback/iugucc.php')) { callback = { status: r.status(), body: await r.text().catch(() => '') }; } });
    await page.fill('#iugucc-card-number', CARD);
    await page.fill('#iugucc-card-holder', 'TESTE AUTOMATIZADO');
    await page.fill('#iugucc-card-exp', '12/30');
    await page.fill('#iugucc-card-cvv', '123');
    if (!(await page.inputValue('#iugucc-cpf-cnpj'))) await page.fill('#iugucc-cpf-cnpj', process.env.E2E_CPF || '529.982.247-25');
    await shot(page, 'card_filled');
    await page.click('#iugucc-submit-btn');
    const t0 = Date.now();
    while (Date.now() - t0 < 60000 && !callback) { const a = (await page.locator('#iugucc-alert-box').textContent().catch(() => '')).trim(); if (a) break; await page.waitForTimeout(500); }
    await page.waitForTimeout(3000);
    const alertText = (await page.locator('#iugucc-alert-box').textContent().catch(() => '') || '').trim();
    await shot(page, 'after_pay');
    report.payment = { iuguCalls, callback, alertText, url: page.url() };
    check(iuguCalls.some((c) => c.startsWith('200') && c.endsWith('/payment_token')), 'Cartão tokenizado pela Iugu no navegador', iuguCalls.join(', ') || 'nenhuma resposta da Iugu');
    let cbJson = null; try { cbJson = callback && JSON.parse(callback.body); } catch (e) { /* not json */ }
    check(cbJson && cbJson.success === true && cbJson.lr_code === '00', 'Pagamento aprovado pelo módulo (LR 00)', callback ? `HTTP ${callback.status} ${callback.body.slice(0, 200)}` : alertText || 'sem resposta do callback');
    await page.goto(`${BASE}viewinvoice.php?id=${s.invoice_id}`, { waitUntil: 'domcontentloaded', timeout: 90000 }); await settle(page, 2500);
    const status = await page.evaluate(() => (document.querySelector('.invoice-status, .status, [class*=paid], [class*=unpaid]') || document.body).innerText.replace(/\s+/g, ' ').slice(0, 120));
    await shot(page, 'invoice_final');
    const paid = check(/pag[oa]|paid/i.test(status) && !/n[ãa]o pag|unpaid/i.test(status), 'Fatura consta como Paga no WHMCS', status);
    if (cbJson && cbJson.success) saveState({ paid_at: new Date().toISOString(), iugu_invoice_id: cbJson.transaction_id });
    return paid;
}

async function verify(page) {
    const s = loadState();
    console.log(`\nVerificação pós-pagamento — serviço e site ${s.prefix}.imovelsite.com.br`);
    if (!(await login(page))) return;
    check((report.services || []).some((r) => /ativo|active/i.test(r)), 'Serviço Imóvel Site ativo na área do cliente', JSON.stringify(report.services || []));
    const site = `https://${s.prefix}.imovelsite.com.br/`;
    const resp = await page.goto(site, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch((e) => ({ error: e.message }));
    await settle(page, 2500);
    await shot(page, 'site');
    check(resp && resp.status && resp.status() === 200, `Site provisionado responde em ${site}`, resp && resp.status ? `HTTP ${resp.status()} — ${await page.title()}` : (resp && resp.error || '').slice(0, 120));
}

/** Reads a client-area list page (services, invoices, e-mails) in the current session. */
async function clientAreaRows(page, action) {
    await page.goto(`${BASE}clientarea.php?action=${action}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await settle(page, 3000);
    if (/login/i.test(page.url())) return null; // session lost (IP changed or expired)
    return page.evaluate(() => Array.from(document.querySelectorAll('table tbody tr')).map((tr) => ({
        text: tr.innerText.trim().replace(/\s+/g, ' ').slice(0, 220),
        link: (tr.querySelector('a[href*="viewemail"], a[href*="viewinvoice"], a[href*="productdetails"]') || {}).href || (tr.getAttribute('onclick') || ''),
    })).filter((r) => r.text && !/nenhum registro|no records/i.test(r.text)));
}

const BASE_EMAIL_SUBJECTS = /verification|bem-vindo|lan[çc]amento de cobran|confirma[çc][ãa]o de pedido|confirma[çc][ãa]o de pagamento/i;

async function full(page) {
    // ---- 1. order, starting at the Imóvel Site home page ------------------------------------------------
    await order(page);
    const st = loadState();
    if (report.checks.some((c) => !c.ok)) return;

    // ---- 2. find the invoice in the same session ----------------------------------------------------------
    let invoiceId = st.invoice_id || (page.url().match(/viewinvoice\.php\?id=(\d+)/) || [])[1];
    if (!invoiceId) {
        const invoices = await clientAreaRows(page, 'invoices');
        if (!check(invoices !== null, 'Sessão do cliente mantida após o pedido (sem novo login)')) return;
        invoiceId = ((invoices.find((r) => /n[ãa]o pago|unpaid/i.test(r.text)) || invoices[0] || {}).text || '').match(/\b(\d{3,})\b/)?.[1];
    }
    if (!check(!!invoiceId, 'Fatura do pedido localizada', invoiceId ? `#${invoiceId}` : 'nenhuma fatura na área do cliente')) return;
    saveState({ invoice_id: invoiceId });

    // ---- 3. pay on the invoice page with the test card -----------------------------------------------------
    if (!(await payInvoice(page, invoiceId))) return;

    // ---- 4. provisioning: service becomes Active ------------------------------------------------------------
    let services = null;
    const t0 = Date.now();
    while (Date.now() - t0 < 6 * 60000) {
        services = await clientAreaRows(page, 'services');
        if (services === null || services.some((r) => /\bativo\b|\bactive\b/i.test(r.text))) break;
        await page.waitForTimeout(15000);
    }
    report.services = services;
    if (!check(services !== null, 'Sessão do cliente mantida após o pagamento')) return;
    check(services.some((r) => /\bativo\b|\bactive\b/i.test(r.text)), 'Serviço Imóvel Site ativado automaticamente após o pagamento', JSON.stringify(services.map((r) => r.text)));
    await shot(page, 'services');

    // ---- 5. e-mails sent to the customer: no duplicates ----------------------------------------------------
    const emails = await clientAreaRows(page, 'emails');
    report.emails = (emails || []).map((r) => r.text);
    if (emails) {
        const subjects = emails.map((r) => r.text.replace(/^\S+\s+\S*\s*(\(\d{2}:\d{2}\))?\s*/, '').replace(/\s+(ver|view).*$/i, '').trim());
        const dup = subjects.filter((x, i) => subjects.indexOf(x) !== i);
        check(dup.length === 0, `E-mails enviados sem repetição (${emails.length} mensagens)`, dup.length ? 'repetidos: ' + [...new Set(dup)].join(' | ') : subjects.join(' | '));
        check(emails.some((r) => /confirma[çc][ãa]o de pagamento|payment confirmation/i.test(r.text)), 'E-mail de confirmação de pagamento enviado');
    }

    // ---- 6. provisioned site and access data --------------------------------------------------------------
    const site = `https://${st.prefix}.imovelsite.com.br/`;
    let siteStatus = 0;
    for (let i = 0; i < 12 && siteStatus !== 200; i++) {
        const r = await page.goto(site, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
        siteStatus = r ? r.status() : 0;
        if (siteStatus !== 200) await page.waitForTimeout(15000);
    }
    await shot(page, 'site');
    if (!check(siteStatus === 200, `Site provisionado no ar em ${site}`, `HTTP ${siteStatus} — ${await page.title().catch(() => '')}`)) return;

    let access = null;
    for (const mail of (emails || []).filter((r) => r.link && !BASE_EMAIL_SUBJECTS.test(r.text))) {
        const href = (mail.link.match(/viewemail\.php\?id=\d+/) || [])[0];
        if (!href) continue;
        await page.goto(BASE + href, { waitUntil: 'domcontentloaded', timeout: 60000 }); await settle(page, 1500);
        const text = await page.evaluate(() => document.body.innerText);
        const url = (text.match(/https?:\/\/[^\s"'<>]*(?:wp-login|wp-admin|admin|painel|login)[^\s"'<>]*/i) || [])[0];
        const user = (text.match(/(?:usu[áa]rio|login|user(?:name)?)\s*:\s*(\S+)/i) || [])[1];
        const pass = (text.match(/senha[^:\n]{0,30}:\s*(\S+)/i) || text.match(/password[^:\n]{0,30}:\s*(\S+)/i) || [])[1];
        if (url || pass) { access = { subject: mail.text.slice(0, 80), url, user, pass }; break; }
    }
    report.access = access ? { subject: access.subject, url: access.url, user: access.user, password: access.pass ? '<recebida>' : null } : null;
    if (!check(!!access && !!access.pass, 'E-mail de acesso ao site recebido (link e senha provisória)', access ? `${access.subject} — ${access.url || 'sem link'}` : 'nenhum e-mail além dos de pedido/fatura')) return;

    // ---- 7. site admin: log in and register a property ------------------------------------------------------
    const adminUrl = access.url || `${site}wp-login.php`;
    await page.goto(adminUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }); await settle(page, 2000);
    if (await page.locator('#user_login').count()) {
        await page.fill('#user_login', access.user || st.email);
        await page.fill('#user_pass', access.pass);
        await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), page.click('#wp-submit')]);
        await settle(page, 4000);
    }
    const menu = await page.evaluate(() => Array.from(document.querySelectorAll('#adminmenu a, nav a, .sidebar a')).map((a) => ({ t: (a.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 50), h: a.href })).filter((m) => m.t));
    report.admin_menu = menu.slice(0, 60);
    await shot(page, 'site_admin');
    if (!check(/wp-admin|painel|dashboard|admin/i.test(page.url()) && menu.length > 0, 'Login no painel do site provisionado', page.url())) return;
    const novo = menu.find((m) => /post-new\.php\?post_type=/i.test(m.h) && /im[óo]ve/i.test(m.t + m.h)) || menu.find((m) => /im[óo]ve/i.test(m.t) && /post_type=/i.test(m.h));
    if (!check(!!novo, 'Menu de cadastro de imóveis encontrado no painel', novo ? `${novo.t} — ${novo.h}` : menu.map((m) => m.t).slice(0, 25).join(', '))) return;
    const postType = (novo.h.match(/post_type=([a-z0-9_-]+)/i) || [])[1];
    await page.goto(new URL(`post-new.php?post_type=${postType}`, novo.h).toString(), { waitUntil: 'domcontentloaded', timeout: 60000 }); await settle(page, 4000);
    const title = `Casa de teste automatizado ${new Date().toISOString().slice(0, 16)}`;
    if (await page.locator('#title').count()) {
        await page.fill('#title', title);
        await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), page.click('#publish')]);
    } else {
        await page.locator('.editor-post-title__input, h1[contenteditable="true"]').first().fill(title);
        await page.locator('.editor-post-publish-panel__toggle, .editor-post-publish-button__button').first().click();
        await page.waitForTimeout(1500);
        await page.locator('.editor-post-publish-panel .editor-post-publish-button, .editor-post-publish-button').first().click().catch(() => {});
    }
    await settle(page, 5000);
    await shot(page, 'property_saved');
    const saved = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ')).then((t) => /publicad|published|atualizad|post=\d+/i.test(t + location.href));
    check(saved, 'Imóvel cadastrado no painel do site', title);
    await page.goto(site, { waitUntil: 'domcontentloaded', timeout: 60000 }); await settle(page, 2500);
    check((await page.evaluate(() => document.body.innerText)).includes('Casa de teste automatizado'), 'Imóvel cadastrado aparece no site público');
    await shot(page, 'site_with_property');
}

(async () => {
    const browser = await chromium.launch();
    const ctxOptions = { viewport: { width: 1366, height: 1000 }, locale: 'pt-BR' };
    if (stage !== 'order' && stage !== 'preflight' && fs.existsSync(SESSION_FILE)) ctxOptions.storageState = SESSION_FILE;
    const ctx = await browser.newContext(ctxOptions);
    const page = await ctx.newPage();
    let fatal = null;
    try {
        if (stage === 'preflight') await preflight(page);
        else if (stage === 'order') await order(page);
        else if (stage === 'login') await login(page);
        else if (stage === 'pay') await pay(page);
        else if (stage === 'verify') await verify(page);
        else if (stage === 'full') await full(page);
        else throw new Error('unknown stage ' + stage);
    } catch (e) { fatal = e; await shot(page, 'fatal'); }
    await browser.close();
    report.finished_at = new Date().toISOString();
    const out = path.join(path.dirname(STATE_FILE), `report_${stage}.json`);
    fs.writeFileSync(out, JSON.stringify(report, null, 2).split(loadState().password || '\u0000').join('<senha>'));
    const failed = report.checks.filter((c) => !c.ok).length;
    console.log(`\n ${stage.toUpperCase()}: ${report.checks.length - failed} ok, ${failed} falhou${fatal ? ' — erro: ' + fatal.message : ''}\n relatório: ${out}`);
    process.exit(fatal || failed ? 1 : 0);
})();
