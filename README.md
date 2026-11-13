# WHMCS Iugu Credit Card Checkout Gateway (`iugucc`)

[![Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](./VERSION)
[![WHMCS](https://img.shields.io/badge/WHMCS-8.13.7-success.svg)](https://www.whmcs.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%7C%208.2-777bb4.svg)](https://www.php.net/)
[![PCI-DSS](https://img.shields.io/badge/PCI--DSS-Client--Side%20Tokenized-brightgreen.svg)](https://dev.iugu.com/)

> **Português (BR)** | [English Below](#-english-documentation)

---

## 🇧🇷 Documentação em Português

Módulo comercial de gateway de pagamento via **Cartão de Crédito (Checkout Transparente)** para **WHMCS 8.13.7** (PHP 8.1 e 8.2), integrado à API REST v1 da **Iugu** e tokenização client-side via **Iugu.js v2**, desenvolvido para **imovelsite.com.br** (`https://www.webstorage.net.br/financeiro/master/`).

### Principais Recursos
- **Conformidade PCI-DSS Total (Tokenização Client-Side):** Os dados sensíveis do cartão (`número` e `CVV`) são tokenizados diretamente no navegador do cliente via `https://js.iugu.com/v2` e **jamais** passam pelo servidor do WHMCS.
- **Baixa Automática Síncrona e Assíncrona (Webhook):**
  - **Retorno Síncrono:** Assim que a cobrança (`POST /v1/charge`) retorna `LR 00`, o módulo registra o pagamento imediatamente via `addInvoicePayment()`, deduzindo a taxa real da Iugu (`taxes_paid_cents`) e acionando a liberação/provisionamento automático do serviço contratado no WHMCS.
  - **Webhook Idempotente:** O endpoint `modules/gateways/callback/iugucc.php` recebe notificações `invoice.status_changed` da Iugu, confirma a autenticidade diretamente na API (`GET /v1/invoices/{id}`) e garante que nenhuma fatura receba baixa duplicada.
- **Zero Interferência com o Módulo de Boleto Existente:** Utiliza o identificador exclusivo `iugucc`, convivendo em total harmonia com o módulo de boleto bancário da Iugu já instalado (por Edvan).
- **Suporte a Parcelamento e Estornos:** Configurável de `1x` a `12x` com valor mínimo de parcela e suporte nativo a reembolso total ou parcial (`iugucc_refund`) direto pelo painel Admin do WHMCS.

---

### Prévia da Interface de Checkout Transparente (`viewinvoice.php`)

```text
+-----------------------------------------------------------------------+
|  Pagamento com Cartão de Crédito                             [ VISA ] |
|  Ambiente seguro criptografado via Iugu (PCI-DSS)                     |
|-----------------------------------------------------------------------|
|  [!] MODO SANDBOX / TESTE ATIVO (Exibido apenas em modo de teste)     |
|                                                                       |
|  Número do Cartão                                                     |
|  [ 4111 1111 1111 1111                                             ]  |
|                                                                       |
|  Nome Impresso no Cartão                                              |
|  [ JOAO SILVA                                                      ]  |
|                                                                       |
|  Validade (MM/AA)                 Código de Segurança (CVV)           |
|  [ 12/30                       ]  [ ***                            ]  |
|                                                                       |
|  CPF ou CNPJ do Titular                                               |
|  [ 123.456.789-09                                                  ]  |
|                                                                       |
|  Parcelamento                                                         |
|  [ 3x de R$ 50,00 sem juros                                    (v) ]  |
|                                                                       |
|  [               Pagar Agora com Cartão (R$ 150,00)                ]  |
|-----------------------------------------------------------------------|
|  🔒 Seus dados de cartão são tokenizados diretamente no navegador     |
|     e nunca ficam armazenados em nosso servidor.                      |
+-----------------------------------------------------------------------+
```

---

### Fluxo de Arquitetura e Pagamento

```mermaid
flowchart LR
    A["Cliente na Fatura WHMCS<br/>(viewinvoice.php)"] -->|"Preenche Cartão"| B["Iugu.js v2 no Navegador"]
    B -->|"HTTPS Direto"| C["API Iugu<br/>(Cria iugu_token)"]
    C -->|"Retorna Token Descartável"| B
    B -->|"Envia apenas iugu_token"| D["Callback WHMCS<br/>(callback/iugucc.php)"]
    D -->|"POST /v1/charge"| E["API Iugu Cobrança"]
    E -->|"Aprovado (LR 00)"| D
    D -->|"addInvoicePayment()"| F["Fatura Paga +<br/>Serviço Ativado!"]
```

---

### Estrutura de Diretórios
```text
.
├── AGENTS.md                           # Regras obrigatórias de segurança e backup em archived/
├── CHANGELOG.md                        # Histórico de versões (SemVer)
├── VERSION                             # Versão atual do módulo (1.1.0)
├── composer.json                       # Metadados do pacote e scripts
├── archived/                           # Diretório local de backups (ignorado no .gitignore)
├── docs/
│   ├── ARCHITECTURE.md                 # Diagramas de sequência e arquitetura PCI-DSS
│   ├── INSTALLATION_WHMCS.md           # Passo a passo de instalação no WHMCS 8.13.7 e Iugu
│   └── SANDBOX_TESTING.md              # Guia de testes Sandbox e códigos LR
├── modules/
│   └── gateways/
│       ├── iugucc.php                  # Entry point principal do módulo WHMCS
│       ├── callback/
│       │   └── iugucc.php              # Handler síncrono de checkout + Webhook assíncrono
│       └── iugucc/
│           ├── autoload.php            # Autoloader PSR-4 standalone (sem necessidade de SSH/Composer)
│           ├── assets/                 # CSS e JS (integração Iugu.js v2)
│           ├── lang/                   # Traduções PT-BR e EN
│           ├── lib/                    # Cliente HTTP Iugu, sanitizador PCI e processadores
│           └── templates/              # Template HTML seguro do checkout
├── scripts/
│   ├── bump-version.js                 # Utilitário de bump semântico de versão
│   ├── cloud-env-setup.sh              # Setup do ambiente de nuvem (PHP 8.1 + 8.2, lint, Sentinel)
│   ├── sentinel-autosetup.php          # Agente Sentinela & AutoSetup (PHP)
│   └── sentinel-autosetup.js           # Agente Sentinela & AutoSetup (Node)
└── tests/
    ├── run_tests.js                    # Suíte automatizada de testes (48 asserções + E2E Sandbox)
    ├── adversarial_suite.js            # Testes adversariais de payload e PCI (Node)
    ├── adversarial_webhook_test.js     # Testes adversariais de idempotência de Webhook (Node)
    ├── adversarial_stress_test.php     # Testes adversariais de stress (PHP 8.1/8.2)
    ├── browser_checkout_e2e.js         # E2E em Chromium: template real + Iugu.js v2 (conta de teste)
    ├── browser/render_checkout.php     # Renderiza o template do checkout fora do WHMCS para o E2E
    ├── whmcs_finance_sim.js            # Operações do Financeiro do WHMCS com os arquivos reais do módulo
    ├── whmcs_sim/stubs/                # Simulador do WHMCS (dublê de teste) usado pela simulação acima
    ├── sandbox_e2e_iugu.php            # Cobrança/estorno reais em sandbox (somente token de TESTE)
    ├── phpunit_runner.php              # Runner nativo para PHP 8.1/8.2 CLI
    └── fixtures/                       # Payloads de simulação da API Iugu
```

---

### Ambiente de Nuvem para Revisão e Testes (Claude Code)
O repositório possui um ambiente de nuvem dedicado ao papel de **revisor e tester**, que garante compatibilidade com o WHMCS 8.13.7 e com a API Iugu v1 sem tocar em servidores de produção. O script `scripts/cloud-env-setup.sh` prepara o container (PHP 8.1 e 8.2 CLI com `curl`, `json`, `mbstring`, `openssl`; `archived/`; lint; Sentinel AutoSetup). Hosts de rede, variáveis e política de segredos estão documentados na seção 5 do [`AGENTS.md`](./AGENTS.md).

```bash
# Suíte completa exatamente como a CI (executar em PHP 8.1 e 8.2):
mkdir -p archived
find modules scripts tests -name "*.php" -print0 | xargs -0 -n1 php -l
php scripts/sentinel-autosetup.php && node scripts/sentinel-autosetup.js
node tests/run_tests.js && node tests/adversarial_suite.js && node tests/adversarial_webhook_test.js
php tests/phpunit_runner.php && php tests/adversarial_stress_test.php
node tests/whmcs_finance_sim.js   # fatura, parcial, crédito, Mass Pay, Add Funds, gatilhos, reembolso, status
```

---

### Instalação Rápida
1. No servidor, com o usuário dono dos arquivos do WHMCS, rode o instalador apontando para a **raiz do WHMCS** (pasta com `configuration.php`). Ele copia só os arquivos do `iugucc`, faz backup de versão anterior e não toca em mais nada:
   ```bash
   curl -fsSL https://raw.githubusercontent.com/RLuf/whmcs_iugu_checkout/main/scripts/install-on-whmcs.sh | IUGUCC_REF=main bash -s -- /home/USUARIO/public_html/financeiro
   ```
   Alternativas: `--dry-run` para simular; `bash scripts/build-release.sh` gera `dist/iugucc-<versão>.zip` para extrair pelo cPanel; ou copie a pasta `modules/` manualmente.
2. No Admin do WHMCS, em **Apps & Integrations** (ou **Portais para Pagamento > All Payment Gateways**), ative **Iugu - Cartão de Crédito**.
3. Configure o **ID da Conta Iugu (Account ID)** e os tokens. O **Modo Sandbox exige o Token de API do tipo *Teste***; o token de produção nunca é usado em sandbox.
4. No painel da Iugu (**Configurações > Gatilhos**), aponte o webhook para a raiz do WHMCS (não a pasta do Admin):
   `https://www.webstorage.net.br/financeiro/modules/gateways/callback/iugucc.php`
5. Confira com `node tests/e2e/live_flow.js preflight` (somente leitura).

Consulte os manuais detalhados em:
- [Guia Completo de Instalação no WHMCS 8.13.7](./docs/INSTALLATION_WHMCS.md)
- [Arquitetura e Segurança PCI-DSS](./docs/ARCHITECTURE.md)
- [Guia de Testes em Modo Sandbox](./docs/SANDBOX_TESTING.md)
- [Manifesto de Mapeamento de Esquemas (WHMCS ↔ Iugu ↔ Sentinela)](./docs/SCHEMA_MAPPING.md)
- [Agente Sentinela & AutoSetup (Snapshot No-Hot, Watchdog & Prontidão Comercial)](./docs/SENTINEL_AUTOSETUP.md)
- [Auditoria de Conformidade WHMCS 8.13.7 × API Iugu v1 (defeitos corrigidos, matriz do Financeiro, roadmap de recorrência)](./docs/AUDITORIA_WHMCS_IUGU.md)

---

## 🇺🇸 English Documentation

Commercial-grade, configurable **Credit Card Transparent Checkout** payment gateway module for **WHMCS 8.13.7** (PHP 8.1 and 8.2), integrated with the **Iugu REST API v1** and **Iugu.js v2** client-side tokenization.

### Key Features
- **PCI-DSS Client-Side Tokenization:** Cardholder data (`number` and `verification_value`) are tokenized in the browser via `https://js.iugu.com/v2`. Card inputs intentionally omit HTML `name` attributes so raw card numbers never touch the WHMCS server.
- **Synchronous Checkout & Idempotent Webhook Handler:**
  - Marks WHMCS invoices as `Paid` immediately upon checkout approval (`addInvoicePayment`), recording the exact Iugu gateway fee (`taxes_paid_cents`) and automatically triggering WHMCS service provisioning.
  - Includes an asynchronous webhook callback (`modules/gateways/callback/iugucc.php`) that verifies invoice status server-to-server (`GET /v1/invoices/{id}`) and updates unpaid invoices idempotently.
- **Non-Interference by Design:** Uses the dedicated `iugucc` module identifier so it never conflicts with existing Iugu Boleto modules.

### Running Automated Tests & Version Bumping
```bash
# Run the automated unit, PCI-DSS, and E2E Sandbox simulation suite:
node tests/run_tests.js

# Bump semantic version (automatically backs up modified files to archived/):
node scripts/bump-version.js patch
```

### Quick Install
Run the installer on the server as the user that owns the WHMCS files, pointing at the **WHMCS root** (the folder containing `configuration.php`). It copies only the `iugucc` files, backs up a previous version to `~/iugucc_backups/` and touches nothing else:
```bash
curl -fsSL https://raw.githubusercontent.com/RLuf/whmcs_iugu_checkout/main/scripts/install-on-whmcs.sh | IUGUCC_REF=main bash -s -- /home/USER/public_html/financeiro
```
Add `--dry-run` to preview. `bash scripts/build-release.sh` builds `dist/iugucc-<version>.zip` for a cPanel upload. Then activate **Iugu - Cartão de Crédito** under **Apps & Integrations**, fill in the Account ID and tokens (Sandbox Mode requires an Iugu **Test** API token; the live token is never used in sandbox), and point the Iugu webhook at `<whmcs_root_url>/modules/gateways/callback/iugucc.php`. `node tests/e2e/live_flow.js preflight` is a read-only check that the module is deployed and offered at checkout; `node tests/e2e/live_flow.js full` is the definitive customer journey (storefront → order → test-card payment in Sandbox Mode → service activation → e-mails → provisioned site).

### Cloud Review & Testing Environment (Claude Code)
A dedicated cloud environment acts as **reviewer and tester**, validating compatibility with WHMCS 8.13.7 and the Iugu API v1 without touching production servers. `scripts/cloud-env-setup.sh` provisions the container (PHP 8.1 and 8.2 CLI with `curl`, `json`, `mbstring`, `openssl`; the `archived/` directory; strict lint on both versions; Sentinel AutoSetup). Allowed network hosts, non-secret variables and the secrets policy are documented in section 5 of [`AGENTS.md`](./AGENTS.md). Iugu.js v2 is loaded from its official host `https://js.iugu.com/v2`; the host `js.iugu.net` does not exist and is rejected by a regression test.

`node tests/whmcs_finance_sim.js` runs the real gateway and callback files over HTTP inside a WHMCS simulator (a test double) against an Iugu API double built from the official contract. It covers every billing operation WHMCS exposes to a third-party gateway: invoice payment, declines and retries, partial payments, applied credit, Mass Payment, Add Funds, installment limits, every invoice status, webhooks, admin refunds and chargebacks. The compliance report is in [`docs/AUDITORIA_WHMCS_IUGU.md`](./docs/AUDITORIA_WHMCS_IUGU.md). Never run any test with a LIVE Iugu token.
