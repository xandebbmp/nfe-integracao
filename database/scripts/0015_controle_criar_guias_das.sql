-- ============================================================================
-- NfePHP
-- Script: 0015_controle_criar_guias_das.sql
--
-- Objetivo:
--   Guia do Simples Nacional (DAS) no sistema.
--
--     fiscal_guias_das   uma guia por empresa e mes (competencia). O contador
--                        gera o DAS no site da Receita (PGDAS-D) e anexa o PDF
--                        no painel admin (Fechamento do mes). O cliente ve e
--                        baixa no portal (menu "Guias do Simples").
--
--   O PDF fica no banco (como os XMLs das notas), para API e worker poderem
--   rodar em servidores diferentes sem dividir disco. Uma guia DAS tem
--   poucos KB.
--
--   Anexar de novo no mesmo mes TROCA a guia (ex.: declaracao retificada).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0005 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.fiscal_guias_das (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    competencia CHAR(7) NOT NULL,                -- AAAA-MM: mes da receita (nao o do pagamento)
    valor NUMERIC(12, 2) NOT NULL,
    vencimento DATE NOT NULL,
    linha_digitavel VARCHAR(48),                 -- codigo de barras em numeros (48 digitos), opcional
    pdf BYTEA NOT NULL,
    nome_arquivo VARCHAR(200),
    enviado_por VARCHAR(300),                    -- quem anexou: "Nome <email>" ou "Chave de emergência"
    enviado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_guias_das_uk UNIQUE (cnpj, competencia),
    CONSTRAINT fiscal_guias_das_competencia_chk CHECK (competencia ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT fiscal_guias_das_valor_chk CHECK (valor > 0),
    CONSTRAINT fiscal_guias_das_linha_chk CHECK (linha_digitavel IS NULL OR linha_digitavel ~ '^[0-9]{48}$')
);

COMMENT ON TABLE public.fiscal_guias_das IS 'Guia DAS do Simples anexada pelo contador: uma por empresa e mes. O cliente baixa no portal.';
COMMENT ON COLUMN public.fiscal_guias_das.competencia IS 'Mes da receita (AAAA-MM). O DAS de novembro vence em dezembro.';

COMMIT;
