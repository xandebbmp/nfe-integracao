# Banco Fiscal Proprio - Planejamento

## 1. Objetivo do banco fiscal proprio

O banco fiscal proprio do NfePHP tem como objetivo armazenar dados operacionais do servico fiscal de forma independente dos sistemas consumidores.

Ele deve apoiar:

- controle fiscal por empresa;
- historico de consultas;
- rastreabilidade de requisicoes;
- controle de NSU da Distribuicao DF-e;
- armazenamento de metadados de documentos fiscais;
- registro de eventos fiscais;
- auditoria das integracoes por API.

Este documento e apenas um planejamento. Nenhum SQL, migration ou codigo funcional e criado nesta etapa.

## 2. Por que o banco deve ser separado dos consumidores

O NfePHP sera uma API/servico fiscal independente. Por isso, seu banco nao deve ser o banco do ERP, do FuraFila ou de qualquer outro consumidor.

Motivos:

- evitar acoplamento ao SQL Server do ERP;
- evitar acoplamento ao PostgreSQL de projetos especificos, como FuraFila;
- permitir que varios sistemas consumam o mesmo servico fiscal por HTTP/API;
- preservar autonomia de evolucao do dominio fiscal;
- centralizar certificados, XMLs, eventos, controles e historicos fiscais;
- reduzir impacto de mudancas nos bancos dos consumidores;
- facilitar auditoria e suporte operacional em um ponto unico.

Os sistemas consumidores devem enviar e receber dados por API HTTP. Eles nao devem depender da estrutura interna do banco fiscal.

## 3. PostgreSQL como banco planejado

PostgreSQL e o banco principal planejado para o servico fiscal NfePHP.

Motivos:

- bom suporte a transacoes;
- bom suporte a concorrencia;
- indices maduros para consultas historicas;
- flexibilidade para metadados fiscais;
- independencia em relacao ao SQL Server do ERP;
- alinhamento com a ideia de servico fiscal proprio;
- boa base para controle de NSU e historico de Distribuicao DF-e.

Esta escolha nao cria banco agora. Ela apenas define a direcao tecnica planejada.

## Decisao sobre modelagem fisica

A arquitetura conceitual do banco fiscal esta sendo planejada neste documento.

A modelagem fisica do banco, incluindo nomes finais de tabelas e colunas, tipos de dados, chaves primarias, constraints, indices, enums, campos de auditoria, schemas e padrao de migrations, so sera definida apos o usuario informar os padroes oficiais de banco do projeto.

## 4. Conceito de multiempresa/multicliente

O banco fiscal deve ser planejado para atender mais de uma empresa fiscal e mais de um sistema consumidor.

Conceitos:

- empresa fiscal: entidade emissora ou interessada em documentos fiscais, identificada por CNPJ/CPF e configuracao fiscal;
- consumidor de API: sistema que chama o NfePHP, como ERP, FuraFila ou outros projetos;
- ambiente fiscal: homologacao ou producao;
- fonte: origem do servico consultado, como `AN` ou `RS` no caso da Distribuicao DF-e.

O controle fiscal nao deve confiar em CNPJ vindo do payload como chave de controle. A chave fiscal deve partir da configuracao efetiva do servico, certificado e contexto operacional validado.

## 5. Tabelas fiscais iniciais planejadas

### empresas_fiscais

Representa as empresas atendidas pelo servico fiscal.

Campos conceituais:

- identificador interno;
- CNPJ/CPF;
- razao social;
- inscricao estadual;
- UF;
- ambiente padrao;
- status;
- datas de criacao e atualizacao.

### certificados_fiscais

Representa certificados usados pelo servico fiscal.

Campos conceituais:

- identificador interno;
- empresa fiscal;
- caminho ou referencia segura do certificado;
- validade inicial;
- validade final;
- status;
- metadados de auditoria.

Nao deve expor senha de certificado em claro.

### dfe_controle_nsu

Controla o estado da Distribuicao DF-e por empresa, ambiente e fonte.

Campos conceituais:

- empresa fiscal;
- CNPJ efetivo;
- ambiente;
- fonte;
- ultimo NSU;
- maximo NSU;
- ultimo `cStat`;
- ultimo `xMotivo`;
- bloqueado ate;
- ultima consulta em;
- datas de criacao e atualizacao.

