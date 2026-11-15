# Manifesto de Mapeamento de Esquemas (Schema Mapping Manifesto) — WHMCS 8.13.7 ↔ Iugu REST API v1 ↔ Sentinela E2E

Este documento aplica a metodologia formal de **Semantic Schema Mapping Planning** (`/schema-mapping`) para documentar de ponta a ponta todas as transformações de dados, chaves de junção (`JOIN`), regras de limpeza e garantias de integridade entre o banco de dados MySQL/MariaDB do **WHMCS 8.13.7**, a **API REST v1 da Iugu** e o **Agente Sentinela (`iugucc`)**.

---

## Variáveis de Entrada do Mapeamento

1. **`SOURCE_SCHEMAS` (Esquemas de Origem — WHMCS 8.13.7 & Browser DOM):**
   - `whmcs_db.tblinvoices`: `{id: int, userid: int, invoicenum: string, date: date, duedate: date, total: decimal(10,2), credit: decimal(10,2), status: string, paymentmethod: string}`
   - `whmcs_db.tblclients`: `{id: int, firstname: string, lastname: string, companyname: string, email: string, address1: string, address2: string, city: string, state: string, postcode: string, country: string, phonenumber: string, tax_id: string, language: string}`
   - `whmcs_db.tblcustomfields`: `{id: int, type: string, fieldname: string}`
   - `whmcs_db.tblcustomfieldsvalues`: `{fieldid: int, relid: int, value: string}`
   - `whmcs_db.tblpaymentgateways`: `{gateway: string, setting: string, value: text}`
   - `whmcs_db.tblaccounts`: `{id: int, userid: int, currency: int, gateway: string, date: datetime, description: string, amountin: decimal(10,2), fees: decimal(10,2), amountout: decimal(10,2), transid: string, invoiceid: int}`
   - `whmcs_db.tblhosting`: `{id: int, userid: int, orderid: int, packageid: int, domain: string, paymentmethod: string, domainstatus: string}`
   - `browser_iugu_js.payment_token_response`: `{id: string, method: string, test: bool, extra_info: object}`

2. **`TARGET_SCHEMAS` (Esquemas de Destino):**
   - **Alvo 1:** `iugu_api.v1_charge_request` (`POST https://api.iugu.com/v1/charge`)
   - **Alvo 2:** `whmcs_db.tblaccounts` & `whmcs_db.tblinvoices` (Baixa Síncrona e Assíncrona via Webhook `invoice.status_changed`)
   - **Alvo 3:** `whmcs_db.tblgatewaylog` (Auditoria PCI-DSS Sanitizada + Diagnóstico Sentinela E2E)
   - **Alvo 4:** `sentinel_archive.no_hot_snapshot` (`archived/snapshots/snapshot_<ts>.json`)

3. **`BUSINESS_CONTEXT`:**
   - Processamento transparente de cartão de crédito para `imovelsite.com.br` sem interferir no módulo de boleto bancário existente (`edvan`).
   - Conformidade estrita **PCI-DSS SAQ A**: PAN (`number`) e CVV (`verification_value`) jamais transitam pelo backend do WHMCS.

---

## Iteração 1 — Plano de Mapeamento: `iugu_api.v1_charge_request` (`POST /v1/charge`)

* **Tabela Fonte Âncora (Anchor Source Table):** `whmcs_db.tblinvoices`
* **Caminhos de Junção & Otimização (Join Paths):**
  * `tblinvoices` JOIN `tblclients` ON `tblinvoices.userid = tblclients.id` (Large-to-Medium, 1:1 por fatura)
  * `tblclients` LEFT JOIN `tblcustomfieldsvalues` ON `tblcustomfieldsvalues.relid = tblclients.id` JOIN `tblcustomfields` ON `tblcustomfields.id = tblcustomfieldsvalues.fieldid AND tblcustomfields.type = 'client'` (Large-to-Small Lookup para extração prioritária de CPF/CNPJ)
  * `tblinvoices` LEFT JOIN `tblaccounts` ON `tblaccounts.invoiceid = tblinvoices.id` (Agregação `SUM(amountin - amountout)` para cálculo de saldo remanescente em pagamentos parciais)
  * `tblpaymentgateways` WHERE `gateway = 'iugucc'` (Static Broadcast Lookup de configuração)

### Mapeamento de Campos (`iugu_api.v1_charge_request`)

