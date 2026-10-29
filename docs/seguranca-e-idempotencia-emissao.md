# Segurança e idempotência da emissão

Mudanças do passo 1 do roadmap (preparação para consumo pelo monitor fiscal).

## Autenticação (fail-closed)

- Toda rota HTTP exige `apiToken` configurado em `config/nfe.local.php`.
- Sem token configurado a API responde `503`; token ausente ou inválido responde `401`.
- CLI continua liberado (scripts de lote e homologação).
- `raw.output` / `paths` de debug só aparecem com `debugRawOutput=true` **e** token válido.
  A exceção para requisição "local" foi removida: atrás de proxy reverso todo acesso chega como `127.0.0.1`.

## Métodos HTTP

- `cancelar_padrao`, `cce_padrao` e `inutilizar_padrao` aceitam somente `POST`.
- `dfe_padrao` foi incluído no roteador `public/index.php` (GET e POST).

## Document root

- Em produção o document root deve ser `public/`.
- O `.htaccess` da raiz devolve 404 para `storage/`, `config/`, `vendor/` etc. quando o Apache
  aponta para a raiz do projeto (WAMP). Requer `AllowOverride FileInfo` (ou `All`).

## Chave de acesso determinística

- Quando o JSON não informa `ide.cNF`, ele é derivado por HMAC de
  `(CNPJ, tpAmb, mod, serie, nNF)` com `cnfSecret` (ou o hash do PFX, se vazio).
- Reenviar a mesma nota (mesmo `nNF` e `dhEmi`) gera a **mesma chave**.
- `cnfSecret` não deve ser trocado depois que a emissão começar.

## Duplicidade (cStat 204)

- Se a SEFAZ responde 204 (nota já recebida, ex.: timeout na tentativa anterior),
  `assinar_enviar_sincrono.php` consulta a chave, recupera o protocolo e monta o `nfeProc`.
- Se o XML da tentativa anterior era diferente (digest diverge), a montagem falha e a nota
  precisa de tratamento manual (consultar a chave e recuperar o XML original).

## Outras correções

- `ide.tpAmb` do JSON precisa bater com o `tpAmb` da config (senão erro antes do envio).
- Denegação reconhece 110, 205, 301, 302 e 303.
- `/nfe/emitir` (`public/api.php`) devolve `DENEGADO` em vez de `OK` para nota denegada.
- CNPJ do emitente, destinatário e autXML preserva letras (CNPJ alfanumérico).
- Fuso horário definido por `timezone` na config (padrão `America/Bahia`).
