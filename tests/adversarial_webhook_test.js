#!/usr/bin/env node
/**
 * Adversarial Verification Suite: Webhook Processing, Forgery, Idempotency & Concurrency
 *
 * Stress-tests:
 * 1. Webhook Idempotency: Sequential duplicate delivery & bursts of 10 concurrent webhooks
 * 2. Webhook Forgery: Spoofed account_id, missing/invalid secret token, fake invoice_id,
 *    authoritative status mismatch (payload claims paid, Iugu API says pending),
 *    foreign invoice without WHMCS ID, and checkout HMAC signature forgery.
 * 3. Race Conditions: Concurrency between synchronous checkout return and asynchronous webhook.
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
 * Pure JavaScript Reference Implementation of WHMCS Iugu WebhookHandler
 * (100% faithful to modules/gateways/iugucc/lib/WebhookHandler.php)
 */
class WebhookHandlerSim {
    constructor(config, httpClient) {
        this.config = config;
        this.httpClient = httpClient;
    }

    async handle(payload, headers = {}, whmcsCallbacks = {}) {
        // 1. Verify optional webhook secret
        const expectedSecret = this.config.webhookSecret || '';
        if (expectedSecret !== '') {
            const providedSecret = this.extractWebhookSecret(headers, payload);
            if (!providedSecret || providedSecret !== expectedSecret) {
                this.logToWhmcs(whmcsCallbacks, { error: 'Invalid webhook secret token.' }, 'Webhook Unauthorized');
                return {
                    http_code: 401,
                    processed: false,
                    action: 'rejected_unauthorized',
                    whmcs_invoice_id: '',
                    iugu_invoice_id: '',
                    message: 'Unauthorized webhook secret.'
                };
            }
        }

        const event = (payload.event || '').trim();
        const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
        const iuguInvoiceId = String(data.id || payload.data_id || payload.id || '').trim();
        const webhookAccountId = String(data.account_id || payload.data_account_id || '').trim();

        if (!iuguInvoiceId) {
            return {
                http_code: 400,
                processed: false,
                action: 'invalid_payload',
                whmcs_invoice_id: '',
                iugu_invoice_id: '',
                message: 'Missing data[id] in webhook payload.'
            };
        }

        // 2. Verify account_id matches configured Iugu Account ID
        const configuredAccountId = (this.config.accountId || '').replace(/-/g, '').toUpperCase();
        const incomingAccountId = webhookAccountId.replace(/-/g, '').toUpperCase();
        if (configuredAccountId !== '' && incomingAccountId !== '' && configuredAccountId !== incomingAccountId) {
            this.logToWhmcs(whmcsCallbacks, { error: 'Webhook account_id mismatch.' }, 'Webhook Account Mismatch');
            return {
                http_code: 403,
                processed: false,
                action: 'rejected_account_mismatch',
                whmcs_invoice_id: '',
                iugu_invoice_id: iuguInvoiceId,
                message: 'Webhook account_id does not match configured Account ID.'
            };
        }

        // 3. Query authoritative invoice status directly from Iugu API
        let authoritativeInvoice;
        try {
            const invoiceResp = await this.httpClient.getInvoice(iuguInvoiceId);
            if (invoiceResp.http_code !== 200 || !invoiceResp.body || !invoiceResp.body.id) {
                this.logToWhmcs(whmcsCallbacks, { event, iugu_invoice_id: iuguInvoiceId }, 'Webhook Verification Failed');
                return {
                    http_code: 404,
                    processed: false,
                    action: 'invoice_not_found_on_iugu',
                    whmcs_invoice_id: '',
                    iugu_invoice_id: iuguInvoiceId,
                    message: 'Could not verify invoice on Iugu API.'
                };
            }
            authoritativeInvoice = invoiceResp.body;
        } catch (e) {
            return {
                http_code: 502,
                processed: false,
                action: 'api_error',
                whmcs_invoice_id: '',
                iugu_invoice_id: iuguInvoiceId,
                message: 'Temporary failure querying Iugu API.'
            };
        }

        // 4. Resolve WHMCS Invoice ID from authoritative Iugu invoice metadata
        const whmcsInvoiceId = WebhookHandlerSim.extractWhmcsInvoiceId(authoritativeInvoice, data);
        if (!whmcsInvoiceId) {
            this.logToWhmcs(whmcsCallbacks, { event, iugu_invoice_id: iuguInvoiceId }, 'Webhook Ignored (Foreign Invoice)');
            return {
                http_code: 200,
                processed: false,
                action: 'ignored_foreign_invoice',
                whmcs_invoice_id: '',
                iugu_invoice_id: iuguInvoiceId,
                message: 'Invoice is not linked to a WHMCS invoice ID.'
            };
        }

        const authoritativeStatus = String(authoritativeInvoice.status || '').toLowerCase().trim();

        // 5. Handle Paid status idempotently
        if (authoritativeStatus === 'paid') {
            if (whmcsCallbacks.isTransactionRecorded && whmcsCallbacks.isTransactionRecorded(iuguInvoiceId)) {
                this.logToWhmcs(whmcsCallbacks, { event, iugu_invoice_id: iuguInvoiceId }, 'Webhook Duplicate Ignored');
                return {
                    http_code: 200,
                    processed: true,
                    action: 'noop_already_recorded',
                    whmcs_invoice_id: whmcsInvoiceId,
                    iugu_invoice_id: iuguInvoiceId,
                    message: 'Transaction already recorded in WHMCS.'
                };
            }

            if (whmcsCallbacks.isInvoicePaid && whmcsCallbacks.isInvoicePaid(Number(whmcsInvoiceId))) {
                return {
                    http_code: 200,
                    processed: true,
                    action: 'noop_invoice_already_paid',
                    whmcs_invoice_id: whmcsInvoiceId,
                    iugu_invoice_id: iuguInvoiceId,
                    message: 'WHMCS invoice is already marked as Paid.'
                };
            }

            if (whmcsCallbacks.checkCbInvoiceID) {
                whmcsCallbacks.checkCbInvoiceID(Number(whmcsInvoiceId), 'iugucc');
            }

            if (whmcsCallbacks.checkCbTransID) {
                whmcsCallbacks.checkCbTransID(iuguInvoiceId);
            }

            const paidCents = Number(authoritativeInvoice.total_paid_cents || authoritativeInvoice.total_cents || 0);
            const feeCents = Number(authoritativeInvoice.taxes_paid_cents || 0);
            const paidAmount = paidCents > 0 ? Math.round((paidCents / 100) * 100) / 100 : 0.0;
            const feeAmount = feeCents > 0 ? Math.round((feeCents / 100) * 100) / 100 : 0.0;

            if (whmcsCallbacks.addInvoicePayment) {
                whmcsCallbacks.addInvoicePayment(Number(whmcsInvoiceId), iuguInvoiceId, paidAmount, feeAmount, 'iugucc');
            }

            this.logToWhmcs(whmcsCallbacks, { event, iugu_invoice_id: iuguInvoiceId }, 'Webhook Payment Recorded');

            return {
                http_code: 200,
                processed: true,
                action: 'payment_recorded',
                whmcs_invoice_id: whmcsInvoiceId,
                iugu_invoice_id: iuguInvoiceId,
                message: 'Invoice marked as Paid via Iugu webhook.'
            };
        }

        // 6. Non-paid lifecycle events
        this.logToWhmcs(whmcsCallbacks, { event, status: authoritativeStatus }, `Webhook Status: ${authoritativeStatus}`);
        return {
            http_code: 200,
            processed: true,
            action: `status_logged_${authoritativeStatus || 'unknown'}`,
            whmcs_invoice_id: whmcsInvoiceId,
            iugu_invoice_id: iuguInvoiceId,
            message: `Webhook event (${event} / ${authoritativeStatus}) logged.`
        };
    }

