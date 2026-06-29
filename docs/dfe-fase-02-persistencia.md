# DF-e Fase 2 - Persistencia inicial

## 1. Objetivo da persistencia DF-e Fase 2

A persistencia inicial da Fase 2 do DF-e prepara a base minima para controle operacional das consultas de Distribuicao DF-e.

Objetivos desta etapa:

- controlar o avanço sequencial de NSU;
- registrar bloqueios locais para evitar consumo indevido;
- manter historico operacional/fiscal das tentativas de consulta;
- preservar rastreabilidade do comportamento do serviço fiscal.

Ainda esta fora do escopo:

- alterar endpoints;
- chamar a SEFAZ com persistencia integrada ao fluxo HTTP;
- processar `docZip`;
- manifestar destinatario;
- criar tabela `dfe_documentos`;
- criar cron, fila ou retry automatico;
- executar em producao.

## 2. Banco fiscal local

O banco DEV local configurado para esta etapa e:

```text
nfephp_dev
```

O usuario da aplicacao configurado localmente e:

```text
nfephp_app
```

Esse usuario representa o acesso limitado da aplicacao ao banco fiscal. A aplicacao nao deve depender de usuario administrador para executar consultas e persistencias operacionais.

Motivos para nao usar usuario administrador:

- reduzir risco operacional;
- limitar o escopo de permissao da aplicacao;
- separar administracao de banco da execucao normal do serviço;
- facilitar auditoria e manutencao.

A configuracao local usada pela aplicacao fica em:

```text
config/database.local.php
```

Esse arquivo deve ficar fora do Git por conter dados locais e segredo de acesso.

O exemplo versionado fica em:

```text
config/database.local.php.example
```

Documentacoes e logs nao devem expor senha real.

## 3. Script SQL criado

Script da base inicial:

```text
database/scripts/0001_dfe_criar_base_fase_02.sql
```

O script segue o padrao oficial descrito em `docs/padrao-scripts-sql.md`:

```text
NNNN_modulo_acao_descricao.sql
```

Neste caso:

- `0001`: ordem global de execucao;
- `dfe`: modulo fiscal;
- `criar`: acao;
- `base_fase_02`: descricao curta.

Escopo do script:

- cria ENUMs operacionais;
- cria `dfe_controles`;
- cria `dfe_bloqueios`;
- cria `dfe_historicos`;
- cria constraints minimas de integridade;
- cria indices basicos para consulta operacional.

Fora do escopo do script:

- execucao automatica;
- conexao/repository;
- endpoint HTTP;
- descompactacao de `docZip`;
- `dfe_documentos`;
- manifestacao do destinatario;
- cron, fila ou retry automatico;
- campos especificos de RTC.

O script usa `BEGIN`, define `search_path` para `public` e finaliza com `COMMIT`.

## 4. ENUMs

### dfe_modo_consulta_enum

Define os modos operacionais aceitos para consulta DF-e.

Valores:

| Valor | Funcao |
| --- | --- |
| `chave` | Consulta pontual por chave fiscal. |
| `numNSU` | Consulta pontual/reconsulta por NSU especifico. |
| `ultNSU` | Consulta sequencial a partir do ultimo NSU. |

### dfe_status_operacional_enum

Define o estado operacional da tentativa registrada no historico.

Valores:

| Valor | Funcao |
| --- | --- |
| `ENVIADO_SEFAZ` | A chamada foi enviada para a SEFAZ. |
| `BLOQUEADO_LOCALMENTE` | A chamada nao foi enviada porque havia bloqueio local ativo. |
| `ERRO_TECNICO_ANTES_SEFAZ` | Ocorreu erro tecnico antes de chamar a SEFAZ. |
| `ERRO_APOS_TENTATIVA_SEFAZ` | Houve tentativa de chamada e erro depois dela. |

## 5. Tabela dfe_controles

### Finalidade

`dfe_controles` armazena o estado sequencial de NSU por contexto fiscal.

O contexto fiscal e:

```text
cnpj + tp_amb + fonte
```

### Regra de unicidade

