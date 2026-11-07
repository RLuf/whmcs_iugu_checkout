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
- A tokenização deve ocorrer exclusivamente no navegador do cliente via `Iugu.js v2` (`https://js.iugu.net/v2`), enviando ao servidor apenas o `iugu_token` descartável gerado pela Iugu.
- Todo payload registrado no Gateway Log do WHMCS deve passar obrigatoriamente pelo `PciSanitizer::sanitize()`.

---

## 4. Compatibilidade e Padrões de Qualidade
- **WHMCS Alvo:** `8.13.7` (`https://www.webstorage.net.br/financeiro/master/` / `imovelsite.com.br`)
- **PHP Alvo:** `8.1` e `8.2` (`declare(strict_types=1);`, tipagem forte, sem propriedades dinâmicas depreciadas no PHP 8.2).
- **Versionamento Semântico:** Utilize `node scripts/bump-version.js <patch|minor|major>` para atualizar a versão no arquivo `VERSION`, `composer.json` e metadados do módulo.
