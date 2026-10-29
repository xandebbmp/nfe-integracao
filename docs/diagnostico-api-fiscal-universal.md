# Diagnóstico técnico — API Fiscal PHP Universal

Data: 29/10/2026 · Branch analisada: `claude/analise-emissor-fiscal-ozjcdz` (não integrada à `main`)

Legenda usada em todo o documento:

- **[F] Fato observado** no código, nos commits ou em evidência registrada no repositório.
- **[R] Requisito definido** no Prompt Mestre.
- **[Rec] Recomendação técnica** (proposta, depende de aprovação).
- **[NC] Não comprovado**: hipótese ou informação de fonte secundária que precisa de verificação.

Situação de cada capacidade: **Adequada** · **Com ajustes** · **Ausente** · **Pendente de comprovação**.

> **Transparência sobre a branch.** Antes do Prompt Mestre, nesta mesma branch, foram implementados:
> endurecimento de segurança, multiempresa com cofre de certificados, numeração no banco,
> idempotência por `idIntegracao` e fila assíncrona com worker (commits `4ef8955` a `babaccd`,
> scripts SQL `0002` a `0004`). O Prompt Mestre pede justificativa antes de criar tabelas e endpoints.
> Esses itens aparecem aqui como **implementados na branch, testados localmente, não homologados**,
> e a sua manutenção é a decisão D2 (seção 12). Nada foi integrado à `main`.

> **Limitação desta análise.** Os portais oficiais (`www.gov.br`, `nfse.sefaz.salvador.ba.gov.br`)
> estão bloqueados pela política de rede da sessão. Tudo o que depende deles está marcado [NC].

---

## 1. Resumo executivo

- **[F]** Projeto em **PHP puro, sem framework**. São scripts procedurais em `public/` e algumas
  classes em `src/` (PSR-4 `Xande\NfeIntegracao`). As dependências são `nfephp-org/sped-nfe` 5.2.3
  (já existe a 5.2.9), `sped-da` 1.1.6 e `sped-common` 5.1.16. O `composer.json` não declara a versão
  mínima do PHP; o código exige pelo menos 8.0, e o ambiente de teste usou 8.3.
- **[F]** A **NF-e modelo 55 em homologação na BA tem evidência de ponta a ponta** em
  `docs/roteiro-homologacao-nfe.md`: status 107, autorização 100, consulta 100, CC-e 135,
  cancelamento 135 e consulta pós-cancelamento 101. Essa evidência é **anterior** às mudanças desta
  branch.
- **[F]** A cobertura tributária da NF-e é **mínima**. ICMS só usa os campos do regime normal
  (CST 00), sem CSOSN, e não há ST, DIFAL, FCP, IPI, transporte detalhado, cobrança, documento
  referenciado nem contingência. **Não é possível hoje emitir NF-e de empresa do Simples Nacional.**
- **[F]** **NFS-e Nacional e NFS-e Salvador: inexistentes.** Não há código, contrato nem dependência.
- **[F]** **Não há testes automatizados.**
- **[F]** A principal dívida estrutural é que **scripts chamam scripts** via `exec` ou `include`,
  e o resultado é extraído **do texto impresso** com regex. Não há camada de domínio.
- **[F]** Na branch já existem: autenticação que recusa por padrão, multiempresa, numeração atômica,
  idempotência, fila com worker e duplicidade 204 recuperada pela consulta. Isso cobre parte das
  seções 4, 7 e 8 do prompt, mas com **um único token para todas as empresas** (viola as seções 4
  e 11) e **reenvio antes da consulta** em caso de incerteza (diverge da seção 8).
- **[Rec]** **Evoluir, não reescrever.** O caminho proposto:
  1. introduzir um núcleo de domínio (solicitação fiscal, estados e histórico, políticas) com portas;
  2. transformar os scripts atuais em um **adaptador NF-e** baseado em classes;
  3. criar os adaptadores **NFS-e Nacional** e **Salvador** atrás da mesma porta;
  4. dar prioridade à **NFS-e Nacional**, porque o primeiro caso de uso (Fura Fila, serviços de
     saúde) é NFS-e e a obrigatoriedade para ME/EPP do Simples começa em 01/11/2026.

---

