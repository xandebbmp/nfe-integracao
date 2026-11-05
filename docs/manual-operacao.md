# Manual de operação — API Fiscal no servidor

Este manual explica **cada peça que roda no servidor**:
- o que cada uma faz;
- como configurar;
- como saber se está funcionando;
- como outra aplicação chama a API;
- como controlar e cobrar cada empresa cliente.

> Instalação do zero, passo a passo para leigos: `docs/manual-implantacao.md`.
> Este manual é a referência de **como tudo funciona e se configura**.

## Sumário

1. [Visão geral: o que roda e quem fala com quem](#1-visão-geral)
2. [Processos que precisam estar no ar](#2-processos-que-precisam-estar-no-ar)
3. [Arquivos de configuração (todas as chaves)](#3-arquivos-de-configuração)
4. [Servidor web: nginx ou Apache](#4-servidor-web-nginx-ou-apache)
5. [PHP-FPM e os dois pools](#5-php-fpm-e-os-dois-pools)
6. [Worker: a fila de emissão](#6-worker-a-fila-de-emissão)
7. [Tempo real: SSE (e por que não WebSocket)](#7-tempo-real-sse-e-por-que-não-websocket)
8. [Banco de dados](#8-banco-de-dados)
9. [Certificados digitais](#9-certificados-digitais)
10. [Como outra aplicação chama a API (autenticação e validação)](#10-como-outra-aplicação-chama-a-api)
11. [Webhooks: avisos para o sistema do cliente](#11-webhooks)
12. [Painel admin: empresas, bloqueios, limites e cobrança](#12-painel-admin)
13. [Como rodar cada front (painel admin e monitor)](#13-como-rodar-cada-front)
14. [Checklist: está tudo funcionando?](#14-checklist-está-tudo-funcionando)
15. [Operação do dia a dia e problemas comuns](#15-operação-do-dia-a-dia)
16. [Segurança](#16-segurança)

---

## 1. Visão geral

```
 Sistema do cliente (ERP, loja, app...)            Navegador (você)
        │  HTTPS + token da empresa                    │            │
        ▼                                              ▼            ▼
 ┌──────────────────────── nginx ou Apache ───────────────────────────────┐
 │  /v1/nfse/*.php  API          /monitor/  painel ao vivo  /admin/ painel │
 └──────────┬──────────────────────────────────┬───────────────────────────┘
            ▼                                   ▼
     PHP-FPM (pool "www")              PHP-FPM (pool "nfse-monitor")
     responde na hora,                 conexões longas do tempo real (SSE)
     grava na fila
            │                                   ▲
            ▼                                   │ lê o feed
 ┌──────────────────────────── PostgreSQL ─────────────────────────────────┐
 │ nfse_documentos  nfse_jobs (fila)  nfse_monitor (feed)  fiscal_clientes │
 │ nfse_eventos     nfse_webhooks_saida            fiscal_uso (cobrança)   │
 └───────────┬─────────────────────────────────────────────────────────────┘
             │ LISTEN/NOTIFY (acorda na hora)
             ▼
     WORKER (bin/nfse_worker.php)  × 2 processos  ──► SEFAZ (NF-e)
       monta, assina, envia, consulta, cancela    ──► Sefin Nacional (NFS-e)
       entrega webhooks ─► sistema do cliente     ──► Prefeitura de Salvador
```

**A regra de ouro:** a API **nunca** fala com o fisco durante a chamada do
cliente. Ela confere os dados, grava na fila e responde em milissegundos.
Quem fala com SEFAZ, Sefin e prefeitura é o **worker**, em segundo plano,
com novas tentativas automáticas. Por isso:
- se a SEFAZ estiver lenta ou fora, o sistema do cliente não trava;
- nenhuma nota se perde: ela fica na fila até dar certo ou virar `ERRO`.

---

## 2. Processos que precisam estar no ar

| Processo | Para quê | Se parar... | Como ver |
|---|---|---|---|
| **nginx** (ou Apache) | Recebe as chamadas HTTPS | Ninguém acessa nada | `systemctl status nginx` |
| **php8.3-fpm** | Roda o PHP da API, do monitor e do painel admin | Erro 502 em tudo | `systemctl status php8.3-fpm` |
| **postgresql** | Guarda tudo (notas, fila, cadastro) | API responde erro 500 | `systemctl status postgresql` |
| **nfse-worker@1 e @2** | Envia as notas, consulta, cancela, entrega webhooks | Notas ficam em `RECEBIDO` (nada se perde: ao voltar, ele continua) | `systemctl status 'nfse-worker@*'` |
| **cron do backup** | Cópia diária do banco | Sem backup | `sudo crontab -l` |

Não há outro processo: **não existe** servidor Node, Redis ou servidor de
WebSocket. O tempo real usa o próprio PHP-FPM (seção 7).

---

## 3. Arquivos de configuração

Todos ficam em `config/`. Os arquivos `*.local.php` têm senhas: **nunca vão
para o Git** e precisam de permissão `640` (dono `root`, grupo `www-data`).

| Arquivo | Conteúdo |
|---|---|
| `config/database.local.php` | Endereço, banco, usuário e senha do PostgreSQL |
| `config/nfe.local.php` | Certificado e senha da empresa padrão (rotas antigas `/v1/nfe/*`), `tpAmb` |
| `config/nfse.local.php` | Tudo da API nova (tabela abaixo) |

Principais chaves do `config/nfse.local.php` (os valores padrão e os
comentários estão em `config/nfse.php`):

| Chave | O que faz | Exemplo |
|---|---|---|
| `tpAmb` | `2` = homologação (testes, sem valor fiscal); `1` = produção | `2` |
| `admin_token` | Senha do painel admin (`/admin/`). Vazio = painel desligado | `openssl rand -hex 32` |
| `chave_certificados` | Chave que criptografa os certificados enviados pelo painel. **Guarde cópia.** | `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` |
| `monitor_token` | Senha do monitor ao vivo (`/monitor/`), que mostra todas as notas | `openssl rand -hex 32` |
| `consumidores` | Sistemas internos com token fixo no arquivo, que emitem por vários CNPJs (opcional; as empresas clientes ficam no painel) | ver `config/nfse.local.php.example` |
| `emitentes` | Empresas configuradas por arquivo (opcional; prefira o painel) | idem |
| `armazenamento` | Cópia dos XMLs: `local` (pasta) ou `s3` (AWS S3, MinIO, Wasabi...) | `['tipo' => 's3', ...]` |
| `api.max_documentos_por_lote` | Máximo de notas por chamada | `50` |
| `api.max_aguardar_segundos` | Máximo que a API espera quando o cliente usa `?aguardar=` | `60` |
| `fila.max_tentativas` | Tentativas antes de virar `ERRO` | `5` |
| `fila.espera_base_segundos` | Espera entre tentativas: 30 s, 60 s, 120 s... | `30` |
| `nfe.serie` | Série padrão da NF-e quando o JSON não manda | `1` |

**Depois de mudar um `*.local.php`**, recarregue:

```bash
sudo systemctl reload php8.3-fpm
```
```bash
sudo systemctl restart 'nfse-worker@*'
```

O cadastro feito no **painel admin** não precisa disso: a API lê na hora e
o worker relê sozinho a cada minuto.

---

## 4. Servidor web: nginx ou Apache

Escolha **um** dos dois. Os arquivos prontos já estão no projeto:

| | nginx (recomendado) | Apache |
|---|---|---|
| Arquivo | `deploy/nginx/nfe-integracao.conf` | `deploy/apache/nfe-integracao.conf` |
| Instalar | `sudo cp` para `/etc/nginx/sites-available/` + link em `sites-enabled` | `sudo a2enmod proxy proxy_fcgi setenvif` + `a2ensite` |
| HTTPS | `sudo certbot --nginx` | `sudo certbot --apache` |

Os dois fazem as mesmas três coisas:

1. **Pasta pública** = `/var/www/nfe-integracao/public`. Nada fora dela
   aparece na internet (configs, certificados e código ficam protegidos).
2. **Rota do tempo real** (`/v1/nfse/monitor.php`) vai para um **pool PHP
   separado**, **sem buffer e sem compressão**, com timeout de 400 s. Sem
   isso, os eventos chegam atrasados ou em blocos (seção 7).
3. **Demais `.php`** vão para o pool normal do PHP-FPM, com timeout de 120 s.

Usa outro servidor (Caddy, Traefik, um balanceador na frente)? Replique as
mesmas três regras. O ponto crítico é o **item 2**: desligue buffer e gzip
na rota do monitor.

---

## 5. PHP-FPM e os dois pools

| Pool | Arquivo | Para quê | Configuração |
|---|---|---|---|
| `www` (padrão) | `/etc/php/8.3/fpm/pool.d/www.conf` | API e painel admin: respostas rápidas | `pm.max_children` = memória livre ÷ ~60 MB (ex.: 2 GB → 20) |
| `nfse-monitor` | `deploy/php-fpm/nfse-monitor.conf` → copiar para `pool.d/` | Conexões longas do monitor ao vivo | `pm = ondemand`, `max_children = 10` (= painéis abertos ao mesmo tempo) |

**Por que separar:** cada monitor aberto prende um processo PHP por até 5
minutos. Num pool só, 20 abas abertas ocupariam a API toda e as chamadas
dos clientes ficariam na fila. Separado, o monitor nunca atrapalha a emissão.

Extensões PHP necessárias: `curl`, `dom`, `gd`, `mbstring`, `openssl`,
`pgsql`/`pdo_pgsql`, `soap`, `sodium`, `xml`, `zip`. O `deploy/instalar_pacotes.sh`
já instala tudo. Para conferir:

```bash
php bin/nfse_verificar.php
```

---

## 6. Worker: a fila de emissão

### O que ele faz, em ordem, sem parar

1. Pega o próximo trabalho da fila (`nfse_jobs`), trava só aquele registro
   (`FOR UPDATE SKIP LOCKED`, assim dois workers nunca pegam o mesmo) e executa:
   - `EMITIR`: monta o XML, assina, valida no XSD e envia ao fisco;
   - `CONSULTAR_PROCESSAMENTO`: lote assíncrono (Salvador, recibo da SEFAZ);
   - `EVENTO`: cancelamento ou Carta de Correção;
   - `CONSULTAR`, `ARQUIVAR` (cópia do XML na nuvem).
2. Falha temporária (fisco fora, timeout): agenda de novo com espera
   crescente (30 s, 60 s, 120 s...). Depois de `fila.max_tentativas`, vira `ERRO`.
3. Entrega os **webhooks** pendentes (seção 11).
4. A cada minuto, devolve à fila os trabalhos de um worker que morreu no
   meio e relê o cadastro de empresas do painel admin.
5. Sem trabalho, **dorme** até o banco avisar (`LISTEN/NOTIFY`) ou 5 s
   passarem. Nota nova é atendida na hora, sem ficar consultando o banco.

**Proteção contra nota em dobro:** antes de reenviar algo que pode ter
chegado ao fisco (um timeout, por exemplo), o worker **consulta primeiro**,
pela chave (NF-e) ou pela DPS/RPS (NFS-e). Só reenvia se o fisco disser que
não existe.

### Quantos rodar

Dois processos atendem bem até alguns milhares de notas por dia. Para mais
volume, suba mais instâncias (`@3`, `@4`...). Eles se dividem sozinhos.

### Opção A: systemd (padrão, já vem pronto)

```bash
sudo cp deploy/systemd/nfse-worker@.service /etc/systemd/system/
```
```bash
sudo systemctl daemon-reload && sudo systemctl enable --now nfse-worker@1 nfse-worker@2
```

| Ação | Comando |
|---|---|
| Ver se está rodando | `systemctl status 'nfse-worker@*'` |
| Ver o que está fazendo | `journalctl -u 'nfse-worker@*' -f` |
| Reiniciar (após atualizar o código ou a config) | `sudo systemctl restart 'nfse-worker@*'` |
| Parar | `sudo systemctl stop 'nfse-worker@*'` |

O serviço reinicia sozinho se cair (`Restart=always`) e liga no boot. Ao
parar, o worker **termina a nota em andamento** antes de sair (até 120 s).

### Opção B: pm2 (se você já usa pm2 no servidor)

O pm2 cuida só do **worker**. A API continua no PHP-FPM, porque o pm2 não
serve PHP pela web.

```bash
sudo -u www-data pm2 start deploy/pm2/ecosystem.config.js
```
```bash
sudo -u www-data pm2 save && sudo pm2 startup systemd -u www-data --hp /var/www
```

Comandos: `pm2 status`, `pm2 logs nfse-worker-1`, `pm2 restart all`.

> Use **systemd OU pm2**, nunca os dois. Se escolher pm2, desligue os
> serviços: `sudo systemctl disable --now 'nfse-worker@*'`.

### Rodar uma vez só (teste ou diagnóstico)

```bash
sudo -u www-data php bin/nfse_worker.php --uma-vez
```

---

## 7. Tempo real: SSE (e por que não WebSocket)

O monitor (`/monitor/`) atualiza sozinho cada nota. Para isso usamos **SSE
(Server-Sent Events)**, não WebSocket.

| | SSE (usado) | WebSocket |
|---|---|---|
| Direção | Servidor → navegador (é o que o monitor precisa) | Dois sentidos |
| Servidor extra | **Nenhum**: é uma requisição HTTP comum, no PHP-FPM | Precisa de um processo separado (Node, Ratchet, Swoole...) e de configuração de *upgrade* no proxy |
| Reconexão | Automática no navegador, continua do último evento (`Last-Event-ID`) | Você programa |
| Passa por proxy/firewall | Sim (é HTTP) | Às vezes bloqueado |

### Como funciona

1. Toda mudança de nota (recebida, enviada, autorizada, rejeitada,
   cancelada...) grava uma linha em `nfse_monitor`, a tabela de feed.
2. O navegador abre `GET /v1/nfse/monitor.php?token=...` e a conexão fica aberta.
3. O PHP fica escutando o aviso do banco (`LISTEN/NOTIFY`). Quando chega
   uma mudança, envia na hora as linhas novas como eventos. Sem mudanças,
   manda um "ping" periódico para a conexão não cair.
4. Depois de ~5 minutos o servidor fecha de propósito, para não prender o
   processo. O navegador reconecta sozinho com o número do último evento e
   **nada se perde**.

### Configuração necessária

Já está nos arquivos de nginx e Apache:
- pool PHP próprio;
- `fastcgi_buffering off` (nginx) ou `flushpackets=on` (Apache);
- gzip desligado;
- timeout de 400 s.

O sistema do cliente também pode usar esse stream, mas o recomendado para
sistemas é o **webhook** (seção 11). O stream é pensado para telas.

---

## 8. Banco de dados

- **PostgreSQL 16.** Os scripts ficam em `database/scripts/`, numerados.
  Aplicar ou atualizar:

  ```bash
  bash deploy/aplicar_banco.sh
  ```

  Pode rodar de novo sem estragar nada.

| Script | Cria |
|---|---|
| `0002` | Notas, fila, eventos, monitor, webhooks, numeração |
| `0003` | Multiempresa, rejeição na entrada, arquivamento |
| `0004` | NF-e (padrão `NFE`, `DENEGADO`, protocolo, CC-e) |
| `0005` | Empresas clientes, **uso para cobrança** e histórico do painel admin |

- **Backup diário:** `deploy/backup_banco.sh`, no cron. Veja o manual de
  implantação, seção "Backup".
- **Os XMLs autorizados ficam no banco**, e também numa cópia em pasta ou
  na nuvem (`armazenamento`). A guarda legal é de 5 anos.

---

## 9. Certificados digitais

Cada empresa emite com o **próprio certificado A1** (`.pfx`). Há duas formas:

| Forma | Como | Onde fica |
|---|---|---|
| **Painel admin** (recomendado) | Enviar o `.pfx` e a senha na tela da empresa | No banco, **criptografado** com a `chave_certificados`. Nunca em disco. |
| Arquivo | `emitentes.<cnpj>.certificado` no `config/nfse.local.php` | `storage/certs/` (permissão 640) |

No envio pelo painel, a API confere **na hora**:
- a senha;
- se é e-CNPJ da mesma empresa (filial pode usar o da matriz);
- se não está vencido.

O painel avisa quando faltam menos de 30 dias para vencer. Para renovar,
basta enviar o novo arquivo na mesma tela.

---

## 10. Como outra aplicação chama a API

### Autenticação: como a API sabe quem está chamando

**Cada empresa cliente tem o próprio token**, gerado no painel admin. O
sistema dela manda o token em toda chamada:

```http
X-NFE-API-TOKEN: <token da empresa>
```

Também vale `Authorization: Bearer <token>`.

### Validação: o que acontece em cada emissão, em ordem

| # | Conferência | Onde a API procura | Se falhar |
|---|---|---|---|
| 1 | O token existe? | Banco (`fiscal_clientes.token_hash`: só o resumo sha256, o token nunca é guardado) | `401 NAO_AUTORIZADO` |
| 2 | A empresa está liberada? (bloqueio manual e vencimento `liberado_ate`) | Banco (painel admin) | `403 EMISSAO_BLOQUEADA` / `403 EMISSAO_VENCIDA` — o lote inteiro é recusado |
| 3 | O CNPJ emitente é o da empresa do token? | **Dados da nota** (`emit.CNPJ` na NF-e, `emitente_cnpj` na NFS-e) comparados com o cadastro | documento `RECUSADO` |
| 4 | O tipo de nota foi contratado? | Banco (`padroes_permitidos`) | `RECUSADO` `TIPO_NAO_CONTRATADO` |
| 5 | Cabe no limite do mês? | Banco: autorizadas no mês + em processamento + as do próprio lote | `RECUSADO` `LIMITE_MENSAL` |
| 6 | Os dados da nota estão certos? | **Dados da nota** (campos obrigatórios, formatos, NCM, CFOP...) | `REJEITADO` com o campo e **como corrigir** |
| 7 | Mesma `referencia` já enviada? | Banco | Devolve a nota existente (`JA_EXISTENTE`), **sem duplicar** |

Ou seja: **quem é** e **o que pode** vêm do **cadastro no banco**; **o que
emitir** vem dos **dados na requisição**.

Consulta, PDF, XML e cancelamento continuam funcionando mesmo com a empresa
bloqueada.

### Exemplos

Emitir uma NF-e e esperar até 30 s pelo resultado:

```bash
curl -X POST https://SEU_DOMINIO/v1/nfse/emitir.php?aguardar=30 \
  -H "X-NFE-API-TOKEN: <token>" -H "Content-Type: application/json" \
  -d @nota.json
```

Corpo da requisição: `{"padrao":"NFE","documentos":[{ "referencia":"PEDIDO-1", "ide":{...}, "emit":{...}, ... }]}`.
O formato completo de NF-e e NFS-e está em `docs/nfse-guia-api.md`.

| Para quê | Chamada |
|---|---|
| Ver tudo de um envio | `GET /v1/nfse/lote.php?id=<lote_id>` |
| Uma nota (JSON) | `GET /v1/nfse/documento.php?id=<id>` |
| XML autorizado | `GET /v1/nfse/documento.php?id=<id>&formato=xml` |
| XML de cancelamento/CC-e | `GET /v1/nfse/documento.php?id=<id>&evento=<evento_id>&formato=xml` |
| DANFE / PDF | `GET /v1/nfse/pdf.php?id=<id>` |
| Cancelar | `POST /v1/nfse/evento.php` `{"documento_id":"...","tipo_evento":"110111","motivo":"..."}` |
| O que cada tipo suporta | `GET /v1/nfse/capacidades.php` |

### Status que o sistema do cliente recebe (iguais para todos os tipos)

`RECEBIDO` → `PROCESSANDO` → `AUTORIZADO`, `REJEITADO`, `DENEGADO` ou `ERRO`.
Depois, pode virar `CANCELADO` (ou `SUBSTITUIDO` na NFS-e).

---

## 11. Webhooks

Em vez de ficar perguntando, o sistema do cliente pode receber um `POST`
**a cada mudança** de nota ou evento. Para isso:
1. Configure a URL e um segredo na tela da empresa, no painel admin.
2. O worker envia:
   - corpo JSON com `tipo` (`documento.atualizado` ou `evento.atualizado`),
     o `documento` completo e `enviado_em`;
   - cabeçalho `X-NFSe-Assinatura: sha256=<HMAC do corpo com o segredo>`.
3. Se o sistema do cliente não responder `2xx`, o worker tenta de novo em
   1 min, 2 min, 4 min... até 10 vezes.

O cliente **deve conferir a assinatura** antes de confiar. Em PHP:

```php
$corpo = file_get_contents('php://input');
$esperado = 'sha256=' . hash_hmac('sha256', $corpo, 'SEGREDO_DO_WEBHOOK');
if (!hash_equals($esperado, $_SERVER['HTTP_X_NFSE_ASSINATURA'] ?? '')) { http_response_code(401); exit; }
```

Em Node.js:

```js
const esperado = 'sha256=' + crypto.createHmac('sha256', SEGREDO).update(corpoBruto).digest('hex');
if (!crypto.timingSafeEqual(Buffer.from(esperado), Buffer.from(req.headers['x-nfse-assinatura'] || ''))) return res.sendStatus(401);
```

---

## 12. Painel admin

Endereço: `https://SEU_DOMINIO/admin/`. Ele pede o `admin_token`.

### O que a tela mostra

- **Resumo:** total de empresas, liberadas, bloqueadas, vencidas, com limite
  atingido e o total de notas autorizadas no mês.
- **Uma linha por empresa:**
  - plano e situação;
  - notas autorizadas no mês por tipo (NF-e, NFS-e Nacional, NFS-e Salvador);
  - franquia usada, com barra;
  - vencimento e validade do certificado;
  - final do token.
- **Ao clicar numa empresa:**
  - controle de emissão;
  - dados cadastrais e webhook;
  - certificado;
  - token;
  - numeração de cada série (último usado e próxima nota);
  - uso dos últimos 12 meses;
  - documentos do mês por status;
  - histórico de alterações, com data e IP.

### Colocar uma empresa nova para emitir

1. **+ Nova empresa:**
   - CNPJ e razão social;
   - UF e IE (NF-e) e/ou inscrição municipal (NFS-e);
   - regime.
2. **Controle de emissão:**
   - plano;
   - limite mensal (vazio = sem limite);
   - "liberado até" (vazio = sem vencimento);
   - tipos de nota contratados.
3. **Certificado:** envie o `.pfx` e a senha.
4. **Acesso à API → Gerar token.** Copie e entregue ao cliente: o token
   **não aparece de novo**. Perdeu? Gere outro; o antigo para de valer na hora.
5. **Numeração:** se a empresa **já emitia** por outro sistema, informe o
   **último número usado** em cada série (NF-e, DPS ou RPS). A API continua
   do seguinte. Antes de aplicar, a tela mostra qual será o próximo número.
   O número **nunca volta para trás**: se a API já usou um maior, ele é
   mantido, para não sair nota com número repetido. Vale para o ambiente
   atual do servidor (homologação e produção têm numeração separada).
   Empresa nova, que nunca emitiu? Não precisa fazer nada: começa do 1.
6. (Opcional) **Webhook:** a URL e o segredo do sistema do cliente.

### As quatro formas de bloqueio

| Bloqueio | Como | O que o cliente recebe |
|---|---|---|
| **Manual** | Desmarcar "Emissão liberada" e escrever o motivo | `403 EMISSAO_BLOQUEADA` com o motivo |
| **Por data** | "Liberado até" = último dia pago. No dia seguinte, bloqueia sozinho | `403 EMISSAO_VENCIDA` |
| **Franquia** | "Limite de notas por mês". Libera sozinho no dia 1º | documento recusado `LIMITE_MENSAL` |
| **Por tipo** | Desmarcar o tipo em "Tipos de nota contratados" | documento recusado `TIPO_NAO_CONTRATADO` |

Em todos os casos, consultar, baixar XML/PDF e **cancelar** notas já
emitidas continuam funcionando. O bloqueio impede apenas **notas novas**.

### Cobrança: o que conta

- Conta **cada nota AUTORIZADA, uma única vez**, no mês da autorização
  (fuso da Bahia). Nota cancelada depois **continua contando**: ela foi emitida.
- **Não contam:** rejeitadas, denegadas, com erro e eventos (cancelamento, CC-e).
- O registro é feito **pelo próprio banco** (gatilho em `fiscal_uso`), no
  momento em que a nota vira autorizada. Ele não depende do código PHP e
  não conta duas vezes a mesma nota.
- O ambiente conta separado: homologação não se mistura com produção.

**Relatório de cobrança:** botão no topo do painel. Escolha o mês e veja
quantas notas cada empresa usou, por tipo. **Baixar planilha (CSV)** abre
direto no Excel. Para integrar com um sistema de cobrança, use a rota:

```bash
curl -H "X-ADMIN-TOKEN: <admin_token>" "https://SEU_DOMINIO/v1/admin/uso.php?mes=2026-11"
```

Para planilha, acrescente `&formato=csv`.

| Rota admin (todas com `X-ADMIN-TOKEN`) | Para quê |
|---|---|
| `GET /v1/admin/clientes.php` | Lista com situação e uso do mês |
| `POST /v1/admin/clientes.php` | Cadastrar ou alterar (só os campos enviados mudam) |
| `GET /v1/admin/cliente.php?cnpj=` | Detalhe, uso de 12 meses e histórico |
| `POST /v1/admin/certificado.php` | Enviar o `.pfx` (multipart: `cnpj`, `senha`, `arquivo`) |
| `POST /v1/admin/token.php` | Gerar um token novo |
| `POST /v1/admin/numeracao.php` | Último número já usado numa série: `{"cnpj","padrao","serie","ultimo_numero"}` |
| `GET /v1/admin/uso.php?mes=AAAA-MM[&formato=csv]` | Relatório de cobrança |

Assim seu sistema de cobrança consegue bloquear e liberar sozinho. Por
exemplo, ao detectar fatura em atraso:

```bash
curl -X POST https://SEU_DOMINIO/v1/admin/clientes.php -H "X-ADMIN-TOKEN: <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"cnpj":"11222333000181","emissao_liberada":false,"motivo_bloqueio":"Fatura de outubro em aberto"}'
```

---

## 13. Como rodar cada front

O projeto tem **dois fronts**. Os dois são páginas HTML/CSS/JS puras:
- **sem build**: não tem `npm install`, `npm run build` nem Node;
- **sem servidor próprio**: o mesmo nginx/Apache que serve a API entrega as
  páginas.

Para "rodar" um front, basta o servidor estar no ar (seções 2 e 4) e abrir
o endereço no navegador.

| Front | Endereço | Arquivos | Senha pedida na tela | Para quem |
|---|---|---|---|---|
| **Painel admin** | `https://SEU_DOMINIO/admin/` | `public/admin/` (`index.html`, `admin.css`, `admin.js`) | `admin_token` do `config/nfse.local.php` | Você: empresas, certificados, tokens, bloqueios, numeração e cobrança |
| **Monitor** | `https://SEU_DOMINIO/monitor/` | `public/monitor/` (`index.html`, `monitor.css`, `monitor.js`) | `monitor_token` (vê **todas** as notas) **ou** o token de uma empresa cliente (vê **só as notas dela**) | Você e a equipe de suporte; e também o próprio cliente, se quiser acompanhar as notas dele |

### Passo a passo (servidor)

1. Confira se o servidor está no ar:

   ```bash
   systemctl status nginx php8.3-fpm postgresql
   ```

2. Confira se os tokens estão configurados no `config/nfse.local.php`
   (`admin_token` e `monitor_token`, com 24 caracteres ou mais) e recarregue:

   ```bash
   sudo systemctl reload php8.3-fpm
   ```

3. Abra o endereço no navegador e digite o token.
   - **Painel:** marque "Lembrar neste navegador" para não digitar de novo.
     Sair apaga o token salvo.
   - **Monitor:** o selo **"Ao vivo"** verde indica que o tempo real (SSE)
     está funcionando.

**Para ver o token configurado** (no servidor):

```bash
sudo grep -E "admin_token|monitor_token" /var/www/nfe-integracao/config/nfse.local.php
```

**Para trocar um token:** gere outro com `openssl rand -hex 32`, troque no
arquivo e rode `sudo systemctl reload php8.3-fpm`. Quem estava logado
precisa entrar de novo.

### Como o front fala com a API

As páginas chamam a API **no mesmo domínio**, por caminho relativo:
- o painel usa `../v1/admin/`;
- o monitor usa `../v1/nfse/`.

Por isso:
- **Front e API ficam juntos no mesmo servidor e domínio.** Não é preciso
  configurar endereço da API nem CORS.
- Para hospedar o front em **outro domínio** (por exemplo, dentro do seu
  sistema), seria preciso liberar CORS na API. Hoje isso não está
  configurado: peça antes de fazer.
- Para **embutir no seu sistema** (Scriptcase ou outro), o mais simples é um
  link ou um `<iframe>` apontando para `https://SEU_DOMINIO/admin/` ou
  `/monitor/`.

### Restringir quem acessa (recomendado)

Os dois fronts mostram dados de todas as empresas. Além do token, limite
por IP ou VPN. No nginx, dentro do `server { ... }`:

```nginx
location ^~ /admin/      { allow 200.100.50.0/24; deny all; }
location ^~ /v1/admin/   { allow 200.100.50.0/24; deny all; include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location ^~ /monitor/    { allow 200.100.50.0/24; deny all; }
```

Troque pelo IP ou faixa do seu escritório ou VPN e rode
`sudo nginx -t && sudo systemctl reload nginx`.

### Alterar o visual ou os textos

- **Cores:** ficam no bloco `:root` do `public/monitor/monitor.css`, que vale
  para os dois fronts. O que é só do painel está em `public/admin/admin.css`.
- **Textos e telas:** `public/admin/admin.js` e `public/monitor/monitor.js`,
  com comentários em português. Nomes de status e de tipos de nota ficam
  nas constantes do início de cada arquivo.
- **Depois de alterar:** não há build. Atualize o servidor
  (`sudo bash deploy/atualizar.sh`) e recarregue a página no navegador com
  Ctrl+F5.

### Ver um front sem servidor completo (só para conferir a tela)

Num computador com PHP 8.3, as dependências instaladas (`composer install`)
e acesso a um PostgreSQL com as tabelas criadas
(`config/database.local.php`), dá para subir um servidor de teste do próprio
PHP:

```bash
php -S 127.0.0.1:8080 -t public
```

Abra `http://127.0.0.1:8080/admin/` ou `/monitor/`. Isso serve só para
conferir a tela: o tempo real e os envios ao fisco precisam do worker e da
configuração completa do servidor.

---

## 14. Checklist: está tudo funcionando?

1. Verificador completo (configuração, banco, certificados, tokens, fisco):

   ```bash
   sudo -u www-data php bin/nfse_verificar.php --rede
   ```

   Precisa terminar sem `[ERRO]`.
2. Processos (seção 2):

   ```bash
   systemctl status nginx php8.3-fpm postgresql 'nfse-worker@*'
   ```

3. API respondendo:

   ```bash
   curl -s https://SEU_DOMINIO/v1/nfse/capacidades.php -H "X-NFE-API-TOKEN: <token>"
   ```

4. Painel admin abre e lista as empresas: `https://SEU_DOMINIO/admin/`.
5. Monitor mostra "Ao vivo": `https://SEU_DOMINIO/monitor/`.
6. Uma nota de teste em homologação chega a `AUTORIZADO` (`docs/nfse-homologacao.md`).

---

## 15. Operação do dia a dia

| Quero... | Comando ou lugar |
|---|---|
| Ver o worker trabalhando | `journalctl -u 'nfse-worker@*' -f` |
| Ver erros da API | `sudo tail -f /var/log/nginx/nfe-integracao.error.log` |
| Notas paradas há muito tempo | Monitor, filtro "Recebido". Ou rode `php bin/nfse_verificar.php` |
| Atualizar o sistema | `sudo bash deploy/atualizar.sh` (baixa, aplica o banco, reinicia) |
| Bloquear ou liberar uma empresa | Painel admin, na tela da empresa |
| Quanto cada empresa emitiu | Painel admin → Relatório de cobrança |

| Problema | Causa provável | Solução |
|---|---|---|
| Notas ficam em `RECEBIDO` | Worker parado | `sudo systemctl restart 'nfse-worker@*'` e veja o `journalctl` |
| `502 Bad Gateway` | PHP-FPM parado | `sudo systemctl restart php8.3-fpm` |
| Monitor em "Reconectando..." ou atrasado | Proxy com buffer/gzip na rota do monitor | Revise a seção 4, item 2 |
| Cliente recebe `401` | Token errado ou trocado | Painel admin → gerar token novo e entregar |
| Cliente recebe `403 EMISSAO_...` | Empresa bloqueada ou vencida | Painel admin → liberar ou ajustar "liberado até" |
| "Certificado guardado não abriu" | A `chave_certificados` mudou | Volte a chave antiga, ou reenvie os certificados no painel |
| Muitas `ERRO` de uma vez | Fisco fora do ar | Aguarde e use "consultar" ou reenvie com a mesma referência (não duplica) |

---

## 16. Segurança

- **HTTPS sempre** (certbot). Os tokens viajam no cabeçalho.
- O **painel admin** e o **monitor**: de preferência, deixe acessíveis só
  pela rede interna ou VPN. No nginx, com `allow`/`deny` por IP nos
  `location /admin/`, `/v1/admin/` e `/monitor/`.
- **Tokens:** o das empresas fica só como hash no banco. O `admin_token` e o
  `monitor_token` ficam no `config/nfse.local.php` (permissão 640).
- **Certificados:** criptografados no banco. A `chave_certificados` fica
  **fora** do banco. Guarde uma cópia dela junto com o backup, mas não no
  mesmo lugar.
- **Histórico:** toda alteração no painel admin fica registrada (o quê,
  quando, IP).
- **Usuário do banco** da aplicação: sem superusuário. O manual de
  implantação já cria assim.
