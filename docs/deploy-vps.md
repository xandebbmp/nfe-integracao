# Implantação em VPS Linux (sem Docker)

Roteiro para Ubuntu 24.04 LTS (PHP 8.3 e PostgreSQL 16 nos repositórios oficiais).

## 1. Pacotes

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php8.3 \
  php8.3-cli php8.3-soap php8.3-bcmath php8.3-pgsql php8.3-gd php8.3-zip \
  php8.3-xml php8.3-mbstring php8.3-curl \
  postgresql composer git unzip
sudo timedatectl set-timezone America/Bahia
```

`soap` é obrigatória: sem ela todo envio à SEFAZ falha (`Undefined constant SOAP_1_2`).

## 2. Banco fiscal

```bash
sudo -u postgres psql -c "CREATE USER nfephp_app PASSWORD 'SENHA_FORTE';"
sudo -u postgres psql -c "CREATE DATABASE nfephp OWNER nfephp_app;"

cd /var/www/nfe-integracao
for f in database/scripts/*.sql; do
  psql -h 127.0.0.1 -U nfephp_app -d nfephp -v ON_ERROR_STOP=1 -f "$f"
done
```

Os scripts são idempotentes (podem rodar de novo). Scripts novos entram com número novo
(ver `docs/padrao-scripts-sql.md`). O PostgreSQL deve escutar só em `localhost` (padrão do Ubuntu).

## 3. Código

```bash
sudo git clone https://github.com/xandebbmp/nfe-integracao.git /var/www/nfe-integracao
cd /var/www/nfe-integracao
sudo composer install --no-dev --optimize-autoloader
sudo mkdir -p storage && sudo chown -R www-data:www-data storage
```

## 4. Configuração

```bash
sudo cp config/nfe.local.php.example config/nfe.local.php
sudo cp config/database.local.php.example config/database.local.php
sudo chown root:www-data config/*.local.php && sudo chmod 640 config/*.local.php
```

Em `config/nfe.local.php`:

| Chave | Como gerar | Observação |
|-------|-----------|------------|
| `apiToken` | `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` | Enviado pelo Financeiro Universal no header `X-NFE-API-TOKEN` |
| `cnfSecret` | idem | **Nunca trocar** depois de começar a emitir |
| `masterKey` | `php bin/empresa.php chave-mestra` | Cifra os certificados no banco. Perdeu = recadastrar certificados. Guarde uma cópia fora do servidor |

Em `config/database.local.php`: host `127.0.0.1`, porta `5432`, banco `nfephp`, usuário e senha do passo 2.

Alternativa: as mesmas chaves por variável de ambiente (`NFE_API_TOKEN`, `NFE_CNF_SECRET`,
`NFE_MASTER_KEY`, `NFE_DB_HOST`, `NFE_DB_PORT`, `NFE_DB_NAME`, `NFE_DB_USER`, `NFE_DB_PASSWORD`).

## 5. Apache + HTTPS

```bash
sudo cp deploy/apache-vhost.conf.example /etc/apache2/sites-available/nfe-api.conf
# edite ServerName
sudo a2dissite 000-default && sudo a2ensite nfe-api && sudo systemctl reload apache2
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d nfe-api.seudominio.com.br
```

O document root é `public/`. Nunca aponte o Apache para a raiz do projeto.

## 6. Empresas

```bash
cd /var/www/nfe-integracao
sudo -u www-data php bin/empresa.php cadastrar --cnpj=41986662000160 --razao="RAZAO SOCIAL" \
  --ie=31638351 --uf=BA --cmun=2927408 --crt=3 --tpamb=2
sudo -u www-data php bin/empresa.php certificado --cnpj=41986662000160 --pfx=/tmp/cert.pfx
shred -u /tmp/cert.pfx

# Continuar a numeração de outro emissor (próxima nota = 1235):
sudo -u www-data php bin/empresa.php numeracao --cnpj=41986662000160 --serie=1 --ultimo=1234
```

A senha do PFX é pedida no terminal (ou `NFE_PFX_SENHA`), para não ficar no histórico do shell.

## 7. Worker de emissão

A emissão por `POST /v1/nfe/lotes` é processada pelo worker (ver `docs/fila-emissao.md`):

```bash
sudo cp deploy/nfe-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfe-worker@1 nfe-worker@2
journalctl -u 'nfe-worker@*' -f
```

Dois workers bastam para começar; a SEFAZ leva alguns segundos por nota.
Depois de atualizar o código: `sudo systemctl restart 'nfe-worker@*'`.

## 8. Firewall

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Apache Full' && sudo ufw enable
```

Se o Financeiro Universal tiver IP fixo, restrinja a API a ele (no vhost: `Require ip ...`).

## 9. Backup (obrigatório)

XML de NF-e deve ser guardado por 5 anos. Copie diariamente para fora da VPS:

- `storage/` (XMLs, PDFs, retornos);
- `pg_dump -Fc nfephp` (empresas, certificados cifrados, numeração, documentos);
- a `masterKey` (separada do backup do banco: um sem o outro não abre os certificados).

## 10. Conferência

```bash
curl -s https://nfe-api.seudominio.com.br/health
curl -s -H "X-NFE-API-TOKEN: $TOKEN" -H "X-NFE-EMPRESA: 41986662000160" \
  https://nfe-api.seudominio.com.br/v1/nfe/status_padrao
sudo -u www-data php bin/empresa.php vencimentos --dias=30
systemctl status 'nfe-worker@*'
```
