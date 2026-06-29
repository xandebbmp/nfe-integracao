# DF-e Fase 2 - Modelo lógico de persistência

## Objetivo

Definir o modelo lógico mínimo de persistência da Fase 2 do DF-e antes da implementação física em banco.

Nesta fase, o objetivo é preparar a persistência para:

- controle sequencial de NSU;
- bloqueio local conservador;
- histórico operacional/fiscal das consultas DF-e.

A implementação física em banco será feita depois da aprovação deste modelo lógico.

---

## Padrão de banco aprovado

O banco fiscal do NfePHP seguirá como base os padrões oficiais usados no projeto FuraFila, com adaptação fiscal:

- PostgreSQL;
- tabelas no plural;
- chave primária `id BIGSERIAL PRIMARY KEY`;
- chaves estrangeiras no padrão `id_tabela`;
- sem `ON DELETE CASCADE`;
- status/controladores preferencialmente por ENUM PostgreSQL;
- tabelas derivadas no padrão `tabela_principal_sufixo`;
- VIEWs apenas quando houver necessidade real de consulta, grid ou dashboard.

O SQL Server do ERP será consumidor da API HTTP, não base fiscal.

---

## Entidades da Fase 2

A Fase 2 terá inicialmente três estruturas lógicas:

1. `dfe_controles`
2. `dfe_bloqueios`
3. `dfe_historicos`

A estrutura `dfe_documentos` não entra nesta fase.

Ela ficará para fase futura, quando houver descompactação e processamento de `docZip`.

---

## 1. dfe_controles

### Finalidade

Controlar o avanço sequencial do DF-e por contexto fiscal.

### Contexto fiscal

O controle é separado por:

- CNPJ efetivo;
- ambiente fiscal;
- fonte DF-e.

### Campos mínimos

- `id`
- `cnpj`
- `tp_amb`
- `fonte`
- `ult_nsu`
- `max_nsu`
- `criado_em`
- `atualizado_em`

### Regra de unicidade

Deve existir apenas um controle por:

- `cnpj`
- `tp_amb`
- `fonte`

### Regras de atualização

O controle sequencial de NSU só avança no modo `ultNSU`.

O modo `chave` não atualiza controle sequencial.

O modo `numNSU` não atualiza controle sequencial.

Quando o retorno for `cStat 137`, atualizar `ult_nsu` e `max_nsu` se esses valores vierem no retorno.

Quando o retorno for `cStat 138`, atualizar `ult_nsu` e `max_nsu`.

Quando o retorno for `cStat 656`, não atualizar NSU.

Erro técnico não atualiza NSU.

---

## 2. dfe_bloqueios

### Finalidade

Registrar bloqueios locais para impedir chamadas desnecessárias à SEFAZ e evitar consumo indevido.

### Contexto fiscal

O bloqueio é separado por:

- CNPJ efetivo;
- ambiente fiscal;
- fonte DF-e.

### Campos mínimos

- `id`
- `cnpj`
- `tp_amb`
- `fonte`
- `cstat_origem`
- `motivo_origem`
- `bloqueado_ate`
- `criado_em`

### Regra de bloqueio ativo

Um bloqueio é considerado ativo quando:

- `bloqueado_ate` for maior que a data/hora atual.

### Regras de duração

Após retorno `cStat 137`, bloquear por 1 hora.

Após retorno `cStat 656`, bloquear por 2 horas.

Erro técnico local antes de chamar a SEFAZ não gera bloqueio fiscal.

### Resposta segura para bloqueio local

Quando houver bloqueio ativo, o endpoint deve retornar:

- HTTP 200;
- `ok=false`;
- `status=BLOQUEADO_LOCALMENTE`;
- `cStat=null`;
- `paths=[]`;
- `raw=null`.

Nenhuma chamada à SEFAZ deve ser feita quando houver bloqueio local ativo.

---

## 3. dfe_historicos

### Finalidade

Registrar a rastreabilidade operacional/fiscal das consultas DF-e depois que a entrada estiver válida e o contexto fiscal for identificado.

### Campos mínimos

- `id`
- `cnpj`
- `tp_amb`
- `fonte`
- `modo`
- `chave`
- `num_nsu`
- `ult_nsu_enviado`
- `cstat`
- `x_motivo`
- `status_operacional`
- `houve_chamada_sefaz`
- `criado_em`

### Modos esperados

- `chave`
- `numNSU`
- `ultNSU`

### Status operacionais esperados

- `ENVIADO_SEFAZ`
- `BLOQUEADO_LOCALMENTE`
- `ERRO_TECNICO_ANTES_SEFAZ`
- `ERRO_APOS_TENTATIVA_SEFAZ`

### Regra de início do histórico

O histórico fiscal futuro deve começar apenas depois que:

- a entrada estiver válida;
- o contexto fiscal estiver identificado.

### Devem ser registrados futuramente

- chamada enviada à SEFAZ;
- bloqueio local;
- erro técnico antes da chamada SEFAZ;
- erro após tentativa SEFAZ.

### Não são obrigatórios nesta fase

- token inválido;
- método inválido;
- parâmetro inválido;
- JSON quebrado.

---

## Fluxo operacional futuro aprovado

1. Validar token, método e entrada.
2. Carregar configuração efetiva.
3. Identificar contexto fiscal: CNPJ efetivo + `tpAmb` + `fonte`.
4. Identificar modo: `chave`, `numNSU` ou `ultNSU`.
5. Consultar bloqueio local.
6. Se bloqueado, registrar histórico e retornar `BLOQUEADO_LOCALMENTE`.
7. Se liberado, chamar `sefazDistDFe`.
8. Salvar XML bruto.
9. Extrair `cStat`, `xMotivo`, `ultNSU` e `maxNSU`.
10. Registrar histórico.
11. Atualizar controle NSU quando aplicável.
12. Aplicar ou atualizar bloqueio quando aplicável.
13. Retornar JSON seguro.

---

## Fora do escopo desta fase

Nesta fase, não implementar:

- descompactação de `docZip`;
- processamento de documentos;
- manifestação do destinatário;
- tabela `dfe_documentos`;
- cron;
- fila;
- retry automático;
- integração direta com sistemas consumidores;
- SQL Server como banco fiscal;
- alteração nos endpoints de emissão, consulta, cancelamento, CC-e, inutilização ou DANFE.
