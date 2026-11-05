# NFS-e — Arquitetura (Padrão Nacional e Salvador-BA)

Este documento explica **como a emissão de NFS-e funciona por dentro**: o
caminho de um lote desde a chamada da API até a nota autorizada, a fila, o
worker, os eventos e o monitor em tempo real.

Leitura complementar:

- `docs/nfse-guia-api.md` — contrato da API, com exemplos para quem integra.
- `docs/nfse-duvidas-processo.md` — decisões de processo que precisam da sua definição.
- `schemas/nfse/README.md` — XSDs oficiais e o ajuste local feito neles.

---

## 1. Visão geral em uma frase

> A API **só recebe e guarda**; um **worker** em segundo plano fala com o
> fisco; cada mudança vira uma linha no **feed do monitor** que o painel
> recebe na hora (SSE) e o sistema consumidor recebe por **webhook**.

```mermaid
flowchart LR
    C[Sistema consumidor<br/>ERP, FuraFila...] -- "POST /v1/nfse/emitir.php<br/>(lote)" --> API
    subgraph Servico fiscal NfePHP
        API[API PHP<br/>public/v1/nfse] -- "1 transacao:<br/>documentos + jobs + monitor" --> DB[(PostgreSQL)]
        W[Worker<br/>bin/nfse_worker.php] -- "pega job<br/>SKIP LOCKED" --> DB
        W -- "atualiza status,<br/>monitor, webhook" --> DB
        SSE[monitor.php<br/>Server-Sent Events] -- "LISTEN nfse_monitor" --> DB
    end
    W -- "REST + mTLS" --> SEFIN[Sefin Nacional / ADN]
    W -- "SOAP + mTLS" --> SSA[Webservice NFS-e Salvador]
    W -- "POST assinado HMAC" --> C
    SSE -- "eventos em tempo real" --> P[Painel Monitor Fiscal]
```

---

## 2. Por que essa estrutura (fila + worker + SSE + webhook)

Você pediu para avaliar worker, fila, webhook, WebSocket e SSE. A escolha foi
**usar cada um onde ele é melhor**, sem adicionar infraestrutura nova.

| Peça | Escolha | Por quê |
|---|---|---|
| **Fila** | Tabela `nfse_jobs` no PostgreSQL com `FOR UPDATE SKIP LOCKED` | O Postgres já é obrigatório. O job é gravado **na mesma transação** do documento: nunca existe nota sem job nem job sem nota. Vários workers podem rodar em paralelo sem pegar o mesmo job. Volume de NFS-e (dezenas a milhares por dia) está muito abaixo do limite desse modelo. RabbitMQ/Redis só trariam mais um serviço para operar. |
| **Worker** | Processo CLI contínuo (`bin/nfse_worker.php`) gerenciado por systemd/supervisor | Salvador é **assíncrono** (lote → protocolo → consultar depois); o Nacional pode demorar ou cair. Nada disso deve prender a requisição HTTP do consumidor. O worker também faz retentativa com espera exponencial e recupera jobs de workers que morreram. |
| **Acordar o worker** | `LISTEN/NOTIFY` do PostgreSQL | O worker dorme até chegar um aviso de job novo (ou 5 s, para jobs agendados). Reação imediata sem ficar consultando o banco em loop. |
| **Painel em tempo real** | **SSE** (`/v1/nfse/monitor.php`) alimentado por `LISTEN nfse_monitor` | O painel só **recebe** (servidor → navegador). SSE é HTTP comum: passa por proxy/firewall, o navegador **reconecta sozinho** e manda o `Last-Event-ID`, e retomamos exatamente de onde parou (o feed é uma tabela com id crescente — nada se perde). WebSocket seria bidirecional, exige servidor próprio em PHP (Ratchet/Swoole) e não traz ganho aqui. |
| **Avisar o sistema consumidor** | **Webhook** com padrão *outbox* (`nfse_webhooks_saida`) | Sistemas externos não ficam com navegador aberto; webhook é o padrão de mercado. O aviso é gravado no banco junto com a mudança de status e entregue depois, com até 10 tentativas e assinatura HMAC. Se o webhook falhar, o consumidor ainda pode consultar a API. |
| **Quem não quer webhook** | `?aguardar=N` e `GET lote.php` | Integrações simples podem pedir para a API esperar até N segundos (máx. 60) e/ou consultar o lote depois (polling). |

