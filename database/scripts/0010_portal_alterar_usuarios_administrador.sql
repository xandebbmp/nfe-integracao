-- ============================================================================
-- NfePHP
-- Script: 0010_portal_alterar_usuarios_administrador.sql
--
-- Objetivo:
--   Tela "Usuarios" do portal do cliente. Cada pessoa da empresa e:
--
--     administrador = true   convida colegas, desativa, redefine senha e
--                            escolhe quem tambem e administrador;
--     administrador = false  so emite e consulta (emissor).
--
--   Quem ja tinha acesso antes deste script vira administrador (era o
--   suporte que criava todos, e todos podiam o mesmo).
--   O primeiro usuario criado no painel admin para uma empresa sem
--   administrador ativo vira administrador sozinho (PortalAcesso).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0006 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'portal_usuarios' AND column_name = 'administrador'
    ) THEN
        ALTER TABLE public.portal_usuarios ADD COLUMN administrador BOOLEAN NOT NULL DEFAULT FALSE;
        -- So na primeira vez: quem ja existia vira administrador.
        UPDATE public.portal_usuarios SET administrador = TRUE;
    END IF;
END
$$;

COMMENT ON COLUMN public.portal_usuarios.administrador IS 'Pode convidar, desativar e redefinir a senha de colegas da mesma empresa.';

COMMIT;
