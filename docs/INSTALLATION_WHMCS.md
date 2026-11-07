# Guia de Instalação e Configuração no WHMCS 8.13.7

Este guia detalha o passo a passo para instalar e configurar o módulo **Iugu - Cartão de Crédito (`iugucc`)** no WHMCS 8.13.7 (`https://www.webstorage.net.br/financeiro/master/` — `imovelsite.com.br`).

---

## 1. Pré-requisitos do Servidor
- **WHMCS:** Versão `8.13.7` (compatível com toda a série `8.x`)
- **PHP:** `8.1` ou `8.2` com extensões `curl`, `json` e `mbstring` ativas
- **Certificado SSL (HTTPS):** Obrigatório para o funcionamento do `Iugu.js v2` em produção
- **Conta Iugu:** ID da Conta (`Account ID`) e Token de API (Teste/Sandbox e Produção/Live)

---

## 2. Upload dos Arquivos (cPanel / SFTP)
Copie a pasta `modules/` deste repositório para a raiz da sua instalação do WHMCS, preservando a hierarquia exata:

```text
<raiz_do_whmcs>/
└── modules/
    └── gateways/
        ├── iugucc.php
        ├── callback/
        │   └── iugucc.php
        └── iugucc/
            ├── autoload.php
            ├── assets/
            ├── lang/
            ├── lib/
            └── templates/
```

> **Nota de Segurança:** Nenhum arquivo do módulo de boleto bancário existente (`iugu` / `iuguboleto` por Edvan) será afetado, pois todos os arquivos utilizam o namespace e identificador exclusivo `iugucc`.

### Permissões Recomendadas (cPanel / Linux):
- Diretórios: `0755`
- Arquivos `.php`, `.js`, `.css`: `0644`

---

## 3. Ativação no Painel Administrativo do WHMCS
1. Acesse o Admin do WHMCS (`https://www.webstorage.net.br/financeiro/master/admin/`).
2. Navegue até **Configurações (ícone de engrenagem) > Apps & Integrations** ou **Setup > Payments > Payment Gateways**.
3. Clique na aba **All Payment Gateways** e selecione **Iugu - Cartão de Crédito (Checkout Transparente)** (`iugucc`).
4. Preencha os campos de configuração:
   - **Show on Order Form:** Marque para exibir no checkout de novos pedidos.
   - **Friendly Name (Nome de Exibição):** `Cartão de Crédito`
   - **ID da Conta Iugu (Account ID):** Cole o código hexadecimal de 32 caracteres da sua conta Iugu (encontrado no painel da Iugu em *Configurações > Conta*).
   - **Token de API (Produção / Live):** Cole o seu token privado de produção.
   - **Token de API (Teste / Sandbox):** Cole o seu token de ambiente de testes.
   - **Modo Sandbox (Ambiente de Teste):** Marque durante os testes de homologação; desmarque quando for operar em produção.
   - **Parcelamento Máximo:** Escolha de `1x` até `12x`.
   - **Valor Mínimo da Parcela (R$):** Exemplo: `5.00`.
   - **Descrição da Fatura na Iugu:** `Fatura #{invoice_id} - imovelsite.com.br`
   - **Identificação na Fatura do Cartão:** `IMOVELSITE` (até 13 caracteres).
   - **Nome do Campo Personalizado de CPF/CNPJ:** `CPF/CNPJ` (ou o nome exato configurado em *Client Custom Fields*).
   - **Segredo de Validação do Webhook (Opcional):** Defina uma chave secreta (ex: `whsec_...`) caso queira autenticar a URL de gatilho.
   - **Log Detalhado no WHMCS (PCI-Safe):** Recomendado manter ativo para auditoria em *Billing > Gateway Log*.
5. Clique em **Save Changes**.

---

## 4. Configuração do Gatilho (Webhook) no Painel da Iugu
Embora o módulo já realize a baixa **síncrona imediata** no momento em que o pagamento é autorizado no checkout, configurar o Webhook garante redundância total (por exemplo, se o cliente fechar o navegador antes do redirecionamento ou em caso de estorno pelo painel da Iugu):

1. Acesse o Painel da Iugu > **Configurações > Gatilhos (Webhooks)**.
2. Clique em **Novo Gatilho** e configure:
   - **URL de Destino:**
     ```text
     https://www.webstorage.net.br/financeiro/master/modules/gateways/callback/iugucc.php
     ```
     *(Se você configurou um Segredo de Webhook no WHMCS, adicione `?webhook_token=SEU_SEGREDO` ao final da URL ou configure o cabeçalho `Authorization` na Iugu).*
   - **Eventos Recomendados:**
     - `Fatura mudou de status (invoice.status_changed)`
     - `Reembolso de fatura (invoice.refund)`
     - `Falha no pagamento (invoice.payment_failed)`
3. Salve o gatilho.