### Por que não WebSocket?
- O painel não envia nada pelo canal em tempo real (filtros e ações usam a API REST normal).
- Em PHP, WebSocket exige um servidor de longa duração separado; SSE roda no PHP-FPM/nginx que já existe.
- Em Node/TypeScript (migração futura), SSE é ~20 linhas com `res.write()`; se um dia precisar de WebSocket, a fonte de dados (`nfse_monitor` + `NOTIFY`) continua a mesma.

### Limite conhecido do SSE em PHP
Cada painel aberto ocupa **um processo PHP-FPM** enquanto conectado. Por isso
a conexão é encerrada pelo servidor a cada ~5 minutos (o navegador reconecta
sem perder eventos). Para poucos painéis (equipe fiscal) isso é ok. Se forem
dezenas de telas simultâneas, reserve um *pool* PHP-FPM só para o monitor ou
mova apenas esse endpoint para Node primeiro.

---

## 3. Fluxo de emissão em lote

```mermaid
sequenceDiagram
    autonumber
    participant C as Consumidor
    participant API as API (emitir.php)
    participant DB as PostgreSQL
    participant W as Worker
    participant F as Fisco
    C->>API: POST lote (N documentos)
    API->>DB: BEGIN; cria lote
    loop cada documento
        alt nao da para gravar (padrao/emitente invalido)
            API->>API: vai para "recusados"
        else dados invalidos (ValidadorPayload)
            API->>DB: documento REJEITADO (sem numero) + monitor com motivo e correcao
        else valido
            API->>DB: reserva numero DPS/RPS; documento RECEBIDO; job EMITIR; monitor
        end
    end
    API->>DB: COMMIT
    DB-->>W: NOTIFY nfse_jobs
    API-->>C: 202 + situacao de cada documento
    loop para cada job
        W->>DB: pega job (SKIP LOCKED) e marca PROCESSANDO
        W->>F: envia DPS (Nacional) ou lote RPS (Salvador)
        F-->>W: resposta
        W->>DB: status + XML + mensagens; monitor (NOTIFY); webhook (outbox); job ARQUIVAR
    end
```

Pontos importantes:

1. **Documento inválido não derruba o lote** (decisão B1). Ele é gravado
   como `REJEITADO`, **sem consumir número** de DPS/RPS, e aparece no monitor
   com o motivo e a sugestão de correção (`campo` + `correcao`). Corrigido e
   reenviado com a mesma referência, recebe número e segue o fluxo normal.
   Só fica de fora (lista `recusados`) o que nem dá para gravar: padrão
   desconhecido, emitente não liberado, referência repetida no lote.
2. **Cada documento é independente depois de aceito.** No Sefin Nacional não
   existe lote (uma DPS por chamada). Em Salvador enviamos **um RPS por
   lote**, porque no ABRASF um erro em um RPS derruba o lote inteiro.
3. **A numeração da DPS/RPS é reservada na entrada**, dentro da transação
   (`nfse_numeracao`). Se a transação falhar, o número volta. Uma vez
   reservado, o número fica com o documento para sempre — inclusive nas
   retentativas — e é isso que impede nota duplicada.

### 3.1 Padrão Nacional (síncrono)

```
EMITIR ──► monta DPS ► assina ► valida XSD ► POST /nfse
              │
              ├─ 201 + chaveAcesso ............ AUTORIZADO (guarda XML da NFS-e)
              ├─ 400 + erros .................. REJEITADO (mensagens do Sefin)
              └─ timeout / 5xx / 429 / 401-403  falha temporária → nova tentativa
                                                (30s, 60s, 120s... até 5x) → ERRO
```

**Proteção contra duplicidade:** se uma tentativa anterior chegou a enviar a
DPS (timeout no meio do caminho), a próxima tentativa **primeiro pergunta**
`GET /dps/{idDps}`. Se a DPS já virou nota, só baixa a NFS-e. Nunca
reenviamos às cegas.

### 3.2 Salvador (assíncrono)

