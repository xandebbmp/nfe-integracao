#!/usr/bin/env bash
# ==========================================================================
# Cria (ou troca a senha de) um usuario do PostgreSQL SO para as telas de
# cadastro do Scriptcase: empresas, sistemas, vinculos e numeracao inicial.
#
# Uso (na pasta do sistema):  sudo bash deploy/criar_usuario_scriptcase.sh
#                              sudo bash deploy/criar_usuario_scriptcase.sh outro_nome
#
# O usuario NAO consegue ler os certificados criptografados nem as notas.
# No final, mostra os dados para cadastrar a conexao no Scriptcase.
# Rode de novo para gerar uma senha nova (a antiga deixa de valer).
# ==========================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

USUARIO="${1:-scriptcase_cadastro}"
if [[ ! "$USUARIO" =~ ^[a-z_][a-z0-9_]{2,40}$ ]]; then
  echo "ERRO: nome de usuario invalido: '$USUARIO' (use letras minusculas, numeros e _)."
  exit 1
fi
if [ "$(id -u)" -ne 0 ]; then
  echo "ERRO: rode com sudo: sudo bash deploy/criar_usuario_scriptcase.sh"
  exit 1
fi
if [ ! -f config/database.local.php ]; then
  echo "ERRO: crie primeiro config/database.local.php (docs/manual-implantacao.md)."
  exit 1
fi

ler() { php -r '$c = require "config/database.local.php"; echo $c[$argv[1]];' "$1"; }
BANCO="$(ler database)"
HOST="$(ler host)"
PORTA="$(ler port)"
SENHA="$(openssl rand -hex 16)"

existe="$(sudo -u postgres psql -d "$BANCO" -tAc "SELECT to_regclass('public.fiscal_empresas') IS NOT NULL")"
if [ "$existe" != "t" ]; then
  echo "ERRO: as tabelas do cadastro ainda nao existem. Rode antes: bash deploy/aplicar_banco.sh"
  exit 1
fi

sudo -u postgres psql -d "$BANCO" -v ON_ERROR_STOP=1 -q \
  -v usuario="$USUARIO" -v senha="$SENHA" -v banco="$BANCO" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN', :'usuario')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'usuario') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L', :'usuario', :'senha') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', :'banco', :'usuario') \gexec
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'usuario') \gexec
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON public.fiscal_empresas, public.fiscal_sistemas, public.fiscal_sistemas_empresas TO %I', :'usuario') \gexec
SELECT format('GRANT SELECT, INSERT ON public.fiscal_numeracao_inicial TO %I', :'usuario') \gexec
SELECT format('GRANT USAGE ON SEQUENCE public.fiscal_numeracao_inicial_id_seq TO %I', :'usuario') \gexec
SELECT format('GRANT SELECT ON public.nfse_numeracao TO %I', :'usuario') \gexec
SQL

echo
echo "OK! Usuario do Scriptcase pronto. Cadastre esta conexao no Scriptcase"
echo "(Banco de dados: PostgreSQL PDO):"
echo
echo "  Servidor : ${HOST}   (se o Scriptcase estiver em outra maquina, use o IP deste servidor)"
echo "  Porta    : ${PORTA}"
echo "  Banco    : ${BANCO}"
echo "  Usuario  : ${USUARIO}"
echo "  Senha    : ${SENHA}"
echo
echo "Anote a senha agora: ela nao fica gravada em lugar nenhum."
echo "Passo a passo das telas: docs/cadastro-scriptcase.md"