    static extractWhmcsInvoiceId(authoritativeInvoice, webhookData = {}) {
        if (authoritativeInvoice.custom_variables && Array.isArray(authoritativeInvoice.custom_variables)) {
            for (const v of authoritativeInvoice.custom_variables) {
                if (v && v.name === 'whmcs_invoice_id') {
                    const digits = String(v.value || '').replace(/\D/g, '');
                    if (digits) return digits;
                }
            }
        }
        const orderId = String(authoritativeInvoice.order_id || webhookData.order_id || '').trim();
        const match = orderId.match(/^whmcs_(\d+)$/i);
        if (match) return match[1];

        return String(webhookData.whmcs_invoice_id || '').replace(/\D/g, '');
    }

    extractWebhookSecret(headers, payload) {
        for (const [k, v] of Object.entries(headers)) {
            const lower = k.toLowerCase();
            if (['x-iugu-token', 'x-webhook-secret', 'authorization'].includes(lower)) {
                const val = String(v).trim();
                if (val.toLowerCase().startsWith('bearer ')) {
                    return val.substring(7).trim();
                }
                return val;
            }
        }
        return String(payload.webhook_token || payload.token || '').trim();
    }

    logToWhmcs(callbacks, data, status) {
        if (callbacks.logTransaction) {
            callbacks.logTransaction('iugucc', data, status);
        }
    }
}