## 2. Inventário das funcionalidades realmente existentes

### 2.1 NF-e

| Capacidade | Onde | Situação | Evidência |
|---|---|---|---|
| Status do serviço | `public/status.php`, `v1/nfe/status_padrao.php` | Adequada | [F] roteiro: cStat 107 |
| Montagem do XML a partir de JSON | `public/emitir_json_pl010.php` | Com ajustes | [F] só 20 tags de `Make`; totais vêm do cliente; valores em `float` |
| Assinatura, XSD e envio síncrono | `public/assinar_enviar_sincrono.php` | Com ajustes | [F] roteiro: cStat 100; na branch: 204 recuperado pela consulta e XSD vira `REJEITADO` |
| Emissão via HTTP (síncrona) | `v1/nfe/emitir_padrao.php`, `public/api.php` | Com ajustes | [F] faz `exec` do lote e lê a saída em texto; dois contratos diferentes |
| Emissão em lote via CLI | `public/lote_emitir.php` | Com ajustes | [F] sempre termina com código 0; resultado só em texto |
| Consulta por chave | `public/consultar.php`, `v1/nfe/consultar_padrao.php` | Adequada | [F] roteiro: cStat 100 e 101 |
| Cancelamento (110111) | `public/cancelar.php`, `v1/nfe/cancelar_padrao.php` | Com ajustes | [F] roteiro: 135; wrapper troca `$_GET` e faz `include` |
| CC-e (110110) | `public/cce.php`, `v1/nfe/cce_padrao.php` | Com ajustes | [F] roteiro: 135 (após corrigir divergência de CNPJ) |
| Inutilização | `public/inutilizar.php`, `v1/nfe/inutilizar_padrao.php` | Pendente de comprovação | [F] tenta 3 assinaturas de `sefazInutiliza`; sem evidência de homologação |
| DANFE em PDF | `public/danfe.php`, `emitir_padrao.php` | Adequada | [F] roteiro, seção 5 |
| PDF de evento | `public/evento.php`, `NfeService::gerarPdfEvento` | Pendente de comprovação | sem evidência registrada |
| Distribuição DF-e | `v1/nfe/dfe_padrao.php` | Com ajustes | [F] só salva o XML bruto; não abre o `docZip`; `DfeControlStore` existe mas não está ligado |
| Manifestação do destinatário | — | Ausente | |
| Contingência (SVC-AN/RS, EPEC) | — | Ausente | |
| Devolução e complementar (`NFref`) | — | Ausente | [F] `finNFe` é repassado, mas `tagrefNFe` nunca é chamado |
| CSOSN (Simples), ICMS-ST, DIFAL, FCP, IPI, CEST | — | Ausente | [F] a biblioteca oferece `tagICMSSN`, `tagICMSST`, `tagICMSUFDest`, `tagIPI` e `tagCEST`, mas não são usados |
| Transporte (transportadora, volumes), cobrança (`fat`/`dup`), `infRespTec`, intermediador | — | Ausente | [F] idem |
| IBS/CBS/IS | `emitir_json_pl010.php` | Com ajustes | [F] grupos básicos; ver seção 7 |

### 2.2 Plataforma (implementado na branch, testado localmente, não homologado)

| Capacidade | Onde | Situação |
|---|---|---|
| Autenticação que recusa por padrão (503 sem token, 401 com token inválido) | `src/Support/HttpSecurity.php` | Com ajustes: token único, sem escopo por consumidor ou empresa |
| Multiempresa (`X-NFE-EMPRESA`) e cofre de certificados (libsodium) | `src/Empresa/*`, `0002` | Com ajustes: não há "estabelecimento" nem "consumidor" como entidades |
| Numeração atômica por empresa e série | `src/Nfe/DocumentoStore.php`, `0003` | Adequada (teste com 10 emissões concorrentes) |
| Idempotência por `idIntegracao` e hash do conteúdo | `emitir_json_pl010.php`, `FilaStore` | Com ajustes: escopo não inclui o consumidor |
| cNF determinístico (mesma nota, mesma chave) | `src/Support/ChaveAcesso.php` | Adequada; homologação pendente |
| Fila e worker (`POST`/`GET /v1/nfe/lotes`) | `src/Fila/*`, `bin/worker.php`, `0004` | Com ajustes: reenvia antes de consultar; status sobrescrito, sem histórico |
| Separação por CNPJ do `storage` | `EmpresaContexto` | Adequada |
| Guia da VPS e unit do systemd | `docs/deploy-vps.md`, `deploy/` | Adequada |

