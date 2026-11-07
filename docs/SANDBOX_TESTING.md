# Guia de Testes em Sandbox e Suíte Automatizada

Este documento explica como executar os testes automatizados localmente e como homologar pagamentos aprovados e recusados no modo **Sandbox** da Iugu.

---

## 1. Executando a Suíte de Testes Automatizados Localmente
O projeto inclui uma suíte completa de verificação arquitetural, conformidade PCI-DSS, simulação de API Iugu e testes de idempotência de Webhook:

```bash
node tests/run_tests.js
```

Caso possua o binário CLI do PHP 8.1 ou 8.2 instalado no ambiente:
```bash
php tests/phpunit_runner.php
```

---

## 2. Cartões de Teste Oficiais para Modo Sandbox (`Iugu.js`)
Com a opção **Modo Sandbox (Ambiente de Teste)** ativada na configuração do gateway no WHMCS e utilizando um **Token de API de Teste** da Iugu:

| Cenário | Bandeira | Número do Cartão | CVV | Validade | Resultado Esperado |
| :--- | :--- | :--- | :---: | :---: | :--- |
| **Pagamento Aprovado** | Visa | `4111 1111 1111 1111` | `123` | `12/2030` | Aprovado (`LR 00`), fatura marcada como `Paid` e serviço provisionado |
| **Pagamento Aprovado** | Mastercard | `5555 5555 5555 4444` | `123` | `12/2030` | Aprovado (`LR 00`), fatura marcada como `Paid` e serviço provisionado |
| **Pagamento Aprovado** | American Express | `3782 822463 10005` | `1234` | `10/2029` | Aprovado (`LR 00`), fatura marcada como `Paid` |
| **Falha de Algoritmo Luhn** | Visa (Inválido) | `4111 1111 1111 1112` | `123` | `12/2030` | Bloqueado no navegador antes de chamar a API |
| **Cartão Vencido** | Qualquer | `4111 1111 1111 1111` | `123` | `01/2023` | Bloqueado na validação de data de validade |

---

## 3. Tabela de Códigos de Retorno da Adquirente (`LR`) Tratados pelo Módulo
O `ChargeProcessor` traduz automaticamente os códigos de retorno (`LR`) da Iugu para mensagens claras ao cliente (em Português ou Inglês):

| Código `LR` | Significado Técnico | Mensagem Exibida ao Cliente (PT-BR) |
| :---: | :--- | :--- |
| `00` | Transação Autorizada | Pagamento aprovado com sucesso. |
| `04` / `05` | Não autorizada pelo emissor | Transação não autorizada pelo banco emissor. Entre em contato com a central do seu cartão. |
| `07` | Restrição no cartão | Cartão com restrição no emissor (LR 07). Utilize outro cartão. |
| `51` | Saldo / limite insuficiente | Saldo ou limite insuficiente no cartão de crédito (LR 51). |
| `54` | Cartão vencido | Cartão de crédito vencido (LR 54). Verifique a data de validade. |
| `57` | Transação não permitida | Transação não permitida para este cartão (LR 57). Verifique se a função compra online está habilitada. |
| `78` | Cartão bloqueado / 1º uso | Cartão bloqueado ou aguardando desbloqueio de primeiro uso (LR 78). |
| `82` / `N7` | CVV inválido | Código de segurança (CVV) inválido. Confira os dígitos no verso do cartão. |
