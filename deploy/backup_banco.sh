#!/usr/bin/env bash
# ==========================================================================
# Backup do banco de dados (notas, XMLs, eventos, fila, monitor).
#
# Uso manual (na pasta do sistema):   sudo bash deploy/backup_banco.sh
# Automatico todo dia as 02:30 (ver docs/manual-implantacao.md, Parte D).
#
# Cria /var/backups/nfe-integracao/banco-AAAAMMDD-HHMM.dump e apaga os
# backups com mais de DIAS_GUARDAR dias. Para restaurar, veja o manual.
# ==========================================================================
set -euo pipefail

DIAS_GUARDAR="${DIAS_GUARDAR:-30}"
DESTINO="${DESTINO:-/var/backups/nfe-integracao}"

cd "$(dirname "$0")/.."

if [ ! -f config/database.local.php ]; then
  echo "ERRO: config/database.local.php nao encontrado. Rode este script na pasta do sistema (veja docs/manual-implantacao.md, passo 5)."
  exit 1
fi

ler() { php -r '$c = require "config/database.local.php"; echo $c[$argv[1]];' "$1"; }
# Atribui primeiro e exporta depois: assim, se a leitura falhar, o script para.
PGHOST="$(ler host)"
PGPORT="$(ler port)"
PGDATABASE="$(ler database)"
PGUSER="$(ler username)"
PGPASSWORD="$(ler password)"
export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD

mkdir -p "$DESTINO"
chmod 700 "$DESTINO"

ARQUIVO="$DESTINO/banco-$(date +%Y%m%d-%H%M).dump"
pg_dump --format=custom --file="$ARQUIVO" "$PGDATABASE"
chmod 600 "$ARQUIVO"

find "$DESTINO" -name 'banco-*.dump' -mtime +"$DIAS_GUARDAR" -delete

echo "Backup criado: $ARQUIVO ($(du -h "$ARQUIVO" | cut -f1))"
echo "Backups guardados em $DESTINO:"
ls -1t "$DESTINO" | head -5
