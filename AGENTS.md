# AGENTS.md — Regras de Segurança, Arquivamento e Governança do Projeto

Este documento define as diretrizes obrigatórias para qualquer agente de IA ou desenvolvedor atuando neste repositório (`RLuf/whmcs_iugu_checkout`).

---

## 1. Regra Obrigatória de Arquivamento (`archived/`)
> **CRÍTICO — ANTES DE MODIFICAR OU DELETAR QUALQUER ARQUIVO:**
> Antes de alterar, sobrescrever ou remover qualquer arquivo existente do projeto, você **DEVE** obrigatoriamente copiar/mover uma versão de backup do arquivo original para dentro do diretório `archived/` (ex: `archived/<timestamp>_<nome_do_arquivo>`).
> O diretório `archived/` está configurado no `.gitignore` para não poluir o histórico do repositório remoto, garantindo recuperação imediata local em caso de regressão.

### Procedimento Padrão (PowerShell / Bash):
```powershell
# Exemplo no PowerShell antes de editar um arquivo existente:
$ts = Get-Date -Format "yyyyMMdd_HHmmss"
Copy-Item "modules/gateways/iugucc.php" "archived/${ts}_iugucc.php"
```

---

## 2. Política Estrita de Não-Interferência
1. **Módulo de Boleto Bancário Existente (Edvan):**
   - **PROIBIDO** alterar, sobrescrever ou interferir no módulo de boleto bancário da Iugu já existente (desenvolvido por Edvan).
   - Este módulo de Cartão de Crédito utiliza o identificador exclusivo `iugucc` (`modules/gateways/iugucc.php`, `modules/gateways/callback/iugucc.php` e `modules/gateways/iugucc/`) para garantir isolamento total.
2. **Servidores de Produção Remotos:**
   - **PROIBIDO** executar alterações diretas ou scripts destrutivos nos servidores `bells.webstorage.com.br` e `red.webstorage.com.br`.
3. **Credenciais Privadas:**
   - **PROIBIDO** inserir tokens privados de produção (`api_token` ou chaves reais) hardcoded no código-fonte. Todas as credenciais devem ser lidas dinamicamente da configuração do WHMCS Admin (`tblpaymentgateways`).

---

## 3. Conformidade PCI-DSS e Segurança de Dados
- Dados sensíveis de cartão de crédito (`number`, `verification_value` / `cvv`) **NUNCA** devem transitar pelo servidor do WHMCS nem ser gravados em logs (`logTransaction` ou arquivos de log).
- A tokenização deve ocorrer exclusivamente no navegador do cliente via `Iugu.js v2` (`https://js.iugu.com/v2` — o host `js.iugu.net` **não existe** no DNS e nunca deve ser usado), enviando ao servidor apenas o `iugu_token` descartável gerado pela Iugu.
- Todo payload registrado no Gateway Log do WHMCS deve passar obrigatoriamente pelo `PciSanitizer::sanitize()`.

---

## 4. Compatibilidade e Padrões de Qualidade
- **WHMCS Alvo:** `8.13.7` — área do cliente/raiz em `https://www.webstorage.net.br/financeiro/`, Admin em `https://www.webstorage.net.br/financeiro/master/`, vitrine em `https://imovelsite.com.br/`. URLs do módulo (callback/webhook, assets) ficam sob a **raiz**: `https://www.webstorage.net.br/financeiro/modules/gateways/callback/iugucc.php`.
- **PHP Alvo:** `8.1` e `8.2` (`declare(strict_types=1);`, tipagem forte, sem propriedades dinâmicas depreciadas no PHP 8.2).
- **Versionamento Semântico:** Utilize `node scripts/bump-version.js <patch|minor|major>` para atualizar a versão no arquivo `VERSION`, `composer.json` e metadados do módulo.
- **Hosts oficiais da Iugu:** API REST em `https://api.iugu.com/v1` e Iugu.js v2 em `https://js.iugu.com/v2`. O host `js.iugu.net` não existe (NXDOMAIN) e é proibido em qualquer arquivo do módulo (há teste de regressão em `tests/run_tests.js`).

### Suíte de verificação obrigatória (igual à CI `.github/workflows/ci-and-jules-review.yml`)
Executar em **PHP 8.1 e 8.2** antes de qualquer commit:
```bash
mkdir -p archived
find modules scripts tests -name "*.php" -print0 | xargs -0 -n1 php -l
php scripts/sentinel-autosetup.php && node scripts/sentinel-autosetup.js
node tests/run_tests.js && node tests/adversarial_suite.js && node tests/adversarial_webhook_test.js
php tests/phpunit_runner.php && php tests/adversarial_stress_test.php
node tests/whmcs_finance_sim.js   # Financeiro do WHMCS com os arquivos reais do módulo (PHP_BIN=php8.1|php8.2)
```

