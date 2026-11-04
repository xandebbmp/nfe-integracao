# Manual de Implantação — API Fiscal (NF-e + NFS-e)

Este manual ensina, passo a passo, a colocar o sistema no ar em um servidor
novo. **Não é preciso saber programar.** Basta seguir os passos na ordem,
copiar os comandos e conferir se a tela mostra o que o manual diz.

> **Quer testar no seu computador antes de contratar a VPS?** Siga o
> [Manual do ambiente local](manual-ambiente-local.md) (Windows, Linux e
> macOS). Ele usa este mesmo manual, com poucas diferenças.

> **Tempo estimado:** 1h30 a 2h na primeira vez.
> **Servidor:** Linux **Ubuntu 24.04 LTS** (é o sistema testado). Em outro
> sistema os comandos podem ser diferentes.

---

## Sumário

- [Antes de começar](#antes-de-começar)
- [Como ler este manual](#como-ler-este-manual)
- [Parte A — Instalação](#parte-a--instalação) (passos 1 a 12)
- [Parte B — Homologação](#parte-b--homologação)
- [Parte C — Virar para produção](#parte-c--virar-para-produção)
- [Parte D — Dia a dia](#parte-d--dia-a-dia) (conferir, reiniciar, atualizar, backup, certificado)
- [Parte E — Problemas comuns](#parte-e--problemas-comuns)
- [Apêndice — Onde fica cada coisa](#apêndice--onde-fica-cada-coisa)

---

## Antes de começar

Junte isto antes de começar (marque cada item):

- [ ] **Um servidor Ubuntu 24.04** (na nuvem ou na empresa) com pelo menos
      2 GB de memória e 20 GB de disco.
- [ ] **Acesso ao servidor**: endereço IP, usuário e senha (ou chave) de um
      usuário que possa usar `sudo` (administrador).
- [ ] **Um endereço (domínio)** para o sistema, por exemplo
      `fiscal.suaempresa.com.br`, já **apontando para o IP do servidor**.
      Peça isso a quem cuida do domínio da empresa (registro do tipo **A**).
- [ ] **O certificado digital A1** da empresa (arquivo `.pfx`) e a **senha** dele.
- [ ] **Acesso ao repositório no GitHub** `xandebbmp/nfe-integracao`
      (um usuário do GitHub com permissão de leitura).
- [ ] **Dados da empresa**: CNPJ, razão social, inscrição municipal.
- [ ] **Quais sistemas** vão usar a API (ex.: ERP), para criar um acesso para cada um.

### Glossário rápido

| Palavra | O que é |
|---|---|
| **Servidor** | O computador onde o sistema vai rodar. Você acessa ele "de longe". |
| **Terminal** | A tela preta onde se digitam comandos. |
| **SSH** | O jeito de abrir o terminal do servidor a partir do seu computador. |
| **sudo** | Palavra que vai na frente de um comando para rodar como administrador. Pede a sua senha. |
| **nginx** | O "porteiro" que recebe os acessos da internet e entrega ao sistema. |
| **PHP** | A linguagem em que o sistema foi feito. |
| **PostgreSQL** | O banco de dados, onde ficam as notas. |
| **Worker** | Um programa que fica rodando o tempo todo enviando as notas para a prefeitura/Receita. |
| **Token** | Uma senha longa usada por outros sistemas para acessar a API. |
| **Homologação** | Ambiente de **teste** do governo: as notas não valem nada. |
| **Produção** | Ambiente **real**: as notas têm valor fiscal. |

---

## Como ler este manual

- Cada comando está num **bloco cinza**. Copie o bloco inteiro e cole no
  terminal (no terminal, colar costuma ser **botão direito** ou
  **Ctrl+Shift+V**). Depois aperte **Enter**.
- Rode **um bloco de cada vez** e confira o resultado antes de seguir.
- Onde aparecer algo em **MAIÚSCULAS** (ex.: `SEU_DOMINIO_AQUI`), **troque**
  pelo seu valor antes de apertar Enter.
- ✅ **Deve aparecer:** mostra o que você deve ver se deu certo.
- ⚠️ Se aparecer algo diferente, veja a [Parte E — Problemas comuns](#parte-e--problemas-comuns).

### Como editar um arquivo no servidor (editor `nano`)

Vários passos pedem para editar um arquivo. Usamos o editor `nano`:

1. O comando `sudo nano NOME_DO_ARQUIVO` abre o arquivo.
2. Use as **setas do teclado** para andar pelo texto e digite normalmente.
3. Para **salvar**: aperte **Ctrl+O** e depois **Enter**.
4. Para **sair**: aperte **Ctrl+X**.

---

# Parte A — Instalação

## Passo 1 — Entrar no servidor

No seu computador (Windows), abra o **PowerShell** (procure por
"PowerShell" no menu Iniciar) e digite, trocando usuário e IP:

```bash
ssh USUARIO@IP_DO_SERVIDOR
```

Na primeira vez ele pergunta se confia no servidor: digite `yes` e Enter.
Depois digite a senha (ela **não aparece** enquanto você digita; é normal).

✅ **Deve aparecer** algo como `usuario@servidor:~$`. A partir daqui, todos
os comandos são digitados **nesta janela**.

---

## Passo 2 — Baixar o sistema

Instale o git (programa que baixa o código):

```bash
sudo apt update && sudo apt install -y git
```

Baixe o sistema para a pasta `/var/www/nfe-integracao`:

```bash
sudo git clone https://github.com/xandebbmp/nfe-integracao.git /var/www/nfe-integracao
```

Como o repositório é privado, ele vai pedir:

- **Username:** seu usuário do GitHub.
- **Password:** **não é a senha do GitHub**. É um **token**. Para criar:
  GitHub → foto no canto superior direito → **Settings** → **Developer
  settings** → **Personal access tokens** → **Fine-grained tokens** →
  **Generate new token**. Em *Repository access* escolha só
  `nfe-integracao`; em *Permissions → Contents* escolha **Read-only**.
  Copie o token e cole aqui.

✅ **Deve aparecer:** `Cloning into '/var/www/nfe-integracao'...` e, no final, `done`.

Entre na pasta do sistema (todos os próximos comandos são rodados daqui):

```bash
cd /var/www/nfe-integracao
```

---

## Passo 3 — Instalar os programas necessários

Um único script instala tudo: nginx, PHP, PostgreSQL, Composer e certbot.

```bash
sudo bash deploy/instalar_pacotes.sh
```

Leva alguns minutos.

✅ **Deve aparecer** no final:

```text
OK! Programas instalados:
PHP 8.3...
psql (PostgreSQL) 16...
nginx version: nginx/1.24...
Composer version 2...
```

---

## Passo 4 — Instalar as bibliotecas do sistema

```bash
sudo COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
```

✅ **Deve aparecer** no final: `Generating optimized autoload files`.

Crie as pastas de trabalho (elas não vêm no download) e dê permissão ao
usuário do site (`www-data`):

```bash
sudo mkdir -p storage/certs storage/logs storage/xml storage/pdf
```

```bash
sudo chown -R www-data:www-data storage && sudo chmod 750 storage/certs
```

Nenhuma mensagem aparece: isso significa que deu certo.

---

## Passo 5 — Criar o banco de dados

### 5.1 Gerar uma senha para o banco

```bash
openssl rand -hex 16
```

Aparece algo como `3f9a1c...`. **Copie e guarde essa senha** (num gerenciador
de senhas da empresa). Nos próximos comandos ela é chamada de `SENHA_DO_BANCO`.

### 5.2 Criar o usuário e o banco

Troque `SENHA_DO_BANCO` pela senha do passo anterior (mantenha as aspas simples):

```bash
sudo -u postgres psql -c "CREATE ROLE nfephp_app LOGIN PASSWORD 'SENHA_DO_BANCO'"
```

✅ **Deve aparecer:** `CREATE ROLE`

```bash
sudo -u postgres psql -c "CREATE DATABASE nfephp OWNER nfephp_app"
```

✅ **Deve aparecer:** `CREATE DATABASE`

### 5.3 Dizer ao sistema qual é o banco

Crie o arquivo de configuração a partir do modelo:

```bash
sudo cp config/database.local.php.example config/database.local.php
```

```bash
sudo nano config/database.local.php
```

Deixe o arquivo assim (troque só a senha):

```php
<?php

return [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => 5432,
    'database' => 'nfephp',
    'username' => 'nfephp_app',
    'password' => 'SENHA_DO_BANCO',
    'charset' => 'utf8',
];
```

Salve (**Ctrl+O**, Enter) e saia (**Ctrl+X**).

### 5.4 Criar as tabelas

```bash
sudo bash deploy/aplicar_banco.sh
```

Ele mostra a lista de scripts e pergunta `Continuar? (s/N)`: digite `s` e Enter.

✅ **Deve aparecer:**

```text
>> Aplicando 0001_dfe_criar_base_fase_02.sql...
   ok
>> Aplicando 0002_nfse_criar_base.sql...
   ok
>> Aplicando 0003_nfse_alterar_multiempresa_rejeicao_arquivo.sql...
   ok

OK! Banco atualizado.
```

---

## Passo 6 — Enviar o certificado digital para o servidor

O certificado (`.pfx`) está no seu computador. Para copiá-lo, abra **outra**
janela do PowerShell **no seu computador** (não no servidor) e rode,
trocando o caminho do arquivo, o usuário e o IP:

```bash
scp "C:\CAMINHO\DO\CERTIFICADO.pfx" USUARIO@IP_DO_SERVIDOR:/tmp/empresa.pfx
```

Volte para a janela do **servidor** e mova o arquivo para o lugar certo,
com acesso só para o sistema:

```bash
sudo mv /tmp/empresa.pfx storage/certs/empresa.pfx
```

```bash
sudo chown root:www-data storage/certs/empresa.pfx && sudo chmod 640 storage/certs/empresa.pfx
```

> 🔒 Nunca envie o certificado por e-mail ou WhatsApp, e nunca o coloque dentro
> da pasta `public`.

---

## Passo 7 — Configurar a empresa, a senha do certificado e os acessos

### 7.1 Senha do certificado (usada pela NF-e e pela NFS-e)

```bash
sudo nano config/nfe.local.php
```

Cole o conteúdo abaixo (troque a senha):

```php
<?php
return [
    'certPfxPath' => __DIR__ . '/../storage/certs/empresa.pfx',
    'certPassword' => 'SENHA_DO_CERTIFICADO',
];
```

Salve e saia.

> O CNPJ, a razão social e o ambiente (homologação ou produção) da empresa
> ficam em `config/nfe.php`. Confira se o CNPJ lá é o da empresa. Esse
> arquivo vem com o sistema, já em **homologação** (`'tpAmb' => 2`).

### 7.2 Gerar os tokens de acesso

Cada sistema que vai usar a API precisa de um token, e o Monitor também.
Rode o comando abaixo **uma vez para cada token** e guarde os resultados:

```bash
openssl rand -hex 32
```

Exemplo: rode uma vez para o **ERP** e outra para o **Monitor**.

### 7.3 Arquivo de configuração da NFS-e

```bash
sudo cp config/nfse.local.php.example config/nfse.local.php
```

```bash
sudo nano config/nfse.local.php
```

Apague o conteúdo (segure **Ctrl+K** até limpar) e cole este, trocando os
valores em MAIÚSCULAS:

```php
<?php
declare(strict_types=1);

return [
    'emitente' => [
        'inscricao_municipal' => 'INSCRICAO_MUNICIPAL_DA_EMPRESA',
    ],

    'consumidores' => [
        'ERP' => [
            'token' => 'TOKEN_DO_ERP_GERADO_NO_PASSO_7_2',
        ],
    ],

    'monitor_token' => 'TOKEN_DO_MONITOR_GERADO_NO_PASSO_7_2',

    // Copia dos XMLs numa pasta do servidor (mude para a nuvem depois).
    'armazenamento' => [
        'tipo' => 'local',
    ],
];
```

Salve e saia.

> **Mais de uma empresa?** Veja o modelo completo, com o bloco
> `emitentes`, em `config/nfse.local.php.example`.

### 7.4 Proteger os arquivos de configuração (contêm senhas)

```bash
sudo chown root:www-data config/*.local.php && sudo chmod 640 config/*.local.php
```

---

## Passo 8 — Verificar a instalação

O sistema tem um verificador que confere tudo e diz **como resolver** cada problema:

```bash
sudo -u www-data php bin/nfse_verificar.php --rede
```

✅ **Deve aparecer** `[OK]` em quase tudo. Nesta etapa é **normal** ainda
aparecer:

- `[ERRO] Servico nfse-worker@1` → será resolvido no passo 11;
- `[ATENCAO]` em nginx/armazenamento → resolvidos nos próximos passos.

Para qualquer outro `[ERRO]`, siga a linha **"Como resolver"** logo abaixo
dele e rode o verificador de novo.

---

## Passo 9 — Colocar o site no ar (nginx)

### 9.1 Pool separado para o Monitor

```bash
sudo cp deploy/php-fpm/nfse-monitor.conf /etc/php/8.3/fpm/pool.d/
```

```bash
sudo systemctl restart php8.3-fpm
```

### 9.2 Configuração do site

```bash
sudo cp deploy/nginx/nfe-integracao.conf /etc/nginx/sites-available/nfe-integracao
```

Troque `SEU_DOMINIO_AQUI` pelo seu domínio (ex.: `fiscal.suaempresa.com.br`):

```bash
sudo sed -i 's/SEU_DOMINIO_AQUI/fiscal.suaempresa.com.br/' /etc/nginx/sites-available/nfe-integracao
```

Ative o site e desative a página padrão do nginx:

```bash
sudo ln -sf /etc/nginx/sites-available/nfe-integracao /etc/nginx/sites-enabled/nfe-integracao
```

```bash
sudo rm -f /etc/nginx/sites-enabled/default
```

Teste a configuração:

```bash
sudo nginx -t
```

✅ **Deve aparecer:** `syntax is ok` e `test is successful`.

Aplique:

```bash
sudo systemctl reload nginx
```

---

## Passo 10 — Cadeado de segurança (HTTPS)

Os tokens viajam pela internet; sem HTTPS eles poderiam ser lidos no
caminho. O certificado HTTPS é **gratuito** (Let's Encrypt).

Libere o acesso web no firewall do servidor:

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```

Peça o certificado HTTPS (troque o domínio):

```bash
sudo certbot --nginx -d fiscal.suaempresa.com.br
```

Ele pergunta um e-mail (para avisos de renovação) e pede para aceitar os
termos (`Y`).

✅ **Deve aparecer:** `Successfully deployed certificate` e
`Congratulations! You have successfully enabled HTTPS`.

A renovação é **automática**. Nada mais a fazer.

> ⚠️ Se der erro de "DNS" ou "challenge", o domínio ainda não aponta para
> este servidor. Confira com quem cuida do domínio e tente de novo depois.

---

## Passo 11 — Ligar o worker (quem envia as notas)

```bash
sudo cp deploy/systemd/nfse-worker@.service /etc/systemd/system/
```

```bash
sudo systemctl daemon-reload
```

Liga 2 workers e deixa ligados mesmo se o servidor reiniciar:

```bash
sudo systemctl enable --now nfse-worker@1 nfse-worker@2
```

Confira:

```bash
sudo systemctl status nfse-worker@1 --no-pager
```

✅ **Deve aparecer:** `Active: active (running)` (em verde) e a linha
`Worker ... iniciado (tpAmb=2)`.

---

## Passo 12 — Teste final

Rode o verificador de novo:

```bash
sudo -u www-data php bin/nfse_verificar.php --rede
```

✅ **Deve aparecer** no final:

```text
== Resumo ==
  OK: ...   ATENCAO: ...   ERRO: 0
  Pronto para uso
```

Teste a API pela internet (troque domínio e token do ERP):

```bash
curl -s -H "X-NFE-API-TOKEN: TOKEN_DO_ERP" https://fiscal.suaempresa.com.br/v1/nfse/capacidades.php
```

✅ **Deve aparecer** um texto começando com `{"ambiente":"HOMOLOGACAO"`.

Abra o **Monitor** no navegador do seu computador:

```text
https://fiscal.suaempresa.com.br/monitor/
```

Entre com o **token do Monitor**. ✅ Deve aparecer o painel com
**"Ao vivo"** em verde no canto superior direito.

🎉 **A instalação está concluída.** O sistema está em **homologação** (teste).

---

# Parte B — Homologação

Antes de emitir notas reais, é preciso testar com o governo. O roteiro
completo está em `docs/nfse-homologacao.md`. Resumo:

1. O CNPJ precisa estar **habilitado** no ambiente de testes (Produção
   Restrita do Emissor Nacional e/ou homologação de Salvador).
2. Ajuste a nota de teste em `json/nfse/homologacao_nacional.json` (código do
   serviço e alíquota reais da empresa).
3. Rode, nesta ordem:

```bash
sudo -u www-data php bin/nfse_homologar.php --padrao=NACIONAL --so-validar
```

```bash
sudo -u www-data php bin/nfse_homologar.php --padrao=NACIONAL
```

4. O resultado fica em `storage/logs/homologacao/`. Se algo falhar, envie
   essa pasta para o desenvolvedor.
5. Repita com `--padrao=SALVADOR`, se a empresa emite pela prefeitura de Salvador.

---

# Parte C — Virar para produção

> ⚠️ Faça isto **só depois** da homologação aprovada. A partir daqui as notas
> **têm valor fiscal**.

1. **Faça um backup** do banco:

```bash
sudo bash deploy/backup_banco.sh
```

2. **Numeração:** se a empresa já emitiu notas de serviço pelo portal com a
   mesma série, peça ao desenvolvedor para ajustar o número inicial antes de
   continuar (senão o fisco recusa por número repetido).

3. **Mude o ambiente para produção** em `config/nfe.php`, trocando
   `'tpAmb' => 2` por `'tpAmb' => 1`:

```bash
sudo nano config/nfe.php
```

> Isso vale também para a NF-e. Se a NF-e precisar continuar em homologação
> por enquanto, coloque `'tpAmb' => 1` só no `config/nfse.local.php`.

4. **Reinicie** para aplicar:

```bash
sudo systemctl reload php8.3-fpm && sudo systemctl restart nfse-worker@1 nfse-worker@2
```

5. **Confira:**

```bash
sudo -u www-data php bin/nfse_verificar.php --rede
```

✅ **Deve aparecer:** `[ATENCAO] Ambiente: PRODUCAO (tpAmb = 1)`. Esse aviso é
proposital, para ninguém esquecer que está em produção.

6. O Monitor passa a mostrar **PRODUÇÃO** no topo. Emita a **primeira nota
   real** com um valor pequeno e confira no portal do governo.

---

# Parte D — Dia a dia

## O sistema está funcionando?

Rode o verificador. É o primeiro passo para qualquer dúvida:

```bash
cd /var/www/nfe-integracao && sudo -u www-data php bin/nfse_verificar.php
```

Ou abra o Monitor: se aparecer **"Ao vivo"** e as notas mudarem de status,
está tudo bem.

## Ver o que o worker está fazendo

```bash
sudo journalctl -u nfse-worker@1 -f
```

Para sair, aperte **Ctrl+C**.

## Reiniciar tudo (resolve a maioria dos travamentos)

```bash
sudo systemctl restart php8.3-fpm nginx nfse-worker@1 nfse-worker@2
```

Notas que estavam sendo enviadas **não se perdem**: o sistema confere com o
governo antes de reenviar.

## Atualizar o sistema (quando o desenvolvedor avisar que há versão nova)

```bash
cd /var/www/nfe-integracao && sudo bash deploy/atualizar.sh
```

Ele faz sozinho: backup → baixa a versão nova → bibliotecas → banco →
reinicia → verificação. Se o GitHub pedir usuário e senha, use o token do
passo 2.

✅ **Deve aparecer** no final: `Atualizacao concluida. Versao atual:` e a data.

## Backup do banco

### Backup automático todo dia (configurar uma vez)

```bash
sudo crontab -e
```

Se perguntar o editor, escolha `1` (nano). Vá até o final do arquivo e
acrescente esta linha:

```text
30 2 * * * cd /var/www/nfe-integracao && bash deploy/backup_banco.sh >> /var/log/nfe-backup.log 2>&1
```

Salve e saia. Todo dia às 02:30 é criado um backup em
`/var/backups/nfe-integracao/`, e os backups com mais de 30 dias são apagados.

> 💡 Copie essa pasta periodicamente para **fora do servidor** (outro
> servidor ou a nuvem). Backup no mesmo servidor não protege contra perda
> do servidor.

### Restaurar um backup (em caso de desastre)

> ⚠️ Isto **substitui** todos os dados atuais pelos do backup. Faça com o
> desenvolvedor ao lado, se possível.

1. Veja os backups disponíveis:

```bash
sudo ls -lh /var/backups/nfe-integracao/
```

2. Pare os workers:

```bash
sudo systemctl stop nfse-worker@1 nfse-worker@2
```

3. Restaure (troque o nome do arquivo e a senha do banco):

```bash
sudo PGPASSWORD='SENHA_DO_BANCO' pg_restore --clean --if-exists --no-owner -h 127.0.0.1 -U nfephp_app -d nfephp /var/backups/nfe-integracao/banco-AAAAMMDD-HHMM.dump
```

4. Ligue os workers de novo:

```bash
sudo systemctl start nfse-worker@1 nfse-worker@2
```

## Renovar o certificado digital (todo ano)

O verificador avisa **30 dias antes** do vencimento. Quando tiver o novo `.pfx`:

1. Envie o arquivo novo (passo 6) com o **mesmo nome** `empresa.pfx`.
2. Se a senha mudou, atualize `certPassword` em `config/nfe.local.php` (passo 7.1).
3. Reinicie:

```bash
sudo systemctl reload php8.3-fpm && sudo systemctl restart nfse-worker@1 nfse-worker@2
```

4. Confira com o verificador: deve mostrar a nova data de validade.

## Dar acesso a um novo sistema

1. Gere um token: `openssl rand -hex 32`.
2. Acrescente um bloco em `consumidores` no `config/nfse.local.php`:

```php
'NOME_DO_SISTEMA' => [
    'token' => 'TOKEN_GERADO',
],
```

3. Reinicie o PHP:

```bash
sudo systemctl reload php8.3-fpm
```

4. Envie o token ao responsável pelo sistema **por um canal seguro** (não por e-mail).

Para **tirar o acesso** de um sistema, apague o bloco dele e reinicie o PHP.

---

# Parte E — Problemas comuns

| O que aparece | O que fazer |
|---|---|
| `Permission denied` ao rodar um comando | Faltou o `sudo` no começo do comando. |
| `sudo: unable to resolve host` | Aviso inofensivo; pode ignorar. |
| `Could not open input file: bin/...` | Você não está na pasta do sistema. Rode `cd /var/www/nfe-integracao`. |
| No git clone: `Authentication failed` | O token do GitHub está errado ou vencido. Gere outro (passo 2). |
| `CREATE ROLE` → `already exists` | O usuário do banco já existia. Siga em frente usando a senha dele, ou peça ajuda para trocá-la. |
| Verificador: `Nao conectou no banco` | Senha ou nome do banco diferentes em `config/database.local.php` (passo 5.3). |
| Verificador: `certificado nao abriu` | Senha do certificado errada (passo 7.1) ou arquivo em outro lugar (passo 6). |
| Verificador: `inacessivel ... Could not resolve host` | O servidor não acessa a internet ou o firewall da empresa bloqueia o site do governo. Peça liberação de **saída HTTPS (porta 443)** para os endereços mostrados. |
| `nginx -t` com erro `[::]:80` | O servidor não tem IPv6. Confira se a linha `listen [::]:80;` está comentada (com `#` na frente) no arquivo do site. |
| Site abre com **502 Bad Gateway** | O PHP está parado: `sudo systemctl restart php8.3-fpm`. |
| Monitor: "Token inválido" | Confira o `monitor_token` em `config/nfse.local.php` e rode `sudo systemctl reload php8.3-fpm`. |
| Monitor sempre em "Reconectando…" | Confira se o passo 9.1 (pool do monitor) foi feito e se o nginx foi recarregado. |
| Notas paradas em **RECEBIDO** | O worker está parado. Veja o passo 11 e `sudo systemctl status nfse-worker@1`. |
| Notas em **ERRO** com "acesso negado" | O CNPJ não está habilitado no ambiente do governo, ou o certificado é de outra empresa. |
| `atualizar.sh`: "a pasta esta na branch..." | Alguém mudou a versão manualmente. Rode o comando que a mensagem mostra. |
| `atualizar.sh`: "arquivos do sistema alterados a mao" | Alguém editou arquivos do sistema. Não apague nada: chame o desenvolvedor. |

**Ainda com problema?** Envie ao desenvolvedor:

1. o resultado completo do verificador (`sudo -u www-data php bin/nfse_verificar.php --rede`);
2. as últimas linhas do worker (`sudo journalctl -u nfse-worker@1 -n 100 --no-pager`);
3. as últimas linhas do site (`sudo tail -n 50 /var/log/nginx/nfe-integracao.error.log`).

> 🔒 Nunca envie os arquivos `*.local.php`, o `.pfx` ou os tokens.

---

## Apêndice — Onde fica cada coisa

| O quê | Onde |
|---|---|
| Sistema | `/var/www/nfe-integracao` |
| Configurações com senhas | `/var/www/nfe-integracao/config/*.local.php` |
| Ambiente (homologação/produção), CNPJ | `/var/www/nfe-integracao/config/nfe.php` |
| Certificado digital | `/var/www/nfe-integracao/storage/certs/empresa.pfx` |
| Cópia dos XMLs (armazenamento local) | `/var/www/nfe-integracao/storage/xml/nfse/` |
| Resultados da homologação | `/var/www/nfe-integracao/storage/logs/homologacao/` |
| Backups do banco | `/var/backups/nfe-integracao/` |
| Configuração do site (nginx) | `/etc/nginx/sites-available/nfe-integracao` |
| Pool PHP do monitor | `/etc/php/8.3/fpm/pool.d/nfse-monitor.conf` |
| Serviço do worker | `/etc/systemd/system/nfse-worker@.service` |
| Logs do site | `/var/log/nginx/nfe-integracao.error.log` |
| Logs do worker | `sudo journalctl -u nfse-worker@1` |
| Arquivos do kit de implantação | `/var/www/nfe-integracao/deploy/` |

Documentos técnicos (para o desenvolvedor): `docs/nfse-arquitetura.md`,
`docs/nfse-guia-api.md`, `docs/nfse-monitor.md`, `docs/nfse-homologacao.md`, `docs/ci.md`.
