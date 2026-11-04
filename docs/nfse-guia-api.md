# NFS-e — Guia da API para integradores

API aberta para sistemas externos emitirem e acompanharem NFS-e no
**Padrão Nacional** e no padrão da **Prefeitura de Salvador-BA**.

Base: `https://<servidor>/v1/nfse/`

Arquitetura e fluxo interno: `docs/nfse-arquitetura.md`.

---

## 1. Autenticação

Envie o token do seu sistema em todas as chamadas:

```
X-NFE-API-TOKEN: <seu-token>
```

(ou `Authorization: Bearer <seu-token>`). Cada sistema só vê os próprios
documentos. Tokens são cadastrados em `config/nfse.local.php`.

---

## 2. Rotas

| Método | Rota | Para quê |
|---|---|---|
| GET | `capacidades.php` | O que cada padrão suporta (eventos, PDF, observações) |
| POST | `emitir.php[?aguardar=N]` | Enviar lote com 1+ documentos |
| GET | `lote.php?id=...[&aguardar=N][&xml=1]` | Situação de todos os documentos do lote |
| GET | `documento.php?id=...[&historico=1]` | Um documento (JSON com XML autorizado) |
| GET | `documento.php?id=...&formato=xml` | Apenas o XML autorizado |
| GET | `pdf.php?id=...[&download=1]` | PDF da NFS-e |
| POST | `consultar.php` | Consultar o documento no fisco |
| POST | `evento.php` | Cancelamento e demais eventos |
| GET | `documentos.php` | Lista (carga inicial do painel) |
| GET | `monitor.php?token=...` | Stream em tempo real (SSE) |

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
| `padrao` (lote ou documento) | `NACIONAL` | `SALVADOR` | Obrigatório. Pode variar por documento. |
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

O **prestador (emitente)** vem da configuração do servidor, nunca do corpo.

### Resposta

`202 Accepted` (ainda processando) ou `200 OK` (todos finalizados dentro do `aguardar`):

```json
{
  "lote_id": "24765aae-d214-456f-b0a0-2a61c3c470c9",
  "resumo": { "AUTORIZADO": 1 },
  "documentos": [
    {
      "id": "c59ac931-77ac-419c-a284-ee23c860f088",
      "referencia": "PEDIDO-2026-000123",
      "padrao": "NACIONAL",
      "ambiente": "HOMOLOGACAO",
      "status": "AUTORIZADO",
      "finalizado": true,
      "pode_reenviar": false,
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
  ]
}
```

`situacao_recebimento`: `NOVO`, `JA_EXISTENTE` (mesma referência já
autorizada/em andamento — **nenhuma nota nova foi gerada**) ou `REENVIADO`
(referência estava `REJEITADO`/`ERRO` e foi reenviada com os dados novos).

`422` quando algum documento é inválido (nada é gravado):

```json
{
  "erro": {
    "codigo": "DOCUMENTOS_INVALIDOS",
    "mensagem": "Um ou mais documentos sao invalidos. Nenhum documento foi gravado.",
    "documentos": [
      { "indice": 0, "referencia": "A1", "erros": ["tomador e obrigatorio em Salvador."] }
    ]
  }
}
```

### Status (iguais para todos os padrões)

| Status | O que fazer |
|---|---|
| `RECEBIDO`, `PROCESSANDO` | Aguardar (webhook, `aguardar` ou consultar o lote). |
| `AUTORIZADO` | Usar `xml_autorizado` / baixar PDF. |
| `REJEITADO` | Ler `mensagens`, corrigir e reenviar com a **mesma referência**. |
| `ERRO` | Falha técnica. Chamar `consultar.php` primeiro; se continuar sem nota, reenviar. |
| `CANCELADO` / `SUBSTITUIDO` | Nota não vale mais. |

Cada item de `mensagens`: `{ "codigo": "E0312", "mensagem": "...", "correcao": "..." }`.
Códigos `XSD` indicam erro de estrutura detectado **antes** de enviar ao fisco.

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
| 422 `NAO_SUPORTADO_PELO_PADRAO` | Padrão não aceita (ex.: **Salvador não cancela por webservice**) |

Status do evento: `RECEBIDO`, `PROCESSANDO`, `REGISTRADO`, `REJEITADO`, `ERRO`.
Quando um cancelamento é `REGISTRADO`, o documento passa a `CANCELADO`.

---

## 6. PDF

`GET /v1/nfse/pdf.php?id=...` → `application/pdf`

- **Nacional:** DANFSe oficial, baixado do ADN.
- **Salvador:** espelho gerado pela API (documento auxiliar; a prefeitura não
  fornece PDF por webservice).

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

Carga inicial + stream:

```html
<script>
const token = 'TOKEN_DO_PAINEL';
const linhas = new Map();

fetch('/v1/nfse/documentos.php?limite=200', { headers: { 'X-NFE-API-TOKEN': token } })
  .then(r => r.json())
  .then(({ documentos }) => documentos.forEach(d => desenhar(d.id, d.referencia, d.padrao, d.status, '')));

const fonte = new EventSource('/v1/nfse/monitor.php?token=' + encodeURIComponent(token));
fonte.addEventListener('nfse', (e) => {
  const m = JSON.parse(e.data);
  desenhar(m.documento_id, m.referencia, m.padrao, m.status, `${m.operacao}: ${m.mensagem ?? ''}`);
});

function desenhar(id, referencia, padrao, status, ultimaAcao) {
  let tr = linhas.get(id);
  if (!tr) {
    tr = document.querySelector('#notas').insertRow(0);
    linhas.set(id, tr);
  }
  tr.innerHTML = '';
  for (const valor of [referencia, padrao, status, ultimaAcao]) {
    tr.insertCell().textContent = valor;
  }
}
</script>
<table><tbody id="notas"></tbody></table>
```

Cada evento `nfse`:

```json
{
  "id": 22,
  "documento_id": "c59ac931-...",
  "lote_id": "24765aae-...",
  "padrao": "NACIONAL",
  "referencia": "PEDIDO-2026-000123",
  "operacao": "EMISSAO",
  "status": "AUTORIZADO",
  "mensagem": null,
  "numero_nfse": "123",
  "chave_acesso": "2927...",
  "criado_em": "2026-11-04 13:00:01+00"
}
```

O navegador reconecta sozinho e continua do último `id` recebido.
