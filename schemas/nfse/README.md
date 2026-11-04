# Schemas XSD da NFS-e

Usados para validar o XML **antes** de enviar ao fisco (classe
`src/Nfse/Infra/ValidadorXsd.php`). Erro de schema vira `REJEITADO` com
código `XSD`, sem gastar chamada no webservice.

| Pasta | Origem | Uso |
|---|---|---|
| `nacional/1.01/` | Leiaute oficial da NFS-e Nacional v1.01 (gov.br/nfse – documentação técnica) | DPS e pedido de evento |
| `salvador/1.00/` | `nfse.xsd` ABRASF 1.00 com as alterações da Prefeitura de Salvador (comentários "ALTERADO POR SALVADOR") | Envio de lote RPS e consultas |

## Ajustes locais

Mantemos os arquivos idênticos aos oficiais, exceto:

1. `nacional/1.01/tiposSimples_v1.01.xsd`, tipo `TSSerieDPS`: o oficial usa
   `^0{0,4}\d{1,5}$`. Em XSD a expressão regular já é ancorada e `^`/`$` são
   caracteres literais, então **nenhuma** série seria aceita localmente.
   Trocamos por `0{0,4}\d{1,5}`. A versão 1.00 nem tinha essa regra.

## Ao atualizar o leiaute

1. Baixe os novos XSDs no portal oficial e crie uma pasta nova
   (ex.: `nacional/1.02/`). Não sobrescreva a anterior.
2. Reaplique (ou remova, se corrigido) o ajuste acima.
3. Altere `nacional.versao_leiaute` em `config/nfse.php`.
4. Rode `php tests/nfse/executar.php`.