/**
 * Pure JavaScript Reference Implementation of WHMCS Iugu ChargeProcessor
 * (100% faithful to modules/gateways/iugucc/lib/ChargeProcessor.php lines 101-273)
 */
class ChargeProcessorSim {
    constructor(config, httpClient, options = {}) {
        this.config = config;
        this.httpClient = httpClient;
        this.enableDefensiveCheck = options.enableDefensiveCheck || false;
    }

    async processCharge(requestData, invoiceId, amount, clientDetails, whmcsCallbacks = {}) {
        // 2. Prevent charging an already paid invoice
        if (whmcsCallbacks.isInvoicePaid && whmcsCallbacks.isInvoicePaid(invoiceId)) {
            return {
                success: true,
                invoice_id: String(invoiceId),
                transaction_id: 'ALREADY_PAID',
                lr_code: '00',
                message: 'This invoice is already paid.',
                fee: 0.0
            };
        }

        const iuguToken = String(requestData.iugu_token || '').trim();
        if (!iuguToken) {
            return {
                success: false,
                invoice_id: String(invoiceId),
                transaction_id: '',
                lr_code: 'MISSING_TOKEN',
                message: 'Payment token was not generated.',
                fee: 0.0
            };
        }

        const chargePayload = {
            token: iuguToken,
            email: clientDetails.email,
            months: requestData.installments || 1,
            order_id: `whmcs_${invoiceId}`
        };

        try {
            const apiResponse = await this.httpClient.charge(chargePayload);
            const { http_code: httpCode, body } = apiResponse;
            const chargeSuccess = Boolean(body && body.success);
            const lrCode = String(body.LR || '');
            const iuguInvoiceId = String(body.invoice_id || '');

            if (chargeSuccess && httpCode >= 200 && httpCode < 300 && iuguInvoiceId !== '') {
                let gatewayFee = 0.0;
                try {
                    const invResp = await this.httpClient.getInvoice(iuguInvoiceId);
                    if (invResp.http_code === 200 && invResp.body.taxes_paid_cents) {
                        gatewayFee = Math.round((invResp.body.taxes_paid_cents / 100) * 100) / 100;
                    }
                } catch (_) {}

                // If defensive check is enabled (proposed enhancement):
                if (this.enableDefensiveCheck) {
                    if (whmcsCallbacks.isTransactionRecorded && whmcsCallbacks.isTransactionRecorded(iuguInvoiceId)) {
                        return {
                            success: true,
                            invoice_id: String(invoiceId),
                            transaction_id: iuguInvoiceId,
                            lr_code: lrCode || '00',
                            message: 'Pagamento aprovado com sucesso.',
                            fee: gatewayFee
                        };
                    }
                }

                // WHMCS callback guards (ChargeProcessor.php lines 204-216)
                if (whmcsCallbacks.checkCbTransID) {
                    whmcsCallbacks.checkCbTransID(iuguInvoiceId);
                }

                if (whmcsCallbacks.addInvoicePayment) {
                    whmcsCallbacks.addInvoicePayment(Number(invoiceId), iuguInvoiceId, Number(amount), gatewayFee, 'iugucc');
                }

                return {
                    success: true,
                    invoice_id: String(invoiceId),
                    transaction_id: iuguInvoiceId,
                    lr_code: lrCode || '00',
                    message: 'Pagamento aprovado com sucesso.',
                    fee: gatewayFee
                };
            }

            return {
                success: false,
                invoice_id: String(invoiceId),
                transaction_id: iuguInvoiceId,
                lr_code: lrCode || 'DECLINED',
                message: 'Transaction declined.',
                fee: 0.0
            };
        } catch (e) {
            // Mirrors ChargeProcessor.php lines 252-272
            if (e.message && e.message.startsWith('WHMCS_CHECKCBTRANSID_EXIT')) {
                // In native WHMCS PHP, exit() halts execution; if caught by wrapper, produces exception
                return {
                    success: false,
                    invoice_id: String(invoiceId),
                    transaction_id: '',
                    lr_code: 'EXCEPTION',
                    message: 'WHMCS_TERMINATED_BY_CHECKCBTRANSID: Transaction already recorded.',
                    fee: 0.0
                };
            }
            return {
                success: false,
                invoice_id: String(invoiceId),
                transaction_id: '',
                lr_code: 'EXCEPTION',
                message: 'Não foi possível comunicar com a processadora de pagamentos no momento.',
                fee: 0.0
            };
        }
    }
}

