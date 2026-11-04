-- ============================================================================
-- NfePHP
-- Script: 0003_nfse_alterar_multiempresa_rejeicao_arquivo.sql
--
-- Objetivo:
--   Ajustes da NFS-e decididos apos a primeira entrega
--   (ver docs/nfse-duvidas-processo.md, secao "Decisoes tomadas"):
--
--   1. Documento invalido na ENTRADA tambem e gravado (status REJEITADO),
--      para aparecer no monitor com o motivo e a sugestao de correcao.
--      Ele ainda nao consome numero de DPS/RPS: serie/numero passam a
--      aceitar NULL ate o documento ficar valido.
--   2. Cancelamento registrado manualmente (Salvador cancela so pelo portal):
--      origem do evento, numero do processo e observacao.
--   3. Multiempresa: emitente no feed do monitor para filtrar no painel.
--   4. Arquivamento dos XMLs em armazenamento externo (nuvem): novo tipo de
--      job ARQUIVAR e data do arquivamento.
--
-- Requisito: 0002_nfse_criar_base.sql aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ----------------------------------------------------------------------------
-- 1. Documento rejeitado na entrada (sem numero)
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_documentos ALTER COLUMN serie DROP NOT NULL;
ALTER TABLE public.nfse_documentos ALTER COLUMN numero DROP NOT NULL;

ALTER TABLE public.nfse_documentos DROP CONSTRAINT IF EXISTS nfse_documentos_numero_chk;
ALTER TABLE public.nfse_documentos
    ADD CONSTRAINT nfse_documentos_numero_chk
        CHECK (numero IS NULL OR numero > 0);

-- Serie e numero andam juntos: ou os dois existem, ou nenhum.
ALTER TABLE public.nfse_documentos DROP CONSTRAINT IF EXISTS nfse_documentos_serie_numero_chk;
ALTER TABLE public.nfse_documentos
    ADD CONSTRAINT nfse_documentos_serie_numero_chk
        CHECK ((serie IS NULL) = (numero IS NULL));

-- Sem numero, o documento so pode estar REJEITADO (erro de entrada).
ALTER TABLE public.nfse_documentos DROP CONSTRAINT IF EXISTS nfse_documentos_sem_numero_chk;
ALTER TABLE public.nfse_documentos
    ADD CONSTRAINT nfse_documentos_sem_numero_chk
        CHECK (numero IS NOT NULL OR status = 'REJEITADO');

CREATE INDEX IF NOT EXISTS nfse_documentos_emitente_idx
    ON public.nfse_documentos (emitente_cnpj, status, atualizado_em DESC);

-- ----------------------------------------------------------------------------
-- 2. Cancelamento registrado fora do webservice
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_eventos
    ADD COLUMN IF NOT EXISTS origem VARCHAR(10) NOT NULL DEFAULT 'API';

ALTER TABLE public.nfse_eventos
    ADD COLUMN IF NOT EXISTS numero_processo VARCHAR(60);

ALTER TABLE public.nfse_eventos
    ADD COLUMN IF NOT EXISTS observacao TEXT;

ALTER TABLE public.nfse_eventos
    ADD COLUMN IF NOT EXISTS registrado_por VARCHAR(60);

ALTER TABLE public.nfse_eventos DROP CONSTRAINT IF EXISTS nfse_eventos_origem_chk;
ALTER TABLE public.nfse_eventos
    ADD CONSTRAINT nfse_eventos_origem_chk
        CHECK (origem IN ('API', 'EXTERNO'));

-- Evento externo precisa do numero do processo/protocolo da prefeitura.
ALTER TABLE public.nfse_eventos DROP CONSTRAINT IF EXISTS nfse_eventos_externo_processo_chk;
ALTER TABLE public.nfse_eventos
    ADD CONSTRAINT nfse_eventos_externo_processo_chk
        CHECK (origem = 'API' OR numero_processo IS NOT NULL);

-- ----------------------------------------------------------------------------
-- 3. Emitente no feed do monitor
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_monitor
    ADD COLUMN IF NOT EXISTS emitente_cnpj VARCHAR(14);

UPDATE public.nfse_monitor m
   SET emitente_cnpj = d.emitente_cnpj
  FROM public.nfse_documentos d
 WHERE d.id = m.documento_id
   AND m.emitente_cnpj IS NULL;

-- ----------------------------------------------------------------------------
-- 4. Arquivamento externo dos XMLs
-- ----------------------------------------------------------------------------

ALTER TABLE public.nfse_documentos
    ADD COLUMN IF NOT EXISTS arquivado_em TIMESTAMPTZ;

ALTER TABLE public.nfse_jobs DROP CONSTRAINT IF EXISTS nfse_jobs_tipo_chk;
ALTER TABLE public.nfse_jobs
    ADD CONSTRAINT nfse_jobs_tipo_chk
        CHECK (tipo IN (
            'EMITIR',
            'CONSULTAR_PROCESSAMENTO',
            'CONSULTAR',
            'EVENTO',
            'ARQUIVAR'
        ));

COMMIT;
