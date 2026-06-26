# Guia de Uso - Distribuicao DF-e

## Visao geral

Este guia descreve o uso tecnico-operacional do endpoint seguro de Distribuicao DF-e.

Endpoint:

```text
/v1/nfe/dfe_padrao.php
```

Arquivo:

```text
public/v1/nfe/dfe_padrao.php
```

Autenticacao:

```text
X-NFE-API-TOKEN
```

O endpoint consulta a SEFAZ via NFePHP e salva o XML bruto de retorno em:

```text
storage/xml/retornos
```

Nesta fase, o endpoint nao descompacta `docZip`, nao processa documentos, nao manifesta destinatario, nao controla NSU automaticamente e nao possui banco, cron ou fila.

## Metodos aceitos

- `GET`
- `POST JSON`
- `POST form-data`

## Parametros

- `ultNSU`: inteiro nao negativo, padrao `0`.
- `numNSU`: inteiro nao negativo.
- `chave`: opcional, deve conter exatamente 44 digitos quando informado.
- `fonte`: opcional, padrao `AN`; aceita `AN` ou `RS`.

## Prioridade da consulta

1. Se `chave` vier preenchida, consulta por chave.
2. Senao, se `numNSU > 0`, consulta por NSU especifico.
3. Senao, consulta por `ultNSU`.

## Exemplos com curl

Substitua `$NFE_TOKEN` pelo token configurado no ambiente. Nao use token real em documentacao, logs ou mensagens compartilhadas.

### 1. Consulta por ultNSU=0

```bash
curl -X POST 'http://localhost:8000/v1/nfe/dfe_padrao.php' \
  -H 'Content-Type: application/json' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  --data-binary '{
    "ultNSU": 0,
    "fonte": "AN"
  }'
```

Exemplo equivalente via GET:

```bash
curl 'http://localhost:8000/v1/nfe/dfe_padrao.php?ultNSU=0&fonte=AN' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN"
```

### 2. Consulta por numNSU

```bash
curl -X POST 'http://localhost:8000/v1/nfe/dfe_padrao.php' \
  -H 'Content-Type: application/json' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  --data-binary '{
    "numNSU": 123,
    "fonte": "AN"
  }'
```

### 3. Consulta por chave

```bash
curl -X POST 'http://localhost:8000/v1/nfe/dfe_padrao.php' \
  -H 'Content-Type: application/json' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  --data-binary '{
    "chave": "00000000000000000000000000000000000000000000",
    "fonte": "AN"
  }'
```

### 4. Erro sem token

```bash
curl -X POST 'http://localhost:8000/v1/nfe/dfe_padrao.php' \
  -H 'Content-Type: application/json' \
  --data-binary '{
    "ultNSU": 0
  }'
```

Retorno esperado quando o token não for enviado:

```json
{
  "error": "Unauthorized",
  "message": "Token de API ausente ou invalido."
}
```

### 5. Entrada invalida

Exemplo com `ultNSU` negativo:

```bash
curl -X POST 'http://localhost:8000/v1/nfe/dfe_padrao.php' \
  -H 'Content-Type: application/json' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  --data-binary '{
    "ultNSU": -1
  }'
```

Exemplo com fonte invalida:

```bash
curl -X POST 'http://localhost:8000/v1/nfe/dfe_padrao.php' \
  -H 'Content-Type: application/json' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  --data-binary '{
    "ultNSU": 0,
    "fonte": "XX"
  }'
```

## Retorno

O retorno segue o formato JSON com os dados dentro de `item`.

Campos principais:

- `ok`: indica sucesso tecnico da chamada conforme classificacao do endpoint.
- `status`: classificacao do resultado.
- `cStat`: codigo retornado pela SEFAZ.
- `xMotivo`: mensagem retornada pela SEFAZ.
- `ultNSU`: ultimo NSU retornado pela SEFAZ, quando existir.
- `maxNSU`: maximo NSU retornado pela SEFAZ, quando existir.
- `modo`: `chave`, `numNSU` ou `ultNSU`.
- `chave`: chave consultada, quando usada.
- `numNSU`: NSU especifico consultado, quando usado.
- `fonte`: fonte usada na consulta.
- `paths`: oculto por padrao.
- `raw`: oculto por padrao.

## Interpretacao de status

### cStat 137

Indica que nenhum documento foi localizado.

Nesta fase, esse retorno e tratado como sucesso tecnico:

```text
status: OK
ok: true
```

### cStat 656

Indica consumo indevido.

Quando ocorrer, aguarde 1 hora antes de nova consulta para evitar novas rejeicoes pela SEFAZ.

Esse retorno e tratado como:

```text
status: REJEITADO
ok: false
```

### status OK

Indica que a chamada foi processada tecnicamente e retornou um `cStat` aceito como sucesso pelo endpoint.

Isso nao significa que documentos foram processados, extraidos ou gravados em banco.

### status REJEITADO

Indica rejeicao tratada pelo endpoint, como `cStat 656`.

O XML bruto pode ter sido salvo em `storage/xml/retornos`, mas `paths` fica oculto por padrao.

### status ERRO

Indica falha tecnica no preparo, validacao, execucao ou tratamento da consulta.

Quando `raw` estiver oculto, detalhes tecnicos nao sao expostos no JSON por padrao.

## Limitacoes desta fase

- Nao ha descompactacao de `docZip`.
- Nao ha processamento automatico de notas.
- Nao ha controle automatico de NSU.
- Nao ha manifestacao do destinatario.
- Nao ha banco.
- Nao ha cron.
- Nao ha fila.
