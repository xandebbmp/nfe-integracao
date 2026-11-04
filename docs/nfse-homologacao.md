# Roteiro de Homologação NFS-e e NF-e

Passo a passo para homologar a NFS-e (Padrão Nacional e Salvador) com o
script `bin/nfse_homologar.php`. O script fala **direto com o fisco** (sem
fila e sem banco) e grava tudo o que foi e voltou, para facilitar ajustes.

> O script só roda com `tpAmb = 2` (homologação). Em produção, ele recusa.

---

## 1. Antes de começar

| Item | Onde | Observação |
|---|---|---|
| Certificado A1 (e-CNPJ) do emitente | `storage/certs/` (fora do Git) | O mesmo da NF-e serve, se for do mesmo CNPJ. |
| Emitente configurado | `config/nfse.local.php` → `emitente` (empresa única) ou `emitentes` (várias) | Inscrição municipal obrigatória para Salvador. |
| Ambiente | `config/nfe.php` → `tpAmb = 2` | Ou `tpAmb => 2` em `config/nfse.php`. |
| **Nacional:** CNPJ habilitado na Produção Restrita | Portal da NFS-e Nacional | Sem isso o Sefin recusa a DPS. |
| **Salvador:** contribuinte habilitado em `notahml.salvador.ba.gov.br` | Prefeitura de Salvador | O ambiente costuma ficar fora do ar; tente em horários diferentes. |
| Nota de teste | `json/nfse/homologacao_nacional.json` e `homologacao_salvador.json` | Ajuste o código do serviço e a alíquota para um serviço real da empresa. |

Exemplo de `config/nfse.local.php` para uma empresa:

```php
<?php
return [
    'emitente' => [
        'inscricao_municipal' => '123456',
        // Opcional: se o certificado da NFS-e for diferente do da NF-e.
        // 'certificado' => ['arquivo' => __DIR__ . '/../storage/certs/empresa.pfx', 'senha' => '...'],
    ],
];
```

---

## 2. Passo 1 — validar sem chamar o fisco

Confere configuração, certificado, nota de teste, montagem, assinatura e
XSD. Rode sempre primeiro: se falhar aqui, o fisco também vai recusar.

```bash
php bin/nfse_homologar.php --padrao=NACIONAL --so-validar
```

```bash
php bin/nfse_homologar.php --padrao=SALVADOR --so-validar
```

Esperado: `OK  Montar, assinar e validar no XSD` e `RESULTADO: tudo OK`.

---

## 3. Passo 2 — Padrão Nacional

```bash
php bin/nfse_homologar.php --padrao=NACIONAL
```

| Etapa | O que testa | Esperado |
|---|---|---|
| 1. Emitir | `POST /nfse` com a DPS assinada | `AUTORIZADO`, com número e chave da NFS-e |
| 2. Consultar DPS | `GET /dps/{id}` (proteção contra nota duplicada) | `AUTORIZADO`, mesma chave |
| 3. Consultar NFS-e | `GET /nfse/{chave}` + eventos | `AUTORIZADO` |
| 4. PDF | DANFSe no ADN | `nfse.pdf` gravado |
| 5. Cancelar | Evento 101101 | `REGISTRADO` |
| 6. Conferir cancelamento | Consulta após o evento | `CANCELADO` |

Para manter a nota sem cancelar (ex.: mostrar o PDF a alguém), use `--sem-cancelar`.

## 4. Passo 3 — Salvador

```bash
php bin/nfse_homologar.php --padrao=SALVADOR
```

| Etapa | O que testa | Esperado |
|---|---|---|
| 1. Enviar lote | `EnviarLoteRPS` (RPS e lote assinados) | `PROCESSANDO` com protocolo |
| 2. Aguardar processamento | `ConsultarSituacaoLoteRPS` / `ConsultarLoteRPS` | `AUTORIZADO`, com número e código de verificação |
| 3. Consultar por RPS | `ConsultarNfseRPS` | `AUTORIZADO` |
| 4. PDF | Espelho gerado pela API | `nfse.pdf` gravado |