| Campo de Destino (`Target Field`) | Campo Fonte / Lógica (`Source Field / Logic`) | Tipo de Mapeamento | Racional / Regras de Transformação e Limpeza |
| :--- | :--- | :--- | :--- |
| `token` (`REQUIRED`) | `browser_iugu_js.payment_token_response.id` (`$_POST['iugu_token']`) | Direct | Token descartável de uso único gerado no navegador via `Iugu.createPaymentToken()`. Validado contra vazio (`MISSING_TOKEN`). |
| `email` (`REQUIRED`) | `tblclients.email` | Derived | `trim(tblclients.email)`. Fallback defensivo `NOT NULL`: `'financeiro@imovelsite.com.br'` caso ausente. |
| `months` (`REQUIRED`) | `$_POST['installments']` + `tblpaymentgateways(maxInstallments, minInstallmentValue)` | Derived | Cast para `int`, limitado a `1..maxInstallments`. Se `(amount / months) < minInstallmentValue`, força `1`. |
| `keep_dunning` | Constante `false` | Gap (Defaulted) | Cobrança direta por token de cartão não deve acionar régua de cobrança de boleto. |
| `order_id` | `'whmcs_' . tblinvoices.id` | Derived | Prefixo canônico `whmcs_{id}` para correlação determinística de Webhooks. |
| `soft_descriptor_light` | `tblpaymentgateways.value` (`statementDescriptor`) | Derived | `substr(trim(value), 0, 12)` (limite oficial Iugu: 12 caracteres), padrão `'IMOVELSITE'`. |
| `items[0].description` (`REQUIRED`) | `tblpaymentgateways(invoiceDescription)` + `tblinvoices.id` | Derived | Substitui `{invoice_id}` por `tblinvoices.id` e `{company_name}` por `'imovelsite.com.br'`. |
| `items[0].quantity` (`REQUIRED`) | Constante `1` | Gap (Defaulted) | Consolida o saldo líquido a pagar da fatura WHMCS em 1 item financeiro. |
| `items[0].price_cents` (`REQUIRED`) | `round((tblinvoices.total - SUM(tblaccounts.amountin - amountout)) * 100)` | Aggregated / Derived | Converte o saldo remanescente (`Decimal BRL`) em centavos inteiros (`int > 0`), autenticado via assinatura HMAC-SHA256. |
| `payer.name` (`REQUIRED`) | `tblclients.firstname . ' ' . tblclients.lastname` | Derived | Concatenação com `trim()`. Fallback: `tblclients.companyname` ou `'Cliente WHMCS'`. |
| `payer.cpf_cnpj` | `tblcustomfieldsvalues.value` / `tblclients.tax_id` / `$_POST['cpf_cnpj']` | Joined / Derived | Remove não-dígitos (`\D`). Prioriza campo com nome configurado (`cpfCustomField`) e valida dígito verificador **Mod-11** (evitando colisão com celulares brasileiros de 11 dígitos). |
| `payer.phone_prefix` | `substr(digitsOnly(tblclients.phonenumber), 0, 2)` | Derived | Remove DDI `55` se `strlen >= 12` e extrai os 2 dígitos do DDD brasileiro. |
| `payer.phone` | `substr(digitsOnly(tblclients.phonenumber), 2, 9)` | Derived | Extrai os 8 ou 9 dígitos do telefone após o DDD. |
| `payer.address.street` | `PayloadBuilder::splitStreetAndNumber(tblclients.address1)[0]` | Derived | Separa logradouro do número por regex. Fallback `NOT NULL`: `'Não informado'`. |
| `payer.address.number` | `PayloadBuilder::splitStreetAndNumber(tblclients.address1)[1]` | Derived | Extrai número do endereço ou aplica fallback `'S/N'`. |
| `payer.address.district` | `tblclients.address2` | Derived | Bairro do cliente; fallback `'Centro'`. |
| `payer.address.city` | `tblclients.city` | Derived | Cidade do cliente; fallback `'São Paulo'`. |
| `payer.address.state` | `tblclients.state` | Derived | UF do cliente; fallback `'SP'`. |
| `payer.address.country` | `tblclients.country` | Derived | Código ISO do país; fallback `'BR'`. |
| `payer.address.zip_code` | `digitsOnly(tblclients.postcode)` | Derived | CEP apenas numérico (8 dígitos); fallback `'01001000'`. |
| `custom_variables` | `[{name: 'whmcs_invoice_id', value: tblinvoices.id}, {name: 'whmcs_client_id', value: tblclients.id}, {name: 'integration_module', value: 'iugucc'}]` | Derived | Metadados imutáveis gravados na fatura Iugu para reconciliação de Webhook e isolamento contra faturas de outros sistemas. |

---

## Iteração 2 — Plano de Mapeamento: `whmcs_db.tblaccounts` & `whmcs_db.tblinvoices` (Baixa de Pagamento & Provisão)

* **Tabela Fonte Âncora (Anchor Source Table):** `iugu_api.v1_invoice_resource` (`GET https://api.iugu.com/v1/invoices/{id}` + Resposta de `POST /v1/charge`)
* **Caminhos de Junção & Prevenção de Duplicidade:**
  * `v1_invoice_resource.custom_variables['whmcs_invoice_id']` JOIN `whmcs_db.tblinvoices.id`
  * Verificação Anti-Duplicidade (Idempotência): `NOT EXISTS (SELECT 1 FROM tblaccounts WHERE transid = v1_invoice_resource.id)` via `checkCbTransID()` e `isTransactionRecorded()`.

