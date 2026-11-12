/**
 * WHMCS Iugu Credit Card Client-Side Tokenization Controller (`iugucc.js`)
 *
 * Uses official Iugu.js v2 (`https://js.iugu.com/v2`) to tokenize credit card details
 * directly in the customer's browser (PCI-DSS compliant).
 * Raw card numbers and CVVs are NEVER sent to the WHMCS server.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.IuguCCCheckout = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** Max time to wait for Iugu.js createPaymentToken before releasing the form (ms). */
    var TOKENIZATION_TIMEOUT_MS = 30000;

    /**
     * Strips all non-digit characters from a string.
     */
    function digitsOnly(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    /**
     * Standard Luhn checksum validation for credit card numbers.
     */
    function isValidLuhn(cardNumber) {
        var digits = digitsOnly(cardNumber);
        if (digits.length < 13 || digits.length > 19) {
            return false;
        }
        var sum = 0;
        var shouldDouble = false;
        for (var i = digits.length - 1; i >= 0; i--) {
            var digit = parseInt(digits.charAt(i), 10);
            if (shouldDouble) {
                digit *= 2;
                if (digit > 9) {
                    digit -= 9;
                }
            }
            sum += digit;
            shouldDouble = !shouldDouble;
        }
        return sum % 10 === 0;
    }

    /**
     * Detects card brand from BIN prefix.
     */
    function detectCardBrand(cardNumber) {
        var digits = digitsOnly(cardNumber);
        if (!digits) return 'CARTÃO';
        if (/^4/.test(digits)) return 'VISA';
        if (/^(5[1-5]|2[2-7])/.test(digits)) return 'MASTERCARD';
        if (/^3[47]/.test(digits)) return 'AMEX';
        if (/^(636368|438935|504175|451416|636297|5067|4576|4011|506699)/.test(digits)) return 'ELO';
        if (/^(606282|3841)/.test(digits)) return 'HIPERCARD';
        if (/^3(?:0[0-5]|[68])/.test(digits)) return 'DINERS';
        return 'CARTÃO';
    }

    /**
     * Formats card number in 4-digit groups.
     */
    function formatCardNumber(value) {
        var digits = digitsOnly(value).slice(0, 19);
        var parts = [];
        for (var i = 0; i < digits.length; i += 4) {
            parts.push(digits.slice(i, i + 4));
        }
        return parts.join(' ');
    }

    /**
     * Formats expiration date as MM/YY or MM/YYYY.
     */
    function formatExpiration(value) {
        var digits = digitsOnly(value).slice(0, 6);
        if (digits.length <= 2) {
            return digits;
        }
        return digits.slice(0, 2) + '/' + digits.slice(2);
    }

    /**
     * Validates expiration string (MM/YY or MM/YYYY) against a reference date.
     */
    function isValidExpiration(expirationStr, nowDate) {
        var clean = String(expirationStr || '').trim();
        var match = clean.match(/^(\d{2})\/(\d{2}|\d{4})$/);
        if (!match) return false;

        var month = parseInt(match[1], 10);
        var year = parseInt(match[2], 10);
        if (month < 1 || month > 12) return false;

        if (year < 100) {
            year += 2000;
        }

        var now = nowDate instanceof Date ? nowDate : new Date();
        var currentYear = now.getFullYear();
        var currentMonth = now.getMonth() + 1;

        if (year < currentYear) return false;
        if (year === currentYear && month < currentMonth) return false;
        if (year > currentYear + 25) return false;

        return true;
    }

    /**
     * Validates holder full name (requires at least first and last name).
     */
    function isValidHolderName(fullName) {
        var parts = String(fullName || '').trim().split(/\s+/);
        return parts.length >= 2 && parts[0].length >= 2 && parts[parts.length - 1].length >= 2;
    }

    /**
     * Validates CPF (11 digits) or CNPJ (14 digits) length.
     */
    function isValidCpfCnpj(value) {
        var digits = digitsOnly(value);
        return digits.length === 11 || digits.length === 14;
    }

    /**
     * Builds the strictly sanitized POST body to send to WHMCS callback endpoint.
     * Guarantees PAN and CVV are never included.
     */
    function buildServerSubmissionPayload(params) {
        return {
            action: 'checkout',
            invoice_id: String(params.invoiceId || ''),
            iugu_token: String(params.iuguToken || ''),
            installments: parseInt(params.installments || 1, 10) || 1,
            cpf_cnpj: digitsOnly(params.cpfCnpj || ''),
            signature: String(params.signature || '')
        };
    }

    /**
     * Mounts the interactive checkout behavior onto the DOM form.
     */
    function initCheckoutForm(config) {
        if (typeof document === 'undefined') return;
        var form = document.getElementById(config.formId || 'iugucc-payment-form');
        if (!form) return;

        var numberInput = form.querySelector('[data-iugu="number"]');
        var holderInput = form.querySelector('[data-iugu="full_name"]');
        var expInput = form.querySelector('[data-iugu="expiration"]');
        var cvvInput = form.querySelector('[data-iugu="verification_value"]');
        var cpfInput = form.querySelector('[data-iugucc-field="cpf_cnpj"]');
        var installmentsSelect = form.querySelector('[data-iugucc-field="installments"]');
        var brandBadge = document.getElementById('iugucc-brand-badge');
        var alertBox = document.getElementById('iugucc-alert-box');
        var submitBtn = document.getElementById('iugucc-submit-btn');

        function showAlert(message, type) {
            if (!alertBox) return;
            alertBox.textContent = message;
            alertBox.className = 'iugucc-alert is-visible ' + (type === 'success' ? 'iugucc-alert-success' : 'iugucc-alert-error');
        }

        function clearAlert() {
            if (!alertBox) return;
            alertBox.textContent = '';
            alertBox.className = 'iugucc-alert';
        }

        if (numberInput) {
            numberInput.addEventListener('input', function () {
                numberInput.value = formatCardNumber(numberInput.value);
                if (brandBadge) {
                    brandBadge.textContent = detectCardBrand(numberInput.value);
                }
            });
        }

        if (expInput) {
            expInput.addEventListener('input', function () {
                expInput.value = formatExpiration(expInput.value);
            });
        }

        if (cvvInput) {
            cvvInput.addEventListener('input', function () {
                cvvInput.value = digitsOnly(cvvInput.value).slice(0, 4);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            clearAlert();

            var i18n = config.i18n || {};
            var cardVal = numberInput ? numberInput.value : '';
            var holderVal = holderInput ? holderInput.value : '';
            var expVal = expInput ? expInput.value : '';
            var cvvVal = cvvInput ? cvvInput.value : '';
            var cpfVal = cpfInput ? cpfInput.value : '';

            if (!isValidLuhn(cardVal)) {
                showAlert(i18n.err_invalid_card_number || 'Cartão inválido.', 'error');
                return;
            }
            if (!isValidHolderName(holderVal)) {
                showAlert(i18n.err_invalid_holder || 'Informe o nome completo impresso no cartão.', 'error');
                return;
            }
            if (!isValidExpiration(expVal)) {
                showAlert(i18n.err_invalid_expiration || 'Data de validade inválida.', 'error');
                return;
            }
            if (digitsOnly(cvvVal).length < 3) {
                showAlert(i18n.err_invalid_cvv || 'CVV inválido.', 'error');
                return;
            }
            if (!isValidCpfCnpj(cpfVal)) {
                showAlert(i18n.err_invalid_cpf_cnpj || 'CPF/CNPJ inválido.', 'error');
                return;
            }

            if (typeof window.Iugu === 'undefined') {
                showAlert(i18n.err_tokenization_failed || 'Biblioteca Iugu.js não carregada.', 'error');
                return;
            }

            var originalBtnText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = i18n.processing_button || 'Processando...';
            }

            window.Iugu.setAccountID(config.accountId);
            window.Iugu.setTestMode(Boolean(config.sandboxMode));

            // Watchdog: Iugu.js v2 tokenizes through a JSONP <script> request. If that request fails
            // (API 5xx, network block), Iugu.js never calls back. Without this guard the customer would
            // stay on a disabled "Processando..." button forever.
            var tokenizationSettled = false;
            var watchdogMs = parseInt(config.tokenizationTimeoutMs || TOKENIZATION_TIMEOUT_MS, 10) || TOKENIZATION_TIMEOUT_MS;
            var watchdog = setTimeout(function () {
                if (tokenizationSettled) return;
                tokenizationSettled = true;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalBtnText;
                }
                showAlert(i18n.err_network || i18n.err_tokenization_failed || 'Erro de comunicação com o servidor.', 'error');
            }, watchdogMs);

            window.Iugu.createPaymentToken(form, function (tokenResponse) {
                if (tokenizationSettled) return; // late answer after watchdog fired: ignore (token would be unusable)
                tokenizationSettled = true;
                clearTimeout(watchdog);
                if (!tokenResponse || tokenResponse.errors) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                    showAlert(i18n.err_tokenization_failed || 'Verifique os dados do cartão informados.', 'error');
                    return;
                }

                var payload = buildServerSubmissionPayload({
                    invoiceId: config.invoiceId,
                    iuguToken: tokenResponse.id,
                    installments: installmentsSelect ? installmentsSelect.value : 1,
                    cpfCnpj: cpfVal,
                    signature: config.signature
                });

                fetch(config.callbackUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                })
                    .then(function (res) {
                        return res.json();
                    })
                    .then(function (result) {
                        if (result && result.success) {
                            showAlert(result.message || i18n.success_redirecting || 'Pagamento aprovado!', 'success');
                            var targetUrl = result.redirect_url || ('viewinvoice.php?id=' + encodeURIComponent(config.invoiceId) + '&paymentsuccess=true');
                            setTimeout(function () {
                                window.location.href = targetUrl;
                            }, 600);
                        } else {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = originalBtnText;
                            }
                            showAlert((result && result.message) || i18n.err_tokenization_failed || 'Pagamento recusado.', 'error');
                        }
                    })
                    .catch(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalBtnText;
                        }
                        showAlert(i18n.err_network || 'Erro de comunicação com o servidor.', 'error');
                    });
            });
        });
    }

    return {
        digitsOnly: digitsOnly,
        isValidLuhn: isValidLuhn,
        detectCardBrand: detectCardBrand,
        formatCardNumber: formatCardNumber,
        formatExpiration: formatExpiration,
        isValidExpiration: isValidExpiration,
        isValidHolderName: isValidHolderName,
        isValidCpfCnpj: isValidCpfCnpj,
        buildServerSubmissionPayload: buildServerSubmissionPayload,
        initCheckoutForm: initCheckoutForm,
        TOKENIZATION_TIMEOUT_MS: TOKENIZATION_TIMEOUT_MS
    };
});