### 2.3 Ausentes na plataforma

Webhook ou eventos; consulta e listagem de documentos (`GET /documentos`); central de pendências;
histórico de estados imutável; auditoria de ações; rate limiting; permissões por operação
(emitir, cancelar, consultar); registro de versão de leiaute e política por documento; registro
de artefatos com hash; reconciliação periódica com a SEFAZ; testes automatizados.

### 2.4 NFS-e

| Capacidade | Situação |
|---|---|
| NFS-e Nacional: DPS, emissão, consulta, eventos, DANFSe | Ausente |
| Distribuição do ADN (tomadas e recebidas) | Ausente |
| NFS-e Salvador (webservice municipal) | Ausente |

---

## 3. Matriz de aderência aos requisitos

| Seção do prompt | Requisito | Situação | Observação |
|---|---|---|---|
| 2.1 | DDD | Ausente | [F] não há entidades nem VOs de domínio; regras ficam nos scripts |
| 2.2 | Hexagonal | Ausente | [F] infraestrutura (NFePHP, PDO, arquivos) misturada com HTTP |
| 2.3 | Captura na origem e menos retrabalho | Com ajustes | [F] o cliente precisa montar JSON no formato das tags da NF-e |
| 2.4 | CQRS seletivo | Ausente | consulta de documentos não existe |
| 2.5 | SOLID e testabilidade | Ausente | [F] sem testes; funções duplicadas em vários arquivos |
| 2.6 | Idempotência e resiliência | Com ajustes | [F] branch: idempotência e 204; [R] exige consultar antes de reenviar |
| 2.7 | Segurança por padrão | Com ajustes | [F] recusa por padrão e cofre; falta escopo por consumidor e auditoria |
| 2.8 | Evolução regulatória sem reescrever o núcleo | Ausente | [F] regras e leiaute embutidos nos scripts |
| 3.1 | NF-e 55 completa | Com ajustes | ver seção 4 |
| 3.2 | NFS-e Nacional | Ausente | ver seção 5 |
| 3.3 | NFS-e Salvador e seleção do emissor por política | Ausente | ver seção 6 |
| 4 | Multiempresa e separação de identidades | Com ajustes | [F] empresa sim; consumidor, estabelecimento e titular financeiro não |
| 5 | Integração com o Financeiro por ACL | Ausente | [F] o contrato atual é o formato NFePHP, sem camada anticorrupção |
| 6 | Fura Fila (serviços profissionais) | Ausente | sem contrato de serviço, profissional ou pacote |
| 7 | Contrato canônico, idempotência validada no servidor | Ausente / Com ajustes | [F] `idIntegracao` existe; o contrato não é canônico |
| 7.1 | Compatibilidade com PlugNotas e Node.js | Pendente de comprovação | formato do PlugNotas usado hoje não está no repositório |
| 8 | Ciclo de vida e histórico auditável | Com ajustes | [F] estados existem, mas são sobrescritos; sem "pendente de intervenção" |
| 9 | Central de pendências | Ausente | |
| 10 | Artefatos, versões, webhook assinado | Ausente / Com ajustes | [F] arquivos em disco por CNPJ, sem hash, sem índice, sem webhook |
| 11 | Segurança e LGPD | Com ajustes | [F] logs gravam XML e saída completos; sem rate limit nem auditoria |
| 12 | Reforma tributária por vigência | Com ajustes | ver seção 7 |
| 15 | Testes | Ausente | |

---

## 4. Lacunas para NF-e modelo 55

### 4.1 Críticas para emissão segura

1. **[R]/[F] Consultar antes de reenviar.** Hoje, um envio com resultado incerto (`ERRO_ENVIO`)
   é **reenviado com o mesmo XML** e a recuperação depende de a SEFAZ responder 204. O prompt exige
   **consultar a chave antes** de tentar de novo. **[Rec]** No estado "resultado indeterminado",
   consultar a chave; só reenviar se a SEFAZ responder que a nota não existe (217).
