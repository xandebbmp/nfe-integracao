# Fila de emissão (lote assíncrono + worker)

## Visão geral

```
Financeiro Universal --POST /v1/nfe/lotes--> API  --grava--> emissao_fila (PostgreSQL)
                     <--202 + nNF por nota--                       |
                                                          bin/worker.php (systemd)
                                                                   |
                                         lote_emitir.php -> gera XML, assina, envia SEFAZ
                                                                   |
                                                          nfe_documentos (status final)
Financeiro Universal --GET /v1/nfe/lotes?id=N--> situação de cada nota
```

A requisição HTTP só valida e enfileira: um lote de centenas de notas responde na hora,
sem depender do tempo da SEFAZ. Nenhuma nota se perde se a conexão cair: o estado fica no banco.

## Headers

| Header | Obrigatório |
|--------|-------------|
| `X-NFE-API-TOKEN` | sim |
| `X-NFE-EMPRESA` | sim (CNPJ do emitente) |

## POST /v1/nfe/lotes

Body: array de notas (ou `{"notas": [...]}`), até 500 por lote. Cada nota segue o formato de
`json/lote/001.json` e **precisa** de `idIntegracao` (id único da nota no Financeiro Universal).

- Sem `ide.nNF`: o número é reservado na hora e devolvido.
- Sem `ide.dhEmi`: preenchido na geração.
- O lote é tudo ou nada: se alguma nota for inválida (sem `idIntegracao`, sem blocos obrigatórios,
  `emit.CNPJ` diferente do header, `tpAmb` diferente do ambiente da empresa), nada é enfileirado e
  a resposta `400` lista os erros por índice.

Resposta `202`:

```json
{
  "loteId": 12,
  "itens": [
    {"jobId": 40, "idIntegracao": "FIN-1", "serie": 1, "nNF": 1235, "status": "PENDENTE", "acao": "novo"}
  ]
}
```

`acao` por nota:

| acao | Quando |
|------|--------|
| `novo` | primeiro envio desse `idIntegracao` |
| `existente` | já está na fila ou concluído com o mesmo conteúdo: nada muda |
| `conflito` | já está na fila/autorizada com conteúdo **diferente**: a alteração é ignorada (`erro` explica) |
| `reenfileirado` | estava `FALHOU` ou rejeitada: volta para a fila com o conteúdo novo e o **mesmo nNF** |

## GET /v1/nfe/lotes?id=N

Situação atual de cada nota recebida no lote (mesmos campos do POST, mais `statusDocumento`,
`chave`, `cStat`, `xMotivo`, `erro`, `tentativas`). `resumo` conta as notas por situação.

## Status do job × status do documento

| `status` (job) | `statusDocumento` | Significado para o Financeiro |
|----------------|-------------------|-------------------------------|
| `PENDENTE` | vazio | aguardando o worker |
| `PROCESSANDO` | — | em emissão agora |
| `PENDENTE` | `ERRO_ENVIO` | falha de comunicação: nova tentativa automática (1, 2, 4… min, até 30) |
| `CONCLUIDO` | `AUTORIZADO` | nota válida (`chave`, protocolo em `nfe_documentos`) |
| `CONCLUIDO` | `DENEGADO` | denegada: não pode ser usada nem reemitida |
| `CONCLUIDO` | `REJEITADO` | rejeitada pela SEFAZ ou pelo XSD: corrigir e reenviar o mesmo `idIntegracao` |
| `FALHOU` | vazio | erro nos dados antes de gerar o XML (`erro` diz o quê): corrigir e reenviar |
| `FALHOU` | `ERRO_ENVIO` | tentativas esgotadas (6): verificar certificado/SEFAZ e reenviar |

## Garantias

- **Sem duplicidade:** reenvio com resultado incerto usa o mesmo XML; se a SEFAZ já tinha
  recebido, cai em 204 e o protocolo é recuperado (`docs/seguranca-e-idempotencia-emissao.md`).
- **Vários workers:** `FOR UPDATE SKIP LOCKED` garante um job por worker.
- **Worker caiu no meio:** jobs presos em `PROCESSANDO` por mais de 10 min voltam para a fila.
- **Numeração:** reservada no enfileiramento, atômica por empresa/série (`docs/multiempresa-emissao.md`).

## Próximos passos

- `GET /v1/nfe/documentos` para o monitor listar as notas.
- Webhook de mudança de status para o Financeiro Universal (tempo real no monitor).
- `tipo_documento = NFSE` na mesma fila (NFS-e Nacional e Nota Salvador).
