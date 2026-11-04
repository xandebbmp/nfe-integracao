# NFS-e — Guia da API para integradores

API aberta para sistemas externos emitirem e acompanharem NFS-e no
**Padrão Nacional** e no padrão da **Prefeitura de Salvador-BA**, e também
**NF-e modelo 55** (produtos, seção 9) — tudo com a mesma fila, status,
monitor e webhooks.

Base: `https://<servidor>/v1/nfse/`

Arquitetura e fluxo interno: `docs/nfse-arquitetura.md`.

---

## 1. Autenticação

Envie o token do seu sistema em todas as chamadas:

```
X-NFE-API-TOKEN: <seu-token>
```

(ou `Authorization: Bearer <seu-token>`). Cada sistema só vê os próprios
documentos. Tokens são cadastrados na tela **Sistemas** (Scriptcase,
ver `docs/cadastro-scriptcase.md`) ou em `config/nfse.local.php`.

---

## 2. Rotas

| Método | Rota | Para quê |
|---|---|---|
| GET | `capacidades.php` | O que cada padrão suporta (eventos, PDF, observações) |
| GET | `emitentes.php` | CNPJs que o seu sistema pode usar como emitente |
| POST | `emitir.php[?aguardar=N]` | Enviar lote com 1+ documentos |
| GET | `lote.php?id=...[&aguardar=N][&xml=1]` | Situação de todos os documentos do lote |
| GET | `documento.php?id=...[&historico=1][&xml=0]` | Um documento (JSON; `xml=0` omite o XML autorizado) |
| GET | `documento.php?id=...&formato=xml` | Apenas o XML autorizado |
| GET | `pdf.php?id=...[&download=1]` | PDF da NFS-e |
| POST | `consultar.php` | Consultar o documento no fisco |
| POST | `evento.php` | Cancelamento e demais eventos (via webservice) |
| POST | `cancelamento_externo.php` | Registrar cancelamento feito no portal da prefeitura (Salvador) |
| GET | `documentos.php` | Lista (carga inicial do painel) |
| GET | `monitor.php?token=...[&desde=agora]` | Stream em tempo real (SSE) |
| — | `/monitor/` | Página pronta do painel (ver `docs/nfse-monitor.md`) |

Erros têm sempre o formato:

```json
{ "erro": { "codigo": "DOCUMENTOS_INVALIDOS", "mensagem": "...", "...": "detalhes" } }
```

---

## 3. Emitir

### Requisição

```http
POST /v1/nfse/emitir.php?aguardar=20
X-NFE-API-TOKEN: ...
Content-Type: application/json
```

```json
{
  "padrao": "NACIONAL",
  "emitente_cnpj": "41986662000160",
  "documentos": [
    {
      "referencia": "PEDIDO-2026-000123",
      "competencia": "2026-11-04",
      "tomador": {
        "cpf_cnpj": "11222333000181",
        "nome": "Cliente Exemplo Ltda",
        "email": "financeiro@cliente.com.br",
        "telefone": "71999990000",
        "inscricao_municipal": "",
        "endereco": {
          "logradouro": "Av. Tancredo Neves",
          "numero": "1000",
          "complemento": "Sala 10",
          "bairro": "Caminho das Arvores",
          "codigo_municipio": "2927408",
          "uf": "BA",
          "cep": "41820020"
        }
      },
      "servico": {
        "descricao": "Desenvolvimento de software sob encomenda - OS 4521",
        "codigo_tributacao_nacional": "010101",
        "codigo_tributacao_municipal": "",
        "codigo_nbs": "",
        "item_lista_servico": "01.01",
        "codigo_cnae": "6201501",
        "codigo_municipio_prestacao": "2927408"
      },
      "valores": {
        "valor_servicos": 1500.00,
        "aliquota_iss": 5,
        "iss_retido": false,
        "desconto_incondicionado": 0,
        "desconto_condicionado": 0,
        "deducoes": 0,
        "valor_pis": 0, "valor_cofins": 0, "valor_inss": 0, "valor_ir": 0, "valor_csll": 0,
        "outras_retencoes": 0
      }
    }
  ]
}
```

### Campos