```
EMITIR ──► monta lote (1 RPS) ► assina RPS ► assina lote ► valida XSD ► EnviarLoteRPS
              │
              ├─ Protocolo ................ PROCESSANDO → agenda CONSULTAR_PROCESSAMENTO (15s)
              └─ ListaMensagemRetorno ..... REJEITADO

CONSULTAR_PROCESSAMENTO ──► ConsultarSituacaoLoteRPS
              ├─ 1/2 (não recebido / não processado) ... continua PROCESSANDO (consulta a cada 30s, até 20x)
              ├─ 3 (processado com erro) ► ConsultarLoteRPS ... REJEITADO (mensagens)
              └─ 4 (processado com sucesso) ► ConsultarLoteRPS ... AUTORIZADO (número + código de verificação)
```

Se o envio caiu **antes** de receber o protocolo, a próxima tentativa faz
`ConsultarNfseRPS` antes de reenviar.

### 3.3 NF-e modelo 55 (SEFAZ, síncrono)

A NF-e é só mais um padrão (`NFE`) na mesma estrutura: mesma fila, worker,
status, monitor, webhooks, multiempresa e idempotência. O provedor é
`src/Nfse/Nfe/ProvedorNfe.php`, que usa a biblioteca `sped-nfe` (a mesma das
rotas antigas). O JSON de entrada é o mesmo da rota antiga; a montagem do XML
(`src/Nfse/Nfe/NfeXmlBuilder.php`) é cópia fiel de
`public/emitir_json_pl010.php` — os testes provam que gera o **mesmo XML e a
mesma chave**.

```
EMITIR ──► monta (nNF/serie/tpAmb pela API) ► assina ► valida XSD
         ► grava XML assinado + chave ► sefazEnviaLote (síncrono)
              │
              ├─ 100/150 ............... AUTORIZADO (nfeProc + protocolo)
              ├─ 110/205/301/302/303 ... DENEGADO (final, sem reenvio)
              ├─ 103/105 ............... PROCESSANDO → consulta o recibo a cada 5s
              ├─ outra rejeição ........ REJEITADO
              └─ timeout / 108 / 109 ... falha temporária → nova tentativa
```

**Proteção contra duplicidade:** como o XML assinado e a chave ficam gravados
**antes** do envio, toda nova tentativa começa com `sefazConsultaChave`. Se a
nota já existe, só monta o `nfeProc`; se o SEFAZ responde 217 (não consta),
reenvia **o mesmo XML** (mesma chave). Nunca sai uma segunda nota com outro
número.

Eventos: cancelamento `110111` (usa `protocolo_autorizacao`) e CC-e `110110`
(várias por nota, `sequencia` 1..20 controlada pela API). PDF: DANFE oficial
(`sped-da`). Inutilização continua na rota antiga.

> Os nomes `nfse_*` (tabelas, rotas `/v1/nfse/`, worker `nfse-worker`) ficaram
> por histórico: hoje eles guardam NFS-e **e** NF-e. Renomear é possível, mas
> mexe em implantação e integrações — ver dúvida D3.

---

## 4. Status padronizado

O consumidor só conhece estes status, iguais para todos os padrões
(`src/Nfse/Dominio/StatusNfse.php`):

```mermaid
stateDiagram-v2
    [*] --> RECEBIDO: API aceitou
    RECEBIDO --> PROCESSANDO: worker enviou
    PROCESSANDO --> AUTORIZADO
    PROCESSANDO --> REJEITADO: erro nos dados
    PROCESSANDO --> ERRO: falha técnica após todas as tentativas
    REJEITADO --> RECEBIDO: reenvio com a mesma referencia
    ERRO --> RECEBIDO: reenvio com a mesma referencia
    ERRO --> AUTORIZADO: "consultar" achou a nota
    AUTORIZADO --> CANCELADO: evento 101101
    AUTORIZADO --> SUBSTITUIDO: nova NFS-e substituta (Nacional)
    PROCESSANDO --> DENEGADO: só NF-e (SEFAZ 110/205/301/302/303)
```

| Status | Significado para o consumidor | Pode reenviar? |
|---|---|---|
| `RECEBIDO` | Na fila. | — |
| `PROCESSANDO` | Enviado ao fisco, aguardando. | Não |
| `AUTORIZADO` | NFS-e gerada; XML e PDF disponíveis. | Não (idempotente) |
| `REJEITADO` | Fisco recusou os dados. Corrija e reenvie com a **mesma referência**. | Sim |
| `ERRO` | Falha técnica persistente. **Use "consultar" antes de reenviar.** | Sim |
| `CANCELADO` | Cancelada por evento. | Não |
| `SUBSTITUIDO` | Substituída por outra NFS-e (Nacional). | Não |
| `DENEGADO` | Só NF-e: denegada pelo SEFAZ; número usado, nota sem valor. | Não |

