#!/usr/bin/env bash
# =============================================================================
# Instalador do gateway iugucc em uma instalação WHMCS (rodar NO SERVIDOR, com o
# usuário dono dos arquivos do WHMCS — não precisa de root).
#
#   bash install-on-whmcs.sh /caminho/do/whmcs            # instala / atualiza
#   bash install-on-whmcs.sh /caminho/do/whmcs --dry-run  # só mostra o que faria
#   bash install-on-whmcs.sh /caminho/do/whmcs --uninstall
#
# Uma linha, direto do GitHub (branch ou tag em IUGUCC_REF):
#   curl -fsSL https://raw.githubusercontent.com/RLuf/whmcs_iugu_checkout/main/scripts/install-on-whmcs.sh \
#     | IUGUCC_REF=main bash -s -- /home/USUARIO/public_html/financeiro
#   (antes do merge na main, troque as duas ocorrências de "main" pelo nome da branch)
#
# Garantias:
#   - Copia SOMENTE os arquivos do iugucc:
#       modules/gateways/iugucc.php
#       modules/gateways/callback/iugucc.php
#       modules/gateways/iugucc/**
#   - Nunca altera, sobrescreve ou remove qualquer outro arquivo (o módulo "iugu" de
#     boleto, configuration.php, banco de dados e demais gateways não são tocados).
#   - Se já houver uma versão do iugucc, faz backup antes em ~/iugucc_backups/<data>
#     (fora da pasta pública do site; mude com IUGUCC_BACKUP_DIR).
#   - Valida a sintaxe de cada .php com o PHP do servidor antes de copiar.
#   - Não ativa o gateway: a ativação é feita no Admin do WHMCS (passos impressos ao final).
# =============================================================================
set -euo pipefail

WHMCS_ROOT="${1:-}"
MODE="install"
for arg in "${@:2}"; do
  case "$arg" in
    --dry-run) MODE="dry-run" ;;
    --uninstall) MODE="uninstall" ;;
    *) echo "Opção desconhecida: $arg" >&2; exit 2 ;;
  esac
done
REF="${IUGUCC_REF:-main}"
REPO="${IUGUCC_REPO:-RLuf/whmcs_iugu_checkout}"
SRC="${IUGUCC_SRC:-}"   # opcional: diretório local já contendo modules/ (ex.: zip extraído ou git clone)

say() { printf '%s\n' "$*"; }
die() { printf 'ERRO: %s\n' "$*" >&2; exit 1; }

[ -n "$WHMCS_ROOT" ] || die "informe o diretório do WHMCS. Ex.: bash $0 /home/usuario/public_html/financeiro"
WHMCS_ROOT="$(cd "$WHMCS_ROOT" 2>/dev/null && pwd)" || die "diretório não encontrado: ${1}"
for must in init.php configuration.php modules/gateways modules/gateways/callback; do
  [ -e "$WHMCS_ROOT/$must" ] || die "$WHMCS_ROOT não parece ser a raiz de um WHMCS (falta $must)"
done

FILES_TOP=(modules/gateways/iugucc.php modules/gateways/callback/iugucc.php)
DIR_MOD=modules/gateways/iugucc
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="${IUGUCC_BACKUP_DIR:-${HOME:-/tmp}/iugucc_backups}/${STAMP}"

existing=0
for f in "${FILES_TOP[@]}" "$DIR_MOD"; do [ -e "$WHMCS_ROOT/$f" ] && existing=1; done

backup_existing() {
  [ "$existing" -eq 1 ] || return 0
  say "Backup da versão instalada em: $BACKUP_DIR"
  [ "$MODE" = "dry-run" ] && return 0
  mkdir -p "$BACKUP_DIR"
  for f in "${FILES_TOP[@]}" "$DIR_MOD"; do
    if [ -e "$WHMCS_ROOT/$f" ]; then
      mkdir -p "$BACKUP_DIR/$(dirname "$f")"
      cp -a "$WHMCS_ROOT/$f" "$BACKUP_DIR/$f"
    fi
  done
}

if [ "$MODE" = "uninstall" ]; then
  [ "$existing" -eq 1 ] || { say "iugucc não está instalado em $WHMCS_ROOT. Nada a fazer."; exit 0; }
  say "Antes de remover, desative o gateway no Admin (Portais para Pagamento > Desativar)."
  backup_existing
  for f in "${FILES_TOP[@]}"; do rm -f "$WHMCS_ROOT/$f"; done
  rm -rf "${WHMCS_ROOT:?}/$DIR_MOD"
  say "iugucc removido. Backup preservado em $BACKUP_DIR"
  exit 0
fi

