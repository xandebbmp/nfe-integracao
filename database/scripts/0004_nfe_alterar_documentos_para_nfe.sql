-- ============================================================================
-- NfePHP
-- Script: 0004_nfe_alterar_documentos_para_nfe.sql
--
-- Objetivo:
--   Levar a NF-e (modelo 55) para a mesma estrutura da NFS-e: fila, worker,
--   monitor, status padronizado, webhook, multiempresa e arquivamento.
--
--   As tabelas continuam com o prefixo nfse_ (nome historico): a partir
--   deste script elas guardam TODOS os documentos fiscais da API. O campo
--   "padrao" diz qual e o documento:
--     NACIONAL / SALVADOR -> NFS-e
--     NFE                 -> NF-e modelo 55 (SEFAZ estadual)
--
-- Mudancas:
--   - padrao 'NFE' em nfse_documentos e nfse_numeracao;
--   - status 'DENEGADO' (NF-e com uso denegado: numero consumido, sem valor);
--   - chave de acesso com 44 digitos (NF-e) ou 50 (NFS-e Nacional);
--   - protocolo de autorizacao da NF-e (necessario para cancelar);
--   - sequencia do evento (Carta de Correcao: 1, 2, 3...).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0003 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ----------------------------------------------------------------------------
-- nfse_documentos
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_documentos DROP CONSTRAINT IF EXISTS nfse_documentos_padrao_chk;
ALTER TABLE public.nfse_documentos
    ADD CONSTRAINT nfse_documentos_padrao_chk
        CHECK (padrao IN ('NACIONAL', 'SALVADOR', 'NFE'));

ALTER TABLE public.nfse_documentos DROP CONSTRAINT IF EXISTS nfse_documentos_status_chk;
ALTER TABLE public.nfse_documentos
    ADD CONSTRAINT nfse_documentos_status_chk
        CHECK (status IN (
            'RECEBIDO',
            'PROCESSANDO',
            'AUTORIZADO',
            'REJEITADO',
            'DENEGADO',
            'ERRO',
            'CANCELADO',
            'SUBSTITUIDO'
        ));

ALTER TABLE public.nfse_documentos DROP CONSTRAINT IF EXISTS nfse_documentos_chave_chk;
ALTER TABLE public.nfse_documentos
    ADD CONSTRAINT nfse_documentos_chave_chk
        CHECK (chave_acesso IS NULL OR chave_acesso ~ '^([0-9]{44}|[0-9]{50})$');

ALTER TABLE public.nfse_documentos
    ADD COLUMN IF NOT EXISTS protocolo_autorizacao VARCHAR(20);

-- ----------------------------------------------------------------------------
-- nfse_numeracao (numero da NF-e por CNPJ + ambiente + serie)
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_numeracao DROP CONSTRAINT IF EXISTS nfse_numeracao_padrao_chk;
ALTER TABLE public.nfse_numeracao
    ADD CONSTRAINT nfse_numeracao_padrao_chk
        CHECK (padrao IN ('NACIONAL', 'SALVADOR', 'NFE'));

-- ----------------------------------------------------------------------------
-- nfse_eventos
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_eventos
    ADD COLUMN IF NOT EXISTS sequencia SMALLINT;

ALTER TABLE public.nfse_eventos DROP CONSTRAINT IF EXISTS nfse_eventos_sequencia_chk;
ALTER TABLE public.nfse_eventos
    ADD CONSTRAINT nfse_eventos_sequencia_chk
        CHECK (sequencia IS NULL OR sequencia BETWEEN 1 AND 20);

COMMIT;