**Idempotência por `referencia`:** o mesmo consumidor enviando a mesma
`referencia` (ex.: número do pedido) nunca gera duas notas. Se o documento
existente estiver `REJEITADO`/`ERRO`, os dados novos são aceitos e o mesmo
número de DPS/RPS é reaproveitado.

---

## 5. Eventos (cancelamento etc.)

Os eventos usam a **numeração do Padrão Nacional** para todos os padrões,
assim o consumidor tem um contrato só. O que cada padrão aceita está em
`src/Nfse/Dominio/Capacidades.php` e é exposto em `GET /v1/nfse/capacidades.php`.

| Evento | Nacional | Salvador |
|---|---|---|
| 101101 Cancelamento | ✅ | ❌ pelo webservice · ✅ **registro manual** do cancelamento feito no portal (`cancelamento_externo.php`) |
| 101103 Solicitação de análise fiscal p/ cancelamento (fora do prazo) | ✅ | ❌ |
| 105102 Cancelamento por substituição | ✅ automático ao emitir com `substituicao` | ❌ |
| Consulta de situação | ✅ (NFS-e + eventos) | ✅ (lote / RPS) |
| PDF | DANFSe oficial (ADN) | Espelho gerado pela API + link do portal |
| Confirmação/rejeição (202201, 202205...) | previsto, não implementado | ❌ |
| Eventos do fisco (105104, 305101...) | detectados na consulta | — |

Fluxo: `POST /v1/nfse/evento.php` → validações (padrão suporta? nota
autorizada? motivo 15–255 caracteres?) → `nfse_eventos` + job `EVENTO` →
worker assina e envia → `REGISTRADO` (documento vira `CANCELADO`) ou
`REJEITADO`. Pedidos repetidos devolvem o mesmo evento.

Em Salvador a API **recusa na entrada** (HTTP 422, código
`NAO_SUPORTADO_PELO_PADRAO`) com a orientação da prefeitura, sem chamar o
webservice. Depois que a prefeitura cancelar pelo portal, o sistema
registra em `POST /v1/nfse/cancelamento_externo.php` (número do processo
obrigatório): a nota vira `CANCELADO`, com evento `origem = EXTERNO`,
monitor, webhook e arquivamento — sem chamar a prefeitura.

**Substituição (Nacional):** ao autorizar uma NFS-e com o grupo
`substituicao`, o worker agenda (1 minuto depois) a consulta da nota
substituída, que passa a `SUBSTITUIDO` quando o Sefin registrar o evento 105102.

---

## 6. Monitor fiscal

### Fonte de dados
Tabela `nfse_monitor` (só cresce). Cada linha: documento, lote, consumidor,
padrão, referência, **operação** (`RECEBIMENTO`, `EMISSAO`, `CONSULTA`,
`EVENTO`), status, mensagem, número/chave. Toda gravação dispara
`pg_notify('nfse_monitor', id)`.

### Entrega ao painel
1. **Carga inicial:** `GET /v1/nfse/documentos.php` (estado atual de cada nota).
2. **Tempo real:** `EventSource('/v1/nfse/monitor.php?token=...')` — cada
   evento `nfse` traz a linha do monitor; o painel atualiza a nota pelo
   `documento_id`.
3. **Linha do tempo de uma nota:** `GET /v1/nfse/documento.php?id=...&historico=1`.

Assim o painel mostra cada nota **quando é recebida, enviada, autorizada,
rejeitada, consultada ou cancelada**, inclusive as falhas temporárias
("tentativa 2/5, nova tentativa em 60s").

**Página pronta:** `public/monitor/` (acesse `/monitor/`). Lista em tempo
real, contadores, filtros (status, padrão, emitente, busca), dica ao passar
o mouse nas rejeitadas e modal com motivo + "Como corrigir", eventos e linha
do tempo. Detalhes em `docs/nfse-monitor.md`.

---

## 6.1 Multiempresa

- `config/nfse.local.php → emitentes`: um bloco por CNPJ (inscrição
  municipal, regime, **certificado próprio**). Sem esse bloco, vale a empresa
  única do `config/nfe.php` (compatível com a primeira versão).
- `consumidores.<sistema>.cnpjs`: CNPJs que cada sistema pode usar.
  Com mais de uma empresa configurada, a lista é **obrigatória**.
