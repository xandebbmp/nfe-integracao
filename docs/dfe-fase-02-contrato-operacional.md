# Distribuicao DF-e - Fase 2 - Contrato Operacional

## 1. Objetivo do contrato operacional

Este documento registra o contrato operacional aprovado para a Fase 2 da Distribuicao DF-e.

A Fase 2 deve preparar o controle futuro de NSU, historico e prevencao de consumo indevido, sem implementar banco, SQL, migrations, cron, fila, manifestacao do destinatario ou processamento automatico de documentos.

## 2. Consulta por chave

Modo:

```text
chave
```

Regras:

- consulta pontual;
- usa a chave informada no payload;
- nao usa `ultNSU` salvo;
- nao atualiza controle sequencial de NSU automaticamente;
- nao avanca fluxo sequencial de distribuicao.

## 3. Consulta por numNSU

Modo:

```text
numNSU
```

Regras:

- consulta pontual/reconsulta;
- usa o `numNSU` informado no payload;
- nao usa `ultNSU` salvo;
- nao avanca controle sequencial automaticamente;
- nao deve ser tratada como consumo sequencial normal.

## 4. Consulta por ultNSU

Modo:

```text
ultNSU
```

Regras:

- consulta sequencial;
- se o payload trouxer `ultNSU`, usa o valor informado;
- futuramente, se o payload nao trouxer `ultNSU`, usa o `ultNSU` salvo no controle;
- este e o modo planejado para evoluir para controle sequencial de NSU.

## 5. Regra apos cStat 137

Significado:

```text
Nenhum documento localizado
```

Resposta planejada:

```text
ok = true
status = OK
```

Regra operacional:

- registrar historico da consulta;
- manter ou atualizar dados de `ultNSU` e `maxNSU` quando retornados;
- aplicar bloqueio minimo de 1 hora para o mesmo CNPJ efetivo + `tpAmb` + `fonte`;
- evitar nova consulta imediata para o mesmo contexto fiscal.

## 6. Regra apos cStat 656

Significado:

```text
Consumo indevido
```

Resposta planejada:

```text
ok = false
status = REJEITADO
```

Regra operacional:

- registrar historico da consulta;
- aplicar bloqueio minimo de 1 hora;
- nao realizar nova tentativa automatica;
- orientar o consumidor a aguardar antes de nova consulta;
- proteger o servico contra repeticao de chamadas que possam agravar o consumo indevido.

## 7. Bloqueio local futuro

O bloqueio local do DF-e protege o cliente/certificado e deve ser aplicado de forma conservadora.

O bloqueio local deve ser calculado por:

```text
CNPJ efetivo + tpAmb + fonte
```

O CNPJ efetivo deve vir da configuracao fiscal carregada, nao do payload.

Quando houver bloqueio local ativo, o endpoint futuro nao deve chamar a SEFAZ. O bloqueio vale para qualquer modo DF-e:

- `chave`;
- `numNSU`;
- `ultNSU`.

Regras de tempo:

- apos `cStat 137`, bloquear por 1 hora;
- apos `cStat 656`, bloquear por 2 horas.

Erros:

- erro tecnico local antes de chamar a SEFAZ nao gera bloqueio fiscal;
- erro apos tentativa na SEFAZ deve ser registrado futuramente, mas sem retry automatico.

Resposta HTTP planejada:

```text
HTTP 200
```

O erro operacional deve ficar no JSON.

Resposta segura planejada:

```text
ok = false
status = BLOQUEADO_LOCALMENTE
cStat = null
xMotivo = mensagem explicando bloqueio para evitar consumo indevido
paths = []
raw = null
```

Regras:

- a resposta deve preservar o formato JSON operacional do endpoint sempre que possivel;
- a tentativa bloqueada localmente deve ser registrada em historico quando houver persistencia.

## 8. Atualizacao do controle sequencial de NSU

O controle sequencial de NSU so deve avancar no modo:

