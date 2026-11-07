<?php
/**
 * English Language File for WHMCS Iugu Credit Card Gateway (`iugucc`)
 */

declare(strict_types=1);

return [
    'title' => 'Pay with Credit Card',
    'subtitle' => 'Encrypted & tokenized checkout powered by Iugu (PCI-DSS)',
    'sandbox_badge' => 'SANDBOX / TEST MODE ACTIVE',
    'card_number_label' => 'Card Number',
    'card_number_placeholder' => '0000 0000 0000 0000',
    'card_holder_label' => 'Cardholder Full Name',
    'card_holder_placeholder' => 'AS PRINTED ON CARD',
    'card_expiration_label' => 'Expiration (MM/YY)',
    'card_expiration_placeholder' => 'MM/YY',
    'card_cvv_label' => 'Security Code (CVV)',
    'card_cvv_placeholder' => '123',
    'cpf_cnpj_label' => 'Holder CPF or CNPJ (Tax ID)',
    'cpf_cnpj_placeholder' => '000.000.000-00',
    'installments_label' => 'Installments',
    'pay_button' => 'Pay Now with Credit Card',
    'processing_button' => 'Processing secure payment...',
    'security_notice' => 'Your card details are tokenized directly in your browser and never touch or remain stored on our server.',
    'err_invalid_card_number' => 'Invalid credit card number. Please check the digits entered.',
    'err_invalid_holder' => 'Please enter both first and last name as printed on the card.',
    'err_invalid_expiration' => 'Invalid expiration date or expired card (use MM/YY).',
    'err_invalid_cvv' => 'Invalid security code (CVV).',
    'err_invalid_cpf_cnpj' => 'Please enter a valid CPF (11 digits) or CNPJ (14 digits).',
    'err_tokenization_failed' => 'Could not tokenize card details with Iugu. Please check the highlighted fields.',
    'err_network' => 'Communication error while processing payment. Please check your connection and try again.',
    'err_not_configured' => 'This payment gateway is currently unavailable (Iugu credentials pending configuration in WHMCS).',
    'success_redirecting' => 'Payment approved! Updating your invoice and activating your services...',
];