2. **[F] CSOSN ausente.** Emitentes CRT 1 ou 4 não conseguem emitir NF-e.
3. **[F] Totais vêm do cliente.** `ICMSTot` não é recalculado; ICMS, PIS e COFINS usam `float`.
   **[Rec]** Calcular os totais no servidor a partir dos itens e usar decimal em texto (bcmath).
4. **[F] Classificação de retornos por texto.** **[Rec]** Ter um dicionário de cStat por serviço
   que distinga:
   - autorizada (100, 150);
   - denegada (110, 205, 301, 302, 303);
   - rejeição definitiva (por exemplo, 853, tratada como erro de validação da operação, sem tocar
     no financeiro);
   - falha transitória (108, 109, 999, timeout);
   - duplicidade (204, 539).
5. **[NC] `infRespTec`.** Não sei se a SEFAZ-BA exige o grupo do responsável técnico. Verificar
   na NT vigente da BA.
6. **[F] Biblioteca desatualizada.** `sped-nfe` 5.2.3 e a 5.2.9 já existe. **[NC]** O conteúdo das
   versões intermediárias (NT e correções do PL_010) não foi verificado: a página de releases não
   lista essas versões. **[Rec]** Atualizar com testes de regressão de XSD.
7. **[F] Sem contingência.** Indisponibilidade da SEFAZ-BA vira apenas nova tentativa.
   **[Rec]** SVC-AN/SVC-RS como estado e política explícitos, autorizados por empresa.

### 4.2 Conformidade e completude

- ICMS-ST, DIFAL (`ICMSUFDest`), FCP, IPI, CEST, benefício fiscal: a biblioteca suporta; falta o contrato.
- Transporte (transportadora, veículo, volumes), cobrança (`fat`/`dup`), intermediador.
- Devolução e complementar com `NFref`; `impostoDevol`.
- Manifestação do destinatário e distribuição DF-e completa (abrir `docZip`, NSU persistido, regra 656).
- Cancelamento fora do prazo e regras de prazo por UF: **[NC]**.

---

## 5. Lacunas para NFS-e Nacional

**[F]** Nada existe. Componentes necessários:

| Componente | Observação |
|---|---|
| Montagem e validação da DPS conforme o XSD vigente | **[NC]** versão do leiaute e XSD atuais (portal oficial inacessível nesta sessão) |
| Assinatura XMLDSig e autenticação mTLS com certificado ICP-Brasil | reaproveita o cofre de certificados da branch |
| Transmissão ao **Emissor Público Nacional (Sefin Nacional)** | **[NC]** fontes secundárias citam REST com DPS em gzip+base64 e `POST /nfse`; confirmar no manual oficial |
| Consulta por chave da NFS-e e por identificador da DPS | essencial para o "consultar antes de reenviar" |
| Eventos: cancelamento, substituição e outros disponíveis | **[NC]** códigos e regras |
| DANFSe | **[NC]** uma biblioteca da comunidade registra a API oficial de DANFSe como suspensa (NT 008/2026); confirmar e prever geração local |
| Parâmetros municipais (alíquotas, códigos, convênio) | **[NC]** API oficial de parâmetros municipais |
| Códigos de serviço: cTribNac, NBS, código municipal | sem dado hoje; sem enquadramento, deve virar **pendência** em vez de valor inventado |
| Regime: Simples Nacional, MEI, regime normal | `empresas.crt` existe; falta o perfil tributário de serviço |
| IBS/CBS na DPS | **[R]** efeitos para o Simples a partir de 01/01/2027; tratamento por vigência |
| Distinção entre **Emissor Nacional** e **ADN** | **[R]** adaptadores separados: emissão (Sefin) e distribuição/consulta de terceiros (ADN). Compartilhar com o ADN não é emitir |
| Biblioteca | **[F]** não há biblioteca oficial NFePHP para NFS-e Nacional; **[NC]** bibliotecas da comunidade precisam de avaliação |

---

## 6. Lacunas para NFS-e Salvador

**[F]** Nada existe. Pontos a esclarecer antes de implementar:

