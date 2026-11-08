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
13. [Como rodar cada front (painel admin, monitor e portal do cliente)](#13-como-rodar-cada-front)
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
| `tpAmb` | `2` = homologação (testes, sem valor fiscal); `1` = produção. Valor inicial: quem troca no dia a dia é o painel (Sistema > **Ambiente de emissão**), que vale por cima deste | `2` |
| `admin_token` | Senha do painel admin (`/admin/`). Vazio = painel desligado | `openssl rand -hex 32` |
| `chave_certificados` | Chave que criptografa os certificados enviados pelo painel. **Guarde cópia.** | `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` |
| `monitor_token` | Senha do monitor ao vivo (`/monitor/`), que mostra todas as notas. Opcional: quem entra no painel admin já abre o monitor sem ele. **Prefira gerar pelo painel** (Sistema > **Senha do Monitor**), que vale junto com este | `openssl rand -hex 32` |
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
5. A cada 5 minutos, emite as **notas recorrentes automáticas** do dia
   (seção 13, "Recorrências"). Pausado no painel, não emite.
   A cada volta também envia os **e-mails da fila** ("Esqueci a senha"),
   mesmo pausado: a pausa vale para as notas, não para o login.
6. Sem trabalho, **dorme** até o banco avisar (`LISTEN/NOTIFY`) ou 5 s
   passarem. Nota nova é atendida na hora, sem ficar consultando o banco.

**Proteção contra nota em dobro:** antes de reenviar algo que pode ter
chegado ao fisco (um timeout, por exemplo), o worker **consulta primeiro**,
pela chave (NF-e) ou pela DPS/RPS (NFS-e). Só reenvia se o fisco disser que
não existe.

### No dia a dia: pelo painel (sem terminal)

Depois de instalado (opção A ou B abaixo, **uma vez só**), o worker é
acompanhado e comandado pelo botão **Sistema** no topo do painel admin.
A bolinha do botão mostra a situação: verde = ligado, laranja = pausado ou
reiniciando, vermelha = parado.

| Botão | O que acontece |
|---|---|
| **Ligar worker** | Aparece quando o worker está parado. Liga o worker pelo próprio painel, sem terminal (saída em `storage/logs/worker.log`). Se houver notas de **produção** na fila, o painel avisa quantas são e só liga depois de você confirmar, porque elas valem de verdade. |
| **Desligar worker** | O worker termina a nota atual e para. As notas novas ficam na fila até ligar de novo. |
| **Pausar envios** | Os workers param de enviar. Notas novas continuam entrando na fila e esperam. Use quando a prefeitura estiver fora do ar ou antes de uma manutenção. |
| **Retomar envios** | Voltam a enviar, começando pelas que esperaram. |
| **Reiniciar worker** | Cada worker termina a nota atual e sai; o que foi ligado pelo painel liga um novo sozinho, e no servidor o systemd (ou o pm2) liga de novo em segundos, já com o código e a configuração novos. Use depois de atualizar o sistema. |

A tela também mostra quantas notas esperam na fila, quantas falharam nas
últimas 24 h, cada worker ligado (último sinal de vida) e quem deu cada
ordem (IP e horário).

Como funciona: o painel **não** liga processos no servidor (isso daria ao
site poder de administrador). Ele grava a ordem na tabela `sistema_controle`
e avisa no canal `nfse_controle`; o worker obedece na hora. A cada 10 s o
worker grava um sinal de vida em `sistema_workers`; sem sinal por 30 s, o
painel mostra "parado". Precisa do script `0007_sistema_criar_controle_worker.sql`
(o `deploy/aplicar_banco.sh` aplica). Sem ele, o worker funciona normalmente,
só não aparece no painel.

> "Reiniciar" só religa o worker se ele estiver no systemd ou no pm2. Um
> worker iniciado à mão no terminal apenas para.

### Diagnóstico pelo painel

Na mesma tela **Sistema**, o botão **Rodar diagnóstico** confere servidor,
configuração, banco, worker, certificados de cada empresa e se o servidor
alcança a Sefin, Salvador e a SEFAZ. Mostra primeiro os erros, depois os
avisos, cada um com **Como resolver**. Não altera nada, não assina e não
envia nota (a conferência da internet só pergunta "você responde?", sem
certificado). São as mesmas regras do `bin/nfse_verificar.php` no terminal
(`src/Nfse/Servico/Diagnostico.php`); o terminal confere, a mais, os
arquivos de configuração e os serviços do systemd.

### Configurações pelo painel

Ainda na tela **Sistema**, a seção **Configurações** muda, sem mexer em
arquivo: tempo de sessão, "lembrar de mim", senhas erradas antes de
bloquear e tempo de bloqueio do portal; tipo de NFS-e do portal;
tentativas e espera quando a prefeitura falha; tempo para devolver nota
travada à fila; espera máxima e tamanho de lote da API; link de
visualização de Salvador; **Gerar DAS pela Receita** (SERPRO: Desligado,
Teste ou Produção; ver "Fechamento do mês e guia DAS" na seção 12).

- Cada campo mostra o **padrão do arquivo**. O valor do painel fica em
  `sistema_ajustes` (script `0009`) e substitui o do arquivo. "Voltar ao
  padrão" e salvar apaga o ajuste.
- Valem na hora para o portal e a API. O worker relê em até 1 minuto.
- **Ficam só no arquivo, de propósito:** ambiente (homologação/produção),
  senhas e tokens (`admin_token`, `monitor_token`, `chave_certificados`,
  credenciais do S3).
- Para liberar uma configuração nova no painel, acrescente uma linha em
  `src/Nfse/Dominio/AjustesPainel.php` (a tela e a validação usam essa lista).

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
- **Atualizar pelo painel (sem terminal):** botão **Sistema** → seção
  **Banco de dados**. Ela lista os scripts que ainda não rodaram neste banco
  e o botão **Atualizar banco** aplica todos, em ordem. Se um der erro, ele
  para ali, nada daquele script é gravado e a mensagem mostra qual foi.
  Depois, clique em **Reiniciar worker**.
- **Pelo terminal** (na instalação, antes de o painel existir):

  ```bash
  bash deploy/aplicar_banco.sh
  ```

  Os dois caminhos podem rodar de novo sem estragar nada e anotam o que
  rodou em `sistema_scripts` (script `0008`). Um script cujo arquivo mudou
  depois de aplicado aparece de novo como pendente.

| Script | Cria |
|---|---|
| `0002` | Notas, fila, eventos, monitor, webhooks, numeração |
| `0003` | Multiempresa, rejeição na entrada, arquivamento |
| `0004` | NF-e (padrão `NFE`, `DENEGADO`, protocolo, CC-e) |
| `0005` | Empresas clientes, **uso para cobrança** e histórico do painel admin |
| `0006` | Portal do cliente: usuários, sessões, serviços, tomadores, cache de CNPJ/CEP |
| `0007` | Tela Sistema: sinal de vida e ordens do worker |
| `0008` | Registro dos scripts aplicados (botão **Atualizar banco**) |
| `0009` | Configurações alteradas pelo painel (`sistema_ajustes`) |
| `0010` | Portal: papel de administrador dos usuários (`portal_usuarios.administrador`) |
| `0011` | Portal: notas recorrentes (`portal_recorrencias`) |
| `0012` | Portal: "Esqueci a senha" e fila de e-mails (`portal_redefinicoes`, `sistema_emails`) |

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

Endereço: `https://SEU_DOMINIO/admin/`. Cada pessoa entra com o **próprio
e-mail e senha** (script `0014`).

### Quem entra no painel

- **Pessoas do painel** (tela **Sistema**): cadastre nome e e-mail. O sistema
  gera uma senha provisória e (com a caixa marcada) manda por e-mail; no
  primeiro login a pessoa cria a própria senha. Na lista: **✉ Enviar acesso
  por e-mail** (senha nova + e-mail num clique), **Desativar**/**Reativar**.
  Ninguém desativa a si mesmo.
- **5 senhas erradas** bloqueiam a pessoa por 15 minutos. Para destravar na
  hora, outra pessoa usa **✉ Enviar acesso por e-mail**.
- Login de 12 horas, ou 30 dias com **Manter conectado**. Trocar a senha (botão
  com o nome, no topo) encerra os logins em outros computadores.
- **Chave de emergência:** o `admin_token` de `config/nfse.local.php` ainda
  entra (link "Entrar com a chave de emergência"). Use para cadastrar a
  primeira pessoa ou se ninguém conseguir entrar; o painel mostra um aviso.
- O **histórico** (da empresa e da tela Sistema) mostra **quem** fez cada
  mudança: o nome da pessoa ou "Chave de emergência".
- O banco guarda só o hash das senhas e o sha256 dos logins.

### Ambiente de emissão (homologação ou produção)

Fica em **Sistema > Ambiente de emissão**. Só quem entra no painel admin vê e
usa esta chave; usuários do portal (nem o responsável da empresa) não podem.

- **Homologação** = testes, sem valor fiscal. **Produção** = notas valem de
  verdade.
- Para ligar produção, digite `PRODUÇÃO` na caixa e clique em **Ligar
  produção**. O painel avisa se há empresas liberadas sem certificado.
- Em produção aparece uma **faixa vermelha** no topo do painel.
- **Voltar para homologação** pede só uma confirmação.
- O que está no painel vale por cima do `tpAmb` do arquivo; o worker passa a
  usar o novo ambiente em até 1 minuto.
- A troca fica no **histórico** com o nome de quem fez.
- **Modelo da nota NFS-e Nacional na produção:** a Receita ainda usa o modelo
  antigo na produção (sem `finNFSe`) e o novo, de 20/10/2026, na homologação.
  Quando ela mudar a produção, as notas de produção voltam com **E1235** pedindo
  o `finNFSe`: clique em **A Receita mudou: usar o modelo novo na produção** e
  use **↻ Reenviar** nas notas recusadas. Dá para voltar pelo mesmo lugar.

**Primeira vez:** entre com a chave de emergência, clique em **Atualizar
banco** (Sistema), cadastre você em **Pessoas do painel**, saia e entre com
o seu e-mail.

### O que a tela mostra

- **Resumo:** total de empresas, liberadas, bloqueadas, vencidas, com limite
  atingido e o total de notas autorizadas no mês.
- **Uma linha por empresa:**
  - plano e situação;
  - notas autorizadas no mês por tipo (NF-e, NFS-e Nacional, NFS-e Salvador);
  - franquia usada, com barra;
  - vencimento e validade do certificado;
  - final do token.
- **Ao clicar numa empresa** (uma página só: o essencial à mostra, o resto em
  blocos que abrem com um clique; o botão **Salvar** fica fixo embaixo):
  - **Pronta para emitir?** (primeiro bloco da ficha, veja abaixo);
  - botão **Ver notas desta empresa**: abre o monitor já filtrado nela,
    com XML, PDF (DANFSe) e o motivo de cada rejeição. Nota **rejeitada** ou
    **com erro** tem o botão **↻ Reenviar** (na linha e no detalhe; também em
    "Minhas notas" do portal): manda de novo os mesmos dados, com o mesmo
    número, e fica no histórico com o nome de quem reenviou;
  - controle de emissão;
  - dados cadastrais e webhook;
  - certificado;
  - token;
  - numeração de cada série (último usado e próxima nota);
  - uso dos últimos 12 meses;
  - documentos do mês por status;
  - histórico de alterações, com data, IP e quem fez.

### Colocar uma empresa nova para emitir

1. **+ Nova empresa:**
   - CNPJ e razão social;
   - UF e IE (NF-e) e/ou inscrição municipal (NFS-e);
   - regime;
   - **Simples Nacional ME/EPP:** preencha também o **"% tributos do
     Simples"** (o contador informa, ex.: 2). Sem ele, a NFS-e Nacional
     da empresa volta `REJEITADO` com o código `SIMPLES_SEM_PERCENTUAL`,
     porque o fisco recusaria a nota (rejeição E0712). Se o sistema do
     cliente mandar `valores.percentual_tributos_simples` na nota, vale o
     da nota.
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
7. **Confira o bloco "Pronta para emitir?"** no alto da ficha (próximo item).

### "Pronta para emitir?": o que falta para a empresa emitir

No alto da ficha da empresa, uma lista confere o cadastro e diz o que falta,
com "Como resolver" em cada item. Ela **não emite nada, não abre o
certificado e não chama a prefeitura**: só olha o que já está gravado.

- 🔴 **Falta:** a nota vai ser recusada enquanto não resolver.
- 🟠 **Atenção:** emite, mas merece cuidado.
- 🟢 **OK:** ficam recolhidos em "Ver os itens ok".

O que é conferido:

| Grupo | Item | Nível quando há problema |
|---|---|---|
| Geral | Emissão liberada (bloqueio, vencimento, limite do mês) | Falta |
| Geral | "Liberado até" vence em 7 dias ou menos | Atenção |
| Geral | Pelo menos um tipo de nota contratado | Falta |
| Geral | Certificado enviado e dentro da validade | Falta (sem ou vencido) / Atenção (30 dias ou menos) |
| Geral | Alguém consegue emitir: token da API ou pessoa ativa no portal | Falta |
| Geral | E-mail da empresa | Atenção |
| NFS-e Nacional | Código IBGE do município com 7 números | Falta |
| NFS-e Nacional | Simples ME/EPP: regime de apuração e % de tributos | Falta |
| NFS-e Salvador | Código do município; inscrição municipal | Falta |
| NFS-e Salvador | Município do cadastro diferente de Salvador (2927408) | Atenção |
| NF-e | UF e inscrição estadual | Falta |
| Todos | Série ainda não usada (a primeira nota sai com o nº 1) | Atenção |

Só aparecem os grupos dos tipos de nota contratados. O topo do bloco diz o
ambiente (homologação ou produção). Corrigiu os dados? Clique em **Salvar
alterações** e a lista é conferida de novo. Ao gerar o token ou dar acesso ao
portal, ela se atualiza sozinha.

O certificado que está no `config/nfse.local.php` (e não no painel) conta como
OK, mas a validade dele não aparece na lista.

Para incluir uma conferência nova: `src/Nfse/Servico/ProntidaoEmissao.php`
(um item no grupo certo) e um teste em `tests/nfse/casos_clientes.php`.

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
| `GET /v1/admin/sistema.php` | Tela Sistema: worker ligado, fila e últimas ordens |
| `POST /v1/admin/sistema.php` | `{"acao": "pausar" \| "retomar" \| "reiniciar"}` |
| `GET /v1/admin/banco.php` | Scripts do banco aplicados e pendentes |
| `POST /v1/admin/banco.php` | `{"acao": "aplicar"}`: roda os pendentes |
| `GET /v1/admin/diagnostico.php[?rede=0]` | Diagnóstico (não altera nada; `rede=0` pula a internet) |
| `GET /v1/admin/configuracoes.php` | Configurações editáveis, valor atual e padrão do arquivo |
| `POST /v1/admin/configuracoes.php` | `{"valores": {"portal.tentativas_login": 5, ...}}` |
| `GET /v1/admin/fechamento.php?mes=AAAA-MM[&formato=csv]` | Fechamento do mês: receita por empresa, DAS estimado e guia anexada |
| `POST /v1/admin/guia_das.php` | Anexar ou trocar a guia (multipart: `cnpj`, `competencia`, `valor`, `vencimento`, `linha_digitavel`, `avisar`, `arquivo`) ou JSON `{"acao": "remover" \| "pagar" \| "desfazer_pagamento", "id"}` |
| `GET /v1/admin/guia_das.php?id=` | PDF da guia |
| `GET /v1/admin/serpro_das.php?cnpj=&competencia=` | Conferência da declaração do Simples: receita do mês (com/sem retenção de ISS, mercadorias), atividade e folha sugeridas |
| `POST /v1/admin/serpro_das.php` | `{"acao": "calcular", ...}` (não envia), `{"acao": "transmitir", ..., "conferidos": [...], "avisar": true}` (declara, gera e anexa o DAS) ou `{"acao": "testar"}` (guia de exemplo, só no teste) |
| `GET /v1/admin/serpro_das.php?id=&pdf=recibo\|declaracao` | PDFs guardados da declaração |
| `GET/POST /v1/admin/serpro_acesso.php` | Acesso de produção ao SERPRO: situação, `{"acao": "chaves"}`, certificado (multipart), `{"acao": "testar_login"}`, `{"acao": "apagar"}` |

### Fechamento do mês e guia DAS do Simples

Botão **Fechamento do mês** no topo do painel. Serve para o contador fazer a
declaração do Simples (PGDAS-D) e entregar a guia DAS ao cliente pelo portal.
Passo a passo com telas: Central de Ajuda › *Fechamento do mês e guia DAS*.

- **Receita do mês:** NFS-e autorizadas pela **competência** + NF-e
  autorizadas pela **data de autorização**, no ambiente atual. Canceladas não
  entram (aparecem na coluna "Canceladas"). NF-e de entrada e de devolução
  não entram. **12 meses anteriores** = receita dos 12 meses antes do mês.
- **DAS estimado** = receita × "% Simples" do cadastro, só para ME/EPP. É
  referência: o valor certo é o do PGDAS-D (faixa, anexo, fator R e receitas
  fora do sistema). Regras em `src/Nfse/Servico/FechamentoMes.php`.
- **Guia DAS** (script `0015`): o contador gera o DAS no site do Simples e
  clica em **Anexar guia** (PDF, valor, vencimento e os 48 números do código de
  barras). Uma guia por empresa e mês; **Trocar** substitui. Com "Avisar o
  cliente", todas as pessoas ativas da empresa no portal recebem e-mail (fila
  do worker). O cliente vê em **Guias do Simples**.
- **Pagamento** (script `0016`): **Marcar paga** (painel) ou **Já paguei**
  (portal) grava a data e quem marcou; **Desfazer** volta. O sistema **não
  confere o pagamento na Receita**. Trocar a guia apaga a marcação.
- **Declarar e gerar DAS pela Receita (SERPRO Integra Contador, script
  `0017`):** em **Sistema > Configurações**, "Gerar DAS pela Receita" =
  **Teste** ou **Produção** mostra, na linha de cada ME/EPP do Fechamento,
  **Testar Receita** (teste) ou **Declarar e gerar DAS** (produção). Fluxo
  (`src/Nfse/Servico/DeclaracaoSimples.php`):
  1. **Conferir:** receita do mês das notas, separada em serviços sem e com
     retenção de ISS e mercadorias (`ClienteRepositorio::receitaSimples`);
     atividade (tabela `SERVICOS`/`MERCADORIAS`, códigos `idAtividade` do
     PGDASD) e folha de salários dos 12 meses anteriores (obrigatória para
     PA >= 202611) vêm da última declaração.
  2. **Calcular:** `TRANSDECLARACAO11` com `indicadorTransmissao=false`:
     a Receita devolve os valores, nada é gravado.
  3. **Transmitir:** `indicadorTransmissao=true` + `valoresParaComparacao`
     com o que o contador viu (diferença de 1 centavo = recusa). Guarda em
     `fiscal_declaracoes_simples` (entrada, valores, número, recibo e
     declaração em PDF), pede o DAS (`GERARDAS12`) e anexa a guia como o
     "Anexar guia" (portal + e-mail).
  - **Teste:** ambiente de demonstração gratuito
    (`IntegraContador::URL_TESTE`). Só aceita os pedidos de exemplo da
    documentação: a declaração vai como `DADOS_DECLARACAO_TESTE` e o DAS com
    contribuinte `00000000000100`, período `201801`. Respostas de exemplo;
    a guia **não** é anexada. Tem limite de chamadas ("quota").
  - **Produção:** login OAuth2 com certificado (mTLS) em
    `https://autenticacao.sapi.serpro.gov.br/authenticate` (Basic
    key:secret, `role-type: TERCEIROS`, `grant_type=client_credentials`),
    depois `Bearer access_token` + `jwt_token`. O escritório (CNPJ do
    certificado) é contratante e autor; o cliente é o contribuinte e precisa
    dar **procuração eletrônica** no e-CAC para o escritório. Consumer
    Key/Secret e certificado são cadastrados em **Sistema > Integração com a
    Receita (SERPRO): acesso de produção** e ficam criptografados em
    `sistema_ajustes` (`src/Nfse/Serpro/AcessoSerpro.php`). Produção só
    declara mês já terminado.
  - Histórico: `SERPRO_DECLARACAO[_TESTE]`, `SERPRO_DAS[_TESTE]`.

Assim seu sistema de cobrança consegue bloquear e liberar sozinho. Por
exemplo, ao detectar fatura em atraso:

```bash
curl -X POST https://SEU_DOMINIO/v1/admin/clientes.php -H "X-ADMIN-TOKEN: <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"cnpj":"11222333000181","emissao_liberada":false,"motivo_bloqueio":"Fatura de outubro em aberto"}'
```

---

## 13. Como rodar cada front

O projeto tem **três fronts**. Todos são páginas HTML/CSS/JS puras:
- **sem build**: não tem `npm install`, `npm run build` nem Node;
- **sem servidor próprio**: o mesmo nginx/Apache que serve a API entrega as
  páginas.

Para "rodar" um front, basta o servidor estar no ar (seções 2 e 4) e abrir
o endereço no navegador.

| Front | Endereço | Arquivos | Senha pedida na tela | Para quem |
|---|---|---|---|---|
| **Painel admin** | `https://SEU_DOMINIO/admin/` | `public/admin/` (`index.html`, `admin.css`, `admin.js`) | `admin_token` do `config/nfse.local.php` | Você: empresas, certificados, tokens, bloqueios, numeração e cobrança |
| **Portal do cliente** | `https://SEU_DOMINIO/portal/` | `public/portal/` (`index.html`, `portal.css`, `portal.js`) | E-mail e senha da pessoa (criados no painel admin) | A empresa cliente: emite a própria NFS-e num formulário |
| **Monitor** | `https://SEU_DOMINIO/monitor/` | `public/monitor/` (`index.html`, `monitor.css`, `monitor.js`) | o login do **painel admin** (botão "Notas (monitor)", sem outro token) **ou** `monitor_token` (vê **todas** as notas) **ou** o token de uma empresa cliente (vê **só as notas dela**) | Você e a equipe de suporte; e também o próprio cliente, se quiser acompanhar as notas dele |

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
- o monitor usa `../v1/nfse/`;
- o portal usa `../v1/portal/`.

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

O painel e o monitor mostram dados de todas as empresas. Além do token,
limite por IP ou VPN. **O portal do cliente fica aberto** (é usado pelas
empresas de qualquer lugar): não coloque `/portal/` nem `/v1/portal/` nessa
lista. No nginx, dentro do `server { ... }`:

```nginx
location ^~ /admin/      { allow 200.100.50.0/24; deny all; }
location ^~ /v1/admin/   { allow 200.100.50.0/24; deny all; include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location ^~ /monitor/    { allow 200.100.50.0/24; deny all; }
```

Troque pelo IP ou faixa do seu escritório ou VPN e rode
`sudo nginx -t && sudo systemctl reload nginx`.

### Portal do cliente

A empresa cliente entra com **e-mail e senha** e emite a NFS-e num
formulário de 3 blocos (tomador, serviço, valor). O portal **não tem
emissão própria**: monta o mesmo JSON de `/v1/nfse/emitir.php` e usa a mesma
fila. Bloqueios, franquia, cobrança, monitor e webhooks valem igual.

**Instalar:** aplique o script `database/scripts/0006_portal_criar_usuarios.sql`
(o `deploy/aplicar_banco.sh` já aplica todos). Sem ele, as rotas respondem
`PORTAL_NAO_INSTALADO`.

**Dar acesso a uma pessoa:**
1. No painel admin, abra a empresa e vá até **Portal do cliente**.
2. Informe nome e e-mail e clique em **Dar acesso ao portal**.
3. A caixa **Enviar os dados de acesso por e-mail** já vem marcada: o sistema manda
   tudo sozinho. Para quem já tem acesso, use **✉ Enviar acesso por e-mail** na linha
   da pessoa (gera senha provisória nova e envia num clique).
   Ou clique em **Enviar por e-mail** (o sistema manda na hora o endereço do portal,
   o e-mail e a **senha provisória**) ou em **Copiar dados de acesso** para mandar
   você mesmo (WhatsApp, etc.). A senha aparece uma vez só; se perder, use **Nova senha**.
   O envio por e-mail precisa do e-mail configurado em **Sistema > E-mail**; sem isso
   a tela avisa e oferece o botão **Configurar e-mail agora**. O envio fica no
   histórico da empresa.
4. No primeiro acesso o portal pede para ela criar a própria senha
   (mínimo 8 caracteres, com letras e números).

**Esqueceu a senha ou bloqueou:** depois de 5 senhas erradas seguidas o
acesso fica bloqueado por 15 minutos. No painel, **Nova senha** gera outra
senha provisória e libera na hora. **Desativar** tira o acesso e encerra as
sessões abertas.

**O que é automático no formulário:**
- dados da empresa (prestador), padrão (Nacional ou Salvador) e numeração;
- tomador pelo CNPJ (consulta pública da Receita via BrasilAPI) e endereço
  pelo CEP (ViaCEP, com o código IBGE). A consulta é feita pelo servidor e
  guardada por 30 dias (CNPJ) e 180 dias (CEP) em `portal_consultas`. Se a
  consulta estiver fora do ar, a pessoa preenche à mão;
- tomadores ficam salvos na primeira nota (`portal_tomadores`);
- serviços salvos com códigos e alíquota (`portal_servicos`): escolhidos numa
  lista. Um serviço novo pode ser salvo na própria emissão;
- clique duplo não gera nota em dobro (referência `PORTAL-<id do envio>`).

**Outras telas do portal:**
- **Minhas notas:** todas as NFS-e da empresa (do portal ou da API), 20 por
  página. Filtros por situação (autorizadas, na prefeitura, com problema,
  canceladas), por mês e busca por tomador, CPF/CNPJ ou número. Clicar na
  nota mostra o detalhe: o motivo da prefeitura e como corrigir, valores,
  PDF e XML. Enquanto houver nota na prefeitura, a lista se atualiza sozinha.
- **Serviços:** cadastrar, editar e remover os serviços salvos. "Usar na
  nota" abre a emissão com o serviço já escolhido. Remover só esconde da
  lista: as notas emitidas não mudam.
- **Tomadores:** lista com busca, editar o cadastro (o CPF/CNPJ não muda),
  cadastrar um novo (com CNPJ e CEP preenchidos sozinhos) e remover.
  "Emitir nota" abre a emissão com o tomador preenchido. Editar não muda a
  ordem da lista (os mais usados ficam em cima).
- **Minha empresa:** só para ver. Mostra o cadastro, os dados fiscais (em
  texto: Simples, regime especial, tipos de nota contratados), o certificado
  (titular e validade) e o plano (limite do mês, uso e se a emissão está
  liberada). Para mudar algo, o suporte altera no painel admin. Nunca mostra
  token, certificado, segredo do webhook ou a observação interna.
- **Usuários:** todos veem quem da empresa tem acesso. Cada pessoa é
  **administrador** ou **emissor**. Só administradores convidam (a senha
  provisória aparece uma vez, numa mensagem pronta para copiar), geram nova
  senha, desativam e escolhem quem também é administrador. Ninguém mexe no
  próprio acesso, então a empresa nunca fica sem administrador ativo. Cada um
  troca a própria senha em "Minha senha". O primeiro usuário criado no painel
  para uma empresa sem administrador ativo vira administrador sozinho; no
  painel também há o botão "Tornar administrador" (script `0010`).
- **Recorrências** (script `0011`): a mensalidade de um cliente cadastrada
  uma vez (tomador, serviço, valor, dia do mês até 28, mês de competência
  atual ou anterior, último mês opcional). `{mes}` na descrição vira o mês
  da nota. Cada uma escolhe o modo:
  - **Me avisar para confirmar** (padrão): no dia, aparece "Esperando você"
    no menu e na tela inicial; a nota só sai no clique "Emitir agora" (ou
    "Pular mês").
  - **Emitir sozinha:** o worker emite no dia. Se der erro (empresa
    bloqueada, limite do plano), tenta uma vez por dia e o motivo aparece
    no cartão, que fica esperando o cliente.
  Cada mês tem a referência `PORTAL-rec-<id>-<AAAAMM>`, então a mesma nota
  nunca sai duas vezes. Pausar e retomar não gera notas atrasadas: segue do
  próximo dia marcado. Apagar não mexe nas notas já emitidas.
- **Guias do Simples** (scripts `0015` e `0016`): as guias DAS que a
  contabilidade anexou no Fechamento do mês, mais nova primeiro. Situação
  (a vencer, vence hoje, venceu, paga), **Abrir guia** (PDF), **Copiar código**
  (código de barras) e **Já paguei** / **Desfazer**. Rota `/v1/portal/guias.php`;
  só mostra guias da empresa de quem está logado.
- **Cancelar nota** (botão no detalhe de uma nota autorizada, em "Minhas
  notas"): qualquer usuário da empresa pode cancelar. Pede o motivo (erro na
  emissão, serviço não prestado ou outro), uma explicação de 15 a 255 letras
  e a marcação "Entendi que não pode ser desfeito". O pedido guarda quem fez.
  - **Padrão Nacional:** o pedido vai para a fila e o worker envia ao fisco.
    A nota continua "Autorizada" até a confirmação; se o fisco recusar (fora
    do prazo, por exemplo), o motivo aparece no detalhe e dá para tentar de
    novo.
  - **Salvador:** não cancela por webservice. A tela explica como pedir no
    site da prefeitura; depois de aceito, o cliente informa o protocolo e a
    nota vira "Cancelada" na hora (nada é enviado).
- **Esqueci a senha** (script `0012`): no login, a pessoa digita o e-mail e
  recebe um link (vale 1 hora, uma vez; no máximo 3 pedidos por hora). A
  resposta é sempre a mesma, exista ou não o e-mail. Criada a senha nova,
  as sessões abertas em outros navegadores saem. Quem envia o e-mail é o
  **worker**. Para ligar:
  1. No painel, **Sistema > E-mail**: servidor, porta, criptografia,
     usuário, **senha**, remetente e o **endereço do portal** (vai no link do
     e-mail). Clique em **Salvar e-mail**. A senha fica guardada
     criptografada no banco (mesma chave dos certificados, `chave_certificados`)
     e nunca volta para a tela; para trocar, digite outra.
  2. Opcional (servidor): a senha também pode ir no **`.env`** da raiz
     (`SMTP_PASSWORD`, modelo em `.env.example`). A senha salva no painel
     vale mais.
  3. No bloco **Sistema > E-mail**, use "Enviar e-mail de teste". Ali
     também aparecem os últimos e-mails e o erro do servidor, se falhar
     (tenta de novo em 1, 2, 4 e 8 minutos e desiste na 5ª vez).
  Enquanto faltar algo, o link "Esqueci minha senha" mostra "peça ao
  administrador da sua empresa" (o administrador gera senha provisória em
  Usuários).

**Configuração** (`portal` no `config/nfse.php`, troque no
`config/nfse.local.php`):

| Chave | Padrão | Para que serve |
|---|---|---|
| `sessao_minutos` | `720` | Duração da sessão sem "Manter conectado" (12 h) |
| `sessao_lembrar_dias` | `30` | Duração com "Manter conectado" |
| `tentativas_login` | `5` | Senhas erradas seguidas antes do bloqueio |
| `bloqueio_minutos` | `15` | Tempo de bloqueio |
| `padrao` | vazio | Vazio: Nacional (ou Salvador, se for o único contratado). `NACIONAL`/`SALVADOR` força para todas |
| `url_cnpj`, `url_cep` | BrasilAPI e ViaCEP | Endereços das consultas públicas |

**Segurança da sessão:** cookie `HttpOnly` e `SameSite=Strict` (e `Secure`
no HTTPS). O banco guarda só o sha256 do cookie e o hash da senha. Toda
chamada que altera algo exige o cabeçalho `X-Portal: 1` (o próprio portal
envia), o que impede outro site de emitir usando a sessão de alguém.

**Rotas** (`public/v1/portal/`): `entrar`, `sair`, `sessao`, `senha`,
`inicio`, `notas`, `servicos`, `tomadores`, `empresa`, `usuarios`, `consulta`, `emitir` e `arquivo` (PDF/XML).
No painel: `public/v1/admin/portal_usuarios.php`.

### Alterar o visual ou os textos

- **Cores:** ficam no bloco `:root` do `public/monitor/monitor.css`, que vale
  para os dois fronts. O que é só do painel está em `public/admin/admin.css`.
- **Portal do cliente:** tem cores próprias no `:root` do
  `public/portal/portal.css` (azul-marinho e cobre, com tema escuro).
  O `portal-demo.js` é só para a prévia sem servidor: o `index.html` não o
  carrega.
- **Textos e telas:** `public/admin/admin.js`, `public/monitor/monitor.js` e
  `public/portal/portal.js`,
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

Abra `http://127.0.0.1:8080/admin/`, `/monitor/` ou `/portal/`. Isso serve só para
conferir a tela: o tempo real e os envios ao fisco precisam do worker e da
configuração completa do servidor.

### Central de Ajuda e ajuda guiada

- **Central de Ajuda** (`public/ajuda/`): artigos passo a passo com prints,
  em HTML puro. Lista dos artigos em `public/ajuda/index.html`; modelo e
  regras de escrita no comentário de `public/ajuda/portal/emitir-nfse.html`.
  Prints em `public/ajuda/img/`.
- **Ajuda guiada** (no estilo do Bootstrap Tour, JavaScript puro): a pessoa
  abre a **Ajuda** (menu **Ajuda** do portal, botão **Ajuda** do painel),
  escolhe uma tarefa e o guia leva **campo a campo na tela de verdade**:
  destaca o campo, explica, espera preencher e só libera o **Próximo** quando
  o campo está certo. No fim a tarefa está feita (nota emitida, tomador salvo,
  guia anexada...). Não abre sozinho.
  - Motor: `public/ajuda/tour.js` + `tour.css` (`Tour.iniciar`,
    `Tour.encerrar`, `Tour.ativo`). O formato de cada passo está no
    comentário do topo de `tour.js`: `alvo`, `titulo`, `texto`, `acao`
    (`ler`, `preencher`, `clicar`, `esperar`), `pronto`, `falta`, `opcional`,
    `pular`, `antes`, `pagina`, `aguarde`.
  - Guias do portal: `public/portal/portal-guias.js` (emitir, tomador,
    serviço, recorrência, cancelar, guia do Simples, convidar, senha,
    conhecer). A lista da página **Ajuda** é montada a partir dele; o botão
    **Me guie** da tela Emitir nota abre o guia de emitir.
  - Guias do painel: `public/admin/admin-guias.js` (cadastrar empresa,
    certificado, acesso ao portal, anexar guia DAS, conhecer o painel). Os
    campos usados têm `id` fixo em `admin.js` (`cert-arquivo`, `acesso-nome`,
    `guia-valor`, `botao-salvar-empresa`...).
  - **Guia novo**: acrescente um item em `GUIAS` no arquivo do portal ou do
    painel (`titulo`, `resumo`, `icone`, `passos`). Ele aparece sozinho na
    lista. Mudou o `id` de um campo? Ajuste o seletor do passo.
  - Janelas (`<dialog>`): o guia entra na janela aberta para continuar
    clicável. Fora do destaque a tela fica bloqueada; **Esc** ou **✕** saem.

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