O cancelamento não se aplica: Salvador não cancela por webservice. Depois
de cancelar no portal, teste o registro manual pela API
(`POST /v1/nfse/cancelamento_externo.php`).

**Teste extra (decisão B2):** emita uma nota com erro proposital (ex.: item
de serviço inexistente), anote o número do RPS e reenvie corrigida usando o
mesmo número:

```bash
php bin/nfse_homologar.php --padrao=SALVADOR --numero=<numero_do_rps_rejeitado>
```

Se Salvador aceitar, o reaproveitamento de número está validado.

### 4.1 NF-e (modelo 55)

Usa a nota de `json/nota_homologacao_emitir_padrao.json` (troque com
`--payload=arquivo.json`). O `emit.CNPJ` desse JSON precisa estar em
`emitentes` (com `uf` e `ie`) — hoje o JSON de exemplo usa
`07047183000140` e o `config/nfe.php` usa `41986662000160`: alinhe um dos dois.

```bash
php bin/nfse_homologar.php --padrao=NFE --so-validar
```

```bash
php bin/nfse_homologar.php --padrao=NFE
```

| Etapa | O que testa | Esperado |
|---|---|---|
| 1. Emitir | `NfeAutorizacao4` (síncrono; consulta o recibo se vier 103/105) | `AUTORIZADO`, com chave e protocolo |
| 2. Consultar | `NfeConsultaProtocolo4` pela chave | `AUTORIZADO` |
| 3. DANFE | PDF oficial | `nfse.pdf` gravado |
| 4. CC-e | Evento 110110, sequência 1 | `REGISTRADO` |
| 5. Cancelar | Evento 110111 com o protocolo | `REGISTRADO` |
| 6. Conferir cancelamento | Consulta após o evento | `CANCELADO` |

O número da nota de teste é gerado pela data/hora (`dHis`) para não colidir
com notas reais; troque com `--numero=`. Os pedidos/respostas SOAP ficam
gravados na pasta do resultado (`NN-SOAP-<operação>.txt`).

---

## 5. O que fica gravado

Cada execução cria `storage/logs/homologacao/<data>-<padrao>/` (fora do Git):

| Arquivo | Conteúdo |
|---|---|
| `resumo.txt` | Resultado de cada etapa (copie para o registro da homologação) |
| `01-POST-...txt` | Requisição e resposta completas, com o tempo de cada chamada |
| `*.envio.xml` / `*.retorno.xml` | XMLs enviados/recebidos, já descompactados |
| `envio.xml`, `nfse.xml`, `nfse.pdf` | DPS/lote assinado, NFS-e autorizada e PDF |

**Quando algo falhar, envie essa pasta** para quem for ajustar a
integração: ela tem exatamente o que o fisco recebeu e respondeu. Não há
senha nem chave privada nos arquivos.

---

## 6. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| `Certificado digital (.pfx) nao encontrado` | Caminho errado em `certPfxPath` ou `emitentes.*.certificado.arquivo`. |
| `ATENCAO: O certificado e do CNPJ ...` | Certificado de outra empresa. O fisco recusa a conexão. |
| `HTTP 401/403: acesso negado` | CNPJ não habilitado na Produção Restrita, ou certificado não aceito. |
| `Sem resposta do servidor` | Rede/firewall bloqueando o domínio do fisco, ou serviço fora do ar. Teste `curl -I https://sefin.producaorestrita.nfse.gov.br` no servidor. |
| Rejeição `E...` no Nacional | Regra do Sefin: leia a mensagem e a "correcao" no resumo. Códigos de serviço e alíquota são os erros mais comuns. |
| Lote de Salvador parado em `PROCESSANDO` | Normal por alguns minutos. Se passar das 20 consultas, rode de novo mais tarde. |
| Erro `XSD` no passo 1 | Algum campo da nota de teste fora do formato oficial: a mensagem diz qual. |

---

## 7. Registro do resultado

Ao concluir, registre como no `docs/roteiro-homologacao-nfe.md`: data,
ambiente, emitente, número/chave das notas de teste e o `resumo.txt` de
cada padrão. Em seguida, marque no PR os itens de homologação do checklist
"Antes de produção".