- **[NC]** O único manual localizado em `nota.salvador.ba.gov.br` é o **ABRASF 1.0 (2008)**:
  lote de RPS assíncrono, protocolo e consulta posterior. Versão atual, URLs de homologação e
  produção e regras locais (código de serviço, retenção, exigibilidade) **não verificadas**.
- **[NC]** Fontes secundárias indicam que Salvador **não aderiu ao Emissor Nacional**, mas
  **compartilha as notas com o ADN** (IN 08/25). Se isso se confirmar, prestadores fora do Simples
  continuam no canal municipal.
- **[R]** O canal não pode ser escolhido só pelo município: depende de regime, data de vigência,
  habilitação e política aprovada. Ver o seletor na seção 9.
- Necessário: inscrição municipal por estabelecimento (a coluna `empresas.im` existe), credencial
  e habilitação do webservice municipal (o que o contribuinte precisa: **[NC]**), e regra de
  conversão de RPS em NFS-e com consulta por RPS antes de reenviar.

---

## 7. Riscos e adequações da Reforma Tributária

| Item | Estado | Risco e recomendação |
|---|---|---|
| Grupos IBS/CBS na NF-e (`gIBSUF`, `gIBSMun`, `gCBS`) e IS | [F] básicos; total recalculado com bcmath | não cobre redução, diferimento, crédito presumido, monofásico (`tagIBSCBSMono`), tributação regular (`tagIBSCBSTribRegular`), compras governamentais (`taggCompraGov`) |
| `cClassTrib` / `CSTIS` | [F] repassados do cliente sem validação | **[Rec]** validar contra a tabela vigente; sem enquadramento, pendência |
| Regras e alíquotas | [F] não há motor; valores vêm prontos do cliente | **[Rec]** perfil tributário por empresa, operação e vigência, versionado; nada fixo em controller |
| Registro de versões | [F] o schema vai no payload, mas não no documento | **[Rec]** gravar por documento: versão do contrato, leiaute/XSD, versão da biblioteca e da política |
| Simples Nacional | [R] CBS/IBS para o Simples a partir de 01/01/2027 | regra por data de vigência |
| CNPJ alfanumérico | [F] branch preserva letras; o banco aceita `[A-Z0-9]{14}` | **[NC]** aceitação pela NFePHP e pelos XSD atuais; **[Rec]** teste dedicado |
| Exemplo do repositório | [F] `json/lote/001.json` tinha CST "00" no IBS/CBS (corrigido na branch) | mostra que o cliente consegue mandar valores inválidos: validar no servidor |

---

## 8. Revisão da arquitetura e dos contratos de integração

**Fatos [F]:**

1. **Três contratos HTTP diferentes**, cada um com formato de resposta próprio:
   - `public/api.php` (`/nfe/emitir`);
   - `v1/nfe/*_padrao` (síncronos);
   - `v1/nfe/lotes` (assíncrono, na branch).
2. **O contrato de entrada é o formato das tags da NFePHP** (`ide`, `emit`, `ICMSTot`...). O
   leiaute fiscal vaza para o consumidor e não há contrato canônico nem ACL.
3. **Encadeamento por processo e texto.** `emitir_padrao`, `lote_emitir`, `emitir_json` e
   `assinar_enviar` conversam por `exec` e regex sobre a saída; cancelamento e CC-e usam `include`
   trocando superglobais. A fila da branch reduz o problema (o status vem do banco), mas ainda
   executa os scripts.
4. **Identidade.** Um token global dá acesso a todas as empresas. O consumidor não existe como
   entidade, e não há escopo por operação.
5. **Estados sobrescritos.** `nfe_documentos.status` e `emissao_fila.status` são atualizados no
   lugar, sem histórico.
6. **Logs e artefatos.** XML e saída completa vão para disco e para `emissao_fila.saida`. Não há
   política de mascaramento (LGPD) nem hash dos artefatos.
7. **Pontos positivos:** `idIntegracao` já existe e é compatível com a API Node.js intermediária
   e com o hábito do PlugNotas; PostgreSQL próprio; convenção de scripts SQL; documentação de
   decisões.

---

## 9. Arquitetura-alvo (menor evolução capaz de atender)

