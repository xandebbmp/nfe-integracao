# Decisao Arquitetural - Servico Fiscal Independente

## 1. Visao do projeto

O projeto NfePHP deve evoluir de uma integracao focada em NF-e e Distribuicao DF-e para um servico/API fiscal independente, reutilizavel por diferentes sistemas da empresa.

A ideia central e concentrar regras, comunicacao com SEFAZ/prefeituras, certificados, XMLs, eventos, historicos fiscais e controles operacionais em uma camada fiscal propria.

## 2. Decisao arquitetural

O NfePHP sera planejado como um servico fiscal independente, acessado por API HTTP.

Sistemas consumidores nao devem acessar diretamente o banco interno do NfePHP. A integracao deve ocorrer por endpoints HTTP seguros e contratos bem definidos.

O NfePHP deve ter persistencia propria para guardar controles fiscais, historicos, estados de processamento, XMLs indexados e demais dados operacionais que pertencem ao dominio fiscal.

## 3. Motivos para nao acoplar ao banco do ERP

O ERP da empresa usa SQL Server, mas a integracao fiscal nao deve ficar presa ao banco do ERP.

Motivos:

- evitar dependencia direta do modelo interno do ERP;
- permitir que outros sistemas usem o mesmo servico fiscal;
- reduzir acoplamento entre regras fiscais e regras comerciais;
- evitar que mudancas no ERP afetem a camada fiscal;
- permitir evolucao independente do NfePHP;
- centralizar certificados, XMLs, eventos e controles fiscais em um unico dominio;
- facilitar auditoria e rastreabilidade fiscal independente do sistema consumidor.

## 4. Motivos para escolher PostgreSQL como banco planejado

PostgreSQL sera o banco principal planejado para o servico fiscal.

Motivos:

- e uma opcao robusta para persistencia propria do servico;
- oferece bom suporte a transacoes, indices e concorrencia;
- permite modelar historicos fiscais e controles de NSU com seguranca;
- combina bem com servicos independentes;
- evita dependencia direta do SQL Server do ERP;
- tambem evita acoplamento ao PostgreSQL de projetos especificos, como FuraFila.

O PostgreSQL planejado para o NfePHP deve ser do proprio servico fiscal, nao o banco de outro sistema consumidor.

## 5. Papel dos sistemas consumidores

Sistemas consumidores devem chamar o NfePHP por API HTTP.

Exemplos de consumidores:

- ERP com SQL Server;
- projetos internos com PostgreSQL, como FuraFila;
- outros sistemas futuros que precisem emitir, consultar ou acompanhar documentos fiscais.

Esses sistemas devem enviar requisicoes ao NfePHP e receber respostas padronizadas. Eles nao devem depender da estrutura interna do banco fiscal.

## 6. Escopo inicial: NF-e e DF-e

O escopo inicial permanece em:

- NF-e;
- eventos relacionados a NF-e;
- consulta de NF-e;
- DANFE;
- Distribuicao DF-e;
- controles futuros de NSU e historico de consultas DF-e.

A Fase 1 da Distribuicao DF-e ja criou um endpoint seguro para consulta manual e salvamento do XML bruto de retorno.

## 7. Expansao futura

O desenho como servico fiscal permite expansao futura para outras obrigacoes fiscais, como:

- NFSe;
- NFCe;
- CTe;
- MDFe;
- DCTe;
- outros documentos, eventos e obrigacoes fiscais que sejam necessarios.

Cada nova obrigacao deve ser incorporada por contrato de API e persistencia fiscal propria, sem exigir acesso direto ao banco dos sistemas consumidores.

## 8. Impacto na Fase 2 do DF-e

A Fase 2 do DF-e deve considerar que o controle de NSU, historico de consultas e prevencao de consumo indevido pertencem ao dominio do servico fiscal.

Consequencias:

- o controle de NSU deve ser persistido no banco proprio planejado para o NfePHP;
- a chave logica deve usar o CNPJ efetivo da configuracao fiscal, ambiente e fonte;
- o CNPJ de controle nao deve vir do payload do sistema consumidor;
- o historico de consultas deve ser interno ao servico fiscal;
- sistemas externos devem apenas solicitar consultas e receber respostas por API.

Antes de implementar persistencia na Fase 2, o padrao de banco do servico fiscal deve ser definido.

## 9. O que ainda nao sera implementado agora

Esta decisao nao implementa:

- PHP novo;
- SQL;
- migrations;
- banco PostgreSQL;
- endpoints novos;
- alteracoes em endpoints existentes;
- dependencias novas;
- processamento automatico de documentos;
- fila;
- cron;
- integracao direta com banco do ERP;
- integracao direta com banco de outros projetos;
- manifestacao do destinatario.

Este documento registra apenas a direcao arquitetural planejada.
