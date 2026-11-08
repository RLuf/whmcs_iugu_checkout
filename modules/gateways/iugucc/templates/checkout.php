<?php
/**
 * PCI-DSS Compliant Transparent Credit Card Checkout Template
 *
 * Rendered by `iugucc_link($params)` on WHMCS `viewinvoice.php`.
 * Sensitive inputs (`number`, `verification_value`, `expiration`, `full_name`)
 * purposefully omit the `name` attribute so the browser can never transmit raw
 * card details to the WHMCS server.
 *
 * @var array<string, string> $lang
 * @var array<int, array{installments: int, installment_amount: float, formatted: string}> $installmentOptions
 * @var string $accountId
 * @var bool   $isSandbox
 * @var int    $invoiceId
 * @var string $callbackUrl
 * @var string $assetBaseUrl
 * @var string $signature
 * @var string $defaultHolderName
 * @var string $defaultCpfCnpj
 * @var string $iuguJsUrl
 */

declare(strict_types=1);

$esc = static fn(string $val): string => htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $esc($assetBaseUrl) ?>/assets/css/iugucc.css?v=1.1.0">

<div class="iugucc-checkout-wrapper" id="iugucc-checkout-container">
    <div class="iugucc-header">
        <div>
            <h4 class="iugucc-title"><?= $esc($lang['title']) ?></h4>
            <p class="iugucc-subtitle"><?= $esc($lang['subtitle']) ?></p>
        </div>
        <span class="iugucc-brand-badge" id="iugucc-brand-badge">CARTÃO</span>
    </div>

    <?php if ($isSandbox): ?>
        <div class="iugucc-sandbox-banner" role="status">
            <span>&#9888;</span>
            <span><?= $esc($lang['sandbox_badge']) ?></span>
        </div>
    <?php endif; ?>

    <div id="iugucc-alert-box" class="iugucc-alert" role="alert" aria-live="polite"></div>

    <form id="iugucc-payment-form" method="post" action="<?= $esc($callbackUrl) ?>" autocomplete="off" novalidate>
        <input type="hidden" name="action" value="checkout">
        <input type="hidden" name="invoice_id" value="<?= $esc((string) $invoiceId) ?>">
        <input type="hidden" name="signature" value="<?= $esc($signature) ?>">
        <input type="hidden" name="iugu_token" id="iugucc-token-input" value="">

        <div class="iugucc-field-group">
            <label class="iugucc-label" for="iugucc-card-number"><?= $esc($lang['card_number_label']) ?></label>
            <input
                type="text"
                id="iugucc-card-number"
                class="iugucc-input"
                data-iugu="number"
                inputmode="numeric"
                 autocomplete="cc-number"
                maxlength="23"
                placeholder="<?= $esc($lang['card_number_placeholder']) ?>"
                required
            >
        </div>

        <div class="iugucc-field-group">
            <label class="iugucc-label" for="iugucc-card-holder"><?= $esc($lang['card_holder_label']) ?></label>
            <input
                type="text"
                id="iugucc-card-holder"
                class="iugucc-input"
                data-iugu="full_name"
                autocomplete="cc-name"
                maxlength="64"
                value="<?= $esc($defaultHolderName) ?>"
                placeholder="<?= $esc($lang['card_holder_placeholder']) ?>"
                required
            >
        </div>

        <div class="iugucc-row">
            <div class="iugucc-col-half iugucc-field-group">
                <label class="iugucc-label" for="iugucc-card-exp"><?= $esc($lang['card_expiration_label']) ?></label>
                <input
                    type="text"
                    id="iugucc-card-exp"
                    class="iugucc-input"
                    data-iugu="expiration"
                    inputmode="numeric"
                    autocomplete="cc-exp"
                    maxlength="7"
                    placeholder="<?= $esc($lang['card_expiration_placeholder']) ?>"
                    required
                >
            </div>

            <div class="iugucc-col-half iugucc-field-group">
                <label class="iugucc-label" for="iugucc-card-cvv"><?= $esc($lang['card_cvv_label']) ?></label>
                <input
                    type="password"
                    id="iugucc-card-cvv"
                    class="iugucc-input"
                    data-iugu="verification_value"
                    inputmode="numeric"
                    autocomplete="cc-csc"
                    maxlength="4"
                    placeholder="<?= $esc($lang['card_cvv_placeholder']) ?>"
                    required
                >
            </div>
        </div>

        <div class="iugucc-field-group">
            <label class="iugucc-label" for="iugucc-cpf-cnpj"><?= $esc($lang['cpf_cnpj_label']) ?></label>
            <input
                type="text"
                id="iugucc-cpf-cnpj"
                name="cpf_cnpj"
                class="iugucc-input"
                data-iugucc-field="cpf_cnpj"
                inputmode="numeric"
                maxlength="18"
                value="<?= $esc($defaultCpfCnpj) ?>"
                placeholder="<?= $esc($lang['cpf_cnpj_placeholder']) ?>"
                required
            >
        </div>

        <div class="iugucc-field-group">
            <label class="iugucc-label" for="iugucc-installments"><?= $esc($lang['installments_label']) ?></label>
            <select
                id="iugucc-installments"
                name="installments"
                class="iugucc-select"
                data-iugucc-field="installments"
            >
                <?php foreach ($installmentOptions as $instNum => $instOption): ?>
                    <option value="<?= (int) $instNum ?>">
                        <?= $esc($instOption['formatted']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" id="iugucc-submit-btn" class="iugucc-submit-btn">
            <?= $esc($lang['pay_button']) ?>
        </button>
    </form>

    <div class="iugucc-security-footer">
        &#128274; <?= $esc($lang['security_notice']) ?>
    </div>
</div>

<script src="<?= $esc($iuguJsUrl) ?>"></script>
<script src="<?= $esc($assetBaseUrl) ?>/assets/js/iugucc.js?v=1.1.0"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.IuguCCCheckout && typeof window.IuguCCCheckout.initCheckoutForm === 'function') {
        window.IuguCCCheckout.initCheckoutForm(<?= json_encode([
            'formId' => 'iugucc-payment-form',
            'accountId' => $accountId,
            'sandboxMode' => $isSandbox,
            'invoiceId' => $invoiceId,
            'callbackUrl' => $callbackUrl,
            'signature' => $signature,
            'i18n' => $lang,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    }
});
</script>
