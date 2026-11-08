#!/usr/bin/env node
/**
 * Empirical Adversarial Challenge Suite (JavaScript Client-Side & API Boundary Stress)
 * for RLuf/whmcs_iugu_checkout (`iugucc`)
 *
 * Verifies client-side Iugu.js tokenization helper, Luhn validation boundaries,
 * brand identification, expiration bounds, and payload sanitization guarantees.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const IuguCCCheckout = require(path.join(
    ROOT,
    'modules',
    'gateways',
    'iugucc',
    'assets',
    'js',
    'iugucc.js'
));

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

async function runAdversarialJsSuite() {
    console.log('======================================================================');
    console.log(' WHMCS Iugu CC Gateway — Client-Side & JS Adversarial Challenge Suite');
    console.log('======================================================================\n');

    // ------------------------------------------------------------------------
    // SECTION 1: Luhn Validation Boundary & Adversarial Inputs
    // ------------------------------------------------------------------------
    console.log('Section 1: Luhn Validation Boundary & Adversarial Inputs');

    // Boundary lengths
    assert(IuguCCCheckout.isValidLuhn('411111111111') === false, 'Rejects 12-digit card (below minimum length 13)');
    assert(IuguCCCheckout.isValidLuhn('4111111111111111') === true, 'Accepts valid 16-digit Visa card');
    assert(IuguCCCheckout.isValidLuhn('41111111111111111111') === false, 'Rejects 20-digit card (above maximum length 19)');
    assert(IuguCCCheckout.isValidLuhn('') === false, 'Rejects empty card number');
    assert(IuguCCCheckout.isValidLuhn(null) === false, 'Rejects null card number without throwing');
    assert(IuguCCCheckout.isValidLuhn(undefined) === false, 'Rejects undefined card number without throwing');

    // Formatting tolerance: handles spaces, dashes, dots
    assert(IuguCCCheckout.isValidLuhn('4111-1111-1111-1111') === true, 'Accepts valid card formatted with hyphens');
    assert(IuguCCCheckout.isValidLuhn('4111 1111 1111 1111') === true, 'Accepts valid card formatted with spaces');
    assert(IuguCCCheckout.isValidLuhn('4111.1111.1111.1111') === true, 'Accepts valid card formatted with dots');

    // Adversarial injection strings
    assert(IuguCCCheckout.isValidLuhn("4111111111111111' OR 'a'='b") === true, 'Strips non-digits safely without SQL injection vulnerability');
    assert(IuguCCCheckout.isValidLuhn("4111111111111111' OR '1'='1") === false, 'Rejects injection string with extraneous digits that corrupt Luhn checksum');
    assert(IuguCCCheckout.isValidLuhn('<script>alert("xss")</script>4111111111111111') === true, 'Strips script tags and evaluates only raw digits safely');

    // Brand checks
    assert(IuguCCCheckout.detectCardBrand('4000123456789010') === 'VISA', 'Detects Visa brand (starts with 4)');
    assert(IuguCCCheckout.detectCardBrand('5105105105105100') === 'MASTERCARD', 'Detects Mastercard brand (starts with 51)');
    assert(IuguCCCheckout.detectCardBrand('2221000000000000') === 'MASTERCARD', 'Detects Mastercard 2-series BIN');
    assert(IuguCCCheckout.detectCardBrand('378282246310005') === 'AMEX', 'Detects Amex brand (starts with 37)');
    assert(IuguCCCheckout.detectCardBrand('6363680000000000') === 'ELO', 'Detects Elo BIN (starts with 636368)');
    assert(IuguCCCheckout.detectCardBrand('6062820000000000') === 'HIPERCARD', 'Detects Hipercard BIN (starts with 606282)');
    assert(IuguCCCheckout.detectCardBrand('30500000000000') === 'DINERS', 'Detects Diners BIN (starts with 305)');
    assert(IuguCCCheckout.detectCardBrand('9999999999999999') === 'CARTÃO', 'Falls back to generic CARTÃO on unknown BIN');
    assert(IuguCCCheckout.detectCardBrand('') === 'CARTÃO', 'Falls back to CARTÃO on empty input');

    // ------------------------------------------------------------------------
    // SECTION 2: Expiration Date Validation & Leap Year / Boundary Testing
    // ------------------------------------------------------------------------
    console.log('\nSection 2: Expiration Date Boundaries & Edge Cases');

    const refDate = new Date('2026-11-08T00:00:00Z'); // Current simulated time

    // Current month is valid
    assert(IuguCCCheckout.isValidExpiration('11/2026', refDate) === true, 'Current month/year (11/2026) is valid');
    assert(IuguCCCheckout.isValidExpiration('11/26', refDate) === true, '2-digit current month/year (11/26) is valid');

    // Future months are valid
    assert(IuguCCCheckout.isValidExpiration('12/2026', refDate) === true, 'Next month (12/2026) is valid');
    assert(IuguCCCheckout.isValidExpiration('01/2027', refDate) === true, 'Next year (01/2027) is valid');

    // Expired dates must be rejected
    assert(IuguCCCheckout.isValidExpiration('10/2026', refDate) === false, 'Past month (10/2026) is rejected');
    assert(IuguCCCheckout.isValidExpiration('12/2025', refDate) === false, 'Past year (12/2025) is rejected');
    assert(IuguCCCheckout.isValidExpiration('05/20', refDate) === false, '2-digit past year (05/20) is rejected');

    // Invalid months
    assert(IuguCCCheckout.isValidExpiration('00/2026', refDate) === false, 'Month 00 is rejected');
    assert(IuguCCCheckout.isValidExpiration('13/2026', refDate) === false, 'Month 13 is rejected');
    assert(IuguCCCheckout.isValidExpiration('99/2026', refDate) === false, 'Month 99 is rejected');

    // Outrageous far-future dates (> 25 years)
    assert(IuguCCCheckout.isValidExpiration('12/2060', refDate) === false, 'Far-future date (>25 years) is rejected');

    // Malformed formats
    assert(IuguCCCheckout.isValidExpiration('11-2026', refDate) === false, 'Rejects dash separator');
    assert(IuguCCCheckout.isValidExpiration('2026/11', refDate) === false, 'Rejects inverted year/month');
    assert(IuguCCCheckout.isValidExpiration('abc', refDate) === false, 'Rejects non-numeric string');
    assert(IuguCCCheckout.isValidExpiration('', refDate) === false, 'Rejects empty string');

    // ------------------------------------------------------------------------
    // SECTION 3: Cardholder Name & Document Validation
    // ------------------------------------------------------------------------
    console.log('\nSection 3: Cardholder Name & Document Validation');

    assert(IuguCCCheckout.isValidHolderName('JOAO SILVA') === true, 'Accepts valid two-word holder name');
    assert(IuguCCCheckout.isValidHolderName('MARIA DAS DORES SOUZA') === true, 'Accepts multi-word holder name');
    assert(IuguCCCheckout.isValidHolderName('JOAO') === false, 'Rejects single word holder name (Iugu requires first & last name)');
    assert(IuguCCCheckout.isValidHolderName('J S') === false, 'Rejects single-letter names');
    assert(IuguCCCheckout.isValidHolderName('') === false, 'Rejects empty holder name');

    // CPF / CNPJ length validation
    assert(IuguCCCheckout.isValidCpfCnpj('123.456.789-09') === true, 'Accepts 11-digit formatted CPF');
    assert(IuguCCCheckout.isValidCpfCnpj('12345678909') === true, 'Accepts 11-digit raw CPF');
    assert(IuguCCCheckout.isValidCpfCnpj('12.345.678/0001-99') === true, 'Accepts 14-digit formatted CNPJ');
    assert(IuguCCCheckout.isValidCpfCnpj('12345678000199') === true, 'Accepts 14-digit raw CNPJ');
    assert(IuguCCCheckout.isValidCpfCnpj('1234567890') === false, 'Rejects 10 digits (too short)');
    assert(IuguCCCheckout.isValidCpfCnpj('123456789012') === false, 'Rejects 12 digits (neither CPF nor CNPJ)');
    assert(IuguCCCheckout.isValidCpfCnpj('123456789012345') === false, 'Rejects 15 digits (too long)');

    // ------------------------------------------------------------------------
    // SECTION 4: Client-Side Submission Payload Sanitization (PCI-DSS)
    // ------------------------------------------------------------------------
    console.log('\nSection 4: Client-Side Submission Payload Sanitization (PCI-DSS)');

    const pollutedInput = {
        invoiceId: 1024,
        iuguToken: 'tok_test_single_use_token_999',
        installments: '3',
        cpfCnpj: '123.456.789-09',
        signature: 'hmac_sha256_sig_valid',
        // Accidental or malicious sensitive properties:
        number: '4111 1111 1111 1111',
        cvv: '123',
        verification_value: '123',
        expiration: '12/2030',
        card_holder: 'JOAO SILVA'
    };

    const cleanPayload = IuguCCCheckout.buildServerSubmissionPayload(pollutedInput);

    assert(cleanPayload.action === 'checkout', 'Payload contains action=checkout');
    assert(cleanPayload.invoice_id === '1024', 'Payload contains invoice_id');
    assert(cleanPayload.iugu_token === 'tok_test_single_use_token_999', 'Payload contains iugu_token');
    assert(cleanPayload.installments === 3, 'Payload normalizes installments to integer');
    assert(cleanPayload.cpf_cnpj === '12345678909', 'Payload normalizes cpf_cnpj to digits only');
    assert(cleanPayload.signature === 'hmac_sha256_sig_valid', 'Payload retains HMAC signature');

    // Strict PCI-DSS verification: NO sensitive keys must exist
    assert(cleanPayload.number === undefined, 'Payload strictly strips number');
    assert(cleanPayload.cvv === undefined, 'Payload strictly strips cvv');
    assert(cleanPayload.verification_value === undefined, 'Payload strictly strips verification_value');
    assert(cleanPayload.expiration === undefined, 'Payload strictly strips expiration');
    assert(cleanPayload.card_holder === undefined, 'Payload strictly strips card_holder');

    const allowedKeys = ['action', 'invoice_id', 'iugu_token', 'installments', 'cpf_cnpj', 'signature'];
    const extraKeys = Object.keys(cleanPayload).filter((k) => !allowedKeys.includes(k));
    assert(extraKeys.length === 0, `Payload contains only strictly allowed keys (extra: ${extraKeys.join(', ') || 'none'})`);

    console.log('\n======================================================================');
    console.log(` JS ADVERSARIAL SUMMARY: ${passed} passed, ${failed} failed`);
    console.log('======================================================================\n');

    if (failed > 0) {
        process.exit(1);
    }
}

runAdversarialJsSuite().catch((err) => {
    console.error(err);
    process.exit(1);
});