| Campo | Nacional | Salvador | Observação |
|---|---|---|---|
| `padrao` (lote ou documento) | `NACIONAL` | `SALVADOR` | Obrigatório. Pode variar por documento. Para NF-e use `NFE` (seção 9). |
| `emitente_cnpj` (lote ou documento) | cond. | cond. | CNPJ do prestador. Obrigatório quando o seu sistema pode emitir por mais de uma empresa (veja `emitentes.php`). Com uma só, pode omitir. |
| `referencia` | obrig. | obrig. | Id do documento no **seu** sistema (até 60). Garante que não sai nota duplicada. |
| `competencia` | opcional | — | `AAAA-MM-DD`. Padrão: hoje. |
| `tomador` | opcional | **obrig.** | `nome` obrigatório se informado. CPF (11) ou CNPJ (14). |
| `tomador.endereco` | opcional | opcional | No Nacional, se informar: logradouro, bairro, codigo_municipio (IBGE) e cep. |
| `servico.descricao` | obrig. | obrig. | Até 2000 caracteres. |
| `servico.codigo_tributacao_nacional` | **obrig.** | — | 6 dígitos (LC 116 nacional, ex.: `010101`). |
| `servico.item_lista_servico` | — | **obrig.** | Item da LC 116 (ex.: `01.01`). |
| `servico.codigo_tributacao_municipal` | opcional | opcional | |
| `servico.codigo_cnae` | — | opcional | |
| `servico.codigo_nbs` | opcional | — | |
| `servico.codigo_municipio_prestacao` | opcional | opcional | IBGE. Padrão: município do emitente. |
| `valores.valor_servicos` | obrig. | obrig. | > 0 |
| `valores.aliquota_iss` | opcional | **obrig.** | Percentual: `5` = 5%. |
| `valores.iss_retido` | opcional | opcional | `true` = retido pelo tomador. |
| `valores.tributacao_issqn` | opcional | — | 1 Tributável (padrão), 2 Imunidade, 3 Exportação, 4 Não incidência. |
| `valores.percentual_tributos_simples` | opcional | — | Só para optantes do Simples (pTotTribSN). |
| `natureza_operacao` | — | opcional | 1 (padrão) a 6, tabela ABRASF. |
| `substituicao` | opcional | — | `{ "chave_substituida": "50 dígitos", "codigo_motivo": "01", "motivo": "..." }` |

Os dados do **prestador** (inscrição municipal, regime, certificado) vêm da
configuração do servidor. O corpo só escolhe **qual** CNPJ, e a API confere
se o seu sistema tem permissão para ele.

### Resposta

`202 Accepted` (há documentos processando ou recusados) ou `200 OK` (todos finalizados dentro do `aguardar`):

```json
{
  "lote_id": "24765aae-d214-456f-b0a0-2a61c3c470c9",
  "resumo": { "AUTORIZADO": 1 },
  "documentos": [
    {
      "id": "c59ac931-77ac-419c-a284-ee23c860f088",
      "referencia": "PEDIDO-2026-000123",
      "padrao": "NACIONAL",
      "emitente_cnpj": "41986662000160",
      "ambiente": "HOMOLOGACAO",
      "status": "AUTORIZADO",
      "finalizado": true,
      "pode_reenviar": false,
      "indice": 0,
      "situacao_recebimento": "NOVO",
      "dps_rps": { "serie": "1", "numero": 15 },
      "nfse": {
        "numero": "123",
        "chave_acesso": "29274082...",
        "codigo_verificacao": null,
        "data_autorizacao": "2026-11-04T10:00:00-03:00"
      },
      "mensagens": [],
      "links": {
        "documento": "/v1/nfse/documento.php?id=c59a...",
        "xml": "/v1/nfse/documento.php?id=c59a...&formato=xml",
        "pdf": "/v1/nfse/pdf.php?id=c59a..."
      },
      "xml_autorizado": "<NFSe ...>...</NFSe>"
    }
  ],
  "recusados": []
}
```

`situacao_recebimento` de cada documento:

| Situação | Significado |
|---|---|
| `NOVO` | Válido: gravado e enfileirado para o fisco. |
| `INVALIDO` | Dados com erro: **gravado como `REJEITADO`** (sem número de DPS/RPS), aparece no monitor com o motivo e a sugestão de correção. Corrija e reenvie com a mesma referência. |
| `REENVIADO` | A referência estava `REJEITADO`/`ERRO` e veio corrigida. |
| `JA_EXISTENTE` | Referência já autorizada/em andamento — **nenhuma nota nova foi gerada**. |

Um documento inválido **não derruba o lote**: os válidos seguem normalmente.

Vão para `recusados` (não são gravados nem aparecem no monitor) apenas os
casos em que não dá para gravar: padrão desconhecido, emitente não
informado/não liberado, referência repetida no mesmo lote, item que não é
objeto JSON.

