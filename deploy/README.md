# Kit de implantação

Arquivos para colocar o sistema no ar em um servidor **Ubuntu 24.04 LTS**.

👉 **Siga o passo a passo em [`docs/manual-implantacao.md`](../docs/manual-implantacao.md).**
Ele explica quando e como usar cada arquivo abaixo.

| Arquivo | Para quê | Manual |
|---|---|---|
| `instalar_pacotes.sh` | Instala nginx, PHP 8.3, PostgreSQL, Composer e certbot | Passo 3 |
| `aplicar_banco.sh` | Cria/atualiza as tabelas do banco | Passo 5 |
| `php-fpm/nfse-monitor.conf` | Pool PHP só para o Monitor em tempo real | Passo 9 |
| `nginx/nfe-integracao.conf` | Configuração do site | Passo 9 |
| `systemd/nfse-worker@.service` | Worker que envia as notas, como serviço | Passo 11 |
| `backup_banco.sh` | Backup do banco (manual ou diário) | Parte D |
| `atualizar.sh` | Atualiza o sistema com segurança | Parte D |

Para conferir a instalação a qualquer momento:

```bash
sudo -u www-data php bin/nfse_verificar.php --rede
```

Tudo foi testado em um Ubuntu 24.04 limpo: instalação dos pacotes, banco
(inclusive script com erro e reexecução), nginx + php-fpm (API, monitor SSE
sem buffer e bloqueio de arquivos sensíveis), worker como `www-data` (parada
segura com SIGTERM), `systemd-analyze verify`, backup/restauração e
atualização de uma versão para outra.
