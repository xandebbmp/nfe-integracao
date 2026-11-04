# CI — Testes automáticos no GitHub

O arquivo `.github/workflows/testes.yml` faz o GitHub rodar os testes
**sozinho** em todo Pull Request e em todo push na `main`.

## O que ele faz

1. Sobe uma máquina temporária com **PHP 8.3** e as extensões do NfePHP.
2. Sobe um **PostgreSQL 16** descartável (apagado ao final).
3. `composer install`.
4. Confere a sintaxe de **todos** os arquivos PHP (`php -l`), do JavaScript do monitor e dos scripts de implantação (`shellcheck`).
5. Roda `php tests/nfse/executar.php` **com** a integração no banco
   (fila, worker, monitor, idempotência, arquivamento). Se a parte com banco
   for pulada por algum motivo, o teste falha (`NFSE_TESTE_EXIGIR_PG=1`):
   nada de "verde" falso.

Leva cerca de 1–2 minutos. Não fala com o Sefin nem com Salvador: as
respostas do fisco são simuladas. Homologação real continua sendo feita com
`bin/nfse_homologar.php` (ver `docs/nfse-homologacao.md`).

## Como ler o resultado

No PR, na parte de baixo, aparece o check **"Testes NFS-e (PHP 8.3 + PostgreSQL 16)"**:

- ✅ verde: tudo passou.
- ❌ vermelho: clique em **Details** e abra o passo que falhou.
  - **Conferir sintaxe dos arquivos PHP**: mostra o arquivo e a linha com erro de digitação.
  - **Rodar testes da NFS-e**: procure as linhas `FALHOU` e o bloco `FALHAS:` no final.
    O nome da verificação diz o que quebrou (ex.: `DPS enviada passa no XSD oficial 1.01`).

Para reproduzir na sua máquina:

```bash
php tests/nfse/executar.php
```

Com banco (use um banco descartável — as tabelas `nfse_*` são recriadas):

```bash
NFSE_TESTE_PG="pgsql:host=127.0.0.1;dbname=nfephp_teste;user=nfephp_app;password=..." php tests/nfse/executar.php
```

## Bloquear merge com teste falhando (fazer uma vez)

Só um administrador do repositório consegue ativar:

1. GitHub → repositório → **Settings** → **Branches** (ou **Rules → Rulesets**).
2. **Add branch protection rule** (ou **New ruleset**) para a branch `main`.
3. Marque **Require status checks to pass before merging**.
4. Em "status checks", procure e selecione **Testes NFS-e (PHP 8.3 + PostgreSQL 16)**.
   (Ele só aparece na busca depois que o workflow rodou pelo menos uma vez.)
5. Recomendado também: **Require a pull request before merging**, para
   ninguém enviar direto na `main`.
6. Salve.

A partir daí o botão de merge fica bloqueado enquanto o check estiver vermelho.

## Regras para quem mexe no código

- **Nunca** apague, desative ou "pule" um teste para o CI ficar verde.
  Teste vermelho é aviso de que algo quebrou: corrija o código, ou ajuste o
  teste se a regra de negócio mudou de verdade (e explique no PR).
- Mudou uma regra? Acrescente uma verificação em `tests/nfse/executar.php`.
- Novo script SQL em `database/scripts/` com `_nfse_` no nome entra
  automaticamente nos testes, em ordem.

## Custo

Repositório privado: o GitHub dá uma cota mensal gratuita de minutos de
Actions (2.000 min/mês no plano Free). Cada execução usa ~2 min.
