# NFS-e — Dúvidas de processo para decisão

Estas dúvidas surgiram durante a implementação. Para cada uma registrei o
**padrão adotado no código** (para não travar o desenvolvimento) e o que
muda se a decisão for outra. Marque a decisão e o responsável antes de ir
para produção.

Legenda de prioridade: 🔴 bloqueia produção · 🟡 decidir antes de escalar · 🟢 pode esperar.

## Decisões tomadas (04/11/2026)

| # | Tema | Decisão | Situação no código |
|---|---|---|---|
| A1 | Padrão para notas de Salvador | Manter os dois (Nacional e webservice de Salvador) | Já era assim |
| A4 | Emitentes | **Várias empresas** | Implementado: `emitentes` + `cnpjs` por consumidor + `emitente_cnpj` na emissão. Aguarda cadastro real. |
| A6 | Alíquota no Nacional | Só se o sistema enviar | Já era assim |
| A8 | Tributos aproximados | Não informar | Já era assim |
| A10 | Competência | Data de hoje quando não vier | Já era assim |
| B1 | Documento inválido no lote | Gravar como REJEITADO, mostrar no monitor com motivo e sugestão de correção | Implementado (sem consumir número de DPS/RPS) |
| B2 | Reenvio após rejeição | Reaproveitar o número | Já era assim — **validar em homologação de Salvador** |
| B3 | Cancelamento em Salvador | Rota de registro manual | Implementado: `cancelamento_externo.php` |
| B4 | Cancelamento fora do prazo (Nacional) | Só devolver a rejeição | Já era assim |
| B5 | Confirmação/rejeição (202201…) | Não agora | — |
| B6 | Substituição | Atualizar a substituída automaticamente | Implementado (consulta 1 min após autorizar a substituta) |
| C1 | Quem cancela | Cada sistema, só as próprias notas | Já era assim |
| C3 | Painel | Rede interna/VPN, token na URL do stream | Já era assim; página pronta em `/monitor/` |
| C4 | PDF de Salvador | Espelho + link do portal | Implementado; **falta o formato do link** (`salvador.url_visualizacao`) |
| C6 | XMLs | Banco + armazenamento em nuvem | Implementado (`local` ou `s3`/compatível); **falta escolher o provedor** |
| C7 | CNPJ alfanumérico em Salvador | Recusar com aviso | Já era assim |

## Informações que ainda faltam

| # | O que | Para quê |
|---|---|---|
| A | Cadastro de cada empresa: CNPJ, razão social, inscrição municipal, município, regime e certificado | Preencher `emitentes` em `config/nfse.local.php` |
| B | Quais CNPJs cada sistema pode usar | `consumidores.<sistema>.cnpjs` |
| C | Provedor de nuvem (S3, Azure, Google, MinIO...) e prazo de guarda | `armazenamento` (S3 e compatíveis já prontos; Azure/Google nativos exigem nova classe) |
| E | Exemplo de link de visualização de nota no portal de Salvador | `salvador.url_visualizacao` |
| F | Confirmação do contador: padrão de Salvador em 2026/2027 e regime (Simples?) de cada empresa | Escolha do padrão e campos de regime |
| G | Séries e último número de DPS/RPS já usados por empresa | Evitar conflito de numeração (A3) |
| H | Credenciamento na Produção Restrita (Nacional) e homologação de Salvador | Homologar |
| I | Lista de sistemas, tokens e URLs de webhook | `consumidores` |
| J | Existe retenção de PIS/COFINS? | A9 |
| K | Volume (notas/dia, picos) | Limites de lote e nº de workers (B7, C9) |
| L | Servidor do worker e quem recebe alertas | Operação (C9) |

---

# Registro original das dúvidas

O texto abaixo é o levantamento original, mantido como histórico. As
decisões acima prevalecem.

---

## A. Escopo fiscal e cadastro

### A1 🔴 Salvador vai continuar com webservice próprio?
A Prefeitura de Salvador assinou adesão ao Ambiente de Dados Nacional (ADN).
Desde 01/01/2026 os municípios devem emitir no padrão nacional **ou**
compartilhar os documentos com o ambiente nacional quando têm emissor
próprio.
- **Adotado:** os dois padrões estão implementados e ficam lado a lado.
- **Decidir:** confirmar com a SEFAZ Salvador/contador **qual padrão a empresa deve usar em 2026/2027** e se há data para desligar o webservice municipal. Se Salvador migrar de vez, basta parar de enviar `padrao: SALVADOR`.
- Há notícia (fonte secundária, não confirmada) de que **optantes do Simples Nacional** passariam a emitir **exclusivamente** pelo Emissor Nacional a partir de 01/11/2026. A empresa é optante? (Isso também define `opcao_simples_nacional` na config.)