Testes que exigem a conta de **teste** da Iugu (nunca produção) e rede para `api.iugu.com` / `js.iugu.com`:
```bash
IUGU_ACCOUNT_ID=<account_id> node tests/browser_checkout_e2e.js                      # Chromium + template real + Iugu.js v2
IUGU_E2E_CONFIRM_TEST_TOKEN=yes IUGU_ACCOUNT_ID=<account_id> IUGU_API_TOKEN=<test_token> php tests/sandbox_e2e_iugu.php  # cobrança real em sandbox
```
> **Token de produção (LIVE) é proibido em qualquer teste.** Um token LIVE lista as faturas reais da conta e `POST /v1/charge` criaria cobranças reais. Antes de usar um token, confirme que é do tipo *Teste*. O token usado pelo módulo de boleto existente é de produção.

Teste definitivo no WHMCS real (Chromium, como cliente — cria pedido de verdade; só com o gateway em **Modo Sandbox** e token de **Teste**):
```bash
node tests/e2e/live_flow.js preflight                                  # somente leitura: módulo publicado? gateway no checkout?
E2E_EMAIL=<caixa_de_teste> E2E_PREFIX=<prefixo> node tests/e2e/live_flow.js full   # imovelsite.com.br -> pedido -> cartão de teste -> serviço ativo -> e-mails -> site -> imóvel
```
O estado da conta de teste fica em `archived/e2e/` (nunca versionado). A instalação no servidor é feita por `scripts/install-on-whmcs.sh` (copia só os arquivos do `iugucc`, com backup); o pacote para cPanel sai de `scripts/build-release.sh`.

Regras de negócio fixadas por teste: o callback só cobra faturas com status `Unpaid` (qualquer outro status responde `409 INVOICE_NOT_PAYABLE`); o Modo Sandbox usa somente o token de Teste (sem recorrer ao token LIVE); fora de `viewinvoice.php` (e-mail de fatura, conclusão do pedido) `iugucc_link()` devolve apenas o botão que leva à fatura.
O relatório de conformidade (WHMCS × Iugu) e a lista de limitações do tipo *Third-Party* estão em `docs/AUDITORIA_WHMCS_IUGU.md`.

---

## 5. Ambiente de Nuvem (Claude Code) — Papel de Revisor & Tester
O ambiente de nuvem deste repositório existe **exclusivamente** para revisão de código e testes de compatibilidade com WHMCS 8.13.7 e com a API Iugu v1, seguindo a documentação oficial (`developers.whmcs.com`, `docs.whmcs.com`, `dev.iugu.com`). Nada fora do repositório/git é modificado; servidores de produção (`bells.webstorage.com.br`, `red.webstorage.com.br`) ficam **fora** da lista de rede permitida por desenho.

- **Setup script:** `scripts/cloud-env-setup.sh` (roda como root a partir do diretório pai dos checkouts; idempotente). Instala PHP 8.1 + 8.2 CLI com `curl`, `json`, `mbstring`, `openssl`; define `php` = 8.2; cria `archived/`; lint em 8.1 e 8.2; Sentinel AutoSetup (PHP e Node).
- **Rede permitida (exata, sem curingas):** `api.iugu.com`, `js.iugu.com`, `dev.iugu.com`, `developers.whmcs.com`, `docs.whmcs.com`, `marketplace.whmcs.com`, `github.com`, `raw.githubusercontent.com`, `ppa.launchpadcontent.net`, `keyserver.ubuntu.com`. Registries de pacotes (npm, apt, Packagist) liberados pelo padrão do ambiente.
- **Variáveis (não sigilosas):** `IUGU_SANDBOX=true` (o Sentinel só opera em modo sandbox), `WHMCS_TARGET_VERSION=8.13.7`, `PHP_TARGET_VERSIONS=8.1,8.2`.
- **Segredos:** o token de API **de teste/sandbox** da Iugu é guardado como *network secret* do ambiente para `api.iugu.com` (injetado pelo proxy; nunca entra em arquivo nem em log). Tokens de produção jamais são colocados no ambiente de nuvem.
- **Navegador:** Chromium + Playwright pré-instalados permitem validar o carregamento real de `https://js.iugu.com/v2` e a presença de `window.Iugu.createPaymentToken`.

---

## 6. Workflow Git (não negociável)
1. **Backup antes de operação destrutiva** (`reset`, `rebase`, `force-push`, `branch -D`): criar `backup/AAAA-MM-DD-<contexto>` ou tag `backup-<contexto>` no mesmo turno e informar como restaurar.
2. **Revisões em todo PR:** acionar Jules, GitHub Copilot e Gemini Code Assist (`@jules`, `@copilot`, `@gemini-code-assist`) quando disponíveis; registrar quais foram acionados.
3. **Releases estáveis:** tag anotada `vN.N.N` somente com `CHANGELOG.md` atualizado e suíte executada; tag publicada nunca é movida.
4. **Documentação em toda mudança relevante:** atualizar `AGENTS.md`, `CHANGELOG.md` (Keep a Changelog) e `README.md` (pt-BR primeiro, English depois).
5. **Nunca versionar histórico local:** `archived/`, `archive/`, `arquivado*/`, `historico/`, `histórico/`, `_archive*/` estão no `.gitignore`; conferir `git status` antes de cada commit.
6. **Credenciais:** nunca em arquivo versionado; conferir o diff por `ghp_`, `github_pat_`, `AKIA`, `BEGIN .* PRIVATE KEY`, `password=`, `token=`, `api_key` antes de commitar.
