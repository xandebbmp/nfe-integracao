-- ============================================================================
-- NfePHP
-- Script: 0008_sistema_criar_registro_scripts.sql
--
-- Objetivo:
--   Botao "Atualizar banco" do painel admin (tela Sistema).
--
--     sistema_scripts   quais scripts de database/scripts/ ja rodaram neste
--                       banco, com o sha256 do arquivo. Script que nao esta
--                       aqui (ou cujo arquivo mudou) aparece como pendente.
--
--   Preenchida pelo painel e tambem pelo deploy/aplicar_banco.sh.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0007 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.sistema_scripts (
    nome VARCHAR(120) PRIMARY KEY,          -- ex.: 0007_sistema_criar_controle_worker.sql
    sha256 CHAR(64) NOT NULL,
    origem VARCHAR(20) NOT NULL,            -- PAINEL | TERMINAL
    aplicado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE public.sistema_scripts IS 'Scripts de database/scripts ja aplicados neste banco (botao Atualizar banco do painel).';

COMMIT;
