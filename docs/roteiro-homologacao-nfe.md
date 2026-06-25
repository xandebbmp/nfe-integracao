# Roteiro de Homologacao NF-e

Data do teste: 25/06/2026

## Ambiente validado

- Projeto: NfePHP
- Ambiente: homologacao
- UF: BA
- Token HTTP: X-NFE-API-TOKEN
- Certificado local: config/nfe.local.php
- Certificado usado: storage/certs/07047183000140_openssl3.pfx

## Resultado geral

Fluxo de homologacao validado com sucesso:

1. Status SEFAZ
2. Emissao NF-e
3. Consulta NF-e autorizada
4. Geracao de XML autorizado
5. Geracao de DANFE
6. Envio de CC-e
7. Cancelamento
8. Consulta da NF-e cancelada

## Chave usada no teste

29260607047183000140550010009990261457451227

## Protocolo

129261000114151

## 1. Status SEFAZ

Endpoint:

/v1/nfe/status_padrao.php

Resultado:

cStat 107
xMotivo: Servico em Operacao

## 2. Emissao NF-e

Endpoint:

/v1/nfe/emitir_padrao.php

Comando usado:

curl -X POST 'http://localhost:8000/v1/nfe/emitir_padrao.php' \
  -H 'Content-Type: application/json' \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  --data-binary '@/Users/xande/Documents/NfePHP/nfe-integracao/json/nota_homologacao_emitir_padrao.json'

Resultado:

cStat 100
xMotivo: Autorizado o uso da NF-e

## 3. Consulta da NF-e

Endpoint:

/v1/nfe/consultar_padrao.php?chave=CHAVE

Comando usado:

curl -i \
  -H "X-NFE-API-TOKEN: $NFE_TOKEN" \
  "http://localhost:8000/v1/nfe/consultar_padrao.php?chave=$CHAVE_NFE"

Resultado apos emissao:

cStat 100
xMotivo: Autorizado o uso da NF-e

## 4. XMLs gerados

Arquivos encontrados:

storage/xml/autorizados/NFe-29260607047183000140550010009990261457451227-procNFe.xml
storage/xml/assinados/NFe-29260607047183000140550010009990261457451227.xml
storage/xml/gerados/NFe-29260607047183000140550010009990261457451227.xml
storage/xml/retornos/ret-consulta-29260607047183000140550010009990261457451227.xml

## 5. DANFE

Endpoint:

/danfe.php?chave=CHAVE

Resultado:

DANFE gerado com sucesso em PDF.

## 6. CC-e

Endpoint:

/v1/nfe/cce_padrao.php

Payload usado:

{
  "chave": "29260607047183000140550010009990261457451227",
  "texto": "Correcao de teste em ambiente de homologacao",
  "seq": 1
}

Resultado final:

cStat 135
xMotivo: Evento registrado e vinculado a NF-e

Arquivo gerado:

storage/xml/eventos/cce/cce-29260607047183000140550010009990261457451227-001-procEvento.xml

Observacao:

A CC-e falhou inicialmente com cStat 213 porque o CNPJ da configuracao estava diferente do CNPJ da NF-e/certificado.

Correcao feita no config/nfe.local.php:

cnpj = 07047183000140

## 7. Cancelamento

Endpoint:

/v1/nfe/cancelar_padrao.php

Payload usado:

{
  "chave": "29260607047183000140550010009990261457451227",
  "protocolo": "129261000114151",
  "just": "Cancelamento de teste em ambiente de homologacao"
}

Resultado:

cStat 135
xMotivo: Evento registrado e vinculado a NF-e

Arquivo gerado:

storage/xml/eventos/canc/canc-29260607047183000140550010009990261457451227-procEvento.xml

## 8. Consulta apos cancelamento

Resultado:

cStat 101
xMotivo: Cancelamento de NF-e homologado

Status final da nota:

CANCELADA

## Regras importantes

- Usar preferencialmente os wrappers em public/v1/nfe.
- Nao usar scripts diretos public/status.php, public/consultar.php, public/cancelar.php, public/cce.php como API externa.
- config/nfe.local.php nao deve ir para o Git.
- Certificados nao devem ir para o Git.
- XMLs nao devem ir para o Git.
- PDFs nao devem ir para o Git.
- Logs nao devem ir para o Git.

## Proximo passo

Depois desse roteiro validado, o proximo trabalho deve ser mapear Distribuicao DF-e sem implementar.
