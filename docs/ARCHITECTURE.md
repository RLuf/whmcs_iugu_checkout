# Arquitetura Técnica — WHMCS Iugu Credit Card Gateway (`iugucc`)

Este documento descreve a arquitetura interna, o fluxo de segurança PCI-DSS e os contratos de integração entre o **WHMCS 8.13.7** (PHP 8.1/8.2), o navegador do cliente (**Iugu.js v2**) e a **API REST v1 da Iugu**.

---

## 1. Visão Geral dos Componentes

```mermaid
flowchart TD
    subgraph Browser["Navegador do Cliente (viewinvoice.php)"]
        Form["Formulário de Cartão (checkout.php)<br/>Sem atributo name='' nos inputs de cartão"]
        IuguJS["Iugu.js v2 (https://js.iugu.com/v2)"]
        Controller["Controller JS (iugucc.js)"]
    end

    subgraph IuguCloud["Plataforma Iugu (https://api.iugu.com/v1)"]
        TokenAPI["Serviço de Tokenização Iugu"]
        ChargeAPI["POST /v1/charge"]
        InvoiceAPI["GET /v1/invoices/{id}"]
        WebhookTrigger["Gatilhos / Webhooks Iugu"]
    end

    subgraph WHMCSServer["Servidor WHMCS 8.13.7 (PHP 8.1/8.2)"]
        GatewayEntry["modules/gateways/iugucc.php"]
        Callback["modules/gateways/callback/iugucc.php"]
        ChargeProc["ChargeProcessor.php"]
        WebhookProc["WebhookHandler.php"]
        Sanitizer["PciSanitizer.php"]
        WHMCSCore["addInvoicePayment() & Provisionamento"]
    end

    GatewayEntry -->|"Renderiza HTML/JS"| Form
    Form -->|"1. Valida Luhn/Validade/CPF"| Controller
    Controller -->|"2. Iugu.createPaymentToken()"| IuguJS
    IuguJS -->|"3. Envia PAN/CVV direto via HTTPS"| TokenAPI
    TokenAPI -->|"4. Retorna iugu_token descartável"| Controller
    Controller -->|"5. POST JSON (apenas iugu_token + assinatura)"| Callback
    Callback -->|"Modo Síncrono (action=checkout)"| ChargeProc
    ChargeProc -->|"6. POST /v1/charge com Basic Auth"| ChargeAPI
    ChargeAPI -->|"7. Retorno LR 00 + invoice_id"| ChargeProc
    ChargeProc -->|"8. Sanitiza Logs"| Sanitizer
    ChargeProc -->|"9. Baixa fatura e ativa serviço"| WHMCSCore
    WebhookTrigger -->|"Notificação Assíncrona"| Callback
    Callback -->|"Modo Webhook"| WebhookProc
    WebhookProc -->|"Confirma status real"| InvoiceAPI
    WebhookProc -->|"Baixa idempotente (se pendente)"| WHMCSCore
```

---

## 2. Fluxo de Sequência: Checkout Síncrono e Webhook Assíncrono

