# Kit de implantação

Arquivos para colocar o sistema no ar em um servidor **Ubuntu 24.04 LTS**.

👉 **Siga o passo a passo em [`docs/manual-implantacao.md`](../docs/manual-implantacao.md).**
Ele explica quando e como usar cada arquivo abaixo.

Para montar primeiro no seu computador (Windows com WSL2, macOS ou Linux)
e depois levar para a VPS: [`docs/manual-ambiente-local.md`](../docs/manual-ambiente-local.md).

| Arquivo | Para quê | Manual |
|---|---|---|
| `instalar_pacotes.sh` | Instala nginx, PHP 8.3, PostgreSQL, Composer e certbot | Passo 3 |
| `aplicar_banco.sh` | Cria/atualiza as tabelas do banco | Passo 5 |
| `php-fpm/nfse-monitor.conf` | Pool PHP só para o Monitor em tempo real | Passo 9 |
| `nginx/nfe-integracao.conf` | Configuração do site | Passo 9 |
| `systemd/nfse-worker@.service` | Worker que envia as notas, como serviço | Passo 11 |
| `backup_banco.sh` | Backup do banco (manual ou diário) | Parte D |
| `atualizar.sh` | Atualiza o sistema com segurança | Parte D |
| `instalar_local.sh` | **Só para o computador local:** instala e configura tudo com um comando (homologação, porta 8080, sem HTTPS) | [Ambiente local](../docs/manual-ambiente-local.md), Etapa 2 |

Para conferir a instalação a qualquer momento:

```bash
sudo -u www-data php bin/nfse_verificar.php --rede
```

Tudo foi testado em um Ubuntu 24.04 limpo: instalação dos pacotes, banco
(inclusive script com erro e reexecução), nginx + php-fpm (API, monitor SSE
sem buffer e bloqueio de arquivos sensíveis), worker como `www-data` (parada
segura com SIGTERM), `systemd-analyze verify`, backup/restauração e
atualização de uma versão para outra.
