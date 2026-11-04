#!/usr/bin/env bash
# ==========================================================================
# Instalador do AMBIENTE LOCAL (seu computador) - um comando so.
#
# Funciona dentro do Ubuntu 24.04 do seu computador:
#   macOS e Linux com Multipass, Windows com WSL2, ou Ubuntu direto.
#
# Uso (dentro do Ubuntu, na pasta do sistema):
#   cd /var/www/nfe-integracao
#   sudo bash deploy/instalar_local.sh
#
# Ele pergunta so 3 coisas: o arquivo do certificado, a senha dele e a
# inscricao municipal. Todo o resto e automatico:
#   programas, bibliotecas, banco + senha, tabelas, configuracoes, tokens,
#   site na porta 8080, worker e verificacao final.
#
# Ambiente local fica SEMPRE em homologacao (notas sem valor fiscal).
# Pode rodar de novo: o que ja foi feito e mantido (senhas e tokens tambem).
#
# Manual: docs/manual-ambiente-local.md
# ==========================================================================
set -euo pipefail

PORTA="${PORTA:-8080}"
BANCO="nfephp"
USUARIO_BANCO="nfephp_app"
ARQUIVO_CREDENCIAIS="/root/nfse-credenciais-local.txt"

# Cores so se a saida for um terminal.
if [ -t 1 ]; then VERDE=$'\e[32m'; AMARELO=$'\e[33m'; VERMELHO=$'\e[31m'; NEGRITO=$'\e[1m'; FIM=$'\e[0m'; else VERDE=''; AMARELO=''; VERMELHO=''; NEGRITO=''; FIM=''; fi
etapa() { echo; echo "${NEGRITO}== $1 ==${FIM}"; }
ok()    { echo "  ${VERDE}OK${FIM} $1"; }
aviso() { echo "  ${AMARELO}ATENCAO${FIM} $1"; }
parar() { echo; echo "${VERMELHO}ERRO:${FIM} $1"; exit 1; }

main() {
  verificacoes_iniciais
  instalar_programas
  instalar_bibliotecas
  preparar_pastas
  configurar_banco
  configurar_certificado
  configurar_sistema
  configurar_site
  ligar_worker
  verificar_e_resumir
}