A constraint `dfe_controles_contexto_uk` garante apenas um controle por:

- `cnpj`;
- `tp_amb`;
- `fonte`.

Essa unicidade e usada pelo `DfeControlStore` nos comandos com `ON CONFLICT (cnpj, tp_amb, fonte)`.

### Quando o NSU pode avançar

O controle sequencial so deve avançar no modo `ultNSU`.

Casos em que pode atualizar:

- `cStat 137`, se `ultNSU` e `maxNSU` vierem no retorno;
- `cStat 138`, atualizando `ultNSU` e `maxNSU`.

### Quando o NSU nao pode avançar

Nao deve atualizar controle sequencial quando:

- modo for `chave`;
- modo for `numNSU`;
- retorno for `cStat 656`;
- ocorrer erro tecnico.

### Campos

| Campo | Finalidade |
| --- | --- |
| `id` | Chave primaria `BIGSERIAL`. |
| `cnpj` | CNPJ efetivo do contexto fiscal, como texto de 14 posicoes. |
| `tp_amb` | Ambiente fiscal: `1` producao, `2` homologacao. |
| `fonte` | Fonte DF-e, inicialmente `AN` ou `RS`. |
| `ult_nsu` | Ultimo NSU conhecido para consulta sequencial. |
| `max_nsu` | Maior NSU informado pela SEFAZ. |
| `criado_em` | Data/hora de criacao do registro. |
| `atualizado_em` | Data/hora da ultima atualizacao. |

### Constraints

| Constraint | Motivo |
| --- | --- |
| `dfe_controles_cnpj_chk` | Garante CNPJ alfanumerico de 14 posicoes (`^[A-Z0-9]{14}$`). |
| `dfe_controles_tp_amb_chk` | Restringe ambiente a `1` ou `2`. |
| `dfe_controles_fonte_chk` | Restringe fonte a `AN` ou `RS`. |
| `dfe_controles_nsu_chk` | Impede NSU negativo. |
| `dfe_controles_contexto_uk` | Garante um registro por contexto fiscal. |

### Indices

| Indice | Finalidade |
| --- | --- |
| `dfe_controles_contexto_idx` | Consulta rapida por CNPJ, ambiente e fonte. |

## 6. Tabela dfe_bloqueios

### Finalidade

`dfe_bloqueios` registra bloqueios locais para evitar chamadas desnecessarias a SEFAZ e reduzir risco de consumo indevido.

### Regra de bloqueio ativo

Um bloqueio esta ativo quando:

```sql
bloqueado_ate > CURRENT_TIMESTAMP
```

### Regras operacionais

- Apos `cStat 137`, bloquear por 1 hora.
- Apos `cStat 656`, bloquear por 2 horas.
- Erro tecnico local antes de chamar a SEFAZ nao gera bloqueio fiscal.
- Havendo bloqueio ativo, nenhuma chamada a SEFAZ deve ser feita.

### Campos

| Campo | Finalidade |
| --- | --- |
| `id` | Chave primaria `BIGSERIAL`. |
| `cnpj` | CNPJ efetivo do contexto fiscal. |
| `tp_amb` | Ambiente fiscal. |
| `fonte` | Fonte DF-e. |
| `cstat_origem` | `cStat` que originou o bloqueio. |
| `motivo_origem` | Motivo textual do retorno que originou o bloqueio. |
| `bloqueado_ate` | Data/hora ate quando o bloqueio deve ser considerado ativo. |
| `criado_em` | Data/hora de criacao do bloqueio. |

### Constraints

| Constraint | Motivo |
| --- | --- |
| `dfe_bloqueios_cnpj_chk` | Garante CNPJ alfanumerico de 14 posicoes. |
| `dfe_bloqueios_tp_amb_chk` | Restringe ambiente a `1` ou `2`. |
| `dfe_bloqueios_fonte_chk` | Restringe fonte a `AN` ou `RS`. |
| `dfe_bloqueios_cstat_origem_chk` | Permite apenas `137` ou `656` como origem de bloqueio. |
| `dfe_bloqueios_bloqueado_ate_chk` | Garante que `bloqueado_ate` seja posterior a `criado_em`. |

