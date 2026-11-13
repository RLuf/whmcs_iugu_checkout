#!/usr/bin/env node
/**
 * WHMCS Billing ("Financeiro") simulation for the iugucc gateway.
 *
 * Runs the REAL module files, unmodified, over real HTTP:
 *   - modules/gateways/iugucc.php            (iugucc_link, iugucc_refund)
 *   - modules/gateways/callback/iugucc.php   (synchronous checkout + Iugu webhooks)
 * inside a WHMCS simulator (tests/whmcs_sim/stubs: init.php, gatewayfunctions.php, invoicefunctions.php,
 * viewinvoice.php, Admin refund) served by PHP's built-in server.
 *
 * The module's outbound calls to https://api.iugu.com are routed, through a local CONNECT proxy and a
 * locally-trusted TLS certificate, to an in-process Iugu API double that enforces the official contract
 * (dev.iugu.com: Basic auth, single-use token, items[].price_cents >= 100, months 1..12, invoice statuses,
 * POST /v1/invoices/{id}/refund with partial_value_refund_cents, form-urlencoded webhooks).
 *
 * This is a test harness: it is neither WHMCS nor Iugu. It proves how the module behaves for every
 * billing operation WHMCS exposes to a third-party gateway.
 *
 * Usage: node tests/whmcs_finance_sim.js          (PHP_BIN=php8.1 to pick the interpreter)
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');
const http = require('http');
const https = require('https');
const crypto = require('crypto');
const { execFileSync, spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PHP_BIN = process.env.PHP_BIN || 'php';
const ACCOUNT_ID = 'A1B2C3D4E5F60718293A4B5C6D7E8F90';
const TEST_API_TOKEN = 'sim_test_token_' + crypto.randomBytes(8).toString('hex');

let passed = 0, failed = 0;
const failures = [];
function assert(cond, name, details = '') {
    if (cond) { passed++; console.log(`  \x1b[32m✓\x1b[0m ${name}`); }
    else { failed++; failures.push(name + (details ? ` — ${details}` : '')); console.error(`  \x1b[31m✗\x1b[0m ${name}${details ? ' — ' + details : ''}`); }
}
const section = (t) => console.log(`\n${t}`);

// ---------------------------------------------------------------------------------------------
// Iugu API double (contract from dev.iugu.com)
// ---------------------------------------------------------------------------------------------
const iugu = { tokens: new Map(), invoices: new Map(), requests: [] };
const hex32 = () => crypto.randomBytes(16).toString('hex').toUpperCase();
iugu.issueToken = (outcome = 'approved') => {
    const id = crypto.randomUUID();
    iugu.tokens.set(id, { outcome, used: false });
    return id;
};
iugu.createPaidInvoice = (totalCents, vars, orderId = null) => {
    const id = hex32();
    iugu.invoices.set(id, {
        id, status: 'paid', total_cents: totalCents, total_paid_cents: totalCents, taxes_paid_cents: 449,
        refunded_cents: 0, order_id: orderId, account_id: ACCOUNT_ID,
        custom_variables: vars.map(([name, value]) => ({ name, value })),
    });
    return id;
};
function iuguHandler(req, res) {
    let raw = '';
    req.on('data', (c) => { raw += c; });
    req.on('end', () => {
        const send = (code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(obj)); };
        let body = null;
        try { body = raw ? JSON.parse(raw) : null; } catch (e) { return send(400, { errors: 'invalid json' }); }
        iugu.requests.push({ method: req.method, url: req.url, headers: req.headers, body });
        const expectedAuth = 'Basic ' + Buffer.from(TEST_API_TOKEN + ':').toString('base64');
        if (req.headers.authorization !== expectedAuth) return send(401, { errors: 'Unauthorized' });

        if (req.method === 'POST' && req.url === '/v1/charge') {
            const errors = {};
            if (!body || !body.token) errors.token = ['não pode ficar em branco'];
            if (!body || !Array.isArray(body.items) || body.items.length === 0) errors.items = ['não pode ficar em branco'];
            else if (body.items.some((i) => !(Number.isInteger(i.price_cents) && i.price_cents >= 100))) errors.items = ['price_cents deve ser maior ou igual a 100'];
            if (body && body.months !== undefined && !(Number.isInteger(body.months) && body.months >= 1 && body.months <= 12)) errors.months = ['deve estar entre 1 e 12'];
            if (body && body.soft_descriptor_light && String(body.soft_descriptor_light).length > 12) errors.soft_descriptor_light = ['é muito longo (máximo: 12 caracteres)'];
            if (!body || !body.email) errors.email = ['não pode ficar em branco'];
            if (Object.keys(errors).length) return send(422, { errors });
            const tok = iugu.tokens.get(body.token);
            if (!tok || tok.used) return send(422, { errors: { token: ['Token inválido ou já utilizado'] } });
            tok.used = true; // single use, even when the charge fails
            const total = body.items.reduce((s, i) => s + i.price_cents * (i.quantity || 1), 0);
            const id = hex32();
            const approved = tok.outcome === 'approved';
            iugu.invoices.set(id, {
                id, status: approved ? 'paid' : 'canceled', total_cents: total, total_paid_cents: approved ? total : 0,
                taxes_paid_cents: approved ? 449 : 0, refunded_cents: 0, order_id: body.order_id || null, account_id: ACCOUNT_ID,
                custom_variables: body.custom_variables || [], installments: body.months || 1,
                payer_cpf_cnpj: body.payer && body.payer.cpf_cnpj,
            });
            if (approved) return send(200, { message: 'Autorizado', errors: {}, status: 'captured', success: true, url: `https://faturas.iugu.com/${id}`, pdf: `https://faturas.iugu.com/${id}.pdf`, identification: null, invoice_id: id, LR: '00' });
            return send(200, { message: 'Transação negada', errors: {}, status: 'unauthorized', success: false, url: `https://faturas.iugu.com/${id}`, pdf: `https://faturas.iugu.com/${id}.pdf`, identification: null, invoice_id: id, LR: tok.outcome });
        }
        const mInv = req.url.match(/^\/v1\/invoices\/([A-Za-z0-9_-]+)(\/refund)?$/);
        if (mInv) {
            const inv = iugu.invoices.get(mInv[1]);
            if (!inv) return send(404, { errors: 'Invoice not found' });
            if (req.method === 'GET' && !mInv[2]) return send(200, inv);
            if (req.method === 'POST' && mInv[2]) {
                if (inv.status !== 'paid') return send(400, { errors: 'Fatura não pode ser reembolsada' });
                const partial = body && body.partial_value_refund_cents;
                const refundable = inv.total_paid_cents - inv.refunded_cents;
                const cents = partial ? partial : refundable;
                if (cents > refundable) return send(400, { errors: 'Valor de reembolso maior que o valor pago' });
                inv.refunded_cents += cents;
                if (inv.refunded_cents >= inv.total_paid_cents) inv.status = 'refunded';
                return send(200, inv);
            }
        }
        return send(404, { errors: 'Not found' });
    });
}

// ---------------------------------------------------------------------------------------------
// Infrastructure: TLS for api.iugu.com, CONNECT proxy, WHMCS simulator root, php -S
// ---------------------------------------------------------------------------------------------
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'iugucc-whmcs-sim-'));
const ssl = (args) => execFileSync('openssl', args, { cwd: tmp, stdio: 'pipe' });
ssl(['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'ca.key', '-out', 'ca.pem', '-days', '2', '-subj', '/CN=iugucc-sim-ca']);
ssl(['req', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'srv.key', '-out', 'srv.csr', '-subj', '/CN=api.iugu.com']);
fs.writeFileSync(path.join(tmp, 'ext.cnf'), 'subjectAltName=DNS:api.iugu.com\n');
ssl(['x509', '-req', '-in', 'srv.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial', '-out', 'srv.pem', '-days', '2', '-extfile', 'ext.cnf']);

const simRoot = path.join(tmp, 'whmcs');
fs.cpSync(path.join(__dirname, 'whmcs_sim', 'stubs'), simRoot, { recursive: true });
fs.cpSync(path.join(ROOT, 'modules'), path.join(simRoot, 'modules'), { recursive: true });
const stateFile = path.join(tmp, 'state.json');

const listen = (srv) => new Promise((r) => srv.listen(0, '127.0.0.1', () => r(srv.address().port)));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
    const iuguServer = https.createServer({ key: fs.readFileSync(path.join(tmp, 'srv.key')), cert: fs.readFileSync(path.join(tmp, 'srv.pem')) }, iuguHandler);
    const iuguPort = await listen(iuguServer);
    const foreignHosts = [];
    const proxy = http.createServer((req, res) => { res.writeHead(405); res.end(); });
    proxy.on('connect', (req, clientSocket, head) => {
        if (req.url !== 'api.iugu.com:443') { foreignHosts.push(req.url); clientSocket.end('HTTP/1.1 403 Forbidden\r\n\r\n'); return; }
        const upstream = net.connect(iuguPort, '127.0.0.1', () => {
            clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
            upstream.write(head); upstream.pipe(clientSocket); clientSocket.pipe(upstream);
        });
        upstream.on('error', () => clientSocket.destroy()); clientSocket.on('error', () => upstream.destroy());
    });
    const proxyPort = await listen(proxy);

    const probe = net.createServer(); const whmcsPort = await listen(probe); await new Promise((r) => probe.close(r));
    const base = `http://127.0.0.1:${whmcsPort}`;
    const env = { ...process.env, WHMCS_SIM_STATE: stateFile, HTTPS_PROXY: `http://127.0.0.1:${proxyPort}`, https_proxy: `http://127.0.0.1:${proxyPort}`, NO_PROXY: '', no_proxy: '' };
    delete env.SSL_CERT_FILE; delete env.CURL_CA_BUNDLE;
    const ca = path.join(tmp, 'ca.pem');

    // ---- state helpers ---------------------------------------------------------------------
    const readState = () => JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    const mutate = (fn) => { const s = readState(); fn(s); fs.writeFileSync(stateFile, JSON.stringify(s)); };
    const baseSettings = { accountId: ACCOUNT_ID, apiToken: '', sandboxApiToken: TEST_API_TOKEN, sandboxMode: 'on', maxInstallments: '12', minInstallmentValue: '5.00', invoiceDescription: 'Fatura #{invoice_id} - imovelsite.com.br', statementDescriptor: 'IMOVELSITE', cpfCustomField: 'CPF/CNPJ', webhookSecret: '', debugLogging: 'on', sentinelMode: 'on' };
    const resetState = () => fs.writeFileSync(stateFile, JSON.stringify({
        system: { companyname: 'ImovelSite', systemurl: base + '/', whmcsVersion: '8.13.7' },
        gateways: { iugucc: { active: true, settings: { ...baseSettings } } },
        tblclients: { 42: { id: 42, firstname: 'Joao', lastname: 'Silva', email: 'cliente@imovelsite.com.br', address1: 'Rua Exemplo, 123', address2: 'Centro', city: 'Sao Paulo', state: 'SP', postcode: '01001-000', country: 'BR', phonenumber: '+55.11987654321', tax_id: '529.982.247-25', credit: 0, language: 'portuguese-br' } },
        tblinvoices: {}, tblaccounts: [], tblhosting: {}, tblgatewaylog: [],
    }));
    let nextInvoice = 1000;
    const addInvoice = (total, opts = {}) => {
        const id = ++nextInvoice;
        mutate((s) => {
            s.tblinvoices[id] = { id, userid: 42, status: opts.status || 'Unpaid', total, paymentmethod: 'iugucc', items: opts.items || [{ type: 'Hosting', relid: id, amount: total, description: 'Plano imovelsite' }] };
            if (!opts.items) s.tblhosting[id] = { userid: 42, domain: `site${id}.imovelsite.com.br`, domainstatus: 'Pending' };
        });
        return id;
    };

    // ---- HTTP helpers (what the browser / Iugu / the admin would do) -------------------------
    const view = async (id) => {
        const res = await fetch(`${base}/viewinvoice.php?id=${id}`);
        const html = await res.text();
        const sig = (html.match(/name="signature" value="([^"]*)"/) || [])[1] || '';
        const cfg = (html.match(/initCheckoutForm\((\{.*?\})\);/s) || [])[1];
        const options = [...html.matchAll(/<option value="(\d+)">\s*([^<]+?)\s*<\/option>/g)].map((m) => ({ n: Number(m[1]), label: m[2] }));
        return { html, signature: sig, config: cfg ? JSON.parse(cfg) : null, options, hasForm: html.includes('id="iugucc-payment-form"') };
    };
    const pay = async (id, signature, token, installments = 1, extra = {}) => {
        const res = await fetch(`${base}/modules/gateways/callback/iugucc.php`, {
            method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'checkout', invoice_id: String(id), iugu_token: token, installments, cpf_cnpj: '52998224725', signature, ...extra }),
        });
        const text = await res.text();
        let json = null; try { json = JSON.parse(text); } catch (e) { /* halted by WHMCS helper */ }
        return { status: res.status, json, text };
    };
    const webhook = async (event, data, query = '') => { // Iugu sends application/x-www-form-urlencoded
        const form = new URLSearchParams({ event });
        for (const [k, v] of Object.entries(data)) form.append(`data[${k}]`, String(v));
        const res = await fetch(`${base}/modules/gateways/callback/iugucc.php${query}`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form.toString() });
        const text = await res.text();
        let json = null; try { json = JSON.parse(text); } catch (e) { /* empty */ }
        return { status: res.status, json, text };
    };
    const adminRefund = async (invoiceid, transid, amount = '') => {
        const res = await fetch(`${base}/sim_admin_refund.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ invoiceid: String(invoiceid), transid, amount: String(amount) }).toString() });
        return { status: res.status, json: await res.json() };
    };
    const charges = () => iugu.requests.filter((r) => r.method === 'POST' && r.url === '/v1/charge');
    const txOf = (id) => readState().tblaccounts.filter((r) => r.invoiceid === id);

    resetState();
    const php = spawn(PHP_BIN, ['-d', `curl.cainfo=${ca}`, '-d', `openssl.cafile=${ca}`, '-d', 'display_errors=1', '-S', `127.0.0.1:${whmcsPort}`, '-t', simRoot], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    let phpLog = ''; php.stderr.on('data', (d) => { phpLog += d; }); php.stdout.on('data', (d) => { phpLog += d; });
    for (let i = 0; i < 50; i++) { try { await fetch(`${base}/viewinvoice.php?id=0`); break; } catch (e) { await sleep(100); } }
    const phpVersion = execFileSync(PHP_BIN, ['-r', 'echo PHP_VERSION;']).toString();

    console.log('\n======================================================================');
    console.log(` iugucc × WHMCS Billing simulation (real module files, PHP ${phpVersion})`);
    console.log('======================================================================');

    try {
        // ======================================================================================
        section('1. Fatura comum — cliente paga pela fatura (viewinvoice → checkout transparente)');
        const inv1 = addInvoice(150.00);
        const v1 = await view(inv1);
        assert(v1.hasForm && v1.signature.length === 64, 'viewinvoice.php renderiza o formulário via iugucc_link() com assinatura HMAC-SHA256');
        assert(v1.html.includes('<script src="https://js.iugu.com/v2"></script>'), 'Página carrega o Iugu.js v2 do host oficial');
        assert(v1.config && v1.config.accountId === ACCOUNT_ID && v1.config.sandboxMode === true && v1.config.invoiceId === inv1, 'Config JS recebe accountId, sandboxMode e invoiceId corretos');
        assert(v1.config.callbackUrl === `${base}/modules/gateways/callback/iugucc.php`, 'callbackUrl montado a partir do systemurl do WHMCS (com barra final tratada)', v1.config.callbackUrl);
        assert(v1.options.length === 12 && v1.options[2].label.includes('3x de R$ 50,00'), 'Parcelamento: 12 opções para R$ 150,00 (3x de R$ 50,00)', JSON.stringify(v1.options.slice(0, 3)));
        assert(v1.html.includes('value="529.982.247-25"') || v1.html.includes('value="52998224725"'), 'CPF do cliente (Tax ID nativo do WHMCS) pré-preenchido');

        const r1 = await pay(inv1, v1.signature, iugu.issueToken('approved'), 3);
        const s1 = readState();
        const c1 = charges().at(-1).body;
        assert(r1.status === 200 && r1.json.success === true && r1.json.lr_code === '00', 'Callback responde 200 success=true LR 00', r1.text.slice(0, 200));
        assert(r1.json.redirect_url === `${base}/viewinvoice.php?id=${inv1}&paymentsuccess=true`, 'redirect_url padrão WHMCS (viewinvoice.php?id=…&paymentsuccess=true)', r1.json.redirect_url);
        assert(s1.tblinvoices[inv1].status === 'Paid', 'Fatura marcada como Paid (addInvoicePayment)');
        assert(s1.tblhosting[inv1].domainstatus === 'Active', 'Serviço Pending ativado pela automação de fatura paga');
        const t1 = txOf(inv1);
        assert(t1.length === 1 && t1[0].transid === r1.json.transaction_id && t1[0].amountin === 150 && t1[0].fees === 4.49 && t1[0].gateway === 'iugucc', 'Transação única: transid = ID da fatura Iugu, valor 150,00, taxa 4,49, gateway iugucc', JSON.stringify(t1));
        assert(c1.months === 3 && c1.items[0].price_cents === 15000 && c1.order_id === `whmcs_${inv1}` && c1.keep_dunning === false, 'POST /v1/charge: months=3, price_cents=15000, order_id=whmcs_<fatura>, keep_dunning=false');
        assert(c1.payer.cpf_cnpj === '52998224725' && c1.payer.name === 'Joao Silva' && c1.payer.address.zip_code === '01001000' && c1.payer.phone_prefix === '11', 'payer: nome, CPF, CEP e DDD normalizados');
        assert(c1.custom_variables.some((v) => v.name === 'whmcs_invoice_id' && v.value === String(inv1)) && c1.custom_variables.some((v) => v.name === 'integration_module' && v.value === 'iugucc'), 'custom_variables: whmcs_invoice_id e integration_module=iugucc');
        assert(c1.soft_descriptor_light === 'IMOVELSITE' && c1.soft_descriptor_light.length <= 12, 'soft_descriptor_light dentro do limite de 12 caracteres');
        const log1 = JSON.stringify(s1.tblgatewaylog);
        assert(s1.tblgatewaylog.some((l) => l.result === 'Success' && l.gateway === 'iugucc') && !log1.includes(TEST_API_TOKEN), 'Gateway Log: entrada Success sem vazar o API token');
        assert((await view(inv1)).hasForm === false, 'Fatura Paid não exibe mais o botão/formulário de pagamento');

        // ======================================================================================
        section('2. Recusa da operadora e nova tentativa');
        const inv2 = addInvoice(299.90);
        const v2 = await view(inv2);
        const r2 = await pay(inv2, v2.signature, iugu.issueToken('51'), 1);
        assert(r2.status === 422 && r2.json.success === false && r2.json.lr_code === '51' && /insuficiente/i.test(r2.json.message), 'LR 51 → 422 com mensagem amigável de saldo/limite insuficiente', r2.text.slice(0, 200));
        assert(readState().tblinvoices[inv2].status === 'Unpaid' && txOf(inv2).length === 0, 'Fatura permanece Unpaid e sem transação após recusa');
        assert(readState().tblgatewaylog.some((l) => l.result === 'Declined'), 'Gateway Log registra Declined');
        const usedToken = iugu.issueToken('approved');
        const r2b = await pay(inv2, v2.signature, usedToken, 2);
        assert(r2b.json.success === true && readState().tblinvoices[inv2].status === 'Paid', 'Nova tentativa com outro cartão aprova e quita a fatura');
        assert(charges().at(-1).body.items[0].price_cents === 29990 && charges().at(-1).body.months === 2, 'Centavos exatos para R$ 299,90 (29990) em 2x');
        const inv2c = addInvoice(60.00);
        const r2c = await pay(inv2c, (await view(inv2c)).signature, usedToken, 1);
        assert(r2c.json.success === false && readState().tblinvoices[inv2c].status === 'Unpaid', 'Token Iugu já utilizado (uso único) é recusado sem baixar a fatura', r2c.text.slice(0, 200));

        // ======================================================================================
        section('3. Pagamento parcial anterior e crédito aplicado (saldo devedor)');
        const inv3 = addInvoice(250.00);
        mutate((s) => s.tblaccounts.push({ id: s.tblaccounts.length + 1, invoiceid: inv3, userid: 42, transid: 'BOLETO-PARCIAL-1', amountin: 100.00, amountout: 0, fees: 0, gateway: 'banktransfer' }));
        const v3 = await view(inv3);
        assert(v3.html.includes('R$ 150,00') && v3.options[0].label.includes('150,00'), 'Fatura de R$ 250,00 com R$ 100,00 já pagos oferece o saldo de R$ 150,00');
        const r3 = await pay(inv3, v3.signature, iugu.issueToken('approved'), 1);
        assert(r3.json.success === true && charges().at(-1).body.items[0].price_cents === 15000, 'Cobra exatamente o saldo restante (15000 centavos)');
        assert(readState().tblinvoices[inv3].status === 'Paid' && txOf(inv3).reduce((a, r) => a + r.amountin, 0) === 250, 'Fatura quitada: R$ 100,00 (outro gateway) + R$ 150,00 (iugucc)');
        const inv3b = addInvoice(150.00); // subtotal 200 − crédito 50 já refletido em total (padrão tblinvoices)
        const r3b = await pay(inv3b, (await view(inv3b)).signature, iugu.issueToken('approved'), 1);
        assert(r3b.json.success === true && charges().at(-1).body.items[0].price_cents === 15000 && readState().tblclients[42].credit === 0, 'Fatura com crédito aplicado cobra o total líquido e não gera crédito indevido');

        // ======================================================================================
        section('4. Mass Payment (fatura agrupadora) e Add Funds (depósito de crédito)');
        const a = addInvoice(80.00), b = addInvoice(70.00);
        const mass = addInvoice(150.00, { items: [{ type: 'Invoice', relid: a, amount: 80 }, { type: 'Invoice', relid: b, amount: 70 }] });
        const rM = await pay(mass, (await view(mass)).signature, iugu.issueToken('approved'), 1);
        const sM = readState();
        assert(rM.json.success === true && sM.tblinvoices[mass].status === 'Paid', 'Fatura de Mass Payment paga em uma única cobrança');
        assert(sM.tblinvoices[a].status === 'Paid' && sM.tblinvoices[b].status === 'Paid' && sM.tblhosting[a].domainstatus === 'Active' && sM.tblhosting[b].domainstatus === 'Active', 'Faturas agrupadas quitadas e serviços ativados pelo WHMCS');
        assert(charges().filter((c) => c.body.order_id === `whmcs_${mass}`).length === 1, 'Uma única cobrança na Iugu para o Mass Payment');
        const funds = addInvoice(100.00, { items: [{ type: 'AddFunds', relid: 0, amount: 100 }] });
        const rF = await pay(funds, (await view(funds)).signature, iugu.issueToken('approved'), 1);
        assert(rF.json.success === true && readState().tblinvoices[funds].status === 'Paid' && readState().tblclients[42].credit === 100, 'Add Funds: fatura paga e R$ 100,00 creditados no saldo do cliente');

        // ======================================================================================
        section('5. Parcelamento: limites do Admin e da Iugu');
        const inv5 = addInvoice(12.00);
        const v5 = await view(inv5);
        assert(v5.options.length === 2, 'R$ 12,00 com parcela mínima R$ 5,00 oferece só 1x e 2x', JSON.stringify(v5.options));
        await pay(inv5, v5.signature, iugu.issueToken('approved'), 12);
        assert(charges().at(-1).body.months === 1, 'Parcelas forjadas (12x) acima do permitido são reduzidas para 1x no servidor');
        mutate((s) => { s.gateways.iugucc.settings.maxInstallments = '3'; });
        const inv5b = addInvoice(900.00);
        const v5b = await view(inv5b);
        assert(v5b.options.length === 3, 'Parcelamento Máximo = 3 no Admin limita as opções a 3x');
        await pay(inv5b, v5b.signature, iugu.issueToken('approved'), 9);
        assert(charges().at(-1).body.months === 3, 'Pedido de 9x é limitado ao máximo configurado (3x)');
        mutate((s) => { s.gateways.iugucc.settings.maxInstallments = '12'; });
        const inv5c = addInvoice(0.50);
        const before5c = charges().length;
        const r5c = await pay(inv5c, (await view(inv5c)).signature, iugu.issueToken('approved'), 1);
        assert(r5c.json.success === false && charges().length === before5c && readState().tblinvoices[inv5c].status === 'Unpaid', 'Fatura abaixo de R$ 1,00 (mínimo Iugu de 100 centavos) não é enviada à API');

        // ======================================================================================
        section('6. Estados da fatura: Paid, Cancelled, Refunded, Collections, Draft, Payment Pending');
        const inv6 = addInvoice(120.00);
        const v6 = await view(inv6);
        await pay(inv6, v6.signature, iugu.issueToken('approved'), 1);
        const before6 = charges().length;
        const r6 = await pay(inv6, v6.signature, iugu.issueToken('approved'), 1);
        assert(r6.status === 200 && r6.json.success === true && r6.json.transaction_id === 'ALREADY_PAID' && charges().length === before6 && txOf(inv6).length === 1, 'Reenvio do formulário em fatura já paga: sem segunda cobrança, sem segunda transação');
        for (const status of ['Cancelled', 'Refunded', 'Collections', 'Draft', 'Payment Pending']) {
            const id = addInvoice(90.00);
            const v = await view(id); // assinatura obtida enquanto a fatura estava em aberto
            mutate((s) => { s.tblinvoices[id].status = status; });
            const before = charges().length;
            const r = await pay(id, v.signature, iugu.issueToken('approved'), 1);
            assert(r.status === 409 && r.json && r.json.success === false && r.json.lr_code === 'INVOICE_NOT_PAYABLE' && charges().length === before && txOf(id).length === 0, `Fatura ${status}: cobrança recusada (409) mesmo com assinatura válida capturada antes`, `http ${r.status} ${r.text.slice(0, 120)}`);
            assert((await view(id)).hasForm === false, `Fatura ${status}: sem botão de pagamento na fatura`);
        }

        // ======================================================================================
        section('7. Segurança do callback (assinatura, PCI, fatura inexistente, módulo inativo)');
        const inv7 = addInvoice(200.00);
        const v7 = await view(inv7);
        let before = charges().length;
        const r7a = await pay(inv7, 'f'.repeat(64), iugu.issueToken('approved'), 1);
        assert(r7a.status === 403 && charges().length === before, 'Assinatura forjada → 403, nenhuma cobrança');
        const other = addInvoice(10.00);
        const r7b = await pay(other, v7.signature, iugu.issueToken('approved'), 1);
        assert(r7b.status === 403 && charges().length === before, 'Assinatura de outra fatura (troca de invoice_id) → 403');
        mutate((s) => { s.tblinvoices[inv7].total = 20.00; });
        const r7c = await pay(inv7, v7.signature, iugu.issueToken('approved'), 1);
        assert(r7c.status === 403 && charges().length === before, 'Valor da fatura alterado após renderizar invalida a assinatura (403)');
        mutate((s) => { s.tblinvoices[inv7].total = 200.00; });
        const r7d = await pay(inv7, v7.signature, '', 1, { number: '4111111111111111', verification_value: '123' });
        const log7 = JSON.stringify(readState().tblgatewaylog);
        assert(r7d.json.lr_code === 'PCI_BLOCKED' && charges().length === before && !log7.includes('4111111111111111'), 'PAN/CVV enviados ao servidor são bloqueados (PCI_BLOCKED) e nunca gravados no Gateway Log');
        const r7e = await pay(999999, v7.signature, iugu.issueToken('approved'), 1);
        assert(charges().length === before && readState().tblgatewaylog.some((l) => l.result === 'Invoice ID Not Found'), 'Fatura inexistente: checkCbInvoiceID interrompe a execução, sem cobrança', `http ${r7e.status}`);
        const r7f = await pay(0, v7.signature, iugu.issueToken('approved'), 1);
        assert(r7f.status === 400, 'invoice_id inválido → 400');
        mutate((s) => { s.gateways.iugucc.active = false; });
        const r7g = await pay(inv7, v7.signature, iugu.issueToken('approved'), 1);
        assert(r7g.status === 503 && charges().length === before, 'Módulo desativado no WHMCS → 503 (padrão "Module Not Activated")');
        mutate((s) => { s.gateways.iugucc.active = true; });
        assert(foreignHosts.length === 0, 'O módulo só abriu conexões para api.iugu.com', JSON.stringify(foreignHosts));

        // ======================================================================================
        section('8. Gatilhos (webhooks) da Iugu — form-urlencoded, como a Iugu envia');
        const w1 = await webhook('invoice.status_changed', { id: r1.json.transaction_id, status: 'paid', account_id: ACCOUNT_ID, payment_method: 'iugu_credit_card', paid_cents: 15000, order_id: `whmcs_${inv1}` });
        assert(w1.status === 200 && w1.json.action === 'noop_already_recorded' && txOf(inv1).length === 1, 'Gatilho após baixa síncrona: idempotente, sem pagamento duplicado', w1.text.slice(0, 160));
        const inv8 = addInvoice(180.00);
        const iuguAsync = iugu.createPaidInvoice(18000, [['whmcs_invoice_id', String(inv8)], ['integration_module', 'iugucc']], `whmcs_${inv8}`);
        const w2 = await webhook('invoice.status_changed', { id: iuguAsync, status: 'paid', account_id: ACCOUNT_ID });
        const s8 = readState();
        assert(w2.json.action === 'payment_recorded' && s8.tblinvoices[inv8].status === 'Paid' && s8.tblhosting[inv8].domainstatus === 'Active', 'Gatilho chega antes do retorno síncrono: fatura baixada e serviço ativado');
        assert(txOf(inv8).length === 1 && txOf(inv8)[0].amountin === 180 && txOf(inv8)[0].fees === 4.49, 'Valor (total_paid_cents) e taxa (taxes_paid_cents) vêm da consulta autoritativa GET /v1/invoices/{id}');
        const w2b = await webhook('invoice.status_changed', { id: iuguAsync, status: 'paid', account_id: ACCOUNT_ID });
        assert(w2b.json.action === 'noop_already_recorded' && txOf(inv8).length === 1, 'Reenvio do mesmo gatilho não duplica o pagamento');
        const w3 = await webhook('invoice.status_changed', { id: hex32(), status: 'paid', account_id: ACCOUNT_ID });
        assert(w3.status === 404, 'Gatilho forjado com ID inexistente na Iugu → 404, nada é baixado');
        const inv8b = addInvoice(75.00);
        const spoofId = iugu.createPaidInvoice(7500, [['whmcs_invoice_id', String(inv8b)]]);
        const w4 = await webhook('invoice.status_changed', { id: spoofId, status: 'paid', account_id: 'F'.repeat(32) });
        assert(w4.status === 403 && readState().tblinvoices[inv8b].status === 'Unpaid', 'Gatilho com account_id de outra conta → 403');
        const boleto = iugu.createPaidInvoice(11960, [['id-cliente', '77'], ['id-fatura', String(inv8b)]]);
        const w5 = await webhook('invoice.status_changed', { id: boleto, status: 'paid', account_id: ACCOUNT_ID, payment_method: 'iugu_bank_slip' });
        assert(w5.status === 200 && w5.json.action === 'ignored_foreign_invoice' && readState().tblinvoices[inv8b].status === 'Unpaid', 'Fatura do módulo de boleto existente (variáveis id-cliente/id-fatura) é ignorada: zero interferência');
        const w6 = await webhook('invoice.status_changed', { status: 'paid', account_id: ACCOUNT_ID });
        assert(w6.status === 400, 'Gatilho sem data[id] → 400');
        mutate((s) => { s.gateways.iugucc.settings.webhookSecret = 'segredo-webhook-123'; });
        const inv8c = addInvoice(40.00);
        const idc = iugu.createPaidInvoice(4000, [['whmcs_invoice_id', String(inv8c)]]);
        const w7 = await webhook('invoice.status_changed', { id: idc, status: 'paid', account_id: ACCOUNT_ID });
        assert(w7.status === 401 && readState().tblinvoices[inv8c].status === 'Unpaid', 'Segredo de webhook configurado: sem token → 401');
        const w8 = await webhook('invoice.status_changed', { id: idc, status: 'paid', account_id: ACCOUNT_ID }, '?webhook_token=segredo-webhook-123');
        assert(w8.status === 200 && readState().tblinvoices[inv8c].status === 'Paid', 'Com ?webhook_token= correto na URL do gatilho → pagamento registrado');
        mutate((s) => { s.gateways.iugucc.settings.webhookSecret = ''; });

        // ======================================================================================
        section('9. Reembolso pelo Admin (Refund through Gateway) total e parcial');
        const inv9 = addInvoice(300.00);
        const r9 = await pay(inv9, (await view(inv9)).signature, iugu.issueToken('approved'), 1);
        const ref1 = await adminRefund(inv9, r9.json.transaction_id, 50.00);
        const refundReq = iugu.requests.filter((q) => q.url.endsWith('/refund')).at(-1);
        assert(ref1.json.status === 'success' && refundReq.body.partial_value_refund_cents === 5000, 'Reembolso parcial R$ 50,00 → partial_value_refund_cents=5000', JSON.stringify(ref1.json).slice(0, 160));
        assert(readState().tblinvoices[inv9].status === 'Paid' && iugu.invoices.get(r9.json.transaction_id).status === 'paid', 'Reembolso parcial não altera o status da fatura (WHMCS e Iugu)');
        const ref2 = await adminRefund(inv9, r9.json.transaction_id, 250.00);
        assert(ref2.json.status === 'success' && iugu.invoices.get(r9.json.transaction_id).status === 'refunded' && readState().tblinvoices[inv9].status === 'Refunded', 'Reembolso do restante: fatura Iugu refunded e fatura WHMCS Refunded');
        const ref3 = await adminRefund(inv9, r9.json.transaction_id, 10.00);
        assert(ref3.json.status === 'declined', 'Reembolso além do valor pago é recusado pela Iugu e devolvido como declined', JSON.stringify(ref3.json).slice(0, 160));
        const inv9b = addInvoice(88.00);
        const r9b = await pay(inv9b, (await view(inv9b)).signature, iugu.issueToken('approved'), 1);
        const ref4 = await adminRefund(inv9b, r9b.json.transaction_id, '');
        assert(ref4.json.status === 'success' && readState().tblinvoices[inv9b].status === 'Refunded', 'Reembolso total (valor em branco no Admin) → Refunded');
        const w9 = await webhook('invoice.refund', { id: r9b.json.transaction_id, status: 'refunded', account_id: ACCOUNT_ID });
        assert(w9.status === 200 && w9.json.action === 'status_logged_refunded' && readState().tblinvoices[inv9b].status === 'Refunded', 'Gatilho invoice.refund é registrado no Gateway Log sem efeitos colaterais');

        // ======================================================================================
        section('10. Contestação (chargeback) e limites do tipo Third-Party');
        const inv10 = addInvoice(110.00);
        const r10 = await pay(inv10, (await view(inv10)).signature, iugu.issueToken('approved'), 1);
        iugu.invoices.get(r10.json.transaction_id).status = 'chargeback';
        const w10 = await webhook('invoice.status_changed', { id: r10.json.transaction_id, status: 'chargeback', account_id: ACCOUNT_ID });
        assert(w10.status === 200 && w10.json.action === 'status_logged_chargeback' && readState().tblgatewaylog.some((l) => /Chargeback/i.test(l.result)), 'Chargeback é registrado no Gateway Log para ação manual do financeiro');
        assert(readState().tblinvoices[inv10].status === 'Paid', 'LIMITAÇÃO documentada: chargeback não reverte a fatura automaticamente (usar Refund → Reverse Payment no Admin)');
        const gw = fs.readFileSync(path.join(ROOT, 'modules/gateways/iugucc.php'), 'utf8');
        assert(/function iugucc_link\(/.test(gw) && !/function iugucc_capture\(/.test(gw) && !/function iugucc_storeremote\(/.test(gw) && !/function iugucc_remoteinput\(/.test(gw), 'Tipo Third-Party: sem _capture/_storeremote/_remoteinput → Attempt Capture, cobrança automática por cron e Pay Methods não se aplicam');
        assert(/'TokenisedStorage' => false/.test(gw) && /'DisableLocalCreditCardInput' => true/.test(gw), 'MetaData coerente: cartão nunca armazenado localmente nem tokenizado no WHMCS');
    } finally {
        php.kill();
        await new Promise((r) => iuguServer.close(r));
        await new Promise((r) => proxy.close(r));
        // Only diagnostics raised by code under the simulated WHMCS root count (module + stubs).
        // Interpreter start-up notices ("... in Unknown on line 0", e.g. JIT vs. a debugger extension on CI) do not.
        const phpDiagnostics = phpLog.split('\n').filter((l) => /PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/.test(l) && l.includes(simRoot));
        if (phpDiagnostics.length > 0) {
            failed++; failures.push('PHP emitted warnings/errors from module or simulator code');
            console.error('\n--- PHP diagnostics ---\n' + phpDiagnostics.slice(0, 15).join('\n'));
        } else {
            passed++; console.log('\n  \x1b[32m✓\x1b[0m Nenhum Warning/Notice/Deprecated/Fatal do PHP vindo do módulo ou do simulador');
        }
        fs.rmSync(tmp, { recursive: true, force: true });
    }

    console.log('\n======================================================================');
    console.log(` WHMCS FINANCE SIMULATION SUMMARY: ${passed} passed, ${failed} failed (PHP ${phpVersion})`);
    console.log('======================================================================');
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); try { fs.rmSync(tmp, { recursive: true, force: true }); } catch (x) { /* ignore */ } process.exit(1); });
