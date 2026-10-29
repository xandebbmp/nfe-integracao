-- ============================================================================
-- NfePHP
-- Script: 0003_nfe_criar_emissao.sql
--
-- Objetivo:
--   Controle de numeracao e registro das NF-e emitidas.
--
-- Escopo:
--   - nfe_numeracoes (ultimo nNF por empresa/ambiente/modelo/serie)
--   - nfe_documentos (estado de cada nota emitida)
--
-- Regras:
--   - (cnpj, tp_amb, modelo, serie, nnf) e unico: o mesmo numero nunca e
--     usado por duas notas diferentes.
--   - id_integracao e a chave de idempotencia do sistema consumidor:
--     reenviar o mesmo id_integracao reutiliza o mesmo numero/XML.
-- ============================================================================

BEGIN;

SET search_path TO public;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_type t
        JOIN pg_namespace n ON n.oid = t.typnamespace
        WHERE t.typname = 'nfe_status_documento_enum'
          AND n.nspname = 'public'
    ) THEN
        CREATE TYPE public.nfe_status_documento_enum AS ENUM (
            'GERADO',
            'ENVIADO',
            'AUTORIZADO',
            'DENEGADO',
            'REJEITADO',
            'ERRO_ENVIO'
        );
    END IF;
END;
$$;

-- ============================================================================
-- nfe_numeracoes
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfe_numeracoes (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL
        REFERENCES public.empresas (cnpj),
    tp_amb SMALLINT NOT NULL,
    modelo SMALLINT NOT NULL,
    serie INTEGER NOT NULL,

    ultimo_nnf INTEGER NOT NULL DEFAULT 0,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfe_numeracoes_contexto_uk
        UNIQUE (cnpj, tp_amb, modelo, serie),

    CONSTRAINT nfe_numeracoes_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT nfe_numeracoes_modelo_chk
        CHECK (modelo IN (55, 65)),

    CONSTRAINT nfe_numeracoes_serie_chk
        CHECK (serie BETWEEN 0 AND 999),

    CONSTRAINT nfe_numeracoes_nnf_chk
        CHECK (ultimo_nnf BETWEEN 0 AND 999999999)
);

-- ============================================================================
-- nfe_documentos
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfe_documentos (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL
        REFERENCES public.empresas (cnpj),
    tp_amb SMALLINT NOT NULL,
    modelo SMALLINT NOT NULL,
    serie INTEGER NOT NULL,
    nnf INTEGER NOT NULL,

    id_integracao VARCHAR(100),
    payload_hash VARCHAR(64) NOT NULL,

    chave VARCHAR(44) NOT NULL,
    status public.nfe_status_documento_enum NOT NULL,

    cstat VARCHAR(3),
    x_motivo TEXT,
    n_prot VARCHAR(20),

    dh_emi TIMESTAMP WITH TIME ZONE,
    v_nf NUMERIC(15,2),
    dest_documento VARCHAR(14),

    xml_gerado_path TEXT,
    nfe_proc_path TEXT,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfe_documentos_numero_uk
        UNIQUE (cnpj, tp_amb, modelo, serie, nnf),

    CONSTRAINT nfe_documentos_chave_uk
        UNIQUE (chave),

    CONSTRAINT nfe_documentos_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT nfe_documentos_modelo_chk
        CHECK (modelo IN (55, 65)),

    CONSTRAINT nfe_documentos_chave_chk
        CHECK (chave ~ '^[A-Z0-9]{44}$'),

    CONSTRAINT nfe_documentos_cstat_chk
        CHECK (cstat IS NULL OR cstat ~ '^[0-9]{3}$')
);

CREATE UNIQUE INDEX IF NOT EXISTS nfe_documentos_id_integracao_uk
    ON public.nfe_documentos (cnpj, tp_amb, id_integracao)
    WHERE id_integracao IS NOT NULL;

CREATE INDEX IF NOT EXISTS nfe_documentos_status_idx
    ON public.nfe_documentos (cnpj, tp_amb, status);

CREATE INDEX IF NOT EXISTS nfe_documentos_dh_emi_idx
    ON public.nfe_documentos (cnpj, tp_amb, dh_emi DESC);

COMMIT;