**[Rec]** Continuar sem framework completo. Organizar `src/` em camadas hexagonais e mover a lógica
dos scripts para adaptadores. Os endpoints atuais continuam funcionando e passam a delegar ao novo
núcleo (padrão *strangler*).

```
src/
  Dominio/            Solicitação fiscal, documento fiscal, estados, políticas, VOs (sem I/O)
  Aplicacao/          Casos de uso (comandos) e consultas; portas (interfaces)
  Infra/
    Autorizadores/    NfeSefaz (NFePHP) | NfseNacional (Sefin) | NfseSalvador (ABRASF) | AdnDistribuicao
    Persistencia/     PostgreSQL (repositórios, outbox, histórico)
    Armazenamento/    artefatos (disco por CNPJ hoje; objeto/S3 depois)
    Cofre/            certificados (libsodium já existente)
  Entrada/
    Http/v1/          endpoints atuais (compatibilidade)
    Http/v2/          contrato canônico
    Http/Compat/      adaptador de formato PlugNotas (opcional, isolado)
    Cli/              worker, administração
```

### 9.1 Domínio (linguagem ubíqua)

- **Consumidor**: sistema cliente da API (Financeiro, Fura Fila via Financeiro, ERP). Tem
  credenciais próprias e **escopos** por empresa e operação (emitir, cancelar, consultar).
- **Empresa / Estabelecimento**: emitente fiscal (CNPJ, IE, IM, município, regime, certificado,
  séries).
- **Profissional executante**: dado da operação (nome, conselho, número e UF do registro). **Não**
  é emitente.
- **Titular financeiro**: só uma referência de correlação. A API fiscal não o modela.
- **SolicitacaoFiscal** (agregado): identidade idempotente (consumidor, empresa, tipo,
  `idIntegracao`) + hash do conteúdo, operação de origem, correlação e política aplicada.
- **Estados** (candidatos): `RECEBIDA`, `VALIDADA`, `AGUARDANDO_PRE_REQUISITO`, `PREPARADA`,
  `TRANSMITIDA`, `INDETERMINADA`, `AUTORIZADA`, `REJEITADA`, `DENEGADA`, `CANCELADA`, `SUBSTITUIDA`,
  `PENDENTE_INTERVENCAO`. Ficam em um **histórico só de inclusão** (append-only); o status atual é
  derivado.
- **DocumentoFiscal**: número, série, chave ou identificador oficial, protocolo e artefatos
  versionados com hash.
- **Pendencia**: causa, evidência, ação necessária, competência, estado e histórico; retomada segura.

### 9.2 Portas principais

- `AutorizadorFiscal`: `transmitir`, `consultar`, `cancelar`, `corrigir`/`substituir` quando houver.
  Um adaptador por canal.
- `SeletorDeAutorizador`: escolhe o canal por **política com vigência**, guardada em tabela e não
  em `if`. Exemplo de política proposta, **[Rec] a aprovar**:
  1. NFS-e + prestador ME/EPP do Simples + data a partir de 01/11/2026 → Nacional.
  2. NFS-e + Salvador + regime normal + município não aderente → Salvador ABRASF.
  3. Nenhuma regra aplicável → **pendência** "canal não determinado".
- `PerfilTributario`: devolve o enquadramento (CST, cClassTrib, NBS, alíquotas) para empresa,
  operação e data. Sem enquadramento, devolve pendência.
- `RepositorioDeSolicitacoes`, `HistoricoDeEstados`, `ArmazenamentoDeArtefatos`, `CofreDeCertificados`.
- `PublicadorDeEventos`: **outbox** transacional e um worker de webhook (HMAC, timestamp, id do
  evento, proteção contra replay, novas tentativas). Uma falha no webhook não afeta o documento.

### 9.3 Regras de confiabilidade

- Resultado incerto → `INDETERMINADA` → **consultar** (NF-e por chave; NFS-e por id da DPS) →
  autorizada, rejeitada ou "não existe". Só no último caso há novo envio, com o **mesmo XML**.
- Fila no PostgreSQL com `SKIP LOCKED` (a da branch) e backoff, separando falha transitória de
  erro definitivo.
- Reconciliação periódica: documentos `INDETERMINADA` ou antigos sem desfecho são consultados.

### 9.4 Contratos