```json
"recusados": [
  {
    "indice": 2,
    "situacao_recebimento": "RECUSADO",
    "referencia": "PED-9",
    "erros": [{
      "codigo": "NAO_ARMAZENADO",
      "campo": "emitente_cnpj",
      "mensagem": "Emitente 99999999000199 nao liberado para este sistema.",
      "correcao": "Use um dos CNPJs liberados: 41986662000160."
    }]
  }
]
```

`422` só quando a requisição inteira é inválida (`LOTE_INVALIDO`: lista
vazia, mais de 50 documentos, sistema sem emitente liberado) ou quando
**nenhum** documento pôde ser gravado (`DOCUMENTOS_RECUSADOS`).

### Status (iguais para todos os padrões)

| Status | O que fazer |
|---|---|
| `RECEBIDO`, `PROCESSANDO` | Aguardar (webhook, `aguardar` ou consultar o lote). |
| `AUTORIZADO` | Usar `xml_autorizado` / baixar PDF. |
| `REJEITADO` | Ler `mensagens`, corrigir e reenviar com a **mesma referência**. |
| `ERRO` | Falha técnica. Chamar `consultar.php` primeiro; se continuar sem nota, reenviar. |
| `CANCELADO` / `SUBSTITUIDO` | Nota não vale mais. |
| `DENEGADO` | Só NF-e: SEFAZ denegou (irregularidade cadastral). Número usado, nota não vale e não pode ser reenviada. |

Cada item de `mensagens`:

```json
{ "codigo": "CAMPO_OBRIGATORIO", "campo": "servico.descricao", "mensagem": "...", "correcao": "Como corrigir..." }
```

- Erros de entrada (da API): `CAMPO_OBRIGATORIO`, `FORMATO_INVALIDO`,
  `VALOR_INVALIDO`, `NAO_SUPORTADO_PELO_PADRAO` — sempre com `campo` e `correcao`.
- `XSD`: estrutura recusada pelo schema oficial **antes** de ir ao fisco.
- Demais códigos (ex.: `E0312`, ou `cStat` como `539` na NF-e): vêm do Sefin/Salvador/SEFAZ; `correcao` aparece
  quando o fisco a informa.

---

## 4. Consultar

```http
POST /v1/nfse/consultar.php
{ "documento_id": "c59ac931-...", "aguardar": 20 }
```

- Sem NFS-e ainda (`PROCESSANDO`, `ERRO`, `REJEITADO`): verifica se o envio
  virou nota. É o caminho para **recuperar** uma nota autorizada após timeout.
- Com NFS-e: verifica se foi cancelada/substituída.

Resposta `202` com `consulta_concluida` e o `documento` atualizado.

---

## 5. Eventos (cancelamento)

```http
POST /v1/nfse/evento.php
{
  "documento_id": "c59ac931-...",
  "tipo_evento": "101101",
  "motivo_codigo": "1",
  "motivo": "Erro na emissao: valor incorreto",
  "aguardar": 20
}
```

- `motivo_codigo`: `1` erro na emissão, `2` serviço não prestado, `9` outros.
- `motivo`: 15 a 255 caracteres.
- `tipo_evento` `101103`: solicitação de análise fiscal (cancelamento fora do prazo).

Respostas:

| HTTP | Quando |
|---|---|
| 202 | Evento enfileirado (ou concluído dentro do `aguardar`) |
| 200 | Já havia um pedido igual; devolve o mesmo evento |
| 409 `STATUS_NAO_PERMITE` | Documento não está `AUTORIZADO` |
| 422 `NAO_SUPORTADO_PELO_PADRAO` | Padrão não aceita (ex.: **Salvador não cancela por webservice** — use a seção 5.1) |

Status do evento: `RECEBIDO`, `PROCESSANDO`, `REGISTRADO`, `REJEITADO`, `ERRO`.
Quando um cancelamento é `REGISTRADO`, o documento passa a `CANCELADO`.

---

### 5.1 Cancelamento feito no portal (Salvador)

Salvador só cancela pelo portal da prefeitura ou processo administrativo.
Depois de cancelado lá, registre na API para a nota, o monitor e os webhooks
ficarem coerentes (a API não chama a prefeitura nesta rota):

```http
POST /v1/nfse/cancelamento_externo.php
{
  "documento_id": "c59ac931-...",
  "numero_processo": "2026/77881",
  "motivo_codigo": "1",
  "motivo": "Cancelado no portal: valor incorreto",
  "observacao": "Pedido deferido em 05/11/2026"
}
```