### Mapeamento de Campos (`whmcs_db.tblaccounts` via `addInvoicePayment`)

| Campo de Destino (`Target Field`) | Campo Fonte / Lógica (`Source Field / Logic`) | Tipo de Mapeamento | Racional / Regras de Transformação e Limpeza |
| :--- | :--- | :--- | :--- |
| `tblaccounts.invoiceid` (`NOT NULL`) | `v1_invoice_resource.custom_variables['whmcs_invoice_id']` (fallback: `order_id` regex `^whmcs_(\d+)$`) | Derived / Joined | Validado no WHMCS via `checkCbInvoiceID($invoiceId, 'iugucc')`. Faturas sem vínculo com WHMCS são ignoradas com HTTP 200 (`ignored_foreign_invoice`). |
| `tblaccounts.transid` (`NOT NULL`) | `v1_invoice_resource.id` (`invoice_id` de 32 chars da Iugu) | Direct | Identificador único da transação na Iugu. Garante idempotência entre o retorno síncrono do checkout e o disparo assíncrono do Webhook. |
| `tblaccounts.amountin` (`NOT NULL`) | `round((v1_invoice_resource.total_paid_cents ?? total_cents) / 100, 2)` | Derived | Conversão de centavos inteiros da Iugu para `DECIMAL(10,2)` em Reais (`BRL`). |
| `tblaccounts.fees` (`NOT NULL`) | `round((v1_invoice_resource.taxes_paid_cents ?? 0) / 100, 2)` | Derived | Taxa efetiva descontada pela Iugu (`taxes_paid_cents`), permitindo apuração contábil líquida exata no WHMCS. |
| `tblaccounts.gateway` (`NOT NULL`) | Constante `'iugucc'` | Direct | Isola os lançamentos contábeis do cartão de crédito em relação ao módulo de boleto bancário existente. |
| `tblinvoices.status` (`NOT NULL`) | Transição `'Unpaid'` -> `'Paid'` (executada por `addInvoicePayment`) | Derived | Aciona imediatamente o hook interno do WHMCS (`InvoicePaid` / `AutoProvision`) que ativa o produto `imovelsite` em `tblhosting.domainstatus = 'Active'`. |

---

## Iteração 3 — Plano de Mapeamento: `whmcs_db.tblgatewaylog` (PCI Sanitizer & Sentinela E2E)

* **Tabela Fonte Âncora (Anchor Source Table):** Eventos de `ChargeProcessor`, `WebhookHandler` e `SentinelAuditor`

### Mapeamento de Campos (`whmcs_db.tblgatewaylog` via `logTransaction`)

| Campo de Destino (`Target Field`) | Campo Fonte / Lógica (`Source Field / Logic`) | Tipo de Mapeamento | Racional / Regras de Transformação e Limpeza |
| :--- | :--- | :--- | :--- |
| `tblgatewaylog.gateway` | Constante `'iugucc'` | Direct | Identifica o módulo nos filtros de `Billing > Gateway Log`. |
| `tblgatewaylog.data` | `PciSanitizer::sanitize($payload + $sentinelAudit)` | Derived | Remove recursivamente qualquer chave proibida (`number`, `cvv`, `verification_value`), mascara chaves de API (`apiToken`) e aplica regex anti-PAN (`****-****-****-1234`). Inclui o bloco `sentinel_audit` com `trace_id` e `support_action_hint`. |
| `tblgatewaylog.result` | Status padronizado (`'Success'`, `'Declined'`, `'PCI Violation Blocked'`, `'Webhook Payment Recorded'`) | Direct | Permite auditoria rápida pelo suporte técnico e rastreamento pelo Agente Sentinela. |

---

## Iteração 4 — Plano de Mapeamento: `sentinel_archive.no_hot_snapshot` (`archived/snapshots/snapshot_<ts>.json`)

* **Tabela Fonte Âncora (Anchor Source Table):** Sistema de Arquivos Local (`modules/gateways/iugucc*`) + Metadados Sanitizados do WHMCS (`tblpaymentgateways`)

| Campo de Destino (`Target Field`) | Campo Fonte / Lógica (`Source Field / Logic`) | Tipo de Mapeamento | Racional / Regras de Transformação e Limpeza |
| :--- | :--- | :--- | :--- |
| `schema` | `'iugucc.sentinel.snapshot.v1'` | Gap (Defaulted) | Identificador de versão do manifesto de snapshot. |
| `created_at_utc` | `gmdate('c')` | Derived | Timestamp ISO-8601 em UTC. |
| `files.{relPath}.sha256` | `hash_file('sha256', $fullPath)` | Derived | Assinatura criptográfica de integridade de cada um dos 16 arquivos do módulo antes/depois da instalação. |
| `whmcs_db_state` | `PciSanitizer::sanitize($dbState)` | Derived | Estado das configurações do gateway com segredos mascarados (`abcd********wxyz`). |
