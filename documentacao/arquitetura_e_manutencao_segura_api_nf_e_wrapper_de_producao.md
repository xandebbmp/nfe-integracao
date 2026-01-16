# Arquitetura e Manutenção Segura – API NF-e (Wrapper de Produção)

Este documento consolida **todas as decisões, fluxos, regras e artefatos** definidos até aqui para a API de integração de NF-e, construída como *wrapper* de scripts fiscais já homologados.

---

## 1. Princípio Fundamental

> **A API NÃO possui lógica fiscal.**

Ela atua exclusivamente como **adaptador de transporte**:

HTTP/JSON ⇄ CLI / GET

Tudo que é fiscal (XML, assinatura, envio, retorno, cStat, PDF, idempotência) permanece **exclusivamente** nos scripts existentes.

---

## 2. O que a API faz

1. Recebe requisição HTTP
2. Lê parâmetros via BODY JSON
3. Valida formato mínimo
4. Converte para o formato esperado pelo script (CLI ou $_GET)
5. Executa o script oficial
6. Devolve exatamente a saída do script

---

## 3. O que a API NÃO faz

- Não monta XML
- Não interpreta XML
- Não avalia cStat / xMotivo
- Não decide sucesso ou erro fiscal
- Não gera PDF
- Não altera scripts

---

## 4. Scripts Oficiais

### Emissão
- `public/lote_emitir.php`
- Processa uma pasta com um ou mais JSON
- Sequencial, idempotente, homologado

### Consulta
- `public/consultar.php` (CLI)

### Cancelamento
- `public/cancelar.php` (GET)

### Carta de Correção (CC-e)
- `public/cce.php` (GET)

### Inutilização
- `public/inutilizar.php`
- **Estado atual:** dispara evento e salva retorno bruto (XML)
- **Não gera PDF**

### Visualização
- `public/evento.php`
- Apenas apresenta XML/PDF já existente
- Não conversa com SEFAZ

---

## 5. Decisões Arquiteturais Importantes

- Emissão sempre via lote (mesmo unitária)
- Inutilização mantida como está
- Evento de inutilização retorna **XML apenas**
- Cancelamento e CC-e retornam PDF
- API usa sempre BODY JSON externamente
- Internamente usa CLI ou querystring (Forma 1)

---

## 6. Diagrama de Fluxo (Resumo)

Sistema Externo → API → Scripts Oficiais → SEFAZ

A API nunca fala direto com a SEFAZ.

---

## 7. Checklist de Manutenção Segura

Incluído integralmente como referência oficial para deploys e alterações futuras.

(Ver versão printável anexa ou documento físico.)

---

## 8. Regra Final

> **Em sistema fiscal, estabilidade vence elegância.**
>
> Se já funciona em homologação, não refatore.

---

Este documento deve ser mantido como **referência histórica e técnica** do projeto.

