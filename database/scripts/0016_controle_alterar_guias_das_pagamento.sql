-- ============================================================================
-- NfePHP
-- Script: 0016_controle_alterar_guias_das_pagamento.sql
--
-- Objetivo:
--   Marcar a guia DAS como PAGA (parte 4 da guia do Simples).
--
--     pago_em          data do pagamento informada por quem marcou
--     pago_marcado_por quem marcou: "Nome <email>" (painel), "Portal: Nome <email>"
--                      (cliente) ou "Chave de emergência"
--
--   O sistema NAO confere o pagamento na Receita: e so um registro de quem
--   pagou, para o portal parar de mostrar "Venceu". Desmarcar volta a NULL.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0015 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

ALTER TABLE public.fiscal_guias_das ADD COLUMN IF NOT EXISTS pago_em DATE;
ALTER TABLE public.fiscal_guias_das ADD COLUMN IF NOT EXISTS pago_marcado_por VARCHAR(300);

COMMENT ON COLUMN public.fiscal_guias_das.pago_em IS 'Data do pagamento informada no painel ou no portal (nao conferida na Receita). NULL = nao paga.';

COMMIT;