- O corpo da requisição escolhe o CNPJ (`emitente_cnpj`); a API confere a
  permissão. Inscrição, regime e certificado sempre vêm da configuração.
- `ProvedorFactory::para($padrao, $cnpj)` cria um provedor por padrão +
  CNPJ, cada um com seu certificado (mTLS e assinatura).
- Numeração DPS/RPS, documentos e monitor já são separados por CNPJ.

## 6.2 Arquivamento dos XMLs (nuvem)

- Guarda principal: PostgreSQL. Cópia externa configurável em
  `armazenamento.tipo`: `nenhum`, `local` (pasta) ou `s3` (AWS S3 e
  compatíveis: MinIO, Wasabi, R2, Spaces...). Decisão C6: banco + nuvem.
- Quando a nota chega a `AUTORIZADO`/`CANCELADO`/`SUBSTITUIDO` (ou um evento
  é registrado), o worker cria um job `ARQUIVAR`, que grava
  `{ambiente}/{cnpj}/{AAAA}/{MM}/{documento_id}/` com `envio.xml`,
  `nfse.xml` e os XMLs de eventos, e preenche `arquivado_em`.
- Falha no arquivamento **nunca** altera o status fiscal: tenta de novo com
  espera crescente (até 10 vezes) e avisa no monitor se desistir.
- S3 sem SDK: assinatura AWS Signature V4 própria
  (`src/Nfse/Armazenamento/ArmazenamentoS3.php`), testada com o exemplo
  oficial da AWS.
- Retenção de 5 anos: configure no bucket (ciclo de vida e, se possível,
  Object Lock), não no código.

---

## 7. Organização do código

```
src/Nfse/
├── Dominio/            Vocabulário comum: StatusNfse, StatusEvento, Padrao, TipoEvento, Capacidades
├── Contrato/           ProvedorNfse (interface) e RetornoProvedor (resposta padronizada)
├── Nacional/           Padrão Nacional: DpsXmlBuilder, EventoXmlBuilder, SefinNacionalCliente, ProvedorNacional
├── Salvador/           Salvador: RpsXmlBuilder, SalvadorSoapCliente, ProvedorSalvador
├── Nfe/                NF-e modelo 55: NfeXmlBuilder (JSON → XML, igual à rota antiga), ProvedorNfe (sped-nfe)
├── Infra/              Técnico: AssinadorXml, ValidadorXsd, CertificadoCliente, CurlTransporte, Xml, SoapGravador
├── Pdf/                EspelhoNfsePdf (PDF auxiliar para quem não tem PDF oficial)
├── Armazenamento/      Cópia dos XMLs: ArmazenamentoLocal, ArmazenamentoS3 (+ fábrica)
├── Repositorio/        TODO o SQL: documentos, fila, eventos, monitor, webhooks
├── Servico/            Regras do fluxo: RecebimentoLote, ProcessadorJobs, Solicitacoes, webhooks
├── Api/                Api.php: autenticação, JSON e erros comuns aos endpoints
├── NfseConfig.php      Junta config/nfe.php + config/nfse.php
└── ProvedorFactory.php Escolhe o provedor pelo padrão

public/v1/nfse/         Endpoints HTTP (um arquivo por rota, como em public/v1/nfe)
public/monitor/         Página do Monitor Fiscal (HTML/CSS/JS puros)
bin/nfse_worker.php     Worker
database/scripts/0002_nfse_criar_base.sql
schemas/nfse/           XSDs oficiais
tests/nfse/             Testes (php tests/nfse/executar.php)
```

**Regras de convivência (para manter simples):**

1. Endpoint não tem SQL nem XML: só chama serviço e responde.
2. Serviço não conhece XML de prefeitura: só conversa com `ProvedorNfse`.
3. Provedor não acessa banco: recebe o documento e devolve `RetornoProvedor`.
4. SQL só dentro de `Repositorio/`.
5. Montagem de XML só com `DOMDocument` (helper `Infra/Xml`), nunca concatenando texto.
6. Todo XML é validado no XSD oficial **antes** de ir ao fisco.

### Como incluir um novo município
1. `Dominio/Padrao.php`: nova constante.
2. `src/Nfse/<Municipio>/Provedor<Municipio>.php` implementando `ProvedorNfse` (copie Salvador como base se for ABRASF).
3. `ProvedorFactory` e `Dominio/Capacidades`.
4. Novo script SQL liberando o valor nos `CHECK (padrao IN ...)`.
5. XSDs em `schemas/nfse/<municipio>/` e testes em `tests/nfse/executar.php`.