`201` com o evento (`origem: "EXTERNO"`, `status: "REGISTRADO"`) e o
documento já `CANCELADO`. `422` se o padrão cancela pela API (Nacional:
use `evento.php`), `409` se a nota não estiver `AUTORIZADO`.

---

## 6. PDF

`GET /v1/nfse/pdf.php?id=...` → `application/pdf`

- **Nacional:** DANFSe oficial, baixado do ADN.
- **Salvador:** espelho gerado pela API (documento auxiliar; a prefeitura não
  fornece PDF por webservice), com o link de consulta no portal quando
  `salvador.url_visualizacao` estiver configurada.

`409 SEM_NFSE` se o documento ainda não tiver NFS-e.

---

## 7. Webhook

Se o seu sistema tiver `webhook_url` configurada, você recebe um `POST`
sempre que um documento muda de status ou um evento termina:

```http
POST https://seu-sistema/webhooks/nfse
Content-Type: application/json
X-NFSe-Assinatura: sha256=5d1c...
X-NFSe-Entrega: 42
```

```json
{
  "tipo": "documento.atualizado",
  "documento": { "...mesmo formato do item de emitir..." },
  "enviado_em": "2026-11-04T10:00:03-03:00"
}
```

Tipos: `documento.atualizado` e `evento.atualizado` (traz também `evento`).

**Valide a assinatura** antes de confiar no conteúdo:

```php
$corpo = file_get_contents('php://input');
$esperado = 'sha256=' . hash_hmac('sha256', $corpo, $segredo);
if (!hash_equals($esperado, $_SERVER['HTTP_X_NFSE_ASSINATURA'] ?? '')) {
    http_response_code(401);
    exit;
}
```

Responda `2xx` em até 10 segundos. Falhas são re-tentadas (1, 2, 4, 8... minutos,
até 10 vezes). Use `documento.id` + `status` para ignorar entregas repetidas.

---

## 8. Monitor (painel em tempo real)

Há uma **página pronta** em `/monitor/` (documentação em `docs/nfse-monitor.md`).
Para montar um painel próprio, use a carga inicial + o stream:

```js
const token = 'TOKEN_DO_PAINEL';
fetch('/v1/nfse/documentos.php?limite=200', { headers: { 'X-NFE-API-TOKEN': token } })
  .then(r => r.json())
  .then(({ documentos }) => documentos.forEach(desenhar));

const fonte = new EventSource('/v1/nfse/monitor.php?desde=agora&token=' + encodeURIComponent(token));
fonte.addEventListener('nfse', (e) => {
  const m = JSON.parse(e.data); // linha do monitor (formato abaixo)
  fetch(`/v1/nfse/documento.php?id=${m.documento_id}&xml=0`, { headers: { 'X-NFE-API-TOKEN': token } })
    .then(r => r.json())
    .then(({ documento }) => desenhar(documento));
});
```

Cada evento `nfse`:

```json
{
  "id": 22,
  "documento_id": "c59ac931-...",
  "lote_id": "24765aae-...",
  "padrao": "NACIONAL",
  "emitente_cnpj": "41986662000160",
  "referencia": "PEDIDO-2026-000123",
  "operacao": "EMISSAO",
  "status": "AUTORIZADO",
  "mensagem": null,
  "numero_nfse": "123",
  "chave_acesso": "2927...",
  "criado_em": "2026-11-04 13:00:01+00"
}
```

`desde`: `agora` (só eventos novos), um id (continua dele) ou vazio (últimas
50 linhas). O navegador reconecta sozinho e continua do último `id` recebido.

---

## 9. NF-e (modelo 55, produtos)

A NF-e usa **as mesmas rotas, a mesma fila, os mesmos status, o mesmo
monitor e os mesmos webhooks** da NFS-e. Muda só o `padrao` (`"NFE"`) e o
conteúdo de cada documento, que é o **mesmo JSON** já usado pela rota antiga
`/v1/nfe/emitir_json_padrao.php` (`ide`, `emit`, `dest`, `itens`, `totais`,
`transp`, `pag`, `infAdic`, `reformaTributaria`) mais a `referencia`.

As rotas antigas `/v1/nfe/*_padrao.php` continuam funcionando sem mudança.

### Requisição

```http
POST /v1/nfse/emitir.php?aguardar=30
X-NFE-API-TOKEN: ...
```