# --------------------------------------------------------------------------
verificacoes_iniciais() {
  etapa "0/9 Conferindo o ambiente"

  [ "$(id -u)" -eq 0 ] || parar "rode com sudo:  sudo bash deploy/instalar_local.sh"

  cd "$(dirname "$0")/.."
  PASTA="$(pwd)"
  [ -f bin/nfse_worker.php ] || parar "rode este script dentro da pasta do sistema (cd /var/www/nfe-integracao)."

  case "$PASTA" in
    /mnt/*) parar "o sistema esta em $PASTA (pasta do Windows). Baixe para /var/www/nfe-integracao (manual, Etapa 2)." ;;
  esac

  grep -q 'VERSION_ID="24.04"' /etc/os-release || aviso "este instalador foi feito para Ubuntu 24.04. Seu sistema: $(grep PRETTY_NAME /etc/os-release | cut -d= -f2)"

  if [ "$(ps -p 1 -o comm= | tr -d ' ')" != "systemd" ]; then
    parar "o systemd nao esta ligado (comum no Windows/WSL). Faca o item 1.2 do Windows em docs/manual-ambiente-local.md e rode de novo."
  fi

  if grep -qi microsoft /proc/version 2>/dev/null; then
    TIPO="WSL"
  elif systemd-detect-virt -q 2>/dev/null; then
    TIPO="MAQUINA_VIRTUAL"
  else
    TIPO="LINUX"
  fi
  ok "Pasta: $PASTA | ambiente: $TIPO | porta: $PORTA"
}

# --------------------------------------------------------------------------
instalar_programas() {
  etapa "1/9 Programas (nginx, PHP, PostgreSQL...) - pode levar alguns minutos"
  bash deploy/instalar_pacotes.sh > /tmp/nfse-instalar-pacotes.log 2>&1 \
    || { tail -20 /tmp/nfse-instalar-pacotes.log; parar "falha ao instalar programas (log: /tmp/nfse-instalar-pacotes.log)."; }
  ok "$(php -r 'echo "PHP ".PHP_VERSION;') | $(psql --version | awk '{print "PostgreSQL "$3}') | $(nginx -v 2>&1 | cut -d/ -f2 | sed 's/^/nginx /')"
}

# --------------------------------------------------------------------------
instalar_bibliotecas() {
  etapa "2/9 Bibliotecas do sistema (composer)"
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress > /tmp/nfse-composer.log 2>&1 \
    || { tail -20 /tmp/nfse-composer.log; parar "falha no composer install (log: /tmp/nfse-composer.log)."; }
  ok "vendor/ instalado"
}

# --------------------------------------------------------------------------
preparar_pastas() {
  etapa "3/9 Pastas de trabalho"
  mkdir -p storage/certs storage/logs storage/xml storage/pdf
  chown -R www-data:www-data storage
  chmod 750 storage/certs
  ok "storage/ criada com permissao para o sistema (www-data)"
}

# --------------------------------------------------------------------------
configurar_banco() {
  etapa "4/9 Banco de dados"

  if [ -f config/database.local.php ]; then
    ok "config/database.local.php ja existe: mantido"
  else
    local senha
    senha="$(openssl rand -hex 16)"
    # Cria o usuario (ou troca a senha, se ja existir) e o banco.
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${USUARIO_BANCO}') THEN
    ALTER ROLE ${USUARIO_BANCO} WITH LOGIN PASSWORD '${senha}';
  ELSE
    CREATE ROLE ${USUARIO_BANCO} LOGIN PASSWORD '${senha}';
  END IF;
END
\$\$;
SQL
    if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname = '${BANCO}'" | grep -q 1; then
      sudo -u postgres psql -q -c "CREATE DATABASE ${BANCO} OWNER ${USUARIO_BANCO}"
    fi

    cat > config/database.local.php <<PHP
<?php
// Gerado por deploy/instalar_local.sh em $(date '+%d/%m/%Y %H:%M').
return [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => 5432,
    'database' => '${BANCO}',
    'username' => '${USUARIO_BANCO}',
    'password' => '${senha}',
    'charset' => 'utf8',
];
PHP
    ok "banco '${BANCO}' e usuario '${USUARIO_BANCO}' criados (senha gerada e salva em config/database.local.php)"
  fi
  chown root:www-data config/database.local.php
  chmod 640 config/database.local.php

  bash deploy/aplicar_banco.sh --sim > /tmp/nfse-banco.log 2>&1 \
    || { cat /tmp/nfse-banco.log; parar "falha ao criar as tabelas."; }
  ok "tabelas criadas/atualizadas"
}

# --------------------------------------------------------------------------
# Le dados do certificado com o proprio PHP do sistema. A senha vai por
# variavel de ambiente (nunca aparece na lista de processos).
ler_certificado() {
  CERT_ARQUIVO_LEITURA="$1" php -r '
    require "vendor/autoload.php";
    try {
        $c = NFePHP\Common\Certificate::readPfx(file_get_contents(getenv("CERT_ARQUIVO_LEITURA")), getenv("CERT_SENHA"));
        echo "OK|" . $c->getCnpj() . "|" . $c->getCompanyName() . "|" . $c->getValidTo()->format("d/m/Y") . "|" . ($c->isExpired() ? "VENCIDO" : "VALIDO");
    } catch (Throwable $e) {
        echo "FALHA|" . $e->getMessage();
    }'
}

configurar_certificado() {
  etapa "5/9 Certificado digital A1"
  local destino="storage/certs/empresa.pfx"

  if [ -f config/nfe.local.php ] && [ -f "$destino" ]; then
    ok "certificado ja configurado ($destino): mantido"
    CERT_SENHA="$(php -r '$c = require "config/nfe.local.php"; echo $c["certPassword"] ?? "";')"
    export CERT_SENHA
    INFO_CERT="$(ler_certificado "$destino")"
    return
  fi

  # Onde esta o arquivo? (Multipass: /tmp/empresa.pfx | WSL: /mnt/c/...)
  local origem="${CERT_ARQUIVO:-}"
  if [ -z "$origem" ]; then
    local sugestao=""
    if [ -f /tmp/empresa.pfx ]; then sugestao="/tmp/empresa.pfx"; fi
    echo "  Caminho do arquivo .pfx dentro do Ubuntu."
    echo "  (macOS/Multipass: /tmp/empresa.pfx | Windows/WSL: /mnt/c/Users/SEU_USUARIO/Downloads/arquivo.pfx)"
    read -r -p "  Arquivo [${sugestao}]: " origem
    origem="${origem:-$sugestao}"
  fi
  [ -f "$origem" ] || parar "arquivo nao encontrado: '$origem'. Copie o .pfx para dentro do Ubuntu (manual, Etapa 2) e rode de novo."

  # Senha (pergunta ate acertar, no maximo 3 vezes). Se a senha ja veio
  # pronta na variavel CERT_SENHA (modo automatico), tenta uma vez so.
  local tentativa resultado senha_automatica=""
  if [ -n "${CERT_SENHA:-}" ]; then senha_automatica="sim"; fi
  for tentativa in 1 2 3; do
    if [ -z "${CERT_SENHA:-}" ]; then
      read -r -s -p "  Senha do certificado: " CERT_SENHA
      echo
    fi
    export CERT_SENHA

    resultado="$(ler_certificado "$origem")"
    if [[ "$resultado" == OK* ]]; then
      cp "$origem" "$destino"
      break
    fi

    # Formato antigo (RC2-40): o OpenSSL 3 nao abre sem conversao.
    if openssl pkcs12 -legacy -in "$origem" -passin env:CERT_SENHA -noout 2>/dev/null; then
      aviso "certificado em formato antigo: convertendo para o formato atual (o arquivo original nao e alterado)..."
      local pem
      pem="$(mktemp)"
      chmod 600 "$pem"
      openssl pkcs12 -legacy -in "$origem" -passin env:CERT_SENHA -nodes -out "$pem" 2>/dev/null
      openssl pkcs12 -export -in "$pem" -out "$destino" -passout env:CERT_SENHA 2>/dev/null
      shred -u "$pem" 2>/dev/null || rm -f "$pem"
      resultado="$(ler_certificado "$destino")"
      if [[ "$resultado" == OK* ]]; then break; fi
    fi

    echo "  ${VERMELHO}Nao abriu:${FIM} senha errada ou arquivo invalido."
    unset CERT_SENHA
    if [ -n "$senha_automatica" ] || [ "$tentativa" -eq 3 ]; then
      parar "o certificado nao abriu. Confira o arquivo e a senha e rode de novo."
    fi
  done
  [[ "$resultado" == OK* ]] || parar "o certificado nao abriu."

  chown root:www-data "$destino"
  chmod 640 "$destino"

  # Senha do certificado no arquivo local (lido pela NF-e e pela NFS-e).
  local senha_php
  senha_php="$(php -r 'echo var_export(getenv("CERT_SENHA"), true);')"
  cat > config/nfe.local.php <<PHP
<?php
// Gerado por deploy/instalar_local.sh em $(date '+%d/%m/%Y %H:%M').
return [
    'certPfxPath' => __DIR__ . '/../storage/certs/empresa.pfx',
    'certPassword' => ${senha_php},
];
PHP
  chown root:www-data config/nfe.local.php
  chmod 640 config/nfe.local.php
  INFO_CERT="$resultado"
  ok "certificado instalado em $destino"
}

# --------------------------------------------------------------------------
configurar_sistema() {
  etapa "6/9 Configuracao da empresa e acessos"

  IFS='|' read -r _ CNPJ_CERT RAZAO_CERT VALIDADE_CERT SITUACAO_CERT <<< "$INFO_CERT"
  CNPJ_CERT="$(echo "$CNPJ_CERT" | tr -cd 'A-Za-z0-9' | tr 'a-z' 'A-Z')"
  # Certificados ICP-Brasil trazem o CNPJ tambem no nome: "RAZAO SOCIAL:CNPJ".
  if [ -z "$CNPJ_CERT" ] && [[ "$RAZAO_CERT" =~ :([A-Za-z0-9]{14})$ ]]; then
    CNPJ_CERT="${BASH_REMATCH[1]^^}"
  fi
  echo "  Certificado: ${RAZAO_CERT} | CNPJ ${CNPJ_CERT:-nao identificado} | validade ${VALIDADE_CERT}"
  if [ "$SITUACAO_CERT" = "VENCIDO" ]; then
    aviso "o certificado esta VENCIDO: o governo vai recusar a conexao."
  fi

  if [ -f config/nfse.local.php ]; then
    ok "config/nfse.local.php ja existe: mantido (tokens anteriores continuam valendo)"
    return
  fi

  local cnpj="${NFSE_CNPJ:-$CNPJ_CERT}"
  if [ -z "${NFSE_CNPJ:-}" ]; then
    read -r -p "  CNPJ do emitente [${CNPJ_CERT}]: " resposta
    cnpj="${resposta:-$CNPJ_CERT}"
  fi
  cnpj="$(echo "$cnpj" | tr -cd 'A-Za-z0-9' | tr 'a-z' 'A-Z')"
  [[ "$cnpj" =~ ^[A-Z0-9]{14}$ ]] || parar "CNPJ invalido: '$cnpj' (precisa ter 14 caracteres)."

  local inscricao="${INSCRICAO_MUNICIPAL:-}"
  if [ -z "$inscricao" ]; then
    read -r -p "  Inscricao municipal (Enter para deixar em branco): " inscricao
  fi
  inscricao="$(echo "$inscricao" | tr -cd 'A-Za-z0-9')"

  TOKEN_ERP="$(openssl rand -hex 32)"
  TOKEN_MONITOR="$(openssl rand -hex 32)"
  CHAVE_CADASTRO="$(openssl rand -base64 32)"
  local razao_php
  razao_php="$(RAZAO="$RAZAO_CERT" php -r 'echo var_export(trim(explode(":", getenv("RAZAO"))[0]), true);')"

  cat > config/nfse.local.php <<PHP
<?php
declare(strict_types=1);

// Gerado por deploy/instalar_local.sh em $(date '+%d/%m/%Y %H:%M').
// AMBIENTE LOCAL: sempre homologacao. Producao so na VPS.
return [
    'tpAmb' => 2,

    'emitente' => [
        'cnpj' => '${cnpj}',
        'razao_social' => ${razao_php},
        'inscricao_municipal' => '${inscricao}',
    ],

    'consumidores' => [
        'ERP' => [
            'token' => '${TOKEN_ERP}',
        ],
    ],

    'monitor_token' => '${TOKEN_MONITOR}',

    // Criptografa os certificados enviados pelo cadastro (Scriptcase).
    // Guarde uma copia: sem ela os certificados precisam ser reenviados.
    'cadastro' => [
        'chave' => '${CHAVE_CADASTRO}',
    ],

    'armazenamento' => [
        'tipo' => 'local',
    ],
];
PHP
  chown root:www-data config/nfse.local.php
  chmod 640 config/nfse.local.php
  if [ -z "$inscricao" ]; then
    aviso "inscricao municipal em branco (obrigatoria em Salvador). Edite depois em config/nfse.local.php."
  fi
  ok "empresa ${cnpj} configurada, tokens gerados, ambiente HOMOLOGACAO"
}

# --------------------------------------------------------------------------
configurar_site() {
  etapa "7/9 Site (nginx na porta ${PORTA})"
  cp deploy/php-fpm/nfse-monitor.conf /etc/php/8.3/fpm/pool.d/nfse-monitor.conf
  cp deploy/nginx/nfe-integracao.conf /etc/nginx/sites-available/nfe-integracao
  sed -i "s/SEU_DOMINIO_AQUI/localhost/; s/^    listen 80;/    listen ${PORTA};/" /etc/nginx/sites-available/nfe-integracao
  sed -i "s#root /var/www/nfe-integracao/public;#root ${PASTA}/public;#" /etc/nginx/sites-available/nfe-integracao
  ln -sf /etc/nginx/sites-available/nfe-integracao /etc/nginx/sites-enabled/nfe-integracao
  rm -f /etc/nginx/sites-enabled/default
  nginx -t > /tmp/nfse-nginx.log 2>&1 || { cat /tmp/nfse-nginx.log; parar "configuracao do nginx invalida."; }
  systemctl restart php8.3-fpm
  systemctl reload nginx
  ok "site no ar na porta ${PORTA}"
}

# --------------------------------------------------------------------------
ligar_worker() {
  etapa "8/9 Worker (envio das notas)"
  sed "s#WorkingDirectory=/var/www/nfe-integracao#WorkingDirectory=${PASTA}#" deploy/systemd/nfse-worker@.service > /etc/systemd/system/nfse-worker@.service
  systemctl daemon-reload
  systemctl enable --now nfse-worker@1 nfse-worker@2 > /dev/null 2>&1
  systemctl restart nfse-worker@1 nfse-worker@2
  ok "2 workers ligados (voltam sozinhos quando a maquina liga)"
}

# --------------------------------------------------------------------------
verificar_e_resumir() {
  etapa "9/9 Verificacao"
  local relatorio pendencias=""
  if relatorio="$(sudo -u www-data php bin/nfse_verificar.php --rede 2>&1)"; then
    ok "verificacao sem erros"
  else
    # Mostra so os itens com erro e como resolver.
    pendencias="$(echo "$relatorio" | grep -A1 '\[ERRO\]' | grep -v '^--$')"
    aviso "a verificacao encontrou itens para corrigir:"
    echo "$pendencias"
  fi
  echo "$relatorio" | grep '\[ATENCAO\]' | sed 's/^/  /' || true
  echo "  (relatorio completo: sudo -u www-data php bin/nfse_verificar.php --rede)"

  local endereco
  if [ "$TIPO" = "WSL" ] || [ "$TIPO" = "LINUX" ]; then
    endereco="http://localhost:${PORTA}"
  else
    endereco="http://$(hostname -I | awk '{print $1}'):${PORTA}"
  fi

  # Tokens: le do arquivo (vale tambem quando a config ja existia).
  local token_erp token_monitor
  token_erp="$(php -r '$c = require "config/nfse.local.php"; echo reset($c["consumidores"])["token"] ?? "";')"
  token_monitor="$(php -r '$c = require "config/nfse.local.php"; echo $c["monitor_token"] ?? "";')"

  umask 077
  cat > "$ARQUIVO_CREDENCIAIS" <<TXT
Ambiente LOCAL da API Fiscal - gerado em $(date '+%d/%m/%Y %H:%M')
(HOMOLOGACAO - notas sem valor fiscal)

Monitor:          ${endereco}/monitor/
Token do Monitor: ${token_monitor}

API:              ${endereco}/v1/nfse/
Token do ERP:     ${token_erp}
(cabecalho:       X-NFE-API-TOKEN: <token>)
TXT

  echo
  if [ -z "$pendencias" ]; then
    echo "${VERDE}${NEGRITO}Instalacao local concluida!${FIM}"
  else
    echo "${AMARELO}${NEGRITO}Instalacao local concluida COM PENDENCIAS${FIM} (veja os itens [ERRO] acima, corrija e rode este instalador de novo)."
  fi
  echo
  echo "  Abra no navegador do seu computador:"
  echo "    ${NEGRITO}${endereco}/monitor/${FIM}"
  echo "  Token do Monitor: ${token_monitor}"
  echo
  echo "  API: ${endereco}/v1/nfse/   |  Token do ERP: ${token_erp}"
  echo "  (tudo isso foi salvo em ${ARQUIVO_CREDENCIAIS}; para ver: sudo cat ${ARQUIVO_CREDENCIAIS})"
  echo
  echo "  Proximo passo - homologacao (docs/nfse-homologacao.md):"
  echo "    sudo -u www-data php bin/nfse_homologar.php --padrao=NACIONAL --so-validar"
  echo "    sudo -u www-data php bin/nfse_homologar.php --padrao=NACIONAL"
}

main "$@"
exit
