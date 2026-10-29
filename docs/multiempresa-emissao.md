# Multiempresa e emissão com numeração controlada

## Seleção da empresa

| Contexto | Como informar |
|----------|---------------|
| HTTP | header `X-NFE-EMPRESA: <CNPJ>` (com ou sem máscara) |
| CLI | variável `NFE_EMPRESA_CNPJ=<CNPJ>` |

Com empresa selecionada, `nfe_config()` sobrepõe a config base com os dados de `empresas` e o
certificado ativo (decifrado em memória). XMLs/PDFs/logs ficam em `storage/<pasta>/<CNPJ>/`.

Erros de empresa (não cadastrada, inativa, sem certificado, certificado vencido) respondem `422`.
Sem o header, vale a config única de `config/nfe.php` (modo legado).

O token atual dá acesso a todas as empresas cadastradas: é pensado para um único consumidor
(Financeiro Universal). Tokens por cliente/empresa ficam para uma fase posterior.

## Numeração (`nfe_numeracoes`)

- Sem `ide.nNF` no JSON, o número é reservado no banco por empresa/ambiente/modelo/série.
- A reserva é atômica: requisições simultâneas recebem números diferentes.
- Se o JSON informar `ide.nNF`, ele é aceito e a sequência avança para não reutilizá-lo.
- O mesmo número nunca pertence a duas notas (`nfe_documentos_numero_uk`).

## Idempotência (`idIntegracao`)

O Financeiro Universal deve mandar um `idIntegracao` único por nota (ex.: id do lançamento).

| Status atual da nota | Reenvio com o mesmo `idIntegracao` |
|----------------------|-------------------------------------|
| (não existe) | reserva número, gera XML, envia |
| `GERADO` / `ENVIADO` / `ERRO_ENVIO` | reenvia **o mesmo XML** (mesma chave); conteúdo diferente é recusado até a situação ser esclarecida |
| `REJEITADO` | regera com o **mesmo nNF** e o conteúdo novo (corrigido) |
| `AUTORIZADO` | não reenvia; devolve a nota autorizada |
| `DENEGADO` | recusado: nota denegada não pode ser reemitida |

`ERRO_ENVIO` significa resultado incerto (timeout, queda de conexão): a nota pode ter chegado à
SEFAZ. O reenvio do mesmo XML cai em duplicidade (204) e o protocolo é recuperado pela consulta
da chave (ver `docs/seguranca-e-idempotencia-emissao.md`).

## Status

| Status | Significado |
|--------|-------------|
| `GERADO` | XML montado e salvo, ainda não enviado |
| `ENVIADO` | envio em andamento |
| `ERRO_ENVIO` | falha de comunicação ou SEFAZ paralisada (108/109): reenviar o mesmo XML |
| `AUTORIZADO` | cStat 100/150, `nfe_proc_path` e `n_prot` preenchidos |
| `DENEGADO` | cStat 110/205/301/302/303 |
| `REJEITADO` | rejeição (lote ou protocolo): corrigir e reenviar |

## Administração

Ver `bin/empresa.php` (cabeçalho) e `docs/deploy-vps.md`, passo 6.