- **v2 canônico**: envelope comum (consumidor, empresa/estabelecimento, `idIntegracao`, operação
  de origem, correlação, política, auditoria) + blocos especializados `nfe` ou `nfse`, mais o bloco
  `servicoProfissional` (profissional, conselho, registro, duração, data do atendimento, pacote).
  Nenhum campo de NF-e obrigatório numa NFS-e, e vice-versa.
- **Pacotes**: o campo `agrupamento` (id do pacote + modelo `POR_ATENDIMENTO` ou `CONSOLIDADO`)
  com regra de unicidade: o mesmo atendimento não pode ser documentado duas vezes, nem o pacote
  consolidado duas vezes.
- **v1** mantido, delegando ao núcleo. **Compat PlugNotas** em um adaptador isolado, se aprovado.
- **Resposta padrão**: id da solicitação, id do documento, tipo, status, código e motivo, número e
  série, chave ou id oficial, protocolo, links de XML e PDF, datas, histórico e próximos estados.

### 9.5 O que preservar

NFePHP; os fluxos de assinatura, envio, consulta, cancelamento e CC-e (movidos para o adaptador);
`HttpSecurity` que recusa por padrão; cofre libsodium; cNF determinístico; numeração atômica;
fila com `SKIP LOCKED`; convenção SQL; documentação de DF-e; guia da VPS.

---

## 10. Plano de evolução em etapas (priorizado)

| # | Incremento | Objetivo e escopo | Depende de | Testes e evidência de aceite | Risco principal |
|---|---|---|---|---|---|
| E0 | Base de qualidade | PHPUnit, testes de caracterização dos fluxos atuais, `sped-nfe` 5.2.9, declarar PHP ≥ 8.3; **homologar a branch** (autorização, 204, cancelamento) com certificado real | decisão D2 | suíte verde; protocolos de homologação salvos | regressão silenciosa ao atualizar a biblioteca |
| E1 | Núcleo e identidade | Consumidor com escopos; `SolicitacaoFiscal` + histórico de estados; idempotência por consumidor, com `409` para conflito | E0 | acesso entre empresas negado; concorrência; conteúdo divergente | migração dos dados da branch |
| E2 | Adaptador NF-e | Lógica dos scripts em classes (sem `exec`/regex); dicionário de cStat; **consultar antes de reenviar**; central de pendências (dados e API) | E1 | timeout após transmissão, consulta e desfecho; 150 como autorizada; 853 como pendência | divergência entre XML enviado e autorizado |
| E3 | Consulta e eventos | `GET /v2/documentos` (modelo de leitura), artefatos com hash e links, outbox + webhook assinado | E1 | falha de webhook sem efeito na nota; replay rejeitado | vazamento de dados entre empresas |
| E4 | NFS-e Nacional | DPS, transmissão, consulta, cancelamento e substituição na produção restrita; seletor com a política do Simples | E1, E2 (padrões), D6, D10 | NFS-e autorizada e rejeitada **em produção restrita**; timeout com consulta pelo id da DPS | leiaute ou XSD divergente do manual [NC] |
| E5 | Serviços profissionais (Fura Fila) | Contrato v2 `servicoProfissional`; política de prestador fiscal (clínica ou profissional); pacotes individuais ou consolidados com unicidade | E4 | cenários A, B e C da seção 6.2; pacote não documentado duas vezes | regra de pacote mal definida pelo negócio |
| E6 | NFS-e Salvador | Adaptador ABRASF com RPS e consulta por RPS | manual e credencial da prefeitura [NC] | autorizada e rejeitada em homologação municipal | manual desatualizado ou ambiente indisponível |
| E7 | NF-e completa | CSOSN, ST, DIFAL, FCP, IPI, CEST, transporte, cobrança, `NFref`, contingência, totais calculados, grupos de IBS/CBS por vigência | E2 | uma emissão homologada por grupo tributário | enquadramento incorreto: virar pendência, não chute |
| E8 | Recebidas e tomadas | DF-e completo (`docZip`, NSU, manifestação) e distribuição do ADN | E3 | NSU sem lacunas; bloqueio 656 respeitado | consumo indevido (656) |
| E9 | Compat PlugNotas | Adaptador de formato, se aprovado | E3 | contrato reproduzindo os payloads reais da API Node | formato real ainda desconhecido |

