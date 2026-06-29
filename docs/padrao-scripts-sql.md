# Padrão de scripts SQL

## Objetivo

Definir o padrão oficial de nomenclatura e organização dos scripts SQL do NfePHP.

Este padrão existe para garantir:

- ordem correta de execução;
- rastreabilidade de implantação;
- correções versionadas;
- futuras adequações sem sobrescrever histórico;
- revisão simples pelo Git.

---

## Diretório oficial

Os scripts SQL devem ficar em:

```text
database/scripts/
```

---

## Formato do nome

```text
NNNN_modulo_acao_descricao.sql
```

Onde:

- `NNNN` é a ordem global de execução, com 4 dígitos;
- `modulo` é a área fiscal ou técnica afetada;
- `acao` é o tipo da mudança;
- `descricao` é curta, objetiva, sem acento, em minúsculo e separada por `_`.

---

## Exemplos

```text
0001_dfe_criar_base_fase_02.sql
0002_dfe_criar_indices_fase_02.sql
0003_dfe_ajustar_bloqueios_consumo_indevido.sql
```

---

## Módulos esperados

Exemplos de módulos:

- `base`
- `seguranca`
- `nfe`
- `dfe`
- `nfse`
- `nfce`
- `cte`
- `mdfe`
- `dcte`

---

## Ações esperadas

Exemplos de ações:

- `criar`
- `alterar`
- `ajustar`
- `corrigir`
- `popular`
- `remover`

---

## Regras obrigatórias

- Não reutilizar número.
- Não renomear script já executado em ambiente real.
- Não editar script já aplicado em produção.
- Correção futura deve entrar como novo script.
- Não usar acentos.
- Não usar espaços.
- Não usar data no nome do arquivo.
- Manter scripts pequenos e focados.
- Um script pode conter várias tabelas quando fizer parte do mesmo bloco aprovado.

---

## Primeiro script previsto

Para a Fase 2 do DF-e, o primeiro script previsto será:

```text
database/scripts/0001_dfe_criar_base_fase_02.sql
```
