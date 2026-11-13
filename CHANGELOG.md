# Changelog

Todas as alterações relevantes deste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) e este projeto adere ao [Versionamento Semântico (SemVer)](https://semver.org/lang/pt-BR/).

---

## [Unreleased] - 2026-11-09

### Fixed (Corrigido)
- **URL oficial do Iugu.js v2 (`Config::IUGU_JS_URL`)**: o módulo carregava a biblioteca de tokenização de `https://js.iugu.net/v2`, host que **não existe no DNS** (NXDOMAIN). Em produção o navegador nunca definiria `window.Iugu` e todo checkout falharia com "Biblioteca Iugu.js não carregada". Corrigido para `https://js.iugu.com/v2`, host oficial que serve o bundle com `setAccountID`, `setTestMode` e `createPaymentToken` (verificado em Chromium real via Playwright). Referências ajustadas em `iugucc.js`, `README.md`, `AGENTS.md` e `docs/ARCHITECTURE.md`.

### Fixed (Corrigido) — simulação do Financeiro 2026-11-13
- **Cobrança em fatura que não está em aberto (`callback/iugucc.php`)**: com uma assinatura capturada enquanto a fatura estava `Unpaid`, o callback cobrava o cartão mesmo depois de a fatura virar `Cancelled`, `Refunded`, `Collections`, `Draft` ou `Payment Pending`; o cliente era debitado e a fatura não era baixada. Agora só faturas `Unpaid` seguem para a cobrança; as demais respondem `409 INVOICE_NOT_PAYABLE` e geram a entrada `Checkout Blocked (Invoice Not Payable)` no Gateway Log.

### Added (Adicionado) — simulação do Financeiro 2026-11-13
- **`tests/whmcs_finance_sim.js` + `tests/whmcs_sim/stubs/`**: executa os arquivos reais do módulo (`iugucc_link`, `iugucc_refund`, callback síncrono e gatilhos) via HTTP dentro de um simulador do WHMCS, com a API da Iugu reproduzida pelo contrato oficial atrás de TLS local. Cobre fatura comum, recusa e nova tentativa, token de uso único, pagamento parcial, crédito aplicado, Mass Payment, Add Funds, limites de parcelamento, todos os status de fatura, assinatura/PCI/módulo inativo, gatilhos (idempotência, falsificação, conta errada, fatura do módulo de boleto, segredo), reembolso total/parcial pelo Admin e chargeback. 76 asserções em PHP 8.1 e 8.2; incluído na CI.
- **Trava de segurança em `tests/sandbox_e2e_iugu.php`**: só cria cobranças com `IUGU_E2E_CONFIRM_TEST_TOKEN=yes`, para impedir execução acidental com token de produção.

### Fixed (Corrigido) — auditoria 2026-11-12
- **Cliente preso em "Processando..." (`iugucc.js`)**: o Iugu.js v2 tokeniza via JSONP e, em erro HTTP da API, nunca chama o callback. Adicionado watchdog de 30 s (`TOKENIZATION_TIMEOUT_MS`) que reabilita o botão, exibe `err_network` e ignora respostas tardias. Reproduzido e validado em Chromium real (`tests/browser_checkout_e2e.js`).
- **`soft_descriptor_light` com 13 caracteres**: a Iugu aceita até 12. `Config.php`, campo do Admin e `SCHEMA_MAPPING.md` ajustados.
- **`items[].price_cents` abaixo do mínimo da Iugu (100)**: `PayloadBuilder` passa a rejeitar valores < R$ 1,00 antes da chamada à API.

### Added (Adicionado)
- **Relatório de auditoria (`docs/AUDITORIA_WHMCS_IUGU.md`)**: conformidade função a função com o padrão de gateway do WHMCS 8.13.7 e com a API Iugu v1, matriz "opções do Financeiro × iugucc", recomendação de evolução para gateway tokenizado (`_remoteinput`/`_capture`) e matriz de testes.
- **Teste de navegador (`tests/browser_checkout_e2e.js` + `tests/browser/render_checkout.php`)**: renderiza o template real do checkout, carrega o Iugu.js v2 oficial em Chromium headless, valida Luhn/PCI (nenhum PAN/CVV chega ao callback), tokenização em modo teste e o watchdog.
- **E2E de cobrança em sandbox (`tests/sandbox_e2e_iugu.php`)**: handshake, `payment_token` de teste, `POST /v1/charge` via `ChargeProcessor`, consulta autoritativa, idempotência de webhook, reembolso parcial e total, reuso de token. Suporta `IUGU_AUTH_MODE=query` (autenticação por `api_token`, método oficial) para ambientes cujo proxy reescreve `Authorization`.
- **Testes de regressão (`tests/run_tests.js`)**: duas asserções garantem que `Config::IUGU_JS_URL` aponta para `js.iugu.com` e que nenhum fonte do módulo referencia `js.iugu.net`.
- **Script de ambiente de nuvem (`scripts/cloud-env-setup.sh`)**: instala PHP 8.1 e 8.2 CLI (PPA ondrej/php) com `curl`, `json`, `mbstring` e `openssl`, cria `archived/`, executa lint estrito nas duas versões e o Sentinel AutoSetup (PHP + Node). Usado como *setup script* do ambiente Claude Code na nuvem para o papel de revisor/tester (ver `AGENTS.md`, seção 5).
- **`.gitignore`**: padrões adicionais de histórico local (`archive/`, `arquivado*/`, `historico/`, `histórico/`, `_archive*/`).

---

## [1.1.0] - 2026-11-08

### Added (Adicionado)
- **Agente Sentinela & AutoSetup (`SentinelAuditor` & `scripts/sentinel-autosetup.php`)**:
  - Utilitário de Snapshot Consistente ("No-Hot") em `archived/snapshots/snapshot_<ts>.json` com hash SHA-256 de todos os arquivos do módulo.
  - Watchdog de Instalação validando compatibilidade PHP 8.1/8.2, extensões (`curl`, `json`, `mbstring`, `openssl`) e handshake opcional sem custo na API da Iugu (`GET /v1/invoices?limit=1`).
  - Modo Sentinela Chaveável (`sentinelMode`) na configuração do WHMCS Admin, enriquecendo o Gateway Log com `trace_id` e dicas de ação (`support_action_hint`) por código LR da adquirente sem expor dados sensíveis ao cliente final.
- **Prontidão Comercial WHMCS Marketplace (`whmcs.json` & `logo.svg`)**:
  - Manifesto oficial `modules/gateways/iugucc/whmcs.json` (Schema 1.0) e logotipo vetorial `logo.svg` para publicação e distribuição comercial no WHMCS Marketplace.
- **Manifesto de Mapeamento de Esquemas (`/schema-mapping`)**:
  - Documentação formal completa em `docs/SCHEMA_MAPPING.md` e `docs/SENTINEL_AUTOSETUP.md`.
- **CI/CD, Bump de Versão Automatizado e Gate de Revisão Jules**:
  - Workflows `.github/workflows/ci-and-jules-review.yml` e `.github/workflows/version-bump.yml`.

### Fixed / Improved (Revisão de Código)
- **Validação Mod-11 de CPF/CNPJ e Priorização de Custom Field (`PayloadBuilder.php`)**:
  - Prioriza o campo personalizado com o nome configurado (`cpfCustomField`) e valida dígitos verificadores Mod-11, evitando que números de celular brasileiros de 11 dígitos (`DDD + 9 dígitos`) em outros custom fields sejam confundidos com CPF.
- **Verificação de Assinatura HMAC para Faturas com Crédito ou Pagamento Parcial (`callback/iugucc.php`)**:
  - Calcula o saldo remanescente real (`total - SUM(amountin - amountout)`) em `tblaccounts` e valida a assinatura HMAC-SHA256 tanto para o saldo parcial quanto para o valor cheio da fatura.
- **Sincronização Completa no `scripts/bump-version.js`**:
  - Atualiza automaticamente `VERSION`, `composer.json`, `modules/gateways/iugucc.php`, `modules/gateways/iugucc/whmcs.json`, `IuguHttpClient.php` (`User-Agent`) e `checkout.php` (`?v=`).

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
