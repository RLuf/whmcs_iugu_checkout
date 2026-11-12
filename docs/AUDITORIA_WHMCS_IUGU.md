# Auditoria de Conformidade — Módulo `iugucc` × Padrão WHMCS 8.13.7 × API Iugu v1

**Data:** 2026-11-12 · **Papel:** revisor e tester (ambiente de nuvem `whmcs_iugu_checkout`) · **Branch:** `feat/v1.1.0-sentinel-jules-review`

Fontes oficiais consultadas (texto baixado e comparado linha a linha com o código):

- WHMCS Developer Docs: `payment-gateways/getting-started`, `configuration`, `third-party-gateway`, `merchant-gateway`, `remote-input-gateway`, `tokenised-remote-storage`, `callbacks`, `refunds`, `3d-secure`; repositórios `WHMCS/sample-gateway-module` e `WHMCS/sample-merchant-gateway`.
- WHMCS Docs 9.1: *Payment Gateways*, *Pay Methods*, *Tokenization*, *Configure Payment Capture*, *Invoice Management*, *Credit Balances*, *Payment Reversals*, *Custom Gateway Modules*.
- Iugu Dev: *Autenticação*, *Tokenização e iugu.js*, *Criar Token* (`POST /v1/payment_token`), *Cobrança Direta* (`POST /v1/charge`), *Reembolsar Fatura*, *Consultar Fatura*, *Status de Fatura*, *Tabela de LRs (ABECS)*, *Gatilhos* (lista de eventos de fatura), artigo de suporte *Como realizar uma cobrança simples por cartão de crédito utilizando a API*.
- Bundle real do Iugu.js v2 (`https://js.iugu.com/v2`, 13 057 bytes) inspecionado para confirmar o transporte de tokenização.

---

## 1. Defeitos encontrados e corrigidos nesta auditoria

| # | Severidade | Defeito | Evidência | Correção |
| :-: | :-- | :-- | :-- | :-- |
| 1 | **Crítica** | `Config::IUGU_JS_URL` apontava para `https://js.iugu.net/v2`. O host **não existe** (NXDOMAIN). Em produção `window.Iugu` nunca seria definido e nenhum cliente conseguiria pagar. | `getent hosts js.iugu.net` vazio; Chromium real: `net::ERR_TUNNEL_CONNECTION_FAILED`; `https://js.iugu.com/v2` responde 200 `application/javascript` com `setAccountID`, `setTestMode`, `createPaymentToken`. | URL corrigida para `https://js.iugu.com/v2` em `Config.php`, `iugucc.js`, README, AGENTS, ARCHITECTURE. Teste de regressão em `tests/run_tests.js`. |
| 2 | **Alta** | Cliente preso em "Processando..." para sempre quando a tokenização falha. O Iugu.js v2 tokeniza via **JSONP** (`<script src="https://api.iugu.com/v1/payment_token?...">`); em erro HTTP ele cancela o próprio timer e **nunca chama o callback**. O `iugucc.js` não tinha proteção. | Reproduzido no teste de navegador com a API da Iugu devolvendo 502: botão desabilitado e nenhuma mensagem. | Watchdog de 30 s em `iugucc.js` (`TOKENIZATION_TIMEOUT_MS`): reabilita o botão, exibe `err_network`, ignora callback tardio. Coberto por `tests/browser_checkout_e2e.js`. |
| 3 | Média | `soft_descriptor_light` truncado em 13 caracteres; a Iugu documenta **"Até 12 caracteres"**. | Referência *Cobrança Direta*, campo `soft_descriptor_light`. | `substr(…, 0, 12)` em `Config.php`; `Size`/descrição do campo no Admin e `SCHEMA_MAPPING.md` ajustados. |
| 4 | Média | `items[].price_cents` abaixo de 100 era enviado à API (a Iugu exige mínimo 100 = R$ 1,00). | Referência *Cobrança Direta*, campo `items`. | `PayloadBuilder` rejeita valores < R$ 1,00 antes da chamada, com mensagem clara. Testes D.2b no stress test. |

## 2. Conformidade com o padrão de módulo de gateway WHMCS

