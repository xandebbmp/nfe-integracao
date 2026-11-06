-- ============================================================================
-- NfePHP
-- Script: 0009_sistema_criar_ajustes.sql
--
-- Objetivo:
--   Secao "Configuracoes" da tela Sistema do painel admin.
--
--     sistema_ajustes   valores alterados pelo painel. Cada linha substitui
--                       o valor do config/nfse.php (ou nfse.local.php) com a
--                       mesma chave. Sem linha = vale o arquivo.
--                       So as chaves de src/Nfse/Dominio/AjustesPainel.php
--                       sao aceitas (senhas, tokens e ambiente ficam so no
--                       arquivo, de proposito).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0008 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.sistema_ajustes (
    chave VARCHAR(60) PRIMARY KEY,          -- ex.: portal.tentativas_login
    valor JSONB NOT NULL,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE public.sistema_ajustes IS 'Configuracoes alteradas pelo painel admin (substituem o valor do arquivo).';

COMMIT;
