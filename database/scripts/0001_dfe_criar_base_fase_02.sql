-- ============================================================================
-- NfePHP
-- Script: 0001_dfe_criar_base_fase_02.sql
--
-- Objetivo:
--   Criar a base física inicial da Fase 2 do DF-e.
--
-- Escopo:
--   - ENUMs operacionais do DF-e
--   - dfe_controles
--   - dfe_bloqueios
--   - dfe_historicos
--   - constraints mínimas de integridade
--   - índices básicos para consulta operacional
--
-- Fora do escopo:
--   - execução automática
--   - conexão/repository
--   - endpoint HTTP
--   - descompactação de docZip
--   - dfe_documentos
--   - manifestação do destinatário
--   - cron/fila/retry automático
--   - campos específicos de RTC
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ============================================================================
-- ENUMs
-- ============================================================================

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_type t
        JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE t.typname = 'dfe_modo_consulta_enum'
          AND n.nspname = 'public'
    ) THEN
        CREATE TYPE public.dfe_modo_consulta_enum AS ENUM (
            'chave',
            'numNSU',
            'ultNSU'
        );
    END IF;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_type t
        JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE t.typname = 'dfe_status_operacional_enum'
          AND n.nspname = 'public'
    ) THEN
        CREATE TYPE public.dfe_status_operacional_enum AS ENUM (
            'ENVIADO_SEFAZ',
            'BLOQUEADO_LOCALMENTE',
            'ERRO_TECNICO_ANTES_SEFAZ',
            'ERRO_APOS_TENTATIVA_SEFAZ'
        );
    END IF;
END;
$$;

-- ============================================================================
-- dfe_controles
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.dfe_controles (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL,
    tp_amb SMALLINT NOT NULL,
    fonte VARCHAR(2) NOT NULL,

    ult_nsu NUMERIC(15,0) NOT NULL DEFAULT 0,
    max_nsu NUMERIC(15,0) NOT NULL DEFAULT 0,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT dfe_controles_cnpj_chk
        CHECK (cnpj ~ '^[A-Z0-9]{14}$'),

    CONSTRAINT dfe_controles_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT dfe_controles_fonte_chk
        CHECK (fonte IN ('AN', 'RS')),

    CONSTRAINT dfe_controles_nsu_chk
        CHECK (ult_nsu >= 0 AND max_nsu >= 0),

    CONSTRAINT dfe_controles_contexto_uk
        UNIQUE (cnpj, tp_amb, fonte)
);

CREATE INDEX IF NOT EXISTS dfe_controles_contexto_idx
    ON public.dfe_controles (cnpj, tp_amb, fonte);

-- ============================================================================
-- dfe_bloqueios
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.dfe_bloqueios (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL,
    tp_amb SMALLINT NOT NULL,
    fonte VARCHAR(2) NOT NULL,

    cstat_origem VARCHAR(3) NOT NULL,
    motivo_origem TEXT,
    bloqueado_ate TIMESTAMP WITHOUT TIME ZONE NOT NULL,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT dfe_bloqueios_cnpj_chk
        CHECK (cnpj ~ '^[A-Z0-9]{14}$'),

    CONSTRAINT dfe_bloqueios_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT dfe_bloqueios_fonte_chk
        CHECK (fonte IN ('AN', 'RS')),

    CONSTRAINT dfe_bloqueios_cstat_origem_chk
        CHECK (cstat_origem IN ('137', '656')),

    CONSTRAINT dfe_bloqueios_bloqueado_ate_chk
        CHECK (bloqueado_ate > criado_em)
);

CREATE INDEX IF NOT EXISTS dfe_bloqueios_contexto_idx
    ON public.dfe_bloqueios (cnpj, tp_amb, fonte);

CREATE INDEX IF NOT EXISTS dfe_bloqueios_ativos_idx
    ON public.dfe_bloqueios (cnpj, tp_amb, fonte, bloqueado_ate);

-- ============================================================================
-- dfe_historicos
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.dfe_historicos (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL,
    tp_amb SMALLINT NOT NULL,
    fonte VARCHAR(2) NOT NULL,

    modo public.dfe_modo_consulta_enum NOT NULL,

    chave VARCHAR(44),
    num_nsu NUMERIC(15,0),
    ult_nsu_enviado NUMERIC(15,0),

    cstat VARCHAR(3),
    x_motivo TEXT,

    status_operacional public.dfe_status_operacional_enum NOT NULL,
    houve_chamada_sefaz BOOLEAN NOT NULL DEFAULT FALSE,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT dfe_historicos_cnpj_chk
        CHECK (cnpj ~ '^[A-Z0-9]{14}$'),

    CONSTRAINT dfe_historicos_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT dfe_historicos_fonte_chk
        CHECK (fonte IN ('AN', 'RS')),

    CONSTRAINT dfe_historicos_chave_chk
        CHECK (chave IS NULL OR chave ~ '^[A-Z0-9]{44}$'),

    CONSTRAINT dfe_historicos_num_nsu_chk
        CHECK (num_nsu IS NULL OR num_nsu >= 0),

    CONSTRAINT dfe_historicos_ult_nsu_enviado_chk
        CHECK (ult_nsu_enviado IS NULL OR ult_nsu_enviado >= 0),

    CONSTRAINT dfe_historicos_cstat_chk
        CHECK (cstat IS NULL OR cstat ~ '^[0-9]{3}$'),

    CONSTRAINT dfe_historicos_modo_dados_chk
        CHECK (
            (modo = 'chave' AND chave IS NOT NULL AND num_nsu IS NULL AND ult_nsu_enviado IS NULL)
            OR
            (modo = 'numNSU' AND chave IS NULL AND num_nsu IS NOT NULL AND ult_nsu_enviado IS NULL)
            OR
            (modo = 'ultNSU' AND chave IS NULL AND num_nsu IS NULL AND ult_nsu_enviado IS NOT NULL)
        ),

    CONSTRAINT dfe_historicos_chamada_sefaz_chk
        CHECK (
            (
                status_operacional IN ('ENVIADO_SEFAZ', 'ERRO_APOS_TENTATIVA_SEFAZ')
                AND houve_chamada_sefaz = TRUE
            )
            OR
            (
                status_operacional IN ('BLOQUEADO_LOCALMENTE', 'ERRO_TECNICO_ANTES_SEFAZ')
                AND houve_chamada_sefaz = FALSE
            )
        ),

    CONSTRAINT dfe_historicos_enviado_sefaz_cstat_chk
        CHECK (
            status_operacional <> 'ENVIADO_SEFAZ'
            OR cstat IS NOT NULL
        )
);

CREATE INDEX IF NOT EXISTS dfe_historicos_contexto_criado_idx
    ON public.dfe_historicos (cnpj, tp_amb, fonte, criado_em DESC);

CREATE INDEX IF NOT EXISTS dfe_historicos_modo_idx
    ON public.dfe_historicos (modo);

CREATE INDEX IF NOT EXISTS dfe_historicos_cstat_idx
    ON public.dfe_historicos (cstat);

CREATE INDEX IF NOT EXISTS dfe_historicos_status_operacional_idx
    ON public.dfe_historicos (status_operacional);

COMMIT;
