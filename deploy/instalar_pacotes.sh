#!/usr/bin/env bash
# ==========================================================================
# Instala os programas que o sistema precisa (Ubuntu 24.04 LTS).
#
# Uso:  sudo bash deploy/instalar_pacotes.sh
#
# Instala: nginx (servidor web), PHP 8.3 + extensoes, PostgreSQL 16 (banco),
#          Composer (bibliotecas PHP), git, certbot (HTTPS gratuito).
# Pode rodar de novo sem problema: o que ja estiver instalado e mantido.
# ==========================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Rode com sudo:  sudo bash deploy/instalar_pacotes.sh"
  exit 1
fi

if ! grep -q 'VERSION_ID="24.04"' /etc/os-release; then
  echo "ATENCAO: este script foi preparado para Ubuntu 24.04. Seu sistema:"
  grep PRETTY_NAME /etc/os-release
  read -r -p "Continuar mesmo assim? (s/N) " resposta
  [ "${resposta,,}" = "s" ] || exit 1
fi

echo ">> Atualizando a lista de pacotes..."
apt-get update

echo ">> Instalando programas..."
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  nginx \
  php8.3-fpm php8.3-cli \
  php8.3-curl php8.3-xml php8.3-mbstring php8.3-gd php8.3-zip \
  php8.3-pgsql php8.3-soap php8.3-bcmath php8.3-intl \
  postgresql postgresql-contrib \
  composer git unzip \
  certbot python3-certbot-nginx

echo ">> Ligando os servicos para iniciarem junto com o servidor..."
systemctl enable --now nginx php8.3-fpm postgresql

echo
echo "OK! Programas instalados:"
php -v | head -1
psql --version
nginx -v 2>&1
composer --version 2>/dev/null | head -1
