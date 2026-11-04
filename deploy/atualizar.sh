#!/usr/bin/env bash
# ==========================================================================
# Atualiza o sistema para a versao mais nova da branch main.
#
# Uso (na pasta do sistema):   sudo bash deploy/atualizar.sh
#
# Passo a passo que ele faz:
#   1. backup do banco (por seguranca);
#   2. baixa o codigo novo (git pull);
#   3. instala/atualiza bibliotecas (composer);
#   4. aplica scripts novos do banco;
#   5. reinicia o PHP e os workers;
#   6. roda a verificacao da instalacao.
# Se qualquer passo falhar, ele PARA e mostra o erro.
# ==========================================================================
set -euo pipefail

# Tudo fica dentro de main(): o bash le a funcao inteira antes de executar.
# Isso e necessario porque o "git pull" abaixo substitui este proprio
# arquivo enquanto ele roda.
main() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "Rode com sudo:  sudo bash deploy/atualizar.sh"
    exit 1
  fi

  cd "$(dirname "$0")/.."
  PASTA="$(pwd)"
  git() { command git -c safe.directory="$PASTA" "$@"; }

  # Seguranca: so atualiza a partir da main e sem arquivos do sistema
  # alterados a mao (configuracoes .local.php e storage/ nao contam: ficam
  # fora do Git).
  BRANCH="$(git branch --show-current)"
  if [ "$BRANCH" != "main" ]; then
    echo "ERRO: a pasta esta na branch '$BRANCH', e nao na 'main'."
    echo "Para voltar para a main:  sudo git -C $PASTA checkout main"
    exit 1
  fi
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "ERRO: ha arquivos do sistema alterados a mao nesta pasta:"
    git status --short --untracked-files=no
    echo "Configuracoes devem ficar SO nos arquivos *.local.php. Peca ajuda ao desenvolvedor antes de atualizar."
    exit 1
  fi

  echo ">> 1/6 Backup do banco..."
  bash deploy/backup_banco.sh

  echo ">> 2/6 Baixando a versao nova..."
  git pull --ff-only origin main

  echo ">> 3/6 Bibliotecas..."
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress

  echo ">> 4/6 Banco de dados..."
  bash deploy/aplicar_banco.sh --sim

  echo ">> 5/6 Reiniciando PHP e workers..."
  systemctl reload php8.3-fpm
  # Reinicia todos os workers ligados (nfse-worker@1, @2...).
  for worker in $(systemctl list-units --type=service --state=active --no-legend 'nfse-worker@*' | awk '{print $1}'); do
    systemctl restart "$worker"
    echo "   reiniciado: $worker"
  done

  echo ">> 6/6 Verificacao..."
  sudo -u www-data php bin/nfse_verificar.php || true

  echo
  echo "Atualizacao concluida. Versao atual:"
  git log -1 --format='  %h  %s  (%cd)' --date=format:'%d/%m/%Y %H:%M'
}

main "$@"
exit
