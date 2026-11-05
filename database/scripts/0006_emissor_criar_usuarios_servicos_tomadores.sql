-- ============================================================================
-- NfePHP
-- Script: 0006_emissor_criar_usuarios_servicos_tomadores.sql
--
-- Objetivo:
--   "Emissor simples" (/emissor/): o proprio cliente emite NFS-e Nacional
--   preenchendo so VALOR, TOMADOR e DESCRICAO. O resto vem do cadastro:
--
--     fiscal_clientes_servicos  servicos da empresa (codigo de tributacao
--                               nacional, aliquota, ISS retido...), cadastrados
--                               UMA vez no painel admin. Um e o padrao.
--     fiscal_usuarios           login (e-mail + senha) das pessoas de cada
--                               empresa. Senha so como hash (bcrypt).
--     fiscal_sessoes            sessoes abertas (so o hash do token).
--     fiscal_tomadores          tomadores ja usados pela empresa (para
--                               completar sozinho na proxima nota).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0005 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ----------------------------------------------------------------------------
-- fiscal_clientes_servicos
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_clientes_servicos (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    nome VARCHAR(80) NOT NULL,
    codigo_tributacao_nacional CHAR(6) NOT NULL,
    codigo_nbs VARCHAR(9),
    codigo_tributacao_municipal VARCHAR(20),
    aliquota_iss NUMERIC(5, 2),
    iss_retido BOOLEAN NOT NULL DEFAULT FALSE,
    descricao_padrao VARCHAR(2000),
    padrao BOOLEAN NOT NULL DEFAULT FALSE,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_clientes_servicos_codigo_chk CHECK (codigo_tributacao_nacional ~ '^[0-9]{6}$'),
    CONSTRAINT fiscal_clientes_servicos_nbs_chk CHECK (codigo_nbs IS NULL OR codigo_nbs ~ '^[0-9]{9}$'),
    CONSTRAINT fiscal_clientes_servicos_aliquota_chk CHECK (aliquota_iss IS NULL OR aliquota_iss BETWEEN 0 AND 100)
);

CREATE INDEX IF NOT EXISTS fiscal_clientes_servicos_cnpj_idx ON public.fiscal_clientes_servicos (cnpj);

COMMENT ON TABLE public.fiscal_clientes_servicos IS 'Servicos que cada empresa presta (dados fixos da NFS-e Nacional). Usados pelo emissor simples.';
COMMENT ON COLUMN public.fiscal_clientes_servicos.codigo_tributacao_nacional IS '6 digitos (LC 116 nacional, ex.: 010101). Pergunte ao contador.';
COMMENT ON COLUMN public.fiscal_clientes_servicos.aliquota_iss IS 'Percentual (5 = 5%). Vazio = nao informa (o Sefin calcula pelo municipio).';

-- ----------------------------------------------------------------------------
-- fiscal_usuarios
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_usuarios (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    falhas_seguidas SMALLINT NOT NULL DEFAULT 0,
    bloqueado_ate TIMESTAMPTZ,
    ultimo_acesso TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS fiscal_usuarios_email_uk ON public.fiscal_usuarios (lower(email));
CREATE INDEX IF NOT EXISTS fiscal_usuarios_cnpj_idx ON public.fiscal_usuarios (cnpj);

COMMENT ON TABLE public.fiscal_usuarios IS 'Pessoas que entram no emissor simples. Cada uma pertence a UMA empresa.';
COMMENT ON COLUMN public.fiscal_usuarios.bloqueado_ate IS 'Muitas senhas erradas seguidas: login bloqueado ate esta hora.';

-- ----------------------------------------------------------------------------
-- fiscal_sessoes
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_sessoes (
    token_hash CHAR(64) PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES public.fiscal_usuarios (id) ON DELETE CASCADE,
    expira_em TIMESTAMPTZ NOT NULL,
    ip VARCHAR(45),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS fiscal_sessoes_usuario_idx ON public.fiscal_sessoes (usuario_id);

-- ----------------------------------------------------------------------------
-- fiscal_tomadores
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_tomadores (
    cnpj_emitente VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    documento VARCHAR(14) NOT NULL,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(120),
    telefone VARCHAR(20),
    endereco JSONB,
    usos INTEGER NOT NULL DEFAULT 0,
    ultimo_uso TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (cnpj_emitente, documento),
    CONSTRAINT fiscal_tomadores_documento_chk CHECK (documento ~ '^([0-9]{11}|[0-9A-Z]{14})$')
);

COMMENT ON TABLE public.fiscal_tomadores IS 'Tomadores ja usados por cada empresa (completa sozinho na proxima nota).';

COMMIT;
