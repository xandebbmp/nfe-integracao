# Prompt — Cadastro de empresas, certificados e sistemas pelo Scriptcase

> **Como usar:** copie tudo a partir de "PROMPT" e cole numa nova conversa
> com o assistente, no repositório `xandebbmp/nfe-integracao`. O texto é
> completo por si só.

Histórico: esta funcionalidade já foi implementada uma vez, nos commits
`c963373` (implementação), `d6744e4` e `491cd6a` (reversão), e depois
desfeita. Se fizer sentido, use o commit `c963373` como ponto de partida:

```bash
git show c963373 --stat
```

Ajuste o que estiver abaixo antes de colar.

---

## PROMPT

Quero que as empresas emissoras, os certificados digitais, os sistemas que
usam a API e a numeração inicial sejam **cadastrados no banco PostgreSQL**,
por telas que eu vou montar no **Scriptcase**. A API e o worker devem ler
esse cadastro sozinhos, sem editar `config/nfse.local.php` e sem reiniciar
nada.

### Contexto do projeto

- API fiscal em PHP 8.3 + PostgreSQL 16 (NF-e modelo 55, NFS-e Nacional e
  NFS-e Salvador). Usa fila (`nfse_jobs`), worker (`bin/nfse_worker.php`),
  monitor e webhooks.
- Hoje as empresas ficam em `config/nfse.local.php`:
  - `emitentes`: CNPJ, razão social, UF, IE, inscrição municipal, município,
    regime e certificado (`arquivo` + `senha`);
  - `consumidores`: token, `webhook_url`, `webhook_segredo` e `cnpjs`;
  - `monitor_token`.
- Quem monta a configuração é `src/Nfse/NfseConfig.php` (`carregar()`,
  `paraEmitente()`, `cnpjsPermitidos()`).
- A autenticação está em `src/Nfse/Api/Api.php` (`autenticar()`). O
  certificado é aberto em `src/Nfse/Infra/CertificadoCliente.php`, chamado
  por `src/Nfse/ProvedorFactory.php`, `bin/nfse_homologar.php` e
  `bin/nfse_verificar.php`.
- A numeração fica em `nfse_numeracao` (emitente_cnpj, padrao, tp_amb, serie,
  ultimo_numero). A série da NF-e é gravada sem zeros à esquerda (`'1'`).
- Siga os padrões do repositório:
  - scripts SQL em `database/scripts/NNNN_*.sql`, que podem rodar mais de uma
    vez (ver `docs/padrao-scripts-sql.md`);
  - testes em `tests/nfse/executar.php`;
  - código simples para júnior;
  - documentação em português para leigos.

### Decisões já tomadas

1. **Cadastro permanente no banco:** a API passa a ler dele. O que está em
   `config/nfse.local.php` continua valendo; se houver conflito, **vale o
   banco**. Se existir empresa cadastrada no banco, o emitente "automático"
   do `config/nfe.php` deixa de ser usado.
2. **As telas são feitas no Scriptcase** em cima das tabelas. O projeto
   entrega as tabelas, as regras no banco e um manual de como montar cada
   tela.
3. **Upload do certificado (.pfx) e da senha pela tela, protegido:**
   - O Scriptcase grava o arquivo e a senha em colunas de entrada.
   - O worker confere a senha, se o certificado é e-CNPJ, se a raiz do CNPJ
     (8 dígitos) é a mesma da empresa e a validade.
   - Depois **criptografa** com libsodium (secretbox), usando uma chave que
     fica só no servidor (`config/nfse.local.php` → `'cadastro' => ['chave' => ...]`
     ou a variável `NFSE_CHAVE_CADASTRO`).
   - Por fim **apaga** o arquivo e a senha originais.
   - O resultado aparece numa coluna de situação: `SEM_CERTIFICADO`,
     `AGUARDANDO`, `OK`, `VENCIDO` ou `ERRO`, com uma mensagem clara.

### O que construir

**Banco (novo script `0005_...sql`):**
- `fiscal_empresas`:
  - dados da empresa: cnpj PK, razão social, uf, ie, inscrição municipal,
    código do município (padrão `2927408`), telefone, email;
  - regime: `opcao_simples_nacional` (1, 2, 3), `regime_apuracao_simples`,
    `regime_especial_tributacao` (0 a 6), `incentivador_cultural`,
    `regime_especial_abrasf`, `ativo`;
  - entrada do certificado: `certificado_arquivo` BYTEA e `certificado_senha`;
  - resultado: `certificado_situacao`, `_mensagem`, `_validade`, `_titular`,
    `_processado_em`.
- `fiscal_empresas_certificados`: cnpj e conteúdo cifrado. **O usuário do
  Scriptcase não tem acesso a esta tabela.**
