-- ============================================================================
-- NfePHP
-- Script: 0006_portal_criar_usuarios.sql
--
-- Objetivo:
--   Portal do cliente (/portal/): a propria empresa cliente entra com e-mail
--   e senha e emite a NFS-e dela num formulario.
--
--     portal_usuarios   pessoas que entram no portal. Cada uma pertence a UMA
--                       empresa (fiscal_clientes.cnpj). Senha so como hash
--                       (password_hash do PHP). Bloqueio apos tentativas.
--     portal_sessoes    sessoes abertas (cookie). Guarda so o sha256 do valor
--                       do cookie: quem le o banco nao consegue entrar.
--     portal_servicos   servicos salvos da empresa (descricao, codigos
--                       fiscais e aliquota) para nao redigitar.
--     portal_tomadores  clientes (tomadores) da empresa, salvos sozinhos na
--                       primeira emissao.
--     portal_consultas  cache das consultas de CNPJ e CEP (APIs publicas).
--
--   A emissao continua sendo feita pela API (fila + worker). O portal so
--   monta o mesmo JSON que um sistema integrado enviaria.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0005 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ----------------------------------------------------------------------------
-- portal_usuarios
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.portal_usuarios (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,

    -- Senha provisoria criada no painel admin: pede troca no 1o acesso.
    trocar_senha BOOLEAN NOT NULL DEFAULT TRUE,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,

    -- Protecao contra tentativa e erro de senha.
    tentativas_falhas SMALLINT NOT NULL DEFAULT 0,
    bloqueado_ate TIMESTAMPTZ,

    ultimo_acesso TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT portal_usuarios_email_chk CHECK (email = lower(email) AND position('@' IN email) > 1)
);

-- Um e-mail entra em uma empresa so (o login nao pergunta a empresa).
CREATE UNIQUE INDEX IF NOT EXISTS portal_usuarios_email_uk ON public.portal_usuarios (email);
CREATE INDEX IF NOT EXISTS portal_usuarios_cnpj_idx ON public.portal_usuarios (cnpj);

COMMENT ON TABLE public.portal_usuarios IS 'Pessoas que entram no portal do cliente. Cada uma pertence a uma empresa (fiscal_clientes).';

-- ----------------------------------------------------------------------------
-- portal_sessoes
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.portal_sessoes (
    token_hash CHAR(64) PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES public.portal_usuarios (id) ON DELETE CASCADE,
    expira_em TIMESTAMPTZ NOT NULL,
    ip VARCHAR(45),
    navegador VARCHAR(300),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS portal_sessoes_usuario_idx ON public.portal_sessoes (usuario_id);
CREATE INDEX IF NOT EXISTS portal_sessoes_expira_idx ON public.portal_sessoes (expira_em);

COMMENT ON TABLE public.portal_sessoes IS 'Sessoes abertas do portal. So o sha256 do cookie e guardado.';

-- ----------------------------------------------------------------------------
-- portal_servicos
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.portal_servicos (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    nome VARCHAR(120) NOT NULL,

    -- Pode ter {mes} (ex.: "referente a {mes}"), trocado por "novembro/2026".
    descricao VARCHAR(2000) NOT NULL,
    codigo_tributacao_nacional VARCHAR(6),
    item_lista_servico VARCHAR(5),
    codigo_tributacao_municipal VARCHAR(20),
    codigo_cnae VARCHAR(7),
    aliquota_iss NUMERIC(5, 2),
    valor_padrao NUMERIC(15, 2),

    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT portal_servicos_aliquota_chk CHECK (aliquota_iss IS NULL OR (aliquota_iss >= 0 AND aliquota_iss <= 5))
);

CREATE INDEX IF NOT EXISTS portal_servicos_cnpj_idx ON public.portal_servicos (cnpj, ativo);

COMMENT ON TABLE public.portal_servicos IS 'Servicos salvos de cada empresa (codigos fiscais e aliquota) para o formulario do portal.';

-- ----------------------------------------------------------------------------
-- portal_tomadores
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.portal_tomadores (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    cpf_cnpj VARCHAR(14) NOT NULL,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(160),
    telefone VARCHAR(20),
    cep VARCHAR(8),
    logradouro VARCHAR(150),
    numero VARCHAR(20),
    complemento VARCHAR(60),
    bairro VARCHAR(80),
    codigo_municipio VARCHAR(7),
    cidade VARCHAR(80),
    uf CHAR(2),
    ultima_emissao TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Um cadastro por tomador em cada empresa (a emissao atualiza o mesmo).
CREATE UNIQUE INDEX IF NOT EXISTS portal_tomadores_uk ON public.portal_tomadores (cnpj, cpf_cnpj);

COMMENT ON TABLE public.portal_tomadores IS 'Tomadores (clientes) de cada empresa, salvos na emissao pelo portal.';

-- ----------------------------------------------------------------------------
-- portal_consultas (cache de CNPJ e CEP)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.portal_consultas (
    tipo VARCHAR(10) NOT NULL,          -- CNPJ ou CEP
    chave VARCHAR(14) NOT NULL,
    dados JSONB NOT NULL,
    consultado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tipo, chave)
);

COMMENT ON TABLE public.portal_consultas IS 'Cache das consultas publicas de CNPJ e CEP feitas pelo portal.';

COMMIT;
