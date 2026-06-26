# Distribuicao DF-e - Fase 1

## Resumo

A Fase 1 criou um wrapper seguro para consulta manual de Distribuicao DF-e no projeto NfePHP.

Endpoint criado:

```text
public/v1/nfe/dfe_padrao.php
```

Commit validado:

```text
2cf0256 feat: adiciona endpoint seguro de distribuicao DFe
```

Push realizado para:

```text
main
```

## Objetivo da fase

Disponibilizar uma entrada HTTP segura para consultar Distribuicao DF-e usando a dependencia NFePHP ja instalada, sem implementar processamento automatico dos documentos retornados.

## Contrato do endpoint

Endpoint:

```text
/v1/nfe/dfe_padrao.php
```

Seguranca:

```text
X-NFE-API-TOKEN
```

Implementacao:

- Usa `Xande\NfeIntegracao\NfeService`.
- Chama diretamente `sefazDistDFe`.
- Aceita `GET`, `POST JSON` e `POST form-data`.
- Salva o XML bruto do retorno em `storage/xml/retornos`.
- Oculta `raw` e `paths` por padrao.

Entradas aceitas:

- `ultNSU`
- `numNSU`
- `chave`
- `fonte`, com padrao `AN`

Prioridade de consulta:

1. `chave`
2. `numNSU`
3. `ultNSU`

## Limites da Fase 1

Esta fase nao:

- descompacta `docZip`;
- processa documentos retornados;
- cria banco;
- cria cron;
- cria fila;
- implementa manifestacao do destinatario.

## Testes realizados

- Consulta real com `ultNSU=0` retornou `cStat 137 - Nenhum documento localizado`.
- XML bruto foi salvo em `storage/xml/retornos`.
- Teste sem token retornou `Unauthorized`.
- Metodo invalido retornou `Metodo nao permitido`.
- Chave invalida foi bloqueada.
- Fonte invalida foi bloqueada.
- `ultNSU` negativo foi bloqueado.
- `numNSU` invalido foi bloqueado.
- `cStat 656 - Consumo Indevido` foi tratado como `status: REJEITADO` e `ok: false`.

## Proxima fase sugerida

- controle de NSU;
- controle de intervalo de consulta;
- historico de consultas;
- leitura de `docZip`;
- extracao futura dos documentos;
- manifestacao do destinatario somente em fase posterior.
