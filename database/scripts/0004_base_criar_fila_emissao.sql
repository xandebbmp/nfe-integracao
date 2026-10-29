-- ============================================================================
-- NfePHP
-- Script: 0004_base_criar_fila_emissao.sql
--
-- Objetivo:
--   Fila de emissao assincrona (POST de lote -> worker).
--
-- Escopo:
--   - emissao_lotes (cada POST recebido)
--   - emissao_fila (um job por documento a emitir)
--   - emissao_lote_itens (quais jobs cada lote recebeu; um job pode estar
--     em varios lotes quando o mesmo idIntegracao e reenviado)
--
-- Regras:
--   - tipo_documento preparado para NF-e e NFS-e.
--   - (cnpj, tipo_documento, id_integracao) e unico: reenviar o mesmo
--     idIntegracao nunca cria um segundo job.
--   - Workers disputam jobs com FOR UPDATE SKIP LOCKED.
-- ============================================================================

BEGIN;

SET search_path TO public;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_type t
        JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE t.typname = 'emissao_tipo_documento_enum'
          AND n.nspname = 'public'
    ) THEN
        CREATE TYPE public.emissao_tipo_documento_enum AS ENUM (
            'NFE',
            'NFSE'
        );
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_type t
        JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE t.typname = 'emissao_status_job_enum'
          AND n.nspname = 'public'
    ) THEN
        CREATE TYPE public.emissao_status_job_enum AS ENUM (
            'PENDENTE',
            'PROCESSANDO',
            'CONCLUIDO',
            'FALHOU'
        );
    END IF;
END;
$$;

-- ============================================================================
-- emissao_lotes
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.emissao_lotes (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL
        REFERENCES public.empresas (cnpj),
    tp_amb SMALLINT NOT NULL,
    tipo_documento public.emissao_tipo_documento_enum NOT NULL,
    total INTEGER NOT NULL,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT emissao_lotes_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT emissao_lotes_total_chk
        CHECK (total > 0)
);

-- ============================================================================
-- emissao_fila
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.emissao_fila (
    id BIGSERIAL PRIMARY KEY,

    -- Ultimo lote que (re)enfileirou o job
    lote_id BIGINT NOT NULL
        REFERENCES public.emissao_lotes (id),

    cnpj VARCHAR(14) NOT NULL
        REFERENCES public.empresas (cnpj),
    tp_amb SMALLINT NOT NULL,
    tipo_documento public.emissao_tipo_documento_enum NOT NULL,
    id_integracao VARCHAR(100) NOT NULL,

    payload JSONB NOT NULL,

    status public.emissao_status_job_enum NOT NULL DEFAULT 'PENDENTE',
    tentativas INTEGER NOT NULL DEFAULT 0,
    max_tentativas INTEGER NOT NULL DEFAULT 6,
    disponivel_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    travado_em TIMESTAMP WITHOUT TIME ZONE,
    travado_por VARCHAR(100),

    -- Resultado do documento (espelho de nfe_documentos para leitura rapida)
    status_documento VARCHAR(20),
    chave VARCHAR(44),
    cstat VARCHAR(3),
    x_motivo TEXT,
    ultimo_erro TEXT,
    saida TEXT,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT emissao_fila_id_integracao_uk
        UNIQUE (cnpj, tp_amb, tipo_documento, id_integracao),

    CONSTRAINT emissao_fila_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT emissao_fila_tentativas_chk
        CHECK (tentativas >= 0 AND max_tentativas > 0)
);

CREATE INDEX IF NOT EXISTS emissao_fila_pendentes_idx
    ON public.emissao_fila (disponivel_em, id)
    WHERE status = 'PENDENTE';

CREATE INDEX IF NOT EXISTS emissao_fila_processando_idx
    ON public.emissao_fila (travado_em)
    WHERE status = 'PROCESSANDO';

CREATE INDEX IF NOT EXISTS emissao_fila_lote_idx
    ON public.emissao_fila (lote_id);

-- ============================================================================
-- emissao_lote_itens
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.emissao_lote_itens (
    id BIGSERIAL PRIMARY KEY,

    lote_id BIGINT NOT NULL
        REFERENCES public.emissao_lotes (id),
    fila_id BIGINT NOT NULL
        REFERENCES public.emissao_fila (id),

    -- novo | existente | reenfileirado | conflito
    acao VARCHAR(20) NOT NULL,

    CONSTRAINT emissao_lote_itens_uk
        UNIQUE (lote_id, fila_id),

    CONSTRAINT emissao_lote_itens_acao_chk
        CHECK (acao IN ('novo', 'existente', 'reenfileirado', 'conflito'))
);

CREATE INDEX IF NOT EXISTS emissao_lote_itens_fila_idx
    ON public.emissao_lote_itens (fila_id);

COMMIT;
