-- ============================================================================
-- NfePHP
-- Script: 0017_controle_criar_declaracoes_simples.sql
--
-- Objetivo:
--   Declaracao do Simples (PGDAS-D) feita pelo sistema, pela API Integra
--   Contador do SERPRO (Fechamento do mes > "Declarar e gerar DAS").
--
--     fiscal_declaracoes_simples  historico de cada declaracao TRANSMITIDA:
--                        o que foi enviado (receitas, atividades, folha), os
--                        valores calculados pela Receita, o numero da
--                        declaracao e os PDFs (recibo e declaracao).
--                        Serve de prova do que foi declarado e para
--                        preencher a proxima (atividade e folha de salarios).
--
--   teste = TRUE: feita no ambiente de demonstracao do SERPRO (exemplo, nao
--   vale na Receita).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0005 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.fiscal_declaracoes_simples (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    competencia CHAR(7) NOT NULL,                -- AAAA-MM: mes da receita (periodo de apuracao)
    teste BOOLEAN NOT NULL DEFAULT FALSE,
    tipo_declaracao SMALLINT NOT NULL,           -- 1 = original, 2 = retificadora
    entrada JSONB NOT NULL,                      -- o que a tela mandou (receitas, atividades, folha)
    valores_devidos JSONB NOT NULL,              -- [{codigo, valor}] calculados pela Receita
    valor_total NUMERIC(12, 2) NOT NULL,
    numero_declaracao VARCHAR(40),
    transmitida_em VARCHAR(20),                  -- como a Receita devolve (AAAAMMDDHHmmSS)
    recibo_pdf BYTEA,
    declaracao_pdf BYTEA,
    enviado_por VARCHAR(300),                    -- "Nome <email>" ou "Chave de emergência"
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_declaracoes_simples_competencia_chk CHECK (competencia ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT fiscal_declaracoes_simples_tipo_chk CHECK (tipo_declaracao IN (1, 2))
);

CREATE INDEX IF NOT EXISTS fiscal_declaracoes_simples_cnpj_idx
    ON public.fiscal_declaracoes_simples (cnpj, competencia, criado_em DESC);

COMMENT ON TABLE public.fiscal_declaracoes_simples IS 'Declaracoes do Simples (PGDAS-D) transmitidas pelo sistema via SERPRO. teste = ambiente de demonstracao.';

COMMIT;