### A2 🔴 Credenciamento e homologação
- Nacional: o CNPJ precisa estar habilitado na **Produção Restrita** do Sefin Nacional (e o município de Salvador conveniado/parametrizado para a empresa).
- Salvador: homologação em `notahml.salvador.ba.gov.br` exige inscrição municipal e cadastro do contribuinte. Integradores relatam que esse ambiente fica fora do ar com frequência.
- **Decidir:** quem faz o credenciamento e qual certificado (o mesmo e-CNPJ A1 da NF-e?).

### A3 🔴 Numeração inicial de DPS/RPS
A API controla a sequência em `nfse_numeracao` e começa do **1**.
- Se a empresa já emitiu DPS pelo **Emissor Web Nacional** ou RPS em Salvador com a **mesma série**, haverá conflito de número.
- **Decidir:** quais séries usar (`nacional.serie_dps`, `salvador.serie_rps`) e o último número já utilizado em cada uma. Para começar de outro número:
  ```sql
  INSERT INTO nfse_numeracao (emitente_cnpj, padrao, tp_amb, serie, ultimo_numero)
  VALUES ('41986662000160', 'NACIONAL', 1, '1', 1234);
  ```

### A4 🟡 Um ou vários emitentes (multiempresa)?
- **Adotado:** um emitente, o mesmo CNPJ/certificado do `config/nfe.php`. O prestador **nunca** vem do corpo da requisição (mesma regra do DF-e).
- **Se for multiempresa:** criar cadastro de emitentes (CNPJ, IM, regime, certificado) e vincular cada consumidor aos CNPJs que pode usar. A tabela `nfse_documentos` já guarda `emitente_cnpj`; a mudança fica em `NfseConfig`/`ProvedorFactory`.

### A5 🟡 Regime tributário e campos que o XSD não decide
Configurados em `config/nfse.php → emitente`:
- `opcao_simples_nacional` (1 não optante, 2 MEI, 3 ME/EPP), `regime_apuracao_simples`, `regime_especial_tributacao`.
- Salvador: `regime_especial_abrasf` (tabela ABRASF é diferente da nacional) e `incentivador_cultural`.
- **Decidir com o contador.**

### A6 🟡 Alíquota (`pAliq`) no Padrão Nacional
- **Adotado:** enviamos `pAliq` somente se o consumidor informar `valores.aliquota_iss`.
- O Sefin tem regras que **rejeitam** `pAliq` em algumas situações (município conveniado com alíquota parametrizada, Simples etc.).
- **Decidir:** os consumidores devem mandar a alíquota no Nacional ou devemos sempre omitir e deixar o Sefin aplicar a parametrização do município?

### A7 🟡 Reforma Tributária (IBS/CBS) na DPS
O leiaute 1.01 já tem o grupo opcional `IBSCBS`.
- **Adotado:** não enviamos (seguindo `docs/compatibilidade-fiscal-2026.md`: sem modelagem rígida antes da fase própria).
- **Decidir:** a partir de quando a empresa precisa informar IBS/CBS na NFS-e e quais campos (cClassTrib etc.).

### A8 🟢 Tributos aproximados (Lei 12.741)
- **Adotado:** `indTotTrib = 0` (não informar), ou `pTotTribSN` se o consumidor enviar `percentual_tributos_simples`.
- **Decidir:** se a empresa precisa exibir o valor aproximado dos tributos (então passamos a enviar `vTotTrib`/`pTotTrib`).

### A9 🟢 Retenções federais no Nacional
- **Adotado:** enviamos `vRetCP` (INSS), `vRetIRRF`, `vRetCSLL` quando informados. PIS/COFINS (`tribFed/piscofins`, que exige CST) **não** está implementado.
- **Decidir:** se há retenção de PIS/COFINS nos serviços da empresa.

### A10 🟢 Competência
- **Adotado:** se o consumidor não mandar `competencia`, usamos a data de hoje.
- **Decidir:** se a competência deve ser obrigatória (ex.: serviços do mês anterior faturados no início do mês).

---

## B. Regras de processo da API