### Indices

| Indice | Finalidade |
| --- | --- |
| `dfe_bloqueios_contexto_idx` | Consulta por contexto fiscal. |
| `dfe_bloqueios_ativos_idx` | Busca eficiente de bloqueios ativos por contexto e data. |

## 7. Tabela dfe_historicos

### Finalidade

`dfe_historicos` registra a rastreabilidade operacional/fiscal das tentativas DF-e.

### Quando o historico começa

O historico fiscal futuro deve começar apenas depois que:

- a entrada estiver valida;
- o contexto fiscal estiver identificado.

### O que deve registrar

- chamada enviada a SEFAZ;
- bloqueio local;
- erro tecnico antes da chamada SEFAZ;
- erro apos tentativa SEFAZ.

### O que nao precisa registrar nesta fase

- token invalido;
- metodo invalido;
- parametro invalido;
- JSON quebrado.

Isso evita poluir o historico fiscal com requisicoes que nem chegaram a formar uma tentativa fiscal valida.

### Campos

| Campo | Finalidade |
| --- | --- |
| `id` | Chave primaria `BIGSERIAL`. |
| `cnpj` | CNPJ efetivo do contexto fiscal. |
| `tp_amb` | Ambiente fiscal. |
| `fonte` | Fonte DF-e. |
| `modo` | Modo da consulta: `chave`, `numNSU` ou `ultNSU`. |
| `chave` | Chave fiscal consultada no modo `chave`. |
| `num_nsu` | NSU consultado no modo `numNSU`. |
| `ult_nsu_enviado` | Ultimo NSU enviado no modo `ultNSU`. |
| `cstat` | Codigo de retorno fiscal, quando houver. |
| `x_motivo` | Motivo textual de retorno, quando houver. |
| `status_operacional` | Estado operacional da tentativa. |
| `houve_chamada_sefaz` | Indica se a chamada chegou a ser enviada a SEFAZ. |
| `criado_em` | Data/hora do registro historico. |

### Constraints

| Constraint | Motivo |
| --- | --- |
| `dfe_historicos_cnpj_chk` | Garante CNPJ alfanumerico de 14 posicoes. |
| `dfe_historicos_tp_amb_chk` | Restringe ambiente a `1` ou `2`. |
| `dfe_historicos_fonte_chk` | Restringe fonte a `AN` ou `RS`. |
| `dfe_historicos_chave_chk` | Garante chave fiscal alfanumerica de 44 posicoes quando informada. |
| `dfe_historicos_num_nsu_chk` | Impede `num_nsu` negativo. |
| `dfe_historicos_ult_nsu_enviado_chk` | Impede `ult_nsu_enviado` negativo. |
| `dfe_historicos_cstat_chk` | Garante `cStat` com 3 digitos quando informado. |
| `dfe_historicos_modo_dados_chk` | Garante coerencia entre `modo` e campos (`chave`, `num_nsu`, `ult_nsu_enviado`). |
| `dfe_historicos_chamada_sefaz_chk` | Garante coerencia entre `status_operacional` e `houve_chamada_sefaz`. |
| `dfe_historicos_enviado_sefaz_cstat_chk` | Exige `cstat` quando o status for `ENVIADO_SEFAZ`. |

### Coerencia entre modo e campos

- `modo = chave`: exige `chave`, sem `num_nsu` e sem `ult_nsu_enviado`.
- `modo = numNSU`: exige `num_nsu`, sem `chave` e sem `ult_nsu_enviado`.
- `modo = ultNSU`: exige `ult_nsu_enviado`, sem `chave` e sem `num_nsu`.

### Coerencia entre status_operacional e houve_chamada_sefaz

- `ENVIADO_SEFAZ`: `houve_chamada_sefaz = TRUE`.
- `ERRO_APOS_TENTATIVA_SEFAZ`: `houve_chamada_sefaz = TRUE`.
- `BLOQUEADO_LOCALMENTE`: `houve_chamada_sefaz = FALSE`.
- `ERRO_TECNICO_ANTES_SEFAZ`: `houve_chamada_sefaz = FALSE`.

