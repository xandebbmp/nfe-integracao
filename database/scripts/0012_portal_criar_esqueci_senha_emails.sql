-- ============================================================================
-- NfePHP
-- Script: 0012_portal_criar_esqueci_senha_emails.sql
--
-- Objetivo:
--   "Esqueci a senha" do portal, com link por e-mail.
--
--     portal_redefinicoes   um link de nova senha. Guarda so o sha256 do
--                           codigo que vai no e-mail. Vale 1 hora e uma vez.
--     sistema_emails        fila de e-mails. A tela so grava aqui; quem
--                           envia e o worker (pelo SMTP da configuracao).
--                           Assim a tela responde na hora, igual para quem
--                           existe ou nao, e um SMTP lento nao trava nada.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0006 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.portal_redefinicoes (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT NOT NULL REFERENCES public.portal_usuarios (id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,        -- sha256 do codigo do link
    expira_em TIMESTAMPTZ NOT NULL,
    usado_em TIMESTAMPTZ,                       -- preenchido quando a senha e trocada
    ip VARCHAR(45),                             -- quem pediu
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS portal_redefinicoes_usuario_idx ON public.portal_redefinicoes (usuario_id, criado_em);

CREATE TABLE IF NOT EXISTS public.sistema_emails (
    id BIGSERIAL PRIMARY KEY,
    para VARCHAR(255) NOT NULL,
    assunto VARCHAR(200) NOT NULL,
    texto TEXT NOT NULL,                        -- versao sem formatacao
    html TEXT,                                  -- versao bonita (opcional)
    status VARCHAR(12) NOT NULL DEFAULT 'PENDENTE',   -- PENDENTE | ENVIADO | ERRO
    tentativas SMALLINT NOT NULL DEFAULT 0,
    ultimo_erro VARCHAR(500),
    enviar_apos TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em TIMESTAMPTZ,

    CONSTRAINT sistema_emails_status_chk CHECK (status IN ('PENDENTE', 'ENVIADO', 'ERRO'))
);

CREATE INDEX IF NOT EXISTS sistema_emails_pendentes_idx ON public.sistema_emails (enviar_apos) WHERE status = 'PENDENTE';

COMMENT ON TABLE public.portal_redefinicoes IS 'Links de "Esqueci a senha" do portal (so o sha256 do codigo).';
COMMENT ON TABLE public.sistema_emails IS 'Fila de e-mails; o worker envia pelo SMTP configurado.';

COMMIT;
