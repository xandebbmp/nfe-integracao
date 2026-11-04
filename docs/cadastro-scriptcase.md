# Cadastro pelo Scriptcase — empresas, certificados, sistemas e numeração

As empresas emissoras, os certificados, os sistemas que usam a API e a
numeração inicial ficam **no banco de dados**. Você monta as telas no
**Scriptcase** em cima dessas tabelas. A API lê o cadastro sozinha e
**não precisa editar nenhum arquivo de configuração** para incluir uma
empresa nova.

> O que estava em `config/nfse.local.php` continua valendo. Se o mesmo CNPJ
> (ou o mesmo código de sistema) estiver nos dois lugares, **vale o banco**.

---

## 1. O que existe no banco (script `0005`)

| Tabela | Tela no Scriptcase | Para quê |
|---|---|---|
| `fiscal_empresas` | **Empresas** (grade + formulário) | Dados da empresa e upload do certificado |
| `fiscal_sistemas` | **Sistemas** (grade + formulário) | Quem pode usar a API (token) e quem abre o monitor |
| `fiscal_sistemas_empresas` | Detalhe dentro de **Sistemas** | Quais empresas cada sistema pode usar |
| `fiscal_numeracao_inicial` | **Numeração inicial** (só incluir) | Último número já usado, para a API continuar do seguinte |
| `fiscal_empresas_certificados` | **nenhuma** | Certificado criptografado. Só a API lê. |

As regras de segurança ficam no próprio banco, então valem mesmo que a tela
seja montada de outro jeito:

- **Certificado:** o `.pfx` e a senha enviados pela tela são conferidos pelo
  worker em poucos segundos. Ele confere a senha, se o certificado é da empresa
  e a validade. Depois **criptografa** o certificado e **apaga** o arquivo e a
  senha da tabela da tela. O resultado aparece em `certificado_situacao` e
  `certificado_mensagem`.
- **Token:** o banco guarda só o "resumo" (hash) do token e apaga o que foi
  digitado. Ninguém consegue ver o token depois, nem pelo banco.
- **Numeração:** a numeração nunca volta para trás. Se você informar um
  número menor do que a API já usou, ele é ignorado e a tela avisa.

---

## 2. Preparar o servidor (uma vez)

Na pasta do sistema, no servidor:

1. Criar as tabelas (o mesmo comando de sempre, que aplica o script 0005):

   ```bash
   bash deploy/aplicar_banco.sh
   ```

2. Gerar a **chave que protege os certificados**. O instalador local já faz
   isso sozinho. Na VPS, gere a chave:

   ```bash
   php bin/fiscal_cadastro.php --gerar-chave
   ```

   Cole o resultado em `config/nfse.local.php`:

   ```php
   'cadastro' => [
       'chave' => 'COLE_A_CHAVE_AQUI',
   ],
   ```

   Guarde uma cópia da chave junto com os backups. Se ela se perder, basta
   reenviar os certificados pela tela.

3. Reiniciar o worker e o PHP para lerem a chave:

   ```bash
   sudo systemctl restart nfse-worker@1 nfse-worker@2 php8.3-fpm
   ```

4. Criar o **usuário de banco do Scriptcase**. Ele só mexe nas tabelas do
   cadastro e não consegue ler certificados nem notas:

   ```bash
   sudo bash deploy/criar_usuario_scriptcase.sh
   ```

   O comando mostra servidor, porta, banco, usuário e senha. **Anote a senha.**

> **Scriptcase em outra máquina?** O PostgreSQL vem aceitando só conexões
> locais. Se o Scriptcase rodar em outro servidor, peça ao suporte para
> liberar o IP dele (`listen_addresses` e `pg_hba.conf`) **somente para esse
> usuário**, ou use um túnel SSH. Nunca abra a porta 5432 para a internet toda.

---

## 3. Conexão no Scriptcase

1. **Banco de dados → Nova conexão → PostgreSQL PDO**.
2. Preencha com os dados que o passo 2.4 mostrou.
3. Nome sugerido: `conn_fiscal`. Teste a conexão e salve.