### Indices

| Indice | Finalidade |
| --- | --- |
| `dfe_historicos_contexto_criado_idx` | Consulta historica por contexto, ordenada por data. |
| `dfe_historicos_modo_idx` | Filtro por modo de consulta. |
| `dfe_historicos_cstat_idx` | Filtro por codigo fiscal de retorno. |
| `dfe_historicos_status_operacional_idx` | Filtro por status operacional. |

## 8. Compatibilidade fiscal 2026+

Decisoes aplicadas ou preservadas nesta etapa:

- CNPJ deve ser texto alfanumerico de 14 posicoes.
- CNPJ nao deve ser tratado como numero.
- Chave fiscal deve ser texto de 44 posicoes.
- Identificadores fiscais devem preservar zeros a esquerda e tamanho oficial.
- RTC fica fora desta fase.
- Campos de IBS, CBS e IS nao entram na Fase 2 DF-e.
- XML bruto continua importante como fonte fiscal integral para evolucao futura.

O script fisico usa `VARCHAR(14)` para CNPJ e regex `^[A-Z0-9]{14}$`, alinhado com a preparacao para CNPJ alfanumerico.

## 9. ConnectionFactory

Arquivo:

```text
src/Database/ConnectionFactory.php
```

Finalidade:

- carregar a configuracao local do banco fiscal;
- criar uma conexao PDO com PostgreSQL;
- configurar erros por excecao;
- configurar fetch associativo por padrao;
- desativar prepared statements emulados;
- definir `client_encoding` como `UTF8`.

A configuracao lida e:

```text
config/database.local.php
```

Se a configuracao local nao existir, a classe gera erro claro orientando a criar o arquivo a partir de:

```text
config/database.local.php.example
```

A senha real deve ficar fora do Git e nao deve ser reproduzida em documentacao.

## 10. DfeControlStore

Arquivo:

```text
src/DFe/DfeControlStore.php
```

Finalidade:

- encapsular as operacoes iniciais de persistencia DF-e;
- usar uma conexao `PDO` recebida no construtor;
- nao criar conexao propria;
- manter consultas simples, diretas e testaveis.

### Metodos

| Metodo | Funcao |
| --- | --- |
| `buscarBloqueioAtivo` | Busca bloqueio ativo para CNPJ, ambiente e fonte usando `bloqueado_ate > CURRENT_TIMESTAMP`. |
| `registrarBloqueio` | Insere bloqueio local com `cstat_origem`, motivo e data final de bloqueio. |
| `buscarOuCriarControle` | Cria controle NSU inicial se nao existir e retorna o registro. |
| `atualizarControleNsu` | Atualiza ou cria controle para `ult_nsu` e `max_nsu`. |
| `registrarHistorico` | Insere registro em `dfe_historicos` com os dados operacionais da tentativa. |

Todos os metodos usam prepared statements.

NSU e aceito como `string` nos metodos para evitar perda de precisao na aplicacao.

`buscarOuCriarControle` e `atualizarControleNsu` dependem da constraint unica:

```text
dfe_controles_contexto_uk
```

Essa constraint permite usar:

```text
ON CONFLICT (cnpj, tp_amb, fonte)
```

## 11. Validacoes ja realizadas

Validações registradas nesta etapa:

- script SQL executado com sucesso em PostgreSQL DEV;
- objetos criados: ENUMs, `dfe_controles`, `dfe_bloqueios`, `dfe_historicos`, constraints e indices;
- inserts validos testados com `ROLLBACK`;
- constraints negativas testadas;
- `DfeControlStore` testada contra banco DEV com `ROLLBACK`;
- nenhum dado de teste foi mantido.

## 12. Fora do escopo atual

Nao faz parte desta etapa:

- alteracao em endpoint;
- chamada SEFAZ com persistencia;
- processamento de `docZip`;
- manifestacao do destinatario;
- tabela `dfe_documentos`;
- cron;
- fila;
- retry automatico;
- execucao em producao.