/**
 * Executes adversarial test harness against Mock HTTP server
 */
async function runAdversarialTests() {
    console.log('\n======================================================================');
    console.log(' WHMCS Iugu Gateway (iugucc) — Empirical Adversarial Challenge Suite');
    console.log('======================================================================\n');

    // Dynamic Mock Iugu API Server
    const dynamicInvoices = {
        'IUGU_INV_PAID_001': {
            id: 'IUGU_INV_PAID_001',
            status: 'paid',
            total_cents: 15000,
            total_paid_cents: 15000,
            taxes_paid_cents: 449,
            order_id: 'whmcs_1024',
            custom_variables: [{ name: 'whmcs_invoice_id', value: '1024' }]
        },
        'IUGU_INV_PENDING_002': {
            id: 'IUGU_INV_PENDING_002',
            status: 'pending',
            total_cents: 15000,
            taxes_paid_cents: 0,
            order_id: 'whmcs_1025',
            custom_variables: [{ name: 'whmcs_invoice_id', value: '1025' }]
        },
        'IUGU_INV_FOREIGN_003': {
            id: 'IUGU_INV_FOREIGN_003',
            status: 'paid',
            total_cents: 8000,
            taxes_paid_cents: 200,
            order_id: 'shopify_9999',
            custom_variables: [{ name: 'source', value: 'shopify' }] // NO whmcs_invoice_id
        },
        'IUGU_INV_REFUNDED_004': {
            id: 'IUGU_INV_REFUNDED_004',
            status: 'refunded',
            total_cents: 15000,
            taxes_paid_cents: 449,
            order_id: 'whmcs_1024',
            custom_variables: [{ name: 'whmcs_invoice_id', value: '1024' }]
        }
    };

    const server = http.createServer((req, res) => {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            const parsed = body ? JSON.parse(body) : null;

            if (req.method === 'POST' && req.url === '/v1/charge') {
                if (parsed.token === 'tok_approved') {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({
                        success: true,
                        invoice_id: 'IUGU_INV_PAID_001',
                        LR: '00'
                    }));
                    return;
                }
                res.writeHead(422, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, LR: '51' }));
                return;
            }

            if (req.method === 'GET' && req.url.startsWith('/v1/invoices/')) {
                const invId = req.url.split('/').pop();
                if (dynamicInvoices[invId]) {
                    res.writeHead(200, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify(dynamicInvoices[invId]));
                    return;
                }
                res.writeHead(404, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ errors: 'Not found' }));
                return;
            }

            res.writeHead(404);
            res.end();
        });
    });

    await new Promise(r => server.listen(0, '127.0.0.1', r));
    const port = server.address().port;

    const mockHttpClient = {
        async charge(payload) {
            const res = await fetch(`http://127.0.0.1:${port}/v1/charge`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            return { http_code: res.status, body: data };
        },
        async getInvoice(id) {
            const res = await fetch(`http://127.0.0.1:${port}/v1/invoices/${id}`);
            const data = await res.json();
            return { http_code: res.status, body: data };
        }
    };

    const config = {
        accountId: 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
        apiToken: 'test_token_live',
        sandboxApiToken: 'test_token_sandbox',
        webhookSecret: 'secret_token_whsec_123'
    };

    try {
        // ====================================================================
        // CHALLENGE 1: WEBHOOK IDEMPOTENCY
        // ====================================================================
        console.log('--- Challenge 1: Webhook Idempotency ---');

        const whmcsState = {
            invoices: {
                1024: { id: 1024, status: 'Unpaid', balance: 150.00 }
            },
            transactions: new Set(),
            paymentsCallCount: 0,
            paymentHistory: []
        };

        const callbacks = {
            isTransactionRecorded: (tx) => whmcsState.transactions.has(tx),
            isInvoicePaid: (id) => (whmcsState.invoices[id] && whmcsState.invoices[id].status === 'Paid'),
            checkCbInvoiceID: (id, gw) => true,
            checkCbTransID: (tx) => {
                // WHMCS native behavior: if transid exists, checkCbTransID terminates
                if (whmcsState.transactions.has(tx)) {
                    throw new Error(`WHMCS_CHECKCBTRANSID_EXIT: Transaction ${tx} already recorded.`);
                }
            },
            addInvoicePayment: (invId, transId, amt, fee, gw) => {
                whmcsState.paymentsCallCount++;
                whmcsState.transactions.add(transId);
                whmcsState.invoices[invId].status = 'Paid';
                whmcsState.paymentHistory.push({ invId, transId, amt, fee });
            },
            logTransaction: (gw, data, status) => {}
        };

        const handler = new WebhookHandlerSim(config, mockHttpClient);

        const validWebhookPayload = {
            event: 'invoice.status_changed',
            data: {
                id: 'IUGU_INV_PAID_001',
                account_id: 'A1B2C3D4E5F60718293A4B5C6D7E8F90',
                status: 'paid'
            }
        };
        const validHeaders = { Authorization: 'Bearer secret_token_whsec_123' };

        // Test 1.1: 1st delivery of webhook
        const res1 = await handler.handle(validWebhookPayload, validHeaders, callbacks);
        assert(
            res1.http_code === 200 && res1.action === 'payment_recorded' && whmcsState.paymentsCallCount === 1,
            'First webhook arrival marks invoice as Paid and calls addInvoicePayment exactly once'
        );

        // Test 1.2: 2nd sequential delivery of the SAME webhook
        const res2 = await handler.handle(validWebhookPayload, validHeaders, callbacks);
        assert(
            res2.http_code === 200 && res2.action === 'noop_already_recorded' && whmcsState.paymentsCallCount === 1,
            'Second delivery of same webhook is detected via isTransactionRecorded and returns noop_already_recorded without calling addInvoicePayment'
        );

        // Test 1.3: 3rd sequential delivery of the SAME webhook
        const res3 = await handler.handle(validWebhookPayload, validHeaders, callbacks);
        assert(
            res3.http_code === 200 && res3.action === 'noop_already_recorded' && whmcsState.paymentsCallCount === 1,
            'Third delivery of same webhook is also strictly idempotent'
        );

        // Test 1.4: Webhook when invoice is marked Paid but transaction set is cleared (e.g. status-only check)
        whmcsState.transactions.clear(); // Simulate transaction record check miss
        const res4 = await handler.handle(validWebhookPayload, validHeaders, callbacks);
        assert(
            res4.http_code === 200 && res4.action === 'noop_invoice_already_paid' && whmcsState.paymentsCallCount === 1,
            'Fallback idempotency guard isInvoicePaid prevents duplicate payment if transaction table record is not found'
        );

        // Test 1.5: Concurrent Burst: 10 webhooks fired simultaneously
        whmcsState.invoices[1024].status = 'Unpaid';
        whmcsState.transactions.clear();
        whmcsState.paymentsCallCount = 0;

        const burstPromises = Array.from({ length: 10 }).map(() =>
            handler.handle(validWebhookPayload, validHeaders, callbacks)
        );
        const burstResults = await Promise.all(burstPromises);
        const recordedCount = burstResults.filter(r => r.action === 'payment_recorded').length;
        const noopCount = burstResults.filter(r => r.action.startsWith('noop_')).length;

        assert(
            recordedCount === 1 && noopCount === 9 && whmcsState.paymentsCallCount === 1,
            `Concurrent burst of 10 identical webhooks results in exactly 1 payment_recorded and 9 no-ops (actual: ${recordedCount} payments, ${noopCount} no-ops)`
        );

        // ====================================================================
        // CHALLENGE 2: WEBHOOK FORGERY & SPOOFING ATTACKS
        // ====================================================================
        console.log('\n--- Challenge 2: Webhook Forgery & Spoofing Defenses ---');

        // Test 2.1: Attacker sends spoofed webhook with fake account_id
        const fakeAccountPayload = {
            event: 'invoice.status_changed',
            data: {
                id: 'IUGU_INV_PAID_001',
                account_id: 'EVIL_ATTACKER_ACCOUNT_99999999999',
                status: 'paid'
            }
        };
        const resFakeAccount = await handler.handle(fakeAccountPayload, validHeaders, callbacks);
        assert(
            resFakeAccount.http_code === 403 && resFakeAccount.action === 'rejected_account_mismatch',
            'Spoofed webhook with mismatched account_id is rejected with HTTP 403 Forbidden'
        );

        // Test 2.2: Attacker sends webhook without secret token
        const resNoSecret = await handler.handle(validWebhookPayload, {}, callbacks);
        assert(
            resNoSecret.http_code === 401 && resNoSecret.action === 'rejected_unauthorized',
            'Webhook with missing secret token is rejected with HTTP 401 Unauthorized'
        );

        // Test 2.3: Attacker sends webhook with wrong secret token
        const resBadSecret = await handler.handle(validWebhookPayload, { Authorization: 'Bearer evil_token_guess' }, callbacks);
        assert(
            resBadSecret.http_code === 401 && resBadSecret.action === 'rejected_unauthorized',
            'Webhook with invalid secret token is rejected with HTTP 401 Unauthorized'
        );

        // Test 2.4: Attacker provides secret via alternate header (X-Iugu-Token)
        const resAltHeader = await handler.handle(validWebhookPayload, { 'X-Iugu-Token': 'secret_token_whsec_123' }, callbacks);
        assert(
            resAltHeader.http_code === 200,
            'Valid webhook secret accepted via X-Iugu-Token header'
        );

        // Test 2.5: Attacker claims non-existent invoice is paid
        const fakeInvoicePayload = {
            event: 'invoice.status_changed',
            data: {
                id: 'IUGU_INV_NON_EXISTENT_999',
                account_id: config.accountId,
                status: 'paid'
            }
        };
        const preCallCount = whmcsState.paymentsCallCount;
        const resFakeInvoice = await handler.handle(fakeInvoicePayload, validHeaders, callbacks);
        assert(
            resFakeInvoice.http_code === 404 && resFakeInvoice.action === 'invoice_not_found_on_iugu' &&
            whmcsState.paymentsCallCount === preCallCount,
            'Fake invoice ID fails outbound Iugu API query (404) and never calls addInvoicePayment'
        );

        // Test 2.6: Attacker sends payload claiming status="paid", but authoritative Iugu API status is "pending"
        const pendingInvoicePayload = {
            event: 'invoice.status_changed',
            data: {
                id: 'IUGU_INV_PENDING_002',
                account_id: config.accountId,
                status: 'paid' // ATTACK: Lying payload!
            }
        };
        const resPending = await handler.handle(pendingInvoicePayload, validHeaders, callbacks);
        assert(
            resPending.http_code === 200 && resPending.action === 'status_logged_pending' &&
            whmcsState.paymentsCallCount === preCallCount,
            'Payload claiming "paid" when Iugu API reports "pending" is logged as pending and NEVER marks invoice Paid'
        );

        // Test 2.7: Foreign invoice from another store/app without WHMCS invoice ID
        const foreignInvoicePayload = {
            event: 'invoice.status_changed',
            data: {
                id: 'IUGU_INV_FOREIGN_003',
                account_id: config.accountId,
                status: 'paid'
            }
        };
        const resForeign = await handler.handle(foreignInvoicePayload, validHeaders, callbacks);
        assert(
            resForeign.http_code === 200 && resForeign.action === 'ignored_foreign_invoice' &&
            whmcsState.paymentsCallCount === preCallCount,
            'Authoritative invoice without whmcs_invoice_id is safely ignored with 200 OK ignored_foreign_invoice'
        );

        // Test 2.8: HMAC Signature Forgery verification for Synchronous Checkout
        function generateSignature(invId, clientId, amount, apiToken, accountId) {
            const secret = `${apiToken}|${accountId}`;
            const normalizedAmount = Number(amount).toFixed(2);
            return crypto.createHmac('sha256', secret).update(`${invId}|${clientId}|${normalizedAmount}`).digest('hex');
        }

        const validSig = generateSignature(1024, 42, 150.00, config.apiToken, config.accountId);
        const tamperedAmountSig = generateSignature(1024, 42, 1.00, config.apiToken, config.accountId);

        assert(
            validSig !== tamperedAmountSig,
            'HMAC-SHA256 signature changes when invoice amount is tampered'
        );

        function verifySignature(invId, clientId, amount, sig, apiToken, accountId) {
            const expected = generateSignature(invId, clientId, amount, apiToken, accountId);
            return crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(sig, 'hex'));
        }

        assert(
            verifySignature(1024, 42, 150.00, validSig, config.apiToken, config.accountId) === true,
            'Valid HMAC-SHA256 checkout signature is verified successfully in constant time'
        );
        assert(
            verifySignature(1024, 42, 1.00, validSig, config.apiToken, config.accountId) === false,
            'Tampered amount (R$ 1.00 instead of R$ 150.00) fails signature verification'
        );
        assert(
            verifySignature(9999, 42, 150.00, validSig, config.apiToken, config.accountId) === false,
            'Tampered invoice ID (9999 instead of 1024) fails signature verification'
        );

        // ====================================================================
        // CHALLENGE 3: RACE CONDITIONS (SYNC RETURN VS ASYNC WEBHOOK)
        // ====================================================================
        console.log('\n--- Challenge 3: Synchronous vs Asynchronous Concurrency & Races ---');

        const processor = new ChargeProcessorSim(config, mockHttpClient);

        // Scenario 3.1: Synchronous checkout succeeds first, Webhook arrives 50ms later
        whmcsState.invoices[1024].status = 'Unpaid';
        whmcsState.transactions.clear();
        whmcsState.paymentsCallCount = 0;

        const syncResult1 = await processor.processCharge(
            { iugu_token: 'tok_approved', installments: 1 },
            1024,
            150.00,
            { email: 'user@test.com' },
            callbacks
        );

        assert(
            syncResult1.success === true && whmcsState.paymentsCallCount === 1 && whmcsState.invoices[1024].status === 'Paid',
            'Scenario 3.1: Synchronous checkout records payment #1'
        );

        const webhookResult1 = await handler.handle(validWebhookPayload, validHeaders, callbacks);
        assert(
            webhookResult1.http_code === 200 && webhookResult1.action === 'noop_already_recorded' && whmcsState.paymentsCallCount === 1,
            'Scenario 3.1: Subsequent asynchronous webhook detects existing transaction and yields no-op (Total payments: 1)'
        );

        // Scenario 3.2: Webhook arrives FIRST and marks invoice Paid before browser returns
        whmcsState.invoices[1024].status = 'Unpaid';
        whmcsState.transactions.clear();
        whmcsState.paymentsCallCount = 0;

        const webhookResult2 = await handler.handle(validWebhookPayload, validHeaders, callbacks);
        assert(
            webhookResult2.http_code === 200 && webhookResult2.action === 'payment_recorded' && whmcsState.paymentsCallCount === 1,
            'Scenario 3.2: Asynchronous webhook arrives first and marks invoice as Paid'
        );

        // Now user's browser triggers sync checkout for this already-paid invoice
        const syncResult2 = await processor.processCharge(
            { iugu_token: 'tok_approved', installments: 1 },
            1024,
            150.00,
            { email: 'user@test.com' },
            callbacks
        );
        assert(
            syncResult2.success === true && syncResult2.transaction_id === 'ALREADY_PAID' && whmcsState.paymentsCallCount === 1,
            'Scenario 3.2: Sync checkout detects invoice is ALREADY_PAID before charging card, avoiding double charge and double payment'
        );

        // Scenario 3.3: TRUE RACE WINDOW — Charge submitted to Iugu, and Webhook finishes BEFORE Sync receives HTTP response
        // Under current code: WHMCS checkCbTransID terminates execution or throws exception
        whmcsState.invoices[1024].status = 'Unpaid';
        whmcsState.transactions.clear();
        whmcsState.paymentsCallCount = 0;

        let raceDoublePaymentHappened = false;
        const raceCallbacks = {
            ...callbacks,
            addInvoicePayment: (invId, transId, amt, fee, gw) => {
                if (whmcsState.transactions.has(transId)) {
                    raceDoublePaymentHappened = true;
                }
                callbacks.addInvoicePayment(invId, transId, amt, fee, gw);
            }
        };

        const [raceSyncRes, raceWebhookRes] = await Promise.all([
            processor.processCharge(
                { iugu_token: 'tok_approved', installments: 1 },
                1024,
                150.00,
                { email: 'user@test.com' },
                raceCallbacks
            ),
            handler.handle(validWebhookPayload, validHeaders, raceCallbacks)
        ]);

        assert(
            whmcsState.paymentsCallCount === 1 && !raceDoublePaymentHappened,
            `Scenario 3.3: WHMCS database integrity maintained: exactly 1 payment recorded (no double payment in tblaccounts)`
        );

        // Inspect sync result in race condition
        const syncTerminatedByCheckCbTransID = !raceSyncRes.success &&
            raceSyncRes.message.includes('WHMCS_TERMINATED_BY_CHECKCBTRANSID');

        assert(
            syncTerminatedByCheckCbTransID === true,
            'Scenario 3.3 (Finding): When webhook finishes while charge is in-flight, ChargeProcessor invokes checkCbTransID which halts execution instead of returning a graceful success redirect'
        );

        // Scenario 3.4: With Proposed Defensive check in ChargeProcessor (checking isTransactionRecorded before checkCbTransID)
        whmcsState.invoices[1024].status = 'Unpaid';
        whmcsState.transactions.clear();
        whmcsState.paymentsCallCount = 0;

        const defensiveProcessor = new ChargeProcessorSim(config, mockHttpClient, { enableDefensiveCheck: true });
        const [defensiveSyncRes, defensiveWebhookRes] = await Promise.all([
            defensiveProcessor.processCharge(
                { iugu_token: 'tok_approved', installments: 1 },
                1024,
                150.00,
                { email: 'user@test.com' },
                raceCallbacks
            ),
            handler.handle(validWebhookPayload, validHeaders, raceCallbacks)
        ]);

        assert(
            defensiveSyncRes.success === true && defensiveSyncRes.transaction_id === 'IUGU_INV_PAID_001' &&
            whmcsState.paymentsCallCount === 1,
            'Scenario 3.4 (Mitigation Proof): Checking isTransactionRecorded in ChargeProcessor yields BOTH zero double payment AND clean success redirect for the customer'
        );

    } finally {
        await new Promise(r => server.close(r));
    }

    console.log('\n======================================================================');
    console.log(` ADVERSARIAL TEST SUMMARY: ${passed} passed, ${failed} failed`);
    console.log('======================================================================\n');

    if (failed > 0) {
        process.exit(1);
    }
}

runAdversarialTests().catch(err => {
    console.error(err);
    process.exit(1);
});
