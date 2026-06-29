# Compatibilidade fiscal 2026+

## Objetivo

Registrar decisões técnicas para que o NfePHP suporte mudanças fiscais recentes e futuras sem criar modelagem rígida demais.

Este documento deve orientar decisões de banco, validação, processamento fiscal e evolução dos módulos de NF-e, DF-e, NFC-e, NFSe, CTe, MDFe, DCTe e obrigações relacionadas.

---

## 1. CNPJ alfanumérico

A Receita Federal definiu a adoção do CNPJ alfanumérico a partir de julho de 2026 para novas inscrições.

Os CNPJs existentes permanecem válidos e não serão alterados.

O novo CNPJ mantém 14 posições.

Decisão técnica:

- CNPJ deve ser tratado como texto, nunca como número.
- Não usar `bigint`, `integer`, `numeric` ou qualquer tipo numérico para CNPJ.
- Não aplicar validação fixa baseada apenas em dígitos.
- Não assumir regex exclusiva `^\d{14}$`.
- Normalizar CNPJ sem máscara.
- Preparar validação futura para aceitar letras e números.

Formato lógico esperado:

```text
^[A-Z0-9]{14}$
```

Observação:

Enquanto bibliotecas externas, SEFAZ ou schemas ainda exigirem CNPJ numérico em determinados fluxos, a aplicação deve tratar essa restrição no ponto de integração, sem contaminar a modelagem interna.

---

## 2. Identificadores fiscais como texto

Campos fiscais com tamanho fixo ou formato oficial devem ser armazenados como texto quando não forem usados para cálculo aritmético.

Exemplos:

- CNPJ;
- CPF;
- chave de acesso NF-e;
- chave de acesso NFC-e;
- chave de acesso CTe;
- chave de acesso MDFe;
- inscrição estadual;
- códigos fiscais que possam ganhar letras, zeros à esquerda ou mudança de formato.

Decisão técnica:

- Não converter identificadores fiscais para número.
- Preservar zeros à esquerda.
- Preservar tamanho oficial.
- Validar formato no domínio/aplicação, não pelo tipo numérico do banco.

---

## 3. Chave de acesso

A chave de acesso da NF-e, NFC-e, CTe e documentos similares deve continuar como texto.

Decisão técnica:

- armazenar chave como `varchar(44)` ou equivalente;
- não usar tipo numérico;
- não remover zeros à esquerda;
- não recalcular ou remontar chave a partir de partes salvo necessidade técnica validada.

---

## 4. NSU DF-e

O NSU usado na Distribuição DF-e é controle sequencial técnico da SEFAZ.

Decisão técnica:

- NSU pode ser armazenado como tipo numérico adequado;
- não confundir NSU com identificador fiscal alfanumérico;
- controle sequencial de NSU só deve avançar conforme regra operacional aprovada para DF-e.

Para a Fase 2 DF-e:

- modo `ultNSU` pode atualizar controle sequencial;
- modo `chave` não atualiza controle sequencial;
- modo `numNSU` não atualiza controle sequencial;
- `cStat 137` atualiza `ultNSU` e `maxNSU` se vierem no retorno;
- `cStat 138` atualiza `ultNSU` e `maxNSU`;
- `cStat 656` não atualiza NSU.

---

## 5. Reforma Tributária do Consumo - RTC

A Reforma Tributária do Consumo impacta leiautes fiscais, especialmente NF-e e NFC-e, com campos e regras relacionadas a IBS, CBS e IS.

Decisão técnica:

- não criar modelagem rígida demais de tributos antes da fase própria;
- não adicionar colunas de IBS, CBS e IS na Fase 2 DF-e;
- não antecipar tabela fiscal de itens/produtos sem análise do leiaute vigente;
- preservar XML bruto como fonte fiscal integral;
- projetar processamento futuro para suportar novos grupos, campos e regras de validação.

Impacto na Fase 2 DF-e:

- nenhum campo específico de RTC será criado agora;
- o foco permanece em controle NSU, bloqueio local e histórico;
- documentos recebidos por `docZip` não serão processados nesta fase.

---

## 6. SPED e mudanças de leiaute

Mudanças recentes do SPED reforçam que campos fiscais podem mudar de tipo, tamanho, regra ou orientação.

Decisão técnica:

- evitar modelagem excessivamente fechada para campos fiscais sujeitos a alteração;
- não transformar códigos fiscais em números sem necessidade real;
- manter documentos e retornos fiscais brutos quando possível;
- tratar extrações específicas em fases próprias.

---

## 7. Regra geral para o NfePHP

O NfePHP deve ser tolerante a evolução fiscal.

Antes de criar campos físicos para novas obrigações, deve haver:

1. identificação do documento fiscal afetado;
2. análise do leiaute vigente;
3. definição do objetivo do armazenamento;
4. separação entre dado bruto, dado operacional e dado fiscal processado;
5. decisão sobre validação;
6. decisão sobre impacto em consumidores da API.

---

## 8. Impacto imediato na Fase 2 DF-e

Para o primeiro script físico da Fase 2 DF-e:

- `cnpj` deve ser texto de 14 posições;
- não criar validação apenas numérica para CNPJ;
- `chave` deve ser texto de 44 posições;
- `tp_amb` pode ser numérico pequeno;
- `fonte` pode ser texto/controlador;
- `ult_nsu` e `max_nsu` podem ser numéricos;
- não criar campos de IBS, CBS ou IS;
- não criar tabela `dfe_documentos`;
- manter XML bruto salvo em storage como já ocorre hoje.

---

## Fontes oficiais consultadas

- Receita Federal: CNPJ alfanumérico
  https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2024/outubro/cnpj-tera-letras-e-numeros-a-partir-de-julho-de-2026

- Receita Federal: página oficial do CNPJ alfanumérico
  https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/programas-e-atividades/cnpj-alfanumerico

- Portal NF-e: Nota Técnica 2025.002 - Reforma Tributária do Consumo
  https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx\?tipoConteudo\=04BIflQt1aY%3D

- SPED: Guia Prático EFD ICMS IPI com vigência 2026
  https://sped.rfb.gov.br/item/show/7901
