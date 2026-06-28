# Distribuicao DF-e - Fase 2 - Planejamento

## 1. Objetivo da Fase 2

A Fase 2 tem como objetivo planejar o controle de NSU, historico de consultas e prevencao de consumo indevido para o endpoint seguro de Distribuicao DF-e.

A Fase 1 ja disponibiliza o endpoint `public/v1/nfe/dfe_padrao.php`, que consulta `sefazDistDFe`, salva o XML bruto do retorno em `storage/xml/retornos` e devolve JSON padronizado.

Nesta fase, o foco e definir a estrutura tecnica necessaria antes de implementar persistencia e regras de controle.

## 2. Fora do escopo

Esta fase nao deve:

- implementar PHP funcional;
- criar SQL real ou migration;
- criar banco;
- criar cron;
- criar fila;
- alterar endpoints existentes;
- refatorar `NfeService`;
- alterar emissao, consulta, cancelamento, CC-e, inutilizacao ou DANFE;
- implementar manifestacao do destinatario;
- descompactar `docZip`;
- processar documentos retornados automaticamente.

## Decisoes apos mapeamento tecnico

- O projeto atualmente nao possui dependencia de banco, ORM, PDO, mysqli, pg_connect, SQL ou migrations identificadas.
- Portanto, a Fase 2 nao deve implementar persistencia sem antes definir o padrao de banco do projeto.
- O controle de NSU deve usar sempre o CNPJ efetivo da configuracao carregada por `config/nfe.php`, ja considerando sobrescrita de `config/nfe.local.php`.
- O CNPJ usado como chave logica nao deve vir do payload da requisicao.
- A chave logica planejada continua sendo: CNPJ efetivo + `tpAmb` + `fonte`.

## 3. Tabela proposta: dfe_controle_nsu

Tabela para armazenar o estado atual da distribuicao por CNPJ, ambiente e fonte.

Campos minimos propostos:

- `id`
- `cnpj`
- `tp_amb`
- `fonte`
- `ult_nsu`
- `max_nsu`
- `ultimo_cstat`
- `ultimo_xmotivo`
- `bloqueado_ate`
- `ultima_consulta_em`
- `created_at`
- `updated_at`

Chave unica planejada:

- `cnpj`
- `tp_amb`
- `fonte`

Finalidade:

- saber qual foi o ultimo NSU consultado;
- evitar consultas repetidas sem necessidade;
- controlar bloqueios temporarios apos retornos sensiveis;
- separar corretamente homologacao/producao e fontes como `AN` ou `RS`.

## 4. Tabela proposta: dfe_consulta_historico

Tabela para registrar cada tentativa de consulta DF-e.

Campos minimos propostos:

- `id`
- `cnpj`
- `tp_amb`
- `fonte`
- `modo`
- `chave`
- `num_nsu`
- `ult_nsu_enviado`
- `ult_nsu_retorno`
- `max_nsu_retorno`
- `cstat`
- `xmotivo`
- `status`
- `ok`
- `xml_path`
- `bloqueada_localmente`
- `erro_tecnico`
- `created_at`

Finalidade:

- auditar consultas realizadas;
- registrar chamadas bloqueadas localmente;
- manter rastreabilidade do XML bruto salvo;
- diagnosticar rejeicoes, erros tecnicos e consumo indevido.

## 5. Tabela futura opcional: dfe_doczip_indice

Tabela opcional para fase posterior, caso seja necessario indexar `docZip` sem ainda processar documentos completos.

Campos minimos propostos:

- `id`
- `consulta_id`
- `nsu`
- `schema`
- `chave`
- `doczip_presente`
- `created_at`

Finalidade:

- mapear quais retornos continham `docZip`;
- evitar duplicidade futura por NSU ou chave;
- preparar terreno para extracao posterior dos documentos.

Esta tabela nao implica descompactacao nem processamento automatico nesta fase.

## 6. Fluxo tecnico planejado

1. Receber requisicao no endpoint DF-e.
2. Validar token HTTP com `X-NFE-API-TOKEN`.
3. Validar entrada: `ultNSU`, `numNSU`, `chave` e `fonte`.
4. Identificar o contexto fiscal efetivo a partir da configuracao: CNPJ, ambiente e fonte.
5. Consultar `dfe_controle_nsu` para esse contexto.
6. Verificar se existe bloqueio ativo em `bloqueado_ate`.
7. Se houver bloqueio ativo, nao chamar a SEFAZ e gravar historico como bloqueado localmente.
8. Se nao houver bloqueio, definir o modo da consulta: `chave`, `numNSU` ou `ultNSU`.
9. Chamar `sefazDistDFe`.
10. Salvar XML bruto em `storage/xml/retornos`.
11. Extrair `cStat`, `xMotivo`, `ultNSU` e `maxNSU`.
12. Atualizar `dfe_controle_nsu` quando houver retorno valido.
13. Aplicar regras de bloqueio conforme `cStat`.
14. Registrar a tentativa em `dfe_consulta_historico`.
15. Retornar JSON no mesmo contrato do endpoint atual.

## 7. Regras para cStat 137 e 656

### cStat 137

Significa que nenhum documento foi localizado.

Regra planejada:

- tratar como sucesso tecnico;
- registrar historico;
- atualizar `ultima_consulta_em`;
- manter ou atualizar `ult_nsu` e `max_nsu` quando retornados;
- aplicar intervalo minimo de 1 hora antes de nova consulta para o mesmo CNPJ, ambiente e fonte.

### cStat 656

Significa consumo indevido.

Regra planejada:

- tratar como rejeicao;
- registrar historico;
- definir `bloqueado_ate` com bloqueio minimo de 1 hora apos o retorno;
- impedir nova chamada SEFAZ enquanto o bloqueio estiver ativo;
- responder ao integrador sem tentar nova consulta automaticamente.

## 8. Riscos de concorrencia e duplicidade

Riscos principais:

- duas requisicoes simultaneas podem consultar o mesmo `ultNSU`;
- chamadas repetidas podem gerar `cStat 656`;
- consulta manual por `numNSU` ou `chave` pode retornar documento ja visto;
- futuramente, o mesmo `docZip` pode ser encontrado por caminhos diferentes;
- CNPJ, ambiente ou fonte incorretos podem misturar controles que deveriam ser separados.

Mitigacoes planejadas:

- chave unica por `cnpj`, `tp_amb` e `fonte`;
- atualizacao transacional do controle de NSU;
- bloqueio local por `bloqueado_ate`;
- historico completo de tentativas;
- futura deduplicacao por NSU e chave antes de processar documentos.

## 9. Proximos passos antes da implementacao

- confirmar qual banco sera usado para persistencia;
- confirmar padrao de migrations do projeto, se houver;
- definir nomes finais de tabelas e colunas;
- confirmar/adotar politica operacional de minimo 1 hora apos `cStat 137`;
- confirmar/adotar bloqueio minimo de 1 hora apos `cStat 656`;
- decidir se o endpoint manual atual deve apenas consultar ou tambem atualizar controle de NSU;
- validar como obter o CNPJ efetivo usado pelo certificado/configuracao;
- planejar testes de concorrencia antes de habilitar uso operacional;
- manter manifestacao do destinatario para fase posterior.