---

## 4. Tela "Empresas" (`fiscal_empresas`)

Crie uma **Grade** e um **Formulário** sobre `fiscal_empresas` e ligue a
grade ao formulário (editar ao clicar).

### Campos do formulário

| Campo | Tipo no Scriptcase | Observação |
|---|---|---|
| `cnpj` | Texto | Obrigatório. Pode digitar com pontos e barra; o banco limpa. |
| `razao_social` | Texto | Obrigatório. |
| `uf` | Seleção (lista manual: AC … TO) | NF-e: estado da empresa. Ex.: BA. |
| `ie` | Texto | NF-e: inscrição estadual. |
| `inscricao_municipal` | Texto | NFS-e (obrigatória em Salvador). |
| `codigo_municipio` | Texto | IBGE. Salvador = `2927408` (já vem preenchido). |
| `telefone`, `email` | Texto / E-mail | Opcionais. |
| `opcao_simples_nacional` | Seleção | `1` Não optante · `2` MEI · `3` ME/EPP |
| `regime_apuracao_simples` | Seleção (vazio, 1, 2, 3) | Só para ME/EPP. Pergunte ao contador. |
| `regime_especial_tributacao` | Seleção (0 a 6) | NFS-e Nacional. `0` = nenhum (padrão). |
| `regime_especial_abrasf` | Seleção (vazio, 1 a 6) | NFS-e Salvador. Normalmente vazio. |
| `incentivador_cultural` | Sim/Não | Normalmente Não. |
| `ativo` | Sim/Não | **Não** = a API para de emitir por esta empresa. |
| `certificado_arquivo` | **Documento (Banco de dados)** | Upload do `.pfx` / `.p12`. |
| `certificado_senha` | Texto, com a opção **Senha** ligada | Senha do `.pfx`. |
| `certificado_situacao` | Rótulo / somente leitura | `SEM_CERTIFICADO`, `AGUARDANDO`, `OK`, `VENCIDO` ou `ERRO`. |
| `certificado_mensagem` | Rótulo / somente leitura | O que aconteceu e o que fazer. |
| `certificado_validade` | Data, somente leitura | Vencimento do certificado. |
| `certificado_titular` | Rótulo / somente leitura | Nome que está no certificado. |

Deixe **ocultos**: `certificado_processado_em`, `criado_em` e `atualizado_em`.

### Na grade

Mostre CNPJ, razão social, UF, ativo, `certificado_situacao`,
`certificado_validade` e `certificado_mensagem`.

Dica: em **Formatação condicional**, pinte `certificado_situacao` de verde
para `OK`, amarelo para `AGUARDANDO` e vermelho para `ERRO` e `VENCIDO`.

### Como trocar ou renovar o certificado

Abra a empresa, envie o novo `.pfx` e a senha, e salve. Em até
**10 segundos** a situação muda de `AGUARDANDO` para `OK`, ou para `ERRO`
com o motivo. Recarregue a grade para ver. O certificado antigo continua
valendo até o novo ser aprovado.

| Mensagem de erro | O que fazer |
|---|---|
| Senha incorreta ou o arquivo não é um certificado A1 | Confira a senha e se o arquivo é o `.pfx` (não o `.cer`). |
| Este certificado é do CNPJ X, que não é desta empresa | Envie o e-CNPJ desta empresa. Filiais podem usar o da matriz, mas não o de outra empresa. |
| Este certificado não é um e-CNPJ | É e-CPF ou outro tipo. Use o certificado A1 da empresa. |
| Certificado VENCIDO | Envie o certificado renovado. |
| O servidor ainda não tem a chave do cadastro | Faça o passo 2.2 e envie de novo. |

---

## 5. Tela "Sistemas" (`fiscal_sistemas`)

Crie uma **Grade** e um **Formulário** sobre `fiscal_sistemas`.