### B1 🔴 Lote com documento inválido: tudo ou nada?
- **Adotado:** se 1 documento for inválido na **entrada**, o lote inteiro é recusado (HTTP 422) com o erro de cada documento; nada é gravado.
- **Alternativa:** aceitar os válidos e devolver os inválidos. Mais flexível, porém o consumidor precisa tratar lote parcial.
- Depois de aceito, cada documento segue independente (um pode ser autorizado e outro rejeitado pelo fisco).

### B2 🔴 Reenvio após rejeição usa o mesmo número?
- **Adotado:** reenviar a mesma `referencia` com status `REJEITADO`/`ERRO` reaproveita o número da DPS/RPS (rejeição não consome número no fisco).
- **Validar na homologação de Salvador** se o RPS de um lote "processado com erro" pode ser reenviado com o mesmo número. Se não puder, a mudança é pequena: gerar número novo no reenvio de `REJEITADO`.

### B3 🔴 Cancelamento em Salvador
O webservice de Salvador **não tem cancelamento** (o XSD da prefeitura diz: "Cancelamento somente por processo administrativo"). A API responde 422 com a orientação.
- **Decidir:** quer um endpoint para **registrar manualmente** que a nota foi cancelada no portal (com número do processo e anexo), para o monitor e os consumidores ficarem coerentes? Hoje a nota continuaria `AUTORIZADO` na API.
- Prazo de cancelamento em Salvador (FAQ antiga da prefeitura): enquanto o ISS não foi pago, até o último dia do mês seguinte à emissão. Confirmar regra atual.

### B4 🟡 Prazo de cancelamento no Nacional
O prazo depende do município. Fora do prazo é preciso usar o evento `101103` (análise fiscal).
- **Adotado:** a API não bloqueia por prazo; o Sefin rejeita e a mensagem volta ao consumidor.
- **Decidir:** quer que a API sugira/automatize o `101103` quando o cancelamento for rejeitado por prazo?

### B5 🟡 Eventos de confirmação/rejeição (202201, 202205...)
- **Adotado:** não implementados (constam como "não suportados" em `capacidades.php`).
- **Decidir:** algum processo da empresa precisa confirmar/rejeitar notas pela API?

### B6 🟡 Substituição de NFS-e (Nacional)
- **Adotado:** suportada enviando `substituicao.chave_substituida` na emissão; o Sefin gera o cancelamento por substituição. A nota antiga só vira `SUBSTITUIDO` na API após uma **consulta** dela.
- **Decidir:** quer que, ao autorizar a substituta, a API consulte automaticamente a substituída?

### B7 🟢 Limites e esperas
- Máx. **50 documentos por lote**, `aguardar` até **60 s**, até **5 tentativas** com espera 30 s, 60 s, 120 s...; Salvador: 1ª consulta em 15 s, depois a cada 30 s, até 20 vezes.
- **Decidir** se esses números fazem sentido para o volume esperado (quantas notas/dia? picos no fim do mês?).

---

## C. Integração, segurança e operação

### C1 🔴 Consumidores e tokens
- **Decidir:** lista de sistemas que vão consumir a API, um token por sistema e quem pode **cancelar** (hoje qualquer consumidor cancela as próprias notas).
- Tokens ficam em `config/nfse.local.php`. Futuro: tabela `api_consumidores` com token em hash (já prevista em `docs/banco-fiscal-planejamento.md`).

### C2 🟡 Webhook
- **Adotado:** URL e segredo **por consumidor**, na configuração (não aceitamos URL vinda na requisição, para evitar uso da API para chamar endereços internos).
- **Decidir:** cada consumidor vai querer webhook? Algum precisa de URL diferente por lote?

### C3 🟡 Token do painel na URL
O `EventSource` do navegador não envia cabeçalhos, então o token do monitor vai em `?token=`.
- **Adotado:** aceito apenas nas rotas de leitura (`monitor.php`, `documentos.php`) e o token do painel **não emite nem cancela**.
- **Decidir:** o painel ficará só na rede interna/VPN? Se for público, o ideal é o backend do painel autenticar o usuário (sessão/cookie) e repassar o stream.

### C4 🟡 PDF de Salvador
- **Adotado:** espelho simples gerado pela API, com aviso de "documento auxiliar".
- **Decidir:** o espelho atende os clientes, ou preferem **link** para a nota no portal da prefeitura? Para o link, informe o formato em `salvador.url_visualizacao` (não encontrei documentação oficial atual do endereço).