```text
ultNSU
```

Regras:

- modo `chave` nao atualiza controle sequencial;
- modo `numNSU` nao atualiza controle sequencial;
- `cStat 137` atualiza `ultNSU` e `maxNSU` se esses valores vierem no retorno;
- `cStat 138` atualiza `ultNSU` e `maxNSU`;
- `cStat 656` nao atualiza NSU, apenas define bloqueio;
- erro tecnico nao atualiza NSU.

## 9. Historico futuro de tentativas DF-e

O historico fiscal futuro deve comecar apenas depois que a entrada estiver valida e o contexto fiscal for identificado.

Devem registrar historico:

- chamada enviada a SEFAZ;
- bloqueio local;
- erro tecnico antes da chamada SEFAZ;
- erro apos tentativa SEFAZ.

Nao sao obrigatorios no historico fiscal nesta fase:

- validacoes invalidas de entrada;
- token invalido;
- metodo invalido;
- JSON quebrado.

Essa decisao evita poluir o historico fiscal com requisicoes que nem chegaram a formar uma tentativa fiscal valida.

## 10. Ordem futura do fluxo operacional DF-e

Ordem aprovada:

1. validar token/metodo/entrada;
2. carregar config efetiva;
3. identificar contexto fiscal: CNPJ efetivo + `tpAmb` + `fonte`;
4. identificar modo: `chave` | `numNSU` | `ultNSU`;
5. consultar bloqueio local;
6. se bloqueado: registrar historico e retornar `BLOQUEADO_LOCALMENTE`;
7. se liberado: chamar `sefazDistDFe`;
8. salvar XML bruto;
9. extrair `cStat`/`xMotivo`/`ultNSU`/`maxNSU`;
10. registrar historico;
11. atualizar controle NSU quando aplicavel;
12. aplicar/atualizar bloqueio quando aplicavel;
13. retornar JSON.

## 11. Divisao futura de responsabilidades

`public/v1/nfe/dfe_padrao.php` continua sendo o endpoint HTTP.

Responsabilidades do endpoint:

- validar token;
- validar metodo;
- validar entrada;
- montar resposta JSON.

`src/DFe/DfeConsultaService.php` sera planejado para concentrar a regra operacional do DF-e.

Responsabilidades planejadas do `DfeConsultaService`:

- resolver o modo da consulta;
- identificar o contexto fiscal;
- avaliar bloqueio local;
- chamar `sefazDistDFe`;
- extrair dados do retorno;
- decidir atualizacao de NSU;
- decidir aplicacao ou atualizacao de bloqueio.

`src/DFe/DfeControlStore.php` sera planejado como contrato futuro de persistencia.

Responsabilidades planejadas do `DfeControlStore`:

- buscar bloqueio;
- registrar historico;
- salvar controle NSU;
- atualizar bloqueio.

A implementacao real com banco so sera feita depois que o usuario informar os padroes oficiais de banco.

## 12. Compatibilidade com endpoint atual

O endpoint atual `public/v1/nfe/dfe_padrao.php` ja retorna JSON padronizado com dados dentro de `item`.

Na Fase 2, o contrato JSON atual deve ser mantido sempre que possivel, incluindo campos como:

- `ok`;
- `status`;
- `cStat`;
- `xMotivo`;
- `ultNSU`;
- `maxNSU`;
- `modo`;
- `chave`;
- `numNSU`;
- `fonte`;
- `message`;
- `paths`;
- `raw`.

A Fase 2 nao deve alterar emissao, consulta, cancelamento, CC-e, inutilizacao ou DANFE.

## 13. Fora do escopo

Esta fase nao inclui:

- banco;
- SQL;
- migrations;
- manifestacao do destinatario;
- cron;
- fila;
- descompactacao de `docZip`;
- processamento automatico de documentos;
- alteracoes em endpoints fiscais nao relacionados ao DF-e.

Este documento e apenas contrato operacional e nao implementa funcionalidade.