TMP=""
cleanup() { [ -n "$TMP" ] && rm -rf "$TMP"; return 0; }
trap cleanup EXIT

if [ -z "$SRC" ]; then
  TMP="$(mktemp -d)"
  say "Baixando $REPO@$REF do GitHub..."
  curl -fsSL "https://codeload.github.com/${REPO}/tar.gz/${REF}" -o "$TMP/src.tar.gz" || die "falha ao baixar o pacote do GitHub"
  tar -xzf "$TMP/src.tar.gz" -C "$TMP"
  SRC="$(find "$TMP" -mindepth 1 -maxdepth 1 -type d | head -1)"
fi
for f in "${FILES_TOP[@]}" "$DIR_MOD/autoload.php" "$DIR_MOD/whmcs.json" "$DIR_MOD/lib/Config.php"; do
  [ -f "$SRC/$f" ] || die "pacote incompleto: falta $f em $SRC"
done

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -n "$PHP_BIN" ]; then
  PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  say "PHP do servidor (CLI): $PHP_VER"
  case "$PHP_VER" in 8.1|8.2|8.3) ;; *) say "AVISO: o módulo é homologado em PHP 8.1 e 8.2 (encontrado $PHP_VER no CLI; confira o PHP usado pelo site)." ;; esac
  while IFS= read -r -d '' phpfile; do
    "$PHP_BIN" -l "$phpfile" >/dev/null || die "erro de sintaxe em $phpfile com o PHP do servidor"
  done < <(find "$SRC/$DIR_MOD" "$SRC/modules/gateways/iugucc.php" "$SRC/modules/gateways/callback/iugucc.php" -name '*.php' -print0)
  say "Sintaxe PHP validada em todos os arquivos do módulo."
else
  say "AVISO: php CLI não encontrado; validação de sintaxe pulada."
fi

VERSION="$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "$SRC/$DIR_MOD/whmcs.json" | head -1)"
say "Instalando iugucc ${VERSION:-?} em $WHMCS_ROOT (modo: $MODE)"
backup_existing

if [ "$MODE" = "dry-run" ]; then
  say "Arquivos que seriam copiados:"
  for f in "${FILES_TOP[@]}"; do say "  $f"; done
  (cd "$SRC" && find "$DIR_MOD" -type f | sort | sed 's/^/  /')
  say "Nada foi alterado (dry-run)."
  exit 0
fi

for f in "${FILES_TOP[@]}"; do install -m 0644 "$SRC/$f" "$WHMCS_ROOT/$f"; done
rm -rf "${WHMCS_ROOT:?}/$DIR_MOD"
mkdir -p "$WHMCS_ROOT/$DIR_MOD"
(cd "$SRC/$DIR_MOD" && find . -type d -print0) | while IFS= read -r -d '' d; do mkdir -p "$WHMCS_ROOT/$DIR_MOD/$d"; chmod 0755 "$WHMCS_ROOT/$DIR_MOD/$d"; done
(cd "$SRC/$DIR_MOD" && find . -type f -print0) | while IFS= read -r -d '' f; do install -m 0644 "$SRC/$DIR_MOD/$f" "$WHMCS_ROOT/$DIR_MOD/$f"; done

if [ "$(id -u)" -eq 0 ]; then
  OWNER="$(stat -c '%U:%G' "$WHMCS_ROOT/init.php")"
  chown "$OWNER" "${FILES_TOP[@]/#/$WHMCS_ROOT/}"
  chown -R "$OWNER" "$WHMCS_ROOT/$DIR_MOD"
  say "Dono ajustado para $OWNER (mesmo do WHMCS)."
fi

say ""
say "Arquivos instalados (SHA-256):"
(cd "$WHMCS_ROOT" && { for f in "${FILES_TOP[@]}"; do sha256sum "$f"; done; find "$DIR_MOD" -type f | sort | xargs sha256sum; }) | sed 's/^/  /'
say ""
say "Pronto. Próximos passos no Admin do WHMCS:"
say "  1. Configurações > Apps & Integrations > buscar \"Iugu - Cartão de Crédito\" > Ativar"
say "     (ou Configurações > Portais para Pagamento)."
say "  2. Marcar \"Exibir no Formulário de Pedido\" e preencher:"
say "     - ID da Conta Iugu (Account ID)"
say "     - Token de API (Teste / Sandbox)  e marcar \"Modo Sandbox\" para homologar"
say "       (o token de produção não aceita cartões de teste)"
say "  3. Gatilho na Iugu (opcional, recomendado):"
say "     <URL do WHMCS>/modules/gateways/callback/iugucc.php  — evento invoice.status_changed"
[ "$existing" -eq 1 ] && say "Backup da versão anterior: $BACKUP_DIR"
exit 0