### C5 ✅ DANFSe (Nacional)
- **Adotado (06/11/2026):** a API do ADN que gerava o PDF foi desligada em 03/08/2026 (NT 008/2026). A API gera o DANFSe v2.0 a partir do XML autorizado (`src/Nfse/Pdf/DanfsePdf.php`), com QR Code de consulta, "SEM VALIDADE JURÍDICA" em homologação e marca d'água de cancelada/substituída.
- **Limitação:** o XML só traz o nome dos municípios de emissão, prestação e incidência; para os outros (ex.: do tomador) o PDF mostra o código IBGE. A fonte é Arial (a NT pede Microsoft Sans Serif nos conteúdos).

### C6 🟡 Armazenamento e retenção de XML
- **Adotado:** XML enviado e autorizado ficam no **PostgreSQL** (sem disco compartilhado entre API e worker).
- **Decidir:** política de retenção (mínimo legal de 5 anos), backup do banco e se também querem cópia em `storage/xml` ou em objeto (S3).

### C7 🟢 CNPJ alfanumérico (a partir de 07/2026)
- O XSD de Salvador só aceita **CNPJ numérico**. Tomador com CNPJ novo (alfanumérico) não pode ser emitido em Salvador; a API recusa na entrada com mensagem clara.
- **Decidir:** nesses casos emitir pelo Padrão Nacional? Acompanhar atualização do leiaute de Salvador.

### C8 🟢 Algoritmo de assinatura
- **Adotado:** Nacional RSA-SHA256; Salvador RSA-SHA1 (configurável em `config/nfse.php`).
- **Validar na homologação** e ajustar se algum ambiente recusar.

### C9 🟢 Infraestrutura do worker
- **Decidir:** onde roda o worker (mesmo servidor da API?), quantos processos (sugestão: 2) e quem recebe alerta quando houver documentos parados ou webhooks em `FALHOU` (consultas prontas em `docs/nfse-arquitetura.md`, seção 10).

## D. NF-e (modelo 55) na mesma estrutura

### D1 🔴 Quem numera a NF-e: a API ou o ERP?
- **Adotado:** a API numera (`ide.nNF` sempre definido por ela, por CNPJ + série + ambiente), como na NFS-e. Isso evita buraco e número repetido entre sistemas.
- **Decidir:** algum ERP precisa manter a própria numeração? Se sim, ele deve usar uma **série diferente**.
- **Informar:** último número usado em cada série/CNPJ para iniciar `nfse_numeracao` sem colidir com notas já emitidas pelas rotas antigas.

### D2 🔴 CNPJ do emitente da nota de homologação
- `json/nota_homologacao_emitir_padrao.json` usa `emit.CNPJ` **07047183000140**, mas `config/nfe.php` está com **41986662000160**.
- **Decidir:** qual empresa homologa a NF-e e cadastrá-la em `emitentes` (com `uf`, `ie` e certificado).

### D3 🟡 Nome das rotas
- A NF-e entrou em `/v1/nfse/` com `"padrao": "NFE"`. Funciona, mas o nome confunde.
- **Decidir:** criar apelido `/v1/fiscal/` (mesmos arquivos) ou manter como está.

### D4 🟡 Inutilização de numeração
- Continua na rota antiga (`/v1/nfe/inutilizar_padrao.php`). Como a API controla a numeração, buracos só surgem em casos raros (ex.: nota rejeitada e abandonada).
- **Decidir:** trazer a inutilização para a fila/monitor?

### D5 🟢 NFC-e (modelo 65), manifestação do destinatário, EPEC/contingência
- **Fora do escopo agora:** `mod` 65 é recusado com `NAO_SUPORTADO_PELO_PADRAO`; contingência não é feita automaticamente (falha de comunicação vira nova tentativa e, ao final, `ERRO`).

---

## Fontes consultadas

- Manual do contribuinte — API do Sistema Nacional NFS-e (gov.br/nfse, v1.2 out/2025).
- XSDs oficiais NFS-e Nacional v1.01 (cópia em `schemas/nfse/nacional/1.01`).
- XSD da Prefeitura de Salvador (ABRASF 1.00 alterado), endereços e operações SOAP conforme o provedor `ISSSalvador` do projeto ACBr.
- FAQ e manual da NFS-e de Salvador (portal da prefeitura) sobre cancelamento.
