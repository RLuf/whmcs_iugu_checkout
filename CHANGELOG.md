# Changelog

Todas as alterações relevantes deste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e este projeto adere ao [Versionamento Semântico (SemVer)](https://semver.org/lang/pt-BR/).

---

## [1.0.0] - 2026-11-07

### Added (Adicionado)
- **Módulo Gateway WHMCS 8.13.7 (`iugucc`)**:
  - Implementação completa em `modules/gateways/iugucc.php` compatível com WHMCS 8.13.7 e PHP 8.1 / 8.2 (`declare(strict_types=1);`).
  - Configuração administrativa completa no WHMCS (`accountId`, `apiToken`, `sandboxApiToken`, `sandboxMode`, `maxInstallments`, `minInstallmentValue`, `invoiceDescription`, `statementDescriptor`, `cpfCustomField`, `webhookSecret`, `debugLogging`).
  - Suporte nativo a estornos totais e parciais (`iugucc_refund`) via `POST /v1/invoices/{id}/refund`.
- **Checkout Transparente e Tokenização Client-Side (`Iugu.js v2`)**:
  - Integração com `https://js.iugu.net/v2` em `modules/gateways/iugucc/assets/js/iugucc.js` e template `checkout.php`.
  - Conformidade **PCI-DSS**: campos de cartão (`data-iugu="number"`, `data-iugu="verification_value"`, `data-iugu="expiration"`, `data-iugu="full_name"`) não possuem atributo HTML `name=""`, impedindo o envio acidental de PAN ou CVV ao servidor do WHMCS.
  - Sanitizador recursivo `PciSanitizer` garantindo que logs do WHMCS (`tblgatewaylog`) jamais armazenem dados sensíveis de cartão ou tokens secretos de API.
- **Baixa Síncrona, Webhook Assíncrono Idempotente e Provisionamento**:
  - Handler dual em `modules/gateways/callback/iugucc.php` e classes `ChargeProcessor` + `WebhookHandler`.
  - Baixa imediata da fatura via `addInvoicePayment()` após aprovação (`LR 00`), acionando automaticamente o provisionamento de serviços no WHMCS para `imovelsite.com.br`.
  - Verificação server-to-server (`GET /v1/invoices/{id}`) na API da Iugu para validar notificações de Webhook antes de registrar pagamentos, prevenindo requisições forjadas e duplicidade de baixa.
- **Internacionalização e Documentação**:
  - Traduções em Português (`portuguese-br.php`) e Inglês (`english.php`).
  - Documentação bilíngue no `README.md` e manuais técnicos em `./docs/`.
  - Suíte de testes automatizados (`tests/run_tests.js` e `tests/phpunit_runner.php`) e utilitário de versionamento semântico (`scripts/bump-version.js`).
- **Governança e Segurança (`AGENTS.md` & `archived/`)**:
  - Isolamento completo em relação ao módulo de boleto bancário existente (Edvan).
  - Política obrigatória de backup em `archived/` antes de modificar ou excluir qualquer arquivo.