- `fiscal_sistemas`:
  - codigo PK (maiúsculo), nome;
  - perfil: `SISTEMA` (emite pela API) ou `PAINEL` (só monitor);
  - token: coluna de entrada; o banco guarda só o sha256 em `token_hash` e os
    4 últimos caracteres em `token_final`, e apaga o token digitado;
  - `webhook_url`, `webhook_segredo`, `ativo`, `observacao`.
- `fiscal_sistemas_empresas`: quais empresas cada sistema pode usar.
- `fiscal_numeracao_inicial`:
  - o usuário informa o **último número usado** por empresa, padrão
    (`NFE`, `NACIONAL`, `SALVADOR`), ambiente e série;
  - um gatilho `SECURITY DEFINER` aplica o valor em `nfse_numeracao` **sem
    nunca voltar para trás** e grava o resultado
    (ex.: "Aplicado. Próximo número: 501.").
- Gatilhos para:
  - normalizar CNPJ, UF e IE (aceitar o que for digitado com pontuação);
  - exigir token com 32+ caracteres;
  - avisar o worker com `pg_notify('nfse_jobs', ...)` quando chegar um
    certificado novo.

**PHP:**
- `NfseConfig::comCadastro(array $cfg, PDO $pdo)`: mescla o cadastro do banco.
  Se o script 0005 não estiver aplicado, devolve a config como veio.
- `Api`: carrega o cadastro em cada chamada e aceita o token por hash. O
  perfil `PAINEL` dá acesso ao monitor.
- `CertificadoCliente::doEmitente()`: abre o certificado do banco
  (decifrado só em memória) ou do arquivo.
- Worker:
  - confere os certificados pendentes a cada volta;
  - relê o cadastro a cada minuto e logo depois de aprovar um certificado.
- `bin/fiscal_cadastro.php` com as opções `--gerar-chave`, `--gerar-token`,
  `--processar` e `--listar`.
- `deploy/criar_usuario_scriptcase.sh`:
  - cria um usuário PostgreSQL restrito (só as tabelas do cadastro, leitura
    de `nfse_numeracao`, sem acesso aos certificados);
  - mostra os dados da conexão para o Scriptcase.
- O instalador local deve gerar a chave do cadastro.
- `nfse_verificar.php` deve conferir:
  - que o script 0005 foi aplicado;
  - que a chave existe e é válida;
  - os certificados que não estão `OK`.

**Testes:**
- cofre: cifra e decifra; outra chave não abre;
- certificado correto vira `OK`; o arquivo e a senha são apagados;
- certificado de outra empresa → `ERRO`; senha errada → `ERRO`;
- arquivo gravado em base64 (o Scriptcase pode gravar assim) é aceito;
- token curto é recusado; só o hash fica guardado;
- empresa inativa fica de fora;
- permissões por sistema; perfil `PAINEL`;
- a numeração não volta para trás;
- o usuário restrito não lê os certificados.

**Documentação:** `docs/cadastro-scriptcase.md`, passo a passo para leigo:
- conexão PostgreSQL PDO no Scriptcase;
- telas de Empresas, Sistemas (com o detalhe mestre/detalhe de empresas) e
  Numeração inicial (só incluir);
- tipo de cada campo:
  - **Documento (Banco de dados)** para o `.pfx`;
  - Texto com a opção **Senha** para a senha;
  - campos de resultado somente leitura;
- evento `onLoad` para gerar o token ao clicar em Novo;
- cores da grade por situação do certificado;
- menu "Fiscal" liberado só para administradores;
- tabela de mensagens de erro com o que fazer.

Atualize também:
- `docs/manual-implantacao.md` (chave e usuário do Scriptcase);
- `docs/nfse-arquitetura.md`;
- `docs/nfse-guia-api.md`;
- `docs/nfse-duvidas-processo.md`.

### Pontos a confirmar comigo antes ou durante a implementação

- O Scriptcase vai rodar **no mesmo servidor** da API ou em outra máquina?
  Se for em outra, como liberar o acesso ao banco com segurança (IP liberado
  ou túnel SSH)?
- Qual versão do Scriptcase? É para confirmar:
  - como o campo "Documento (Banco de dados)" grava em `bytea` no PostgreSQL;
  - se a macro `sc_btn_new` funciona no `onLoad`.
- O token do monitor (`monitor_token`) também deve ir para o cadastro, ou
  fica no arquivo?

### Ao terminar

- Rode `php tests/nfse/executar.php` com e sem `NFSE_TESTE_PG`, `php -l` em
  tudo e `shellcheck -S warning deploy/*.sh`.
- Teste de ponta a ponta: cadastre um sistema e chame a API com o token;
  envie um certificado e rode `php bin/nfse_worker.php --uma-vez`.
- Commit e push na branch de trabalho. **Abra o PR só quando eu pedir.**