**[Rec] Justificativa da ordem.** E0 a E2 dão confiabilidade ao que já existe. E4 e E5 vêm antes
de E7 porque o primeiro consumidor real (Fura Fila) emite **serviço**, e o prazo do Simples já
começou. **Exceção:** se algum emitente de **mercadoria** for do Simples, o CSOSN (parte de E7)
sobe para logo depois de E2.

---

## 11. Critérios de aceite e homologação

- Uma capacidade só é "pronta" com: **teste automatizado** + **evidência no autorizador**
  (homologação, produção restrita ou ambiente municipal) com protocolo, XML enviado e retornado
  guardados. Nunca em produção para testes exploratórios.
- Cenários obrigatórios do prompt (seção 15), por etapa:
  - **E0:** NF-e autorizada e rejeitada.
  - **E1:** reenvio da mesma solicitação; duas solicitações concorrentes com a mesma identidade;
    conteúdo divergente; acesso entre empresas; seleção incorreta de emitente.
  - **E2:** timeout após transmissão + consulta posterior; certificado vencido ou inválido; erros
    de XML, codificação e assinatura; versão de leiaute incompatível; configuração tributária
    ausente (pendência); cancelamento permitido e impedido.
  - **E3:** falha na notificação após a autorização.
  - **E4:** NFS-e nacional autorizada e rejeitada; mudança de vigência e de canal fiscal.
  - **E5:** emissão por clínica e por profissional; emissão individual e consolidada de pacotes.
  - **E6:** NFS-e municipal autorizada e rejeitada.
- Aceite de segurança: nenhum segredo, certificado ou dado clínico em log (verificado por teste);
  escopos testados por consumidor.

---

## 12. Decisões que precisam de aprovação

| # | Decisão | Recomendação |
|---|---|---|
| D1 | Framework | Manter PHP puro + Composer com camadas hexagonais; sem framework completo |
| D2 | Commits da branch (segurança, multiempresa, numeração, fila) | Manter como base, refatorar para o núcleo em E1 e E2 e homologar em E0 (alternativa: descartar e refazer) |
| D3 | Identidades | Criar "Consumidor" com tokens próprios (guardados como hash) e escopos por empresa e operação; substitui o token único |
| D4 | Contratos | v2 canônico + v1 mantido; compat PlugNotas só se a API Node depender dele (preciso de exemplos reais de payload) |
| D5 | Seleção de canal | Política em tabela, com vigência, aprovada por um responsável fiscal; regra inicial da seção 9.2 |
| D6 | Prioridade | NFS-e Nacional (E4 e E5) antes de completar a NF-e (E7) |
| D7 | Resultado para os consumidores | Webhook assinado + consulta; o WebSocket do monitor fica no Financeiro Universal |
| D8 | Pacotes | `agrupamento` com modelo `POR_ATENDIMENTO` ou `CONSOLIDADO` e unicidade por atendimento e por pacote; o modelo de cada prestador é configuração aprovada |
| D9 | Reenvio em incerteza | Consultar sempre antes de reenviar (muda o comportamento atual da branch) |
| D10 | NFS-e Nacional | Adaptador próprio sobre o manual oficial, avaliando bibliotecas da comunidade só como referência |
| D11 | Armazenamento | Disco por CNPJ + backup agora; abstração para armazenamento de objetos depois |
| D12 | Dados clínicos | O contrato aceita só o necessário para a descrição fiscal; logs mascarados; descrição montada por modelo aprovado |

**Informações necessárias para destravar etapas:**

1. Regime tributário de cada emitente (Simples/MEI ou regime normal) e se algum emite mercadoria.
2. Situação do Nota Salvador: manual atual, credenciais e ambiente de homologação.
3. Exemplos reais dos payloads que a API Node.js envia hoje (formato PlugNotas).
4. Liberar na rede desta sessão os domínios `www.gov.br`, `nfse.sefaz.salvador.ba.gov.br`,
   `www2.sefaz.salvador.ba.gov.br` e `www.nfe.fazenda.gov.br`, para conferir manuais e versões
   oficiais.
5. Certificado A1 de homologação para a etapa E0.
