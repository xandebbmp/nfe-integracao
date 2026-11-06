#!/usr/bin/env bash
# ==========================================================================
# Cria/atualiza as tabelas do banco rodando os scripts de database/scripts/
# em ordem (0001, 0002, 0003...).
#
# Uso (na pasta do sistema):  bash deploy/aplicar_banco.sh
#                              bash deploy/aplicar_banco.sh --sim   (sem perguntar)
#
# Le o endereco, usuario e senha do banco em config/database.local.php.
# Os scripts sao feitos para poder rodar mais de uma vez sem estragar nada
# ("IF NOT EXISTS"), entao e seguro rodar de novo apos cada atualizacao.
# Se algum script der erro, o processo PARA e nada daquele script e gravado.
# ==========================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f config/database.local.php ]; then
  echo "ERRO: crie primeiro config/database.local.php (veja docs/manual-implantacao.md, passo 5)."
  exit 1
fi

# Le a configuracao do banco usando o proprio PHP do sistema.
ler() { php -r '$c = require "config/database.local.php"; echo $c[$argv[1]];' "$1"; }
# Atribui primeiro e exporta depois: assim, se a leitura falhar, o script para.
PGHOST="$(ler host)"
PGPORT="$(ler port)"
PGDATABASE="$(ler database)"
PGUSER="$(ler username)"
PGPASSWORD="$(ler password)"
export PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD

echo "Banco: ${PGDATABASE} em ${PGHOST}:${PGPORT} (usuario ${PGUSER})"
echo "Scripts que serao aplicados, nesta ordem:"
ls -1 database/scripts/*.sql | sed 's/^/  - /'
# --sim pula a pergunta (usado pelo deploy/atualizar.sh).
if [ "${1:-}" != "--sim" ]; then
  read -r -p "Continuar? (s/N) " resposta
  case "$resposta" in s|S) ;; *) echo "Cancelado."; exit 0 ;; esac
fi

for script in database/scripts/*.sql; do
  echo ">> Aplicando $(basename "$script")..."
  if ! saida="$(psql -v ON_ERROR_STOP=1 -q -f "$script" 2>&1)"; then
    echo "$saida"
    echo "ERRO no script $(basename "$script"). Nada dele foi gravado. Corrija e rode de novo."
    exit 1
  fi
  # Mostra so o que interessa (esconde avisos de "ja existe").
  filtrada="$(echo "$saida" | grep -v -E 'NOTICE|already exists, skipping' || true)"
  if [ -n "$filtrada" ]; then
    echo "$filtrada"
  fi
  echo "   ok"
done

# Anota o que rodou em sistema_scripts (script 0008), para o botao
# "Atualizar banco" do painel saber que esta tudo em dia.
for script in database/scripts/*.sql; do
  nome="$(basename "$script")"
  sha="$(php -r 'echo hash_file("sha256", $argv[1]);' "$script")"
  psql -v ON_ERROR_STOP=1 -q -c "INSERT INTO sistema_scripts (nome, sha256, origem) VALUES ('${nome}', '${sha}', 'TERMINAL')
    ON CONFLICT (nome) DO UPDATE SET sha256 = EXCLUDED.sha256, origem = EXCLUDED.origem, aplicado_em = CURRENT_TIMESTAMP" >/dev/null
done

echo
echo "OK! Banco atualizado."
