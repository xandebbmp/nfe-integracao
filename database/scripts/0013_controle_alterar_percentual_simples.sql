-- ============================================================================
-- NfePHP
-- Script: 0013_controle_alterar_percentual_simples.sql
--
-- Objetivo:
--   Percentual aproximado dos tributos do Simples Nacional de cada empresa
--   (Lei 12.741/2012, campo pTotTribSN da NFS-e Nacional).
--
--   Para empresa ME/EPP o Sefin exige esse percentual (rejeicao E0712 se a
--   nota vier com "nao informar"). Com ele no cadastro, a API usa o valor
--   sozinha quando a nota nao traz valores.percentual_tributos_simples.
--   Quem informa o numero e o contador da empresa.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0005 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

ALTER TABLE public.fiscal_clientes ADD COLUMN IF NOT EXISTS percentual_tributos_simples NUMERIC(5,2);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fiscal_clientes_percentual_simples_chk') THEN
        ALTER TABLE public.fiscal_clientes ADD CONSTRAINT fiscal_clientes_percentual_simples_chk
            CHECK (percentual_tributos_simples IS NULL OR percentual_tributos_simples BETWEEN 0 AND 100);
    END IF;
END
$$;

COMMENT ON COLUMN public.fiscal_clientes.percentual_tributos_simples IS
    'Percentual aproximado dos tributos do Simples Nacional (pTotTribSN). Obrigatorio na pratica para ME/EPP no Padrao Nacional.';

COMMIT;