```json
{
  "padrao": "NFE",
  "documentos": [
    {
      "referencia": "PEDIDO-2026-000555",
      "ide": { "cUF": 29, "natOp": "VENDA", "mod": 55, "serie": 1,
               "tpNF": 1, "idDest": 1, "cMunFG": "2927408", "tpImp": 1,
               "tpEmis": 1, "finNFe": 1, "indFinal": 1, "indPres": 1 },
      "emit": { "CNPJ": "41986662000160", "xNome": "...", "IE": "...", "CRT": 3,
                "enderEmit": { "...": "..." } },
      "dest": { "CNPJ": "...", "xNome": "...", "indIEDest": 9, "enderDest": { "...": "..." } },
      "itens": [ { "nItem": 1, "prod": { "cProd": "...", "xProd": "...", "NCM": "...",
                   "CFOP": "5102", "uCom": "UN", "qCom": 1, "vUnCom": 10 }, "imposto": { "...": "..." } } ],
      "totais": { "ICMSTot": { "vNF": 10 } },
      "transp": { "modFrete": 9 },
      "pag": { "detPag": [ { "tPag": "01", "vPag": 10 } ] }
    }
  ]
}
```

Exemplo completo: `json/nota_homologacao_emitir_padrao.json`.

### O que a API controla (não precisa enviar)

| Campo | Regra |
|---|---|
| `ide.nNF` | **Sempre** definido pela API (numeração por CNPJ + série + ambiente). Valor enviado é ignorado. |
| `ide.tpAmb` | Vem da configuração do servidor (homologação/produção). |
| `ide.serie` | Se não vier, usa `nfe.serie` da configuração (padrão `1`). |
| `ide.dhEmi` | Se não vier, data/hora atual (America/Bahia). |
| `ide.cNF` / chave | Gerados na montagem. |
| `emit.CNPJ` | Escolhe o emitente. Precisa estar em `emitentes` e ser permitido ao seu sistema. Se vier `emitente_cnpj` diferente, o documento é recusado. Se `emit` vier sem CNPJ, a API usa `emitente_cnpj`. |

Validações na entrada (documento volta `REJEITADO` com `campo` e `correcao`,
sem consumir número): `mod` 55 (NFC-e/65 não é suportada), `natOp`, `cUF`,
`cMunFG`, série 0–999, `emit` (xNome, IE, CRT, enderEmit), `dest` (xNome,
CPF/CNPJ, indIEDest), itens (nItem único, cProd, xProd, NCM 8 dígitos ou
`00`, CFOP 4 dígitos, uCom, qCom, vUnCom, imposto) e `totais.ICMSTot.vNF`.
Depois, a montagem e o XSD oficial podem recusar com `MONTAGEM` ou `XSD`.

### Resultado

- `AUTORIZADO`: `nfse.chave` (44 dígitos), `nfse.protocolo` e o XML
  `nfeProc` (também arquivado como `nfe-proc.xml`).
- `DENEGADO` (SEFAZ 110, 205, 301, 302, 303): a nota existe e o número foi
  usado, mas **não pode circular nem ser reenviada**. Não tem DANFE.
- `REJEITADO` / `ERRO`: como na NFS-e (corrigir e reenviar a mesma `referencia`).
- Se o SEFAZ responder "em processamento" (103/105), o worker consulta o
  recibo sozinho a cada 5 s.
- **Proteção contra nota em dobro:** o XML assinado e a chave são gravados
  antes do envio. Em qualquer nova tentativa, a API consulta a chave primeiro
  e só reenvia o mesmo XML se o SEFAZ disser que ela não existe (217).

### PDF

`GET pdf.php?id=...` devolve o **DANFE** oficial.

### Eventos

| `tipo_evento` | Evento | Regras |
|---|---|---|
| `110111` (padrão para NF-e) | Cancelamento | `motivo` 15–255 caracteres. Usa o protocolo da autorização. Prazo é do SEFAZ (rejeição devolvida como veio). |
| `110110` | Carta de correção (CC-e) | `motivo` = texto da correção, 15–1000 caracteres. Pode haver várias (até 20); a `sequencia` é numerada pela API e aparece no evento. |

```http
POST /v1/nfse/evento.php
{ "documento_id": "...", "tipo_evento": "110110",
  "motivo": "Corrigir endereco de entrega para Rua B, 20" }
```

`motivo_codigo` não se aplica à NF-e. **Inutilização** de numeração continua
na rota antiga (`/v1/nfe/inutilizar_padrao.php`). Manifestação do
destinatário e NFC-e não fazem parte desta API.
