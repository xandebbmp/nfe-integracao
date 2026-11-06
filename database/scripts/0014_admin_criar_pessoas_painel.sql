-- ============================================================================
-- NfePHP
-- Script: 0014_admin_criar_pessoas_painel.sql
--
-- Objetivo:
--   Login do painel admin com e-mail e senha, uma conta por pessoa.
--
--     admin_usuarios   quem entra no painel. Senha so como hash (bcrypt).
--     admin_sessoes    login aberto. Guarda so o sha256 do codigo que fica
--                      no navegador.
--     fiscal_admin_log ganha a coluna "autor": quem fez cada mudanca.
--
--   O admin_token do config/nfse.local.php continua valendo como CHAVE DE
--   EMERGENCIA (para cadastrar a primeira pessoa ou se todos ficarem fora).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0005 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.admin_usuarios (
    id BIGSERIAL PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,          -- sempre em minusculas
    senha_hash VARCHAR(255) NOT NULL,
    trocar_senha BOOLEAN NOT NULL DEFAULT TRUE,  -- senha provisoria: troca no 1o acesso
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    tentativas_falhas INTEGER NOT NULL DEFAULT 0,
    bloqueado_ate TIMESTAMPTZ,
    ultimo_acesso TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS public.admin_sessoes (
    token_hash CHAR(64) PRIMARY KEY,             -- sha256 do codigo do navegador
    usuario_id BIGINT NOT NULL REFERENCES public.admin_usuarios (id) ON DELETE CASCADE,
    expira_em TIMESTAMPTZ NOT NULL,
    ip VARCHAR(45),
    navegador VARCHAR(300),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS admin_sessoes_usuario_idx ON public.admin_sessoes (usuario_id);

ALTER TABLE public.fiscal_admin_log ADD COLUMN IF NOT EXISTS autor VARCHAR(300);

COMMENT ON TABLE public.admin_usuarios IS 'Pessoas que entram no painel admin com e-mail e senha.';
COMMENT ON TABLE public.admin_sessoes IS 'Logins abertos no painel admin (so o sha256 do codigo).';
COMMENT ON COLUMN public.fiscal_admin_log.autor IS 'Quem fez a acao: "Nome <email>" ou "Chave de emergência".';

COMMIT;
