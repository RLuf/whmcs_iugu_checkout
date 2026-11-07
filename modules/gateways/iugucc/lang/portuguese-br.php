<?php
/**
 * Portuguese (Brazil) Language File for WHMCS Iugu Credit Card Gateway (`iugucc`)
 */

declare(strict_types=1);

return [
    'title' => 'Pagamento com Cartão de Crédito',
    'subtitle' => 'Ambiente seguro criptografado via Iugu (PCI-DSS)',
    'sandbox_badge' => 'MODO SANDBOX / TESTE ATIVO',
    'card_number_label' => 'Número do Cartão',
    'card_number_placeholder' => '0000 0000 0000 0000',
    'card_holder_label' => 'Nome Impresso no Cartão',
    'card_holder_placeholder' => 'COMO ESTÁ NO CARTÃO',
    'card_expiration_label' => 'Validade (MM/AA)',
    'card_expiration_placeholder' => 'MM/AA',
    'card_cvv_label' => 'Código de Segurança (CVV)',
    'card_cvv_placeholder' => '123',
    'cpf_cnpj_label' => 'CPF ou CNPJ do Titular',
    'cpf_cnpj_placeholder' => '000.000.000-00',
    'installments_label' => 'Parcelamento',
    'pay_button' => 'Pagar Agora com Cartão',
    'processing_button' => 'Processando pagamento seguro...',
    'security_notice' => 'Seus dados de cartão são tokenizados diretamente no navegador e nunca ficam armazenados em nosso servidor.',
    'err_invalid_card_number' => 'Número do cartão de crédito inválido. Verifique os dígitos informados.',
    'err_invalid_holder' => 'Informe o nome e sobrenome exatamente como impressos no cartão.',
    'err_invalid_expiration' => 'Data de validade inválida ou cartão vencido (use MM/AA).',
    'err_invalid_cvv' => 'Código de segurança (CVV) inválido.',
    'err_invalid_cpf_cnpj' => 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.',
    'err_tokenization_failed' => 'Não foi possível validar os dados do cartão junto à Iugu. Revise os campos destacados.',
    'err_network' => 'Falha de comunicação ao processar o pagamento. Verifique sua conexão e tente novamente.',
    'err_not_configured' => 'Este meio de pagamento está em manutenção (credenciais Iugu pendentes de configuração no WHMCS).',
    'success_redirecting' => 'Pagamento aprovado! Atualizando sua fatura e liberando seus serviços...',
];