| Requisito oficial WHMCS | Estado no `iugucc` | Evidência |
| :-- | :-- | :-- |
| Arquivo `modules/gateways/<nome>.php`, nome minúsculo, funções prefixadas | ✅ | `iugucc.php`, funções `iugucc_*` |
| `_MetaData` com `DisplayName`, `APIVersion` | ✅ | `APIVersion 1.1`, `DisableLocalCreditCardInput => true`, `TokenisedStorage => false` |
| `_config` com `FriendlyName` tipo `System` e campos `text/password/yesno/dropdown` | ✅ | 12 campos; nomes não colidem com variáveis padrão (`amount`, `invoiceid`, `currency`) |
| `_link($params)` devolve HTML (gateway de terceiros) | ✅ | Renderiza `templates/checkout.php`; usa `invoiceid`, `amount` (saldo devedor), `clientdetails`, `systemurl` |
| Callback em `modules/gateways/callback/<nome>.php` com `init.php`, `gatewayfunctions.php`, `invoicefunctions.php` | ✅ | Linhas 23–25 |
| `getGatewayVariables` + abortar se `type` vazio | ✅ | Responde 503 JSON |
| `checkCbInvoiceID`, `checkCbTransID`, `logTransaction`, `addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway)` | ✅ | Ordem de parâmetros conforme docs; `transid` = ID da fatura Iugu (único) |
| `_refund` devolve `status` `success/declined/error`, `rawdata`, `transid` | ✅ | `POST /v1/invoices/{id}/refund` com `partial_value_refund_cents` para parcial |
| Pagamento parcial / crédito aplicado | ✅ | Assinatura HMAC validada contra o saldo real (`total − Σ amountin + Σ amountout` em `tblaccounts`) e contra o total |
| Mass Payment e Add Funds | ✅ (via fatura) | Ambos geram uma fatura normal; `_link` recebe `invoiceid` e `amount` dessa fatura. Reversão em Mass Pay/Add Funds é tratada pelo próprio WHMCS. |
| Reembolso pelo Admin ("Refund through Gateway") | ✅ | `iugucc_refund` total e parcial |
| `_capture` (cobrança automática por cron, "Attempt Capture", Pay Methods) | ❌ **não se aplica** | O módulo é do tipo **Third-Party** (`_link`). Segundo a documentação oficial: *"For third-party gateways, the system will deactivate Attempt Capture because the client must click the payment button on the invoice"*. Não há cobrança recorrente automática nem cartão salvo. |
| `_remoteinput` / `_remoteupdate` / `TokenisedStorage` | ❌ não implementado | Necessário para Pay Methods tokenizados e recorrência automática (ver §4). |
| `_3dsecure` | ❌ não aplicável | 3DS é tratado pela própria Iugu na tokenização/cobrança. |
| E-mail de fatura | ⚠️ | Gateway de terceiros envia o template **Invoice Created** (não *Credit Card Invoice Created*). Comportamento padrão do WHMCS para este tipo. |
| PHP 8.1/8.2 `strict_types` em 100 % dos arquivos, sem propriedades dinâmicas | ✅ | Lint e suítes executados em PHP 8.1.34 e 8.2.35 |

### Opções do Financeiro do WHMCS × comportamento do `iugucc`

| Operação no Financeiro | Resultado com `iugucc` |
| :-- | :-- |
| Cliente paga fatura (viewinvoice "Pay Now") | ✅ Checkout transparente na própria fatura, baixa síncrona + provisionamento |
| Pagamento parcial anterior / crédito aplicado | ✅ Cobra o saldo restante |
| Mass Pay (fatura agrupadora) | ✅ Trata como fatura única |
| Add Funds (depósito de crédito) | ✅ Trata como fatura única |
| Admin → Attempt Capture | ⛔ Desabilitado pelo WHMCS (tipo Third-Party) |
| Cron → Process Credit Card Payments (recorrência automática) | ⛔ Não aplicável sem `_capture`/Pay Method; cliente recebe o e-mail para pagar manualmente |
| Pay Methods (cartão salvo no Client Area) | ⛔ Não disponível |
| Admin → Refund through Gateway (total/parcial) | ✅ |
| Add to Client's Credit Balance / Manual Refund | ✅ (nativo WHMCS, não depende do módulo) |
| Reverse Payment / Collections após chargeback | ⚠️ Webhook `invoice.status_changed` com `chargeback`/`in_protest` é apenas registrado no Gateway Log; a reversão deve ser feita manualmente no Admin (`Reverse Payment`). |
| Convert To For Processing (moeda) | ✅ Padrão WHMCS; a Iugu opera em BRL |

## 3. Conformidade com a API Iugu v1