---

## 8. Banco de dados

Scripts (seguem `docs/padrao-scripts-sql.md`):

- `0002_nfse_criar_base.sql` — tabelas da NFS-e.
- `0003_nfse_alterar_multiempresa_rejeicao_arquivo.sql` — documento sem
  número (rejeitado na entrada), cancelamento externo (origem, processo),
  emitente no monitor, job `ARQUIVAR` e `arquivado_em`.
- `0004_nfe_alterar_documentos_para_nfe.sql` — padrão `NFE`, status
  `DENEGADO`, chave de 44 dígitos, `protocolo_autorizacao` e `sequencia` do
  evento (CC-e).
- `0005_controle_criar_clientes_uso.sql` — empresas clientes (token próprio,
  certificado criptografado, bloqueio, vencimento, franquia, tipos), uso para
  cobrança (`fiscal_uso`, preenchido por gatilho) e histórico do painel admin.
  Ver `docs/manual-operacao.md`, seção 12.

| Tabela | Papel |
|---|---|
| `nfse_lotes` | Uma linha por chamada de emissão. |
| `nfse_documentos` | Estado atual de cada NFS-e (payload, números, status, XMLs, mensagens). |
| `nfse_numeracao` | Último número de DPS/RPS por CNPJ + padrão + ambiente + série. |
| `nfse_eventos` | Cancelamentos e demais eventos. |
| `nfse_jobs` | Fila do worker. |
| `nfse_monitor` | Feed append-only do painel. |
| `nfse_webhooks_saida` | Avisos pendentes para os consumidores. |
| `fiscal_clientes` | Empresas clientes: dados, certificado (cifrado), hash do token, regras de emissão. |
| `fiscal_uso` | Uma linha por nota autorizada (base da cobrança), gravada por gatilho. |
| `fiscal_admin_log` | Histórico das alterações feitas no painel admin. |

Decisões: status em `VARCHAR + CHECK` (fácil de evoluir e vira *union type*
em TypeScript), datas `TIMESTAMPTZ`, identificadores fiscais como texto
(CNPJ alfanumérico), IDs públicos em UUID e **XML guardado no banco** (API e
worker podem estar em servidores diferentes sem disco compartilhado).

---

## 9. Segurança

- Autenticação por **token por consumidor** (`config/nfse.local.php`,
  cabeçalho `X-NFE-API-TOKEN` ou `Authorization: Bearer`). Cada consumidor
  só enxerga os próprios documentos; o token do painel enxerga todos, mas
  não emite nem cancela.
- Os **dados do emitente vêm da configuração** (inscrição, regime,
  certificado). O corpo só escolhe o CNPJ, e a API confere se o sistema tem
  permissão para ele (`consumidores.<sistema>.cnpjs`).
- Certificado A1: lido do `.pfx`; para o mTLS é gravado em arquivo
  temporário `0600` apagado ao fim do processo.
- Webhook assinado com `X-NFSe-Assinatura: sha256=<HMAC>`.
- Erros internos nunca expõem caminho/stack ao consumidor (vão para o log).
- Sem token configurado, a API só aceita chamadas de `127.0.0.1` (desenvolvimento).

---

## 10. Operação

### Homologação

Roteiro completo em `docs/nfse-homologacao.md`. Resumo:

```bash
php bin/nfse_homologar.php --padrao=NACIONAL --so-validar   # sem chamar o fisco
php bin/nfse_homologar.php --padrao=NACIONAL                # emitir, consultar, PDF, cancelar
php bin/nfse_homologar.php --padrao=SALVADOR                # lote, processamento, consulta, PDF
```

Cada execução grava requisições, respostas e XMLs em
`storage/logs/homologacao/<data>-<padrao>/`.

### Implantação (kit pronto)

Tudo o que é preciso para subir em um servidor Ubuntu 24.04 está em
`deploy/` e o passo a passo para leigos em `docs/manual-implantacao.md`:

| Arquivo | Para quê |
|---|---|
| `deploy/instalar_pacotes.sh` | Instala nginx, PHP 8.3 + extensões, PostgreSQL, Composer, certbot |
| `deploy/aplicar_banco.sh` | Roda os scripts SQL em ordem (para no primeiro erro) |
| `deploy/nginx/nfe-integracao.conf` | Site, com o SSE do monitor sem buffer |
| `deploy/php-fpm/nfse-monitor.conf` | Pool PHP separado para o stream do monitor |
| `deploy/systemd/nfse-worker@.service` | Worker como serviço (reinicia sozinho, parada segura) |
| `deploy/backup_banco.sh` | Backup diário do banco com retenção |
| `deploy/atualizar.sh` | Atualização segura: backup → pull → composer → banco → reinício → verificação |
| `bin/nfse_verificar.php` | Confere a instalação inteira e diz como resolver cada problema |

### Consultas úteis

```sql
-- Fila agora
SELECT tipo, status, count(*) FROM nfse_jobs GROUP BY 1, 2;

-- Notas paradas há mais de 10 minutos
SELECT id, padrao, referencia, status, atualizado_em
  FROM nfse_documentos
 WHERE status IN ('RECEBIDO', 'PROCESSANDO') AND atualizado_em < now() - interval '10 minutes';

-- Webhooks que desistiram
SELECT * FROM nfse_webhooks_saida WHERE status = 'FALHOU' ORDER BY id DESC LIMIT 20;
```

### Testes

```bash
php tests/nfse/executar.php
```

No GitHub, os testes rodam sozinhos em todo PR (CI): ver `docs/ci.md`.

Com banco descartável (fila, worker e monitor de verdade):

```bash
NFSE_TESTE_PG="pgsql:host=127.0.0.1;dbname=nfephp_teste;user=nfephp_app;password=..." php tests/nfse/executar.php
```

---

## 11. Portabilidade para Node.js + TypeScript

O desenho foi feito para a migração ser **tradução**, não reescrita:

| PHP hoje | Node/TS amanhã |
|---|---|
| `StatusNfse`, `Padrao`, `TipoEvento` (constantes) | `type StatusNfse = 'RECEBIDO' \| ...` |
| `ProvedorNfse` (interface) | `interface ProvedorNfse` idêntica |
| `RetornoProvedor` (objeto imutável) | `type RetornoProvedor` + funções construtoras |
| `TransporteHttp` / `CurlTransporte` | `fetch`/`undici` com `Agent({ cert, key })` |
| `AssinadorXml` | `xml-crypto` (mesmo C14N e algoritmos) |
| `ValidadorXsd` | `libxmljs2` com os **mesmos** XSDs de `schemas/` |
| Repositórios PDO | `pg` / Kysely com o **mesmo SQL** |
| `nfse_jobs` + `SKIP LOCKED` | mesmo SQL (ou `graphile-worker`/`pg-boss`, que usam a mesma técnica) |
| `pg_notify` / `LISTEN` | `client.on('notification')` |
| `monitor.php` (SSE) | `res.write('event: nfse\ndata: ...\n\n')` |
| JSON da API (`DocumentoApresentador`) | mesmo contrato — consumidores não mudam nada |

Como o banco, os XSDs e o contrato JSON são os mesmos, dá para migrar
**um pedaço por vez** (por exemplo, primeiro o endpoint SSE, depois o
worker), com PHP e Node rodando juntos sobre o mesmo PostgreSQL.

---

## 12. O que foi verificado e o que depende de homologação

Verificado localmente (testes automatizados):

- DPS, pedido de evento e lote de Salvador **validam nos XSDs oficiais**.
- Assinaturas (DPS em RSA-SHA256; RPS e lote de Salvador em RSA-SHA1) conferem.
- Fluxo completo com PostgreSQL: numeração, fila, retentativas, idempotência,
  reenvio após rejeição, cancelamento, Salvador assíncrono, monitor e webhooks.
- API via HTTP e stream SSE.

Depende de homologação real (não há certificado nem credenciamento neste ambiente):

- Respostas reais do Sefin (nomes de campos foram conferidos em duas
  implementações de referência, ACBr e nfse-php, mas o contrato oficial
  deve ser validado na Produção Restrita).
- URL do DANFSe no ADN e algoritmo de assinatura aceito em cada ambiente.
- Formato exato das respostas SOAP de Salvador (tratamos texto escapado e XML aninhado).
- Regras de negócio do fisco que o XSD não cobre (alíquota informada ou não,
  regime tributário, código de tributação municipal...).
