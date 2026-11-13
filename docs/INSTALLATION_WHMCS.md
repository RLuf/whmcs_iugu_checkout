# Guia de Instalação e Configuração no WHMCS 8.13.7

Este guia detalha o passo a passo para instalar e configurar o módulo **Iugu - Cartão de Crédito (`iugucc`)** no WHMCS 8.13.7 (área do cliente em `https://www.webstorage.net.br/financeiro/`, Admin em `https://www.webstorage.net.br/financeiro/master/` — `imovelsite.com.br`).

---

## 1. Pré-requisitos do Servidor
- **WHMCS:** Versão `8.13.7` (compatível com toda a série `8.x`)
- **PHP:** `8.1` ou `8.2` com extensões `curl`, `json` e `mbstring` ativas
- **Certificado SSL (HTTPS):** Obrigatório para o funcionamento do `Iugu.js v2` em produção
- **Conta Iugu:** ID da Conta (`Account ID`) e Token de API (Teste/Sandbox e Produção/Live)
  - O **Modo Sandbox usa somente o token do tipo *Teste***. A Iugu isola teste e produção: um cartão de teste tokenizado em modo teste é recusado por um token LIVE com `400 {"errors":"token is invalid"}`. Sem token de teste, o módulo mostra um aviso na fatura em vez do formulário.

---

## 2. Instalação dos Arquivos

### 2.1 Instalador (recomendado)
Rodar **no servidor**, com o usuário dono dos arquivos do WHMCS (não precisa de root). O script copia somente os arquivos do `iugucc`, valida a sintaxe com o PHP do servidor, faz backup de uma versão anterior em `~/iugucc_backups/<data>` e não toca em nenhum outro arquivo (o módulo `iugu` de boleto, `configuration.php` e o banco ficam como estão).

```bash
# simulação: mostra o que seria copiado, sem alterar nada
curl -fsSL https://raw.githubusercontent.com/RLuf/whmcs_iugu_checkout/main/scripts/install-on-whmcs.sh | IUGUCC_REF=main bash -s -- /home/USUARIO/public_html/financeiro --dry-run
```

```bash
# instalação / atualização
curl -fsSL https://raw.githubusercontent.com/RLuf/whmcs_iugu_checkout/main/scripts/install-on-whmcs.sh | IUGUCC_REF=main bash -s -- /home/USUARIO/public_html/financeiro
```

```bash
# remoção (faz backup antes)
bash install-on-whmcs.sh /home/USUARIO/public_html/financeiro --uninstall
```

`IUGUCC_REF` aceita branch ou tag. O caminho é a **raiz do WHMCS** (a pasta que contém `configuration.php` e `modules/gateways/`), não a pasta do Admin.

### 2.2 Pacote zip (cPanel > Gerenciador de Arquivos)
`bash scripts/build-release.sh` gera `dist/iugucc-<versão>.zip` contendo apenas `modules/gateways/iugucc.php`, `modules/gateways/callback/iugucc.php` e `modules/gateways/iugucc/`. Envie o zip para a raiz do WHMCS e extraia.

### 2.3 Cópia manual (SFTP)
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
1. Acesse o Admin do WHMCS (`https://www.webstorage.net.br/financeiro/master/`).
2. Navegue até **Configurações (ícone de engrenagem) > Apps & Integrations** ou **Setup > Payments > Payment Gateways**.
3. Em **Apps & Integrations**, procure **Iugu - Cartão de Crédito** (`iugucc`) e clique em **Ativar**; ou, em **Portais para Pagamento**, use a aba **All Payment Gateways**.
4. Preencha os campos de configuração:
   - **Show on Order Form:** Marque para exibir no checkout de novos pedidos.
   - **Friendly Name (Nome de Exibição):** `Cartão de Crédito`
   - **ID da Conta Iugu (Account ID):** Cole o código hexadecimal de 32 caracteres da sua conta Iugu (encontrado no painel da Iugu em *Configurações > Conta*).
   - **Token de API (Produção / Live):** Cole o seu token privado de produção.
   - **Token de API (Teste / Sandbox):** Cole o token do tipo *Teste* (obrigatório para o Modo Sandbox; não há reaproveitamento do token de produção).
   - **Modo Sandbox (Ambiente de Teste):** Marque durante os testes de homologação; desmarque quando for operar em produção. Com o Sandbox ligado a fatura mostra o aviso de ambiente de teste e aceita os cartões de teste da Iugu (ex.: `4111 1111 1111 1111`).
   - **Parcelamento Máximo:** Escolha de `1x` até `12x`.
   - **Valor Mínimo da Parcela (R$):** Exemplo: `5.00`.
   - **Descrição da Fatura na Iugu:** `Fatura #{invoice_id} - imovelsite.com.br`
   - **Identificação na Fatura do Cartão:** `IMOVELSITE` (até 12 caracteres, limite do `soft_descriptor_light` da Iugu).
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
     https://www.webstorage.net.br/financeiro/modules/gateways/callback/iugucc.php
     ```
     *(Se você configurou um Segredo de Webhook no WHMCS, adicione `?webhook_token=SEU_SEGREDO` ao final da URL ou configure o cabeçalho `Authorization` na Iugu).*
   - **Eventos Recomendados:**
     - `Fatura mudou de status (invoice.status_changed)`
     - `Reembolso de fatura (invoice.refund)`
     - `Falha no pagamento (invoice.payment_failed)`
3. Salve o gatilho.

---

## 5. Onde o formulário de cartão aparece
O formulário de cartão é exibido **na página da fatura** (`viewinvoice.php`). Na tela de conclusão do pedido e nos e-mails de fatura (`{$invoice_payment_link}`) o módulo mostra apenas o botão **Pagar com Cartão de Crédito**, que leva à fatura — formulário e scripts não são enviados por e-mail.

## 6. Validação após instalar
```bash
# leitura apenas: confere se os arquivos estão publicados e se o gateway aparece no checkout
node tests/e2e/live_flow.js preflight
```
O teste definitivo (cliente contratando a partir de `imovelsite.com.br`, pagando com cartão de teste em Modo Sandbox, serviço ativado, e-mails sem repetição, painel do site e cadastro de imóvel) é `node tests/e2e/live_flow.js full` — ver `AGENTS.md`, seção 4.