Chave logica planejada:

- CNPJ efetivo;
- ambiente;
- fonte.

### dfe_consulta_historico

Registra tentativas de consulta DF-e.

Campos conceituais:

- empresa fiscal;
- consumidor da API;
- modo da consulta;
- chave consultada;
- NSU consultado;
- ultimo NSU enviado;
- ultimo NSU retornado;
- maximo NSU retornado;
- `cStat`;
- `xMotivo`;
- status;
- indicador de sucesso;
- caminho do XML bruto;
- indicador de bloqueio local;
- indicador de erro tecnico;
- data da consulta.

### documentos_fiscais

Planejada para metadados de documentos fiscais conhecidos pelo servico.

Campos conceituais:

- empresa fiscal;
- tipo de documento;
- chave fiscal;
- numero;
- serie;
- data de emissao;
- CNPJ/CPF do emitente;
- CNPJ/CPF do destinatario;
- status fiscal;
- caminho do XML;
- origem;
- datas de criacao e atualizacao.

Esta tabela nao implica processamento automatico nesta fase.

### eventos_fiscais

Planejada para eventos vinculados a documentos fiscais.

Campos conceituais:

- documento fiscal;
- tipo de evento;
- sequencia;
- protocolo;
- `cStat`;
- `xMotivo`;
- caminho do XML do evento;
- data do evento;
- datas de criacao e atualizacao.

### api_consumidores

Representa sistemas autorizados a consumir a API fiscal.

Campos conceituais:

- nome do consumidor;
- identificador externo;
- status;
- escopos permitidos;
- data de criacao;
- data de atualizacao;
- ultimo uso.

Tokens e segredos devem ser armazenados de forma segura, nunca em claro.

### api_requisicoes_log

Registra chamadas feitas pelos consumidores da API.

Campos conceituais:

- consumidor da API;
- endpoint;
- metodo HTTP;
- status HTTP;
- status fiscal retornado;
- CNPJ/empresa fiscal associada;
- identificador de correlacao;
- IP de origem;
- data da requisicao;
- duracao;
- resumo de erro, quando houver.

Nao deve registrar token real nem dados sensiveis desnecessarios.

## 6. Relacao com a Fase 2 do DF-e

A Fase 2 do DF-e precisa futuramente de controle de NSU e historico.

As tabelas mais diretamente relacionadas sao:

- `dfe_controle_nsu`;
- `dfe_consulta_historico`;
- futuramente, `documentos_fiscais`;
- futuramente, `eventos_fiscais`.

O controle de NSU deve impedir consultas repetidas desnecessarias e reduzir risco de `cStat 656 - Consumo Indevido`.

A Fase 2 deve continuar sem manifestacao do destinatario, sem cron e sem fila ate que essas etapas sejam planejadas separadamente.

## 7. Regras de auditoria e rastreabilidade

Regras planejadas:

- toda consulta fiscal relevante deve gerar historico;
- chamadas bloqueadas localmente tambem devem ser registradas;
- XML bruto deve manter caminho rastreavel;
- tokens reais nao devem aparecer em logs;
- erros tecnicos devem ser registrados sem expor segredos;
- operacoes devem ser vinculadas a empresa fiscal e consumidor de API quando possivel;
- ambiente de homologacao e producao devem ser claramente separados;
- CNPJ efetivo e fonte devem compor a rastreabilidade da Distribuicao DF-e.

## 8. O que ainda nao sera implementado agora

Este planejamento nao implementa:

- PHP;
- SQL;
- migrations;
- criacao de banco;
- conexao PostgreSQL;
- ORM;
- novas dependencias;
- alteracoes em endpoints;
- cron;
- fila;
- manifestacao do destinatario;
- processamento automatico de documentos;
- integracao direta com bancos consumidores.

## 9. Proximos passos antes de criar SQL real

- confirmar o padrao de conexao com PostgreSQL;
- definir se o projeto usara migrations e qual ferramenta;
- confirmar padrao de armazenamento seguro de certificados e segredos;
- validar nomes finais de tabelas e colunas;
- definir estrategia multiempresa;
- definir modelo de consumidores da API e escopos;
- definir politica de auditoria e retencao de logs;
- definir indices e chaves unicas;
- revisar o fluxo da Fase 2 do DF-e antes de criar schema real.
