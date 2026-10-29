-- ============================================================================
-- NfePHP
-- Script: 0002_base_criar_empresas.sql
--
-- Objetivo:
--   Cadastro multiempresa do servico fiscal.
--
-- Escopo:
--   - empresas (emitentes/destinatarios atendidos pelo servico)
--   - empresa_certificados (PFX A1 cifrado pela aplicacao)
--
-- Observacoes:
--   - CNPJ e texto (CNPJ alfanumerico a partir de 07/2026).
--   - pfx_cifrado e senha_cifrada usam libsodium (secretbox) com a chave
--     mestra NFE_MASTER_KEY; o banco nunca ve o certificado em claro.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ============================================================================
-- empresas
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.empresas (
    id BIGSERIAL PRIMARY KEY,

    cnpj VARCHAR(14) NOT NULL,
    razao_social VARCHAR(60) NOT NULL,
    nome_fantasia VARCHAR(60),
    ie VARCHAR(14),
    im VARCHAR(15),
    uf CHAR(2) NOT NULL,
    c_mun INTEGER NOT NULL,
    crt SMALLINT NOT NULL,

    tp_amb SMALLINT NOT NULL DEFAULT 2,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT empresas_cnpj_uk
        UNIQUE (cnpj),

    CONSTRAINT empresas_cnpj_chk
        CHECK (cnpj ~ '^[A-Z0-9]{14}$'),

    CONSTRAINT empresas_uf_chk
        CHECK (uf ~ '^[A-Z]{2}$'),

    CONSTRAINT empresas_c_mun_chk
        CHECK (c_mun BETWEEN 1000000 AND 9999999),

    CONSTRAINT empresas_crt_chk
        CHECK (crt IN (1, 2, 3, 4)),

    CONSTRAINT empresas_tp_amb_chk
        CHECK (tp_amb IN (1, 2))
);

-- ============================================================================
-- empresa_certificados
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.empresa_certificados (
    id BIGSERIAL PRIMARY KEY,

    empresa_id BIGINT NOT NULL
        REFERENCES public.empresas (id),

    pfx_cifrado BYTEA NOT NULL,
    senha_cifrada BYTEA NOT NULL,

    titular TEXT,
    impressao_digital VARCHAR(64) NOT NULL,
    valido_de TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    valido_ate TIMESTAMP WITHOUT TIME ZONE NOT NULL,

    ativo BOOLEAN NOT NULL DEFAULT TRUE,

    criado_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT empresa_certificados_validade_chk
        CHECK (valido_ate > valido_de),

    CONSTRAINT empresa_certificados_impressao_uk
        UNIQUE (empresa_id, impressao_digital)
);

-- No maximo um certificado ativo por empresa.
CREATE UNIQUE INDEX IF NOT EXISTS empresa_certificados_ativo_uk
    ON public.empresa_certificados (empresa_id)
    WHERE ativo;

CREATE INDEX IF NOT EXISTS empresa_certificados_vencimento_idx
    ON public.empresa_certificados (valido_ate)
    WHERE ativo;

COMMIT;