| Campo | Tipo | Observação |
|---|---|---|
| `codigo` | Texto | MAIÚSCULO, sem espaço. Ex.: `ERP`, `LOJA_VIRTUAL`. |
| `nome` | Texto | Nome para você reconhecer. |
| `perfil` | Seleção | `SISTEMA` (emite pela API) ou `PAINEL` (só abre o monitor). |
| `token` | Texto | Mínimo de 32 caracteres. Veja abaixo. |
| `token_final` | Rótulo / somente leitura | Os 4 últimos caracteres, para conferir qual token está valendo. |
| `webhook_url` | Texto | Opcional. Endereço que recebe os avisos de status. |
| `webhook_segredo` | Texto | Opcional. Segredo da assinatura do webhook. |
| `ativo` | Sim/Não | **Não** = o token para de funcionar na hora. |
| `observacao` | Texto longo | Opcional. |

Deixe **oculto**: `token_hash`.

### Token

Para o formulário já trazer um token aleatório ao clicar em **Novo**,
coloque no evento **onLoad** do formulário:

```php
if (sc_btn_new) {
    {token} = bin2hex(random_bytes(32));
}
```

Se preferir gerar à mão, use este comando no servidor:

```bash
php bin/fiscal_cadastro.php --gerar-token
```

**Copie o token e entregue ao responsável pelo sistema ANTES de salvar.**
Depois de salvo ele some (só ficam os 4 últimos caracteres). Para trocar o
token, digite um novo no campo e salve: o antigo para de funcionar na hora.

### Empresas que o sistema pode usar (detalhe)

1. Crie um **Formulário Editável (grid view)** sobre `fiscal_sistemas_empresas`.
2. Configure `empresa_cnpj` como **Seleção**, buscando em `fiscal_empresas`:

   ```sql
   SELECT cnpj, cnpj || ' - ' || razao_social FROM fiscal_empresas WHERE ativo ORDER BY razao_social
   ```

3. Em **Ligações → Mestre/Detalhe**, ligue esse formulário ao de
   **Sistemas**, com `sistema_codigo` = `codigo`.

> Se um sistema não tiver nenhuma empresa marcada, ele só consegue emitir
> quando existir **uma única** empresa ativa. Com várias empresas, marque
> sempre.

---

## 6. Tela "Numeração inicial" (`fiscal_numeracao_inicial`)

Serve para informar o **último número já usado** (no ERP antigo, no portal
ou nas rotas antigas). A API continua do número seguinte.

1. Crie um **Formulário** sobre `fiscal_numeracao_inicial`, só para
   **incluir**: desligue os botões Alterar e Excluir.
2. Crie uma **Grade** para conferir o histórico.

| Campo | Tipo | Observação |
|---|---|---|
| `empresa_cnpj` | Seleção (mesma consulta do item 5) | |
| `padrao` | Seleção | `NFE` = NF-e · `NACIONAL` = NFS-e Nacional (DPS) · `SALVADOR` = NFS-e Salvador (RPS) |
| `tp_amb` | Seleção | `1` Produção · `2` Homologação |
| `serie` | Texto | NF-e: número de 0 a 999 (ex.: `1`). |
| `ultimo_numero` | Número | O **último usado**, e não o próximo. |
| `resultado` | Rótulo / somente leitura (na grade) | Ex.: "Aplicado. Próximo número: 501." |

Deixe **ocultos**: `id` e `criado_em`.

---

## 7. Colocar no menu do Scriptcase

1. Crie um item de menu **"Fiscal"** com as aplicações **Empresas**,
   **Sistemas** e **Numeração inicial**.
2. Libere esse menu **só para administradores** (módulo de Segurança do
   Scriptcase). Quem cadastra empresa e token tem o poder de emitir nota.

---

## 8. Conferir se a API está enxergando o cadastro

No servidor:

```bash
php bin/fiscal_cadastro.php --listar
```

O comando mostra cada empresa, se ela tem certificado e de onde ele vem, e
por quais empresas cada sistema pode emitir. O verificador geral também
confere o cadastro:

```bash
php bin/nfse_verificar.php
```

O worker relê o cadastro **a cada minuto**, e na hora quando um certificado
novo é aprovado. A API lê a cada chamada. **Não é preciso reiniciar nada**
depois de cadastrar.
