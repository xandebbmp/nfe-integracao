# Prompt de continuidade — API Fiscal (NF-e + NFS-e)

> **Como usar:** abra uma nova conversa com o assistente no repositório
> `xandebbmp/nfe-integracao` e cole tudo a partir de "PROMPT". O texto é
> completo por si só. Situação em 05/11/2026 (`main` após o PR #8).

---

## PROMPT

Estou dando continuidade à **API Fiscal** do repositório `xandebbmp/nfe-integracao`
(PHP 8.3 + PostgreSQL 16 + biblioteca NfePHP). Leia este resumo antes de
mexer em qualquer coisa: ele traz o que já existe, as decisões tomadas e o
que falta.

### 1. O que é

Uma **API aberta** que recebe um **objeto JSON** e emite o documento fiscal.
Ela devolve status padronizado, XML autorizado e PDF de cada documento, e
cuida de todos os eventos:
- **NF-e modelo 55** (produtos, SEFAZ). Mesmo JSON da rota antiga
  `/v1/nfe/emitir_json_padrao.php` (`ide`, `emit`, `dest`, `itens`, `totais`,
  `transp`, `pag`, `infAdic`, `reformaTributaria`) mais `referencia`.
  `"padrao":"NFE"`.
- **NFS-e Padrão Nacional** (Sefin/ADN). `"padrao":"NACIONAL"`.
- **NFS-e Salvador-BA** (webservice ABRASF da prefeitura). `"padrao":"SALVADOR"`.

As rotas antigas `/v1/nfe/*_padrao.php` continuam existindo e **não devem
ser alteradas**. Inutilização de NF-e continua nelas.

Será vendida como **serviço para várias empresas clientes**. Cada empresa
tem o próprio token, controle de emissão e cobrança por uso.

### 2. Arquitetura (já implementada e na `main`)

```
Sistema do cliente ─HTTPS + token─► nginx/Apache ─► PHP-FPM (API) ─► PostgreSQL (fila)
                                                                        │ LISTEN/NOTIFY
                                    Worker (bin/nfse_worker.php) ◄──────┘
                                      └─► SEFAZ / Sefin Nacional / Prefeitura de Salvador
                                      └─► webhooks (HMAC) para o sistema do cliente
Navegador ─► /monitor/ (tempo real via SSE)   ·   /admin/ (empresas, bloqueios, cobrança)
```

- **A API nunca fala com o fisco na chamada do cliente.** Ela valida, grava
  na fila (`nfse_jobs`, `FOR UPDATE SKIP LOCKED`) e responde. O worker
  envia, consulta, cancela e tenta de novo com espera crescente.
- **Proteção contra nota em dobro:** antes de reenviar, consulta pela chave
  (NF-e) ou pela DPS/RPS (NFS-e).
- **Status padronizados:** `RECEBIDO`, `PROCESSANDO`, `AUTORIZADO`,
  `REJEITADO`, `DENEGADO` (só NF-e), `ERRO`, `CANCELADO`, `SUBSTITUIDO`.
- **Idempotência:** (consumidor, CNPJ, padrão, ambiente, `referencia`).
  Reenviar a mesma referência nunca duplica.
- **Documento inválido não derruba o lote:** é gravado como `REJEITADO`, com
  campo e **sugestão de correção**, e aparece no monitor.
- **Tempo real:** SSE (`public/v1/nfse/monitor.php`), não WebSocket. Usa um
  pool PHP-FPM separado, sem buffer.
- **Tabelas com prefixo `nfse_`** (nome histórico): guardam NFS-e **e** NF-e.

### 3. Controle por empresa cliente (já implementado)

Script `0005_controle_criar_clientes_uso.sql`:

- **`fiscal_clientes`**, uma linha por empresa:
  - dados fiscais;
  - certificado A1 **criptografado** (libsodium, chave `chave_certificados`
    no `config/nfse.local.php`);
  - token próprio, guardado **só como hash sha256**;
  - webhook;
  - regras: `emissao_liberada` + `motivo_bloqueio`, `liberado_ate`
    (vencimento), `limite_mensal` (franquia), `padroes_permitidos` (tipos
    contratados), `plano`.
- **`fiscal_uso`:** uma linha por nota **autorizada**, gravada por **gatilho**
  no banco. É a base da cobrança: cancelada continua contando; rejeitada,
  denegada e erro não contam.
- **`fiscal_admin_log`:** histórico das alterações no painel, com IP.

**Ordem da validação na emissão:**
1. Token (hash no banco). Falhou: `401`.
2. Empresa bloqueada ou vencida: `403 EMISSAO_BLOQUEADA` / `EMISSAO_VENCIDA`.
3. CNPJ da nota é o da empresa do token.
4. Idempotência: se a nota já existe, devolve a existente.
5. Tipo contratado (`TIPO_NAO_CONTRATADO`) e franquia (`LIMITE_MENSAL`):
   o documento volta `RECUSADO`.
6. Dados da nota: se houver erro, `REJEITADO` com correção.

Consulta, PDF, XML e cancelamento funcionam mesmo com a empresa bloqueada.

**Painel `/admin/`** (HTML/CSS/JS puros, token `admin_token`) e **rotas
`/v1/admin/`**:
- `clientes.php`: listar, cadastrar e alterar;
- `cliente.php`: detalhe, uso de 12 meses, histórico e séries;
- `certificado.php`: upload, conferido na hora;
- `token.php`: gera o token, mostrado uma vez só;
- `numeracao.php`: último número já usado por série; nunca volta para trás;
- `uso.php`: relatório de cobrança do mês, em JSON ou CSV.

Emitentes e consumidores do `config/nfse.local.php` continuam valendo. Para
o mesmo CNPJ, o cadastro do banco tem prioridade
(`NfseConfig::comClientes`). O worker relê o cadastro a cada minuto.

### 4. Onde está cada coisa

| Parte | Caminho |
|---|---|
| Regras, provedores e banco (back) | `src/Nfse/` — `Nfe/`, `Nacional/`, `Salvador/`, `Servico/`, `Repositorio/`, `Dominio/`, `Infra/`, `Api/` |
| Rotas da API | `public/v1/nfse/*.php` |
| Rotas do admin | `public/v1/admin/*.php` |
| Front (sem build) | `public/admin/` (painel) e `public/monitor/` (tempo real). Como rodar: `docs/manual-operacao.md`, seção 13 |
| Worker, homologação, verificador | `bin/nfse_worker.php`, `bin/nfse_homologar.php`, `bin/nfse_verificar.php` |
| Banco | `database/scripts/0001` a `0005` (padrão em `docs/padrao-scripts-sql.md`) |
| Servidor | `deploy/`: nginx, Apache, systemd, pm2, php-fpm, instaladores, backup, atualizar |
| Testes | `tests/nfse/executar.php` (+ `casos_nfe.php`, `casos_clientes.php`, `Apoio.php`) |
| CI | `.github/workflows/testes.yml` (PHP 8.3 + PostgreSQL 16, roda em todo PR) |

Documentação:
- `docs/manual-operacao.md`: cada processo e como configurar. **Comece por ele.**
- `docs/manual-implantacao.md`: instalação passo a passo para leigos.
- `docs/nfse-guia-api.md`: contrato da API para quem integra.
- `docs/nfse-arquitetura.md`: arquitetura.
- `docs/nfse-homologacao.md`: roteiro de homologação.
- `docs/nfse-duvidas-processo.md`: decisões e dúvidas em aberto.
- `docs/nfse-monitor.md`: monitor.
- `docs/ci.md`: CI.
- `docs/manual-ambiente-local.md`: ambiente local.

### 5. Decisões já tomadas (não reabrir sem eu pedir)

- Cada empresa cliente tem o **próprio token** e só emite pelo próprio CNPJ.
- O controle fica num **painel admin na própria API**, e não no Scriptcase.
  (Uma versão com telas no Scriptcase foi feita e desfeita a pedido. Se
  voltar a ser pedido, as telas devem usar as rotas `/v1/admin/` ou as
  tabelas `fiscal_clientes`, sem criar outro cadastro paralelo.)
- **Cobrança:** só notas autorizadas, uma vez por nota, por mês de
  autorização (fuso America/Bahia), separando homologação de produção.
- **Bloqueios:** manual com motivo, por data de vencimento, por franquia
  mensal e por tipo de nota.
- **Numeração:** a API numera (`nNF`, DPS, RPS) por CNPJ, tipo, ambiente e
  série, e nunca volta para trás.
- **NFS-e** (decisões de 04/11/2026, em `docs/nfse-duvidas-processo.md`):
  - multiempresa;
  - rejeição gravada e exibida no monitor com sugestão de correção;
  - cancelamento em Salvador por registro manual (`cancelamento_externo.php`);
  - PDF de Salvador = espelho + link;
  - XML no banco + nuvem (S3 e compatíveis);
  - consulta automática da substituída.
- **Fora do escopo por enquanto:** NFC-e (modelo 65), manifestação do
  destinatário, contingência/EPEC, inutilização na API nova.
- **Servidor:** Ubuntu 24.04 + nginx (ou Apache) + PHP-FPM 8.3 +
  PostgreSQL 16. Worker no systemd (`nfse-worker@1`, `@2`) ou no pm2.
  **Não** usar a máquina local; subir direto numa VPS.

### 6. O que falta (próximos passos)

1. **Subir na VPS** seguindo `docs/manual-implantacao.md`:
   - configurar `admin_token`, `chave_certificados` e `monitor_token`;
   - HTTPS com certbot;
   - `bin/nfse_verificar.php --rede` sem `[ERRO]`.
2. **Primeira empresa no painel:** cadastro, certificado A1, token e,
   se ela já emitia, numeração.
3. **Homologação real**, com `tpAmb = 2`:
   - `bin/nfse_homologar.php --padrao=NFE`;
   - depois `--padrao=NACIONAL` e `--padrao=SALVADOR`.
   - Os testes simulam o fisco. A conexão real com SEFAZ, Sefin e Salvador
     **ainda não foi testada**.
4. **Credenciamentos** (contador): SEFAZ-BA para NF-e, Produção Restrita da
   NFS-e Nacional, homologação de Salvador.
5. **Configurações ainda pendentes:**
   - provedor de nuvem dos XMLs (`armazenamento`);
   - link de visualização de Salvador (`salvador.url_visualizacao`).
6. **GitHub:** ativar a proteção da `main` exigindo o check
   "Testes NFS-e (PHP 8.3 + PostgreSQL 16)" (`docs/ci.md`).
7. **Produção:** `tpAmb = 1` só depois da homologação aprovada.
8. **Portal do cliente** (`public/portal/`, rotas `public/v1/portal/`,
   banco `0006_portal_criar_usuarios.sql`): login, início, "Emitir nota",
   "Minhas notas", "Serviços", "Tomadores", "Minha empresa" e "Usuários"
   (administrador/emissor, script 0010) já ligados na API. Falta:
   "Esqueci a senha" por e-mail (hoje o admin gera nova senha), telas de
   Recorrências, cancelamento de nota e testar a
   consulta de CNPJ/CEP com rede na VPS. Ver `docs/manual-operacao.md`
   seção 13.
9. **Tudo pelo painel (pedido do xande):** o worker já é acompanhado e
   comandado no botão "Sistema" do painel admin (pausar, retomar,
   reiniciar; script 0007) e o banco é atualizado pelo botão "Atualizar
   banco" (script 0008) e o botão "Rodar diagnóstico" usa as mesmas regras
   do `bin/nfse_verificar.php` (`Servico/Diagnostico.php`). Configurações
   editáveis na mesma tela (script 0009, lista em `Dominio/AjustesPainel.php`).
   Ambiente, senhas e tokens continuam só no arquivo, de propósito.
10. **Não testados em ambiente real:** `deploy/apache/nfe-integracao.conf` e
   `deploy/pm2/ecosystem.config.js`.

Dúvidas ainda abertas, em `docs/nfse-duvidas-processo.md`:
- D3: renomear `/v1/nfse` para `/v1/fiscal`?
- D4: levar a inutilização para a API nova?
- C4 e C6: ver o item 5 acima.

### 7. Como trabalhar neste repositório

- **Testes:**
  - sem banco: `php tests/nfse/executar.php`;
  - com banco descartável (as tabelas são recriadas):
    `NFSE_TESTE_EXIGIR_PG=1 NFSE_TESTE_PG="pgsql:host=127.0.0.1;dbname=nfephp_teste;user=...;password=..." php tests/nfse/executar.php`.
  - Situação atual: 330/330 com banco e 92/92 sem.
- **Antes de enviar:** `php -l` nos arquivos alterados,
  `node --check public/admin/admin.js public/monitor/monitor.js public/portal/portal.js` e
  `shellcheck -S warning deploy/*.sh`.
- **Banco:** toda mudança é um script novo `database/scripts/NNNN_*.sql`
  que pode rodar mais de uma vez. Ele entra sozinho nos testes e no
  `deploy/aplicar_banco.sh`.
- **Regras de código:**
  - SQL só em `Repositorio/`;
  - endpoint sem SQL e sem XML;
  - provedor sem acesso ao banco;
  - XML só com DOMDocument;
  - código simples, para júnior.
  - O design deve continuar portável para Node/TypeScript.
- **Documentação** em português, para leigos. Atualize os manuais junto com o código.
- **Nunca** apague, pule ou desative teste para o CI ficar verde.
- **Fluxo Git:**
  - trabalhar numa branch nova a partir da `main`;
  - commit e push;
  - **abrir PR e fazer merge só quando eu pedir**, e só com o CI verde.
- Os arquivos `config/*.local.php` têm senhas e **nunca** vão para o Git.

### 8. O que quero agora

<escreva aqui o próximo pedido — ex.: "vamos subir na VPS X", "homologação
deu este erro: ...", "quero telas no Scriptcase usando as rotas /v1/admin">
