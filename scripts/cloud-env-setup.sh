#!/usr/bin/env bash
# =============================================================================
# Cloud Environment Setup — Revisor & Tester (WHMCS 8.13.7 + Iugu API v1)
# Repositório: RLuf/whmcs_iugu_checkout (gateway exclusivo `iugucc`)
#
# Executado como root em cada container novo do ambiente de nuvem, a partir do
# diretório que contém os checkouts dos repositórios (não de dentro do repo).
#
# O que faz:
#   1. Instala PHP 8.1 e 8.2 CLI (alvo oficial do módulo) com as extensões
#      obrigatórias validadas pelo SentinelAuditor: curl, json, mbstring, openssl.
#      Fonte: PPA ondrej/php (ppa.launchpadcontent.net), chave via keyserver.ubuntu.com.
#   2. Define php8.2 como `php` padrão (CI do repositório testa 8.1 e 8.2).
#   3. Dentro do repositório: cria `archived/` (obrigatório pelo AGENTS.md, ignorado
#      pelo git), faz lint estrito de todos os .php em 8.1 e 8.2 e roda o Sentinel
#      AutoSetup (PHP e Node) para emitir o Sinal Verde.
#
# Não toca em servidores remotos (bells/red.webstorage.com.br) nem em credenciais.
# Idempotente: pode ser reexecutado sem efeitos colaterais.
# =============================================================================
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

. /etc/os-release
CODENAME="${VERSION_CODENAME:-noble}"
KEYRING=/etc/apt/keyrings/ondrej-php.gpg

install -d -m 0755 /etc/apt/keyrings
if [ ! -s "$KEYRING" ]; then
  curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C" \
    | gpg --dearmor -o "$KEYRING"
fi
echo "deb [signed-by=$KEYRING] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $CODENAME main" \
  > /etc/apt/sources.list.d/ondrej-php.list

apt-get update -qq
apt-get install -y -qq \
  php8.1-cli php8.1-curl php8.1-mbstring php8.1-xml \
  php8.2-cli php8.2-curl php8.2-mbstring php8.2-xml
update-alternatives --set php /usr/bin/php8.2 >/dev/null

for v in 8.1 8.2; do
  "php$v" -r 'foreach (["curl","json","mbstring","openssl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "php" . PHP_VERSION . ": extensao ausente: $e\n"); exit(1); } } echo "php" . PHP_VERSION . ": extensoes OK\n";'
done

if [ -d whmcs_iugu_checkout ]; then
  cd whmcs_iugu_checkout
  mkdir -p archived
  find modules scripts tests -name '*.php' -print0 | xargs -0 -n1 php8.1 -l >/dev/null
  find modules scripts tests -name '*.php' -print0 | xargs -0 -n1 php8.2 -l >/dev/null
  php8.1 scripts/sentinel-autosetup.php >/dev/null
  php8.2 scripts/sentinel-autosetup.php >/dev/null
  node scripts/sentinel-autosetup.js >/dev/null
  echo "whmcs_iugu_checkout: lint 8.1/8.2 OK, Sentinel Sinal Verde OK"
fi