| Contrato Iugu | Estado | Observação |
| :-- | :-- | :-- |
| Autenticação Basic `base64(api_token:)` | ✅ | A Iugu também aceita Bearer e `?api_token=`; o módulo usa Basic (recomendado). |
| `POST /v1/payment_token` só no navegador (não PCI) | ✅ | Tokenização exclusivamente via Iugu.js v2; `test: 1` em sandbox; inputs sem `name`. |
| `POST /v1/charge`: `token` de uso único, `months` 2–12, `items[].price_cents ≥ 100`, `payer`, `order_id`, `soft_descriptor_light ≤ 12`, `keep_dunning` | ✅ (após itens 3 e 4) | `months = 1` é aceito ("não é necessário passar 1"). Token reutilizado é recusado pela Iugu e tratado como recusa. |
| `GET /v1/invoices/{id}` como fonte autoritativa de status (`paid`, `refunded`, `chargeback`…) | ✅ | Webhooks nunca são confiados sem esta consulta. |
| `POST /v1/invoices/{id}/refund` (+ `partial_value_refund_cents`) | ✅ | |
| Gatilhos: `Content-Type: application/x-www-form-urlencoded`, `event`, `data[id]`, `data[status]`, `data[account_id]`, `data[order_id]`, `data[paid_cents]` | ✅ | PHP popula `$_POST['data'][...]`; `account_id` é comparado ao configurado; IP de saída da Iugu para firewall: `98.82.243.132`. |
| Tabela de LRs (ABECS) | ✅ parcial | Mapeamento de 10 códigos mais frequentes com mensagem amigável; demais caem na mensagem genérica. A tabela oficial classifica cada LR como reversível/irreversível por bandeira. |
| CPF/CNPJ do pagador | ⚠️ | A Iugu rejeita CPFs sequenciais conhecidos (`123.456.789-09` → `cpf_cnpj não é válido`). O `PayloadBuilder` já valida Mod-11; o checkout aceita 11/14 dígitos no front. |

## 4. Recomendação de evolução (padrão WHMCS para recorrência)

Para que **Attempt Capture**, **cobrança automática por cron** e **Pay Methods** funcionem, o WHMCS exige um gateway do tipo *Tokenised / Remote Input*:

1. `iugucc_MetaData`: `TokenisedStorage => true`.
2. `iugucc_remoteinput($params)`: formulário (iframe) com Iugu.js que tokeniza e cria a **Forma de Pagamento** do cliente na Iugu (`POST /v1/customers/{id}/payment_methods` com `token`), devolvendo `customer_payment_method_id`.
3. Callback: `createCardPayMethod($clientId, 'iugucc', $remoteToken, …)` / `updateCardPayMethod` (WHMCS ≥ 7.9).
4. `iugucc_capture($params)`: `POST /v1/charge` com `customer_payment_method_id = $params['gatewayid']`, sem CVV (recorrência).
5. `iugucc_remoteupdate($params)` para troca de cartão.

Isto mantém a conformidade PCI-DSS SAQ A (o cartão continua só no navegador) e habilita todas as opções do Financeiro.

## 5. Matriz de testes executados

| Suíte | PHP 8.1 | PHP 8.2 | Node 22 |
| :-- | :-: | :-: | :-: |
| Lint estrito (`php -l`) em `modules`, `scripts`, `tests` | 0 erros | 0 erros | — |
| Sentinel AutoSetup (snapshot + watchdog + handshake real `GET /v1/invoices?limit=1` → HTTP 200) | ✅ | ✅ | ✅ |
| `tests/phpunit_runner.php` | 10/10 | 10/10 | — |
| `tests/adversarial_stress_test.php` | 62/62 | 62/62 | — |
| `tests/run_tests.js` | — | — | 48/48 |
| `tests/adversarial_suite.js` | — | — | 60/60 |
| `tests/adversarial_webhook_test.js` | — | — | 23/23 |
| `tests/browser_checkout_e2e.js` (Chromium + template real + Iugu.js v2 real, conta de teste) | — | — | 13/13 |
| `tests/sandbox_e2e_iugu.php` (cobrança real em sandbox) | bloqueado | bloqueado | — |

**Bloqueio do E2E de cobrança:** `POST/GET /v1/payment_token` da Iugu devolveu `502 {"message":"Internal server error"}` (AWS API Gateway `InternalServerErrorException`) em todas as variantes (JSON, form-urlencoded, JSONP idêntico ao Iugu.js) a partir deste ambiente. No mesmo ambiente, o segredo de rede salvo para `api.iugu.com` está incorreto: o proxy sobrescreve o cabeçalho `Authorization` e qualquer chamada autenticada por cabeçalho recebe 401, enquanto o mesmo token via `?api_token=` recebe 200. Até o segredo ser corrigido, o E2E usa `IUGU_AUTH_MODE=query` (terceiro método oficial de autenticação) e o teste de tokenização fica dependente da Iugu.

## 6. Como reproduzir

```bash
# Suítes offline (CI)
php tests/phpunit_runner.php && php tests/adversarial_stress_test.php
node tests/run_tests.js && node tests/adversarial_suite.js && node tests/adversarial_webhook_test.js

# Navegador real (Chromium/Playwright) contra Iugu.js v2 em modo teste
IUGU_ACCOUNT_ID=<account_id> node tests/browser_checkout_e2e.js

# Cobrança real em sandbox com as classes do módulo (token TEST da Iugu)
IUGU_ACCOUNT_ID=<account_id> IUGU_API_TOKEN=<test_token> php tests/sandbox_e2e_iugu.php
# atrás de proxy que reescreve Authorization: IUGU_AUTH_MODE=query
```