```mermaid
sequenceDiagram
    autonumber
    actor Cliente as Cliente (imovelsite.com.br)
    participant Browser as Navegador (iugucc.js + Iugu.js)
    participant Iugu as API Iugu (api.iugu.com/v1)
    participant WHMCS as WHMCS Callback (callback/iugucc.php)
    participant DB as WHMCS Core (addInvoicePayment)

    Cliente->>Browser: Acessa viewinvoice.php?id=1024 e preenche cartão
    Browser->>Browser: Valida Luhn, Validade, Nome e CPF/CNPJ
    Browser->>Iugu: Iugu.createPaymentToken(form)
    Iugu-->>Browser: Retorna response.id (iugu_token de uso único)
    Browser->>WHMCS: POST /modules/gateways/callback/iugucc.php (iugu_token, installments, signature)
    WHMCS->>WHMCS: Verifica assinatura HMAC-SHA256 e bloqueia qualquer PAN/CVV bruto
    WHMCS->>Iugu: POST /v1/charge (token, email, months, items, payer, custom_variables)
    alt Pagamento Aprovado (success=true, LR=00)
        Iugu-->>WHMCS: 200 OK { success: true, invoice_id: "IUGU_ID", LR: "00" }
        WHMCS->>Iugu: GET /v1/invoices/IUGU_ID (consulta taxa taxes_paid_cents)
        Iugu-->>WHMCS: 200 OK { status: "paid", taxes_paid_cents: 449 }
        WHMCS->>DB: checkCbTransID("IUGU_ID") + addInvoicePayment(1024, "IUGU_ID", 150.00, 4.49, "iugucc")
        DB->>DB: Marca fatura como Paid e dispara hook de provisionamento do serviço
        WHMCS-->>Browser: 200 OK { success: true, redirect_url: "viewinvoice.php?id=1024&paymentsuccess=true" }
        Browser-->>Cliente: Redireciona para fatura paga e serviço ativo
    else Pagamento Recusado (LR 51, 54, 82, 05...)
        Iugu-->>WHMCS: 200 OK { success: false, LR: "51" }
        WHMCS-->>Browser: 422 { success: false, message: "Saldo ou limite insuficiente..." }
        Browser-->>Cliente: Exibe mensagem amigável para tentar outro cartão
    end

    Note over Iugu,WHMCS: Fluxo Assíncrono (Gatilho / Webhook da Iugu)
    Iugu->>WHMCS: POST /modules/gateways/callback/iugucc.php (event=invoice.status_changed, data[id]=IUGU_ID)
    WHMCS->>Iugu: GET /v1/invoices/IUGU_ID (validação server-to-server contra falsificação)
    Iugu-->>WHMCS: { status: "paid", custom_variables: [{ name: "whmcs_invoice_id", value: "1024" }] }
    WHMCS->>DB: Verifica se transação IUGU_ID já consta em tblaccounts
    alt Ainda não baixada no WHMCS
        WHMCS->>DB: addInvoicePayment(1024, "IUGU_ID", 150.00, 4.49, "iugucc")
    else Já baixada no retorno síncrono
        WHMCS->>WHMCS: Ignora de forma idempotente (200 OK noop_already_recorded)
    end
```

---

## 3. Garantias de Conformidade PCI-DSS
1. **Desativação de Armazenamento Local no WHMCS**: `iugucc_MetaData()` define `'DisableLocalCreditCardInput' => true`, garantindo que o WHMCS não armazene dados de cartão no banco de dados.
2. **Inputs HTML sem Atributo `name`**: No arquivo `modules/gateways/iugucc/templates/checkout.php`, os campos com `data-iugu="number"`, `data-iugu="verification_value"`, `data-iugu="expiration"` e `data-iugu="full_name"` não possuem atributo `name`. Assim, mesmo que o formulário sofra um submit nativo, o navegador jamais transmite esses campos ao servidor.
3. **Bloqueio Ativo e Sanitização (`PciSanitizer`)**: Caso qualquer requisição malformada tente enviar campos de cartão ao endpoint `callback/iugucc.php`, `PciSanitizer::containsRawCardData()` rejeita a requisição imediatamente com `PCI_BLOCKED` e redacta qualquer sequência numérica de 13 a 19 dígitos antes de chamar `logTransaction()`.

---

## 4. Isolamento e Não-Interferência
- O módulo utiliza o nome de sistema exclusivo `iugucc`.
- Nenhum arquivo do módulo existente de boleto bancário da Iugu (desenvolvido por Edvan) é lido, modificado ou sobrescrito.
- Na API da Iugu, cada fatura gerada pelo `iugucc` recebe `custom_variables` contendo `whmcs_invoice_id` e `integration_module = iugucc`. Caso o Webhook receba eventos de faturas externas sem vínculo com o WHMCS, o `WebhookHandler` responde `200 OK` (`ignored_foreign_invoice`) sem gerar erros.
