-- ============================================================================
-- NfePHP
-- Script: 0002_nfse_criar_base.sql
--
-- Objetivo:
--   Criar a base fisica da emissao de NFS-e (Padrao Nacional e Salvador-BA),
--   incluindo a fila de processamento, o feed do monitor fiscal e a saida
--   de webhooks.
--
-- Escopo:
--   - nfse_lotes              : agrupa os documentos recebidos em uma chamada
--   - nfse_documentos         : uma linha por NFS-e (estado atual do documento)
--   - nfse_numeracao          : controle sequencial de numero DPS / RPS
--   - nfse_eventos            : eventos fiscais (cancelamento etc.)
--   - nfse_jobs               : fila de trabalho consumida pelo worker
--   - nfse_monitor            : feed append-only para o painel em tempo real
--   - nfse_webhooks_saida     : notificacoes para sistemas consumidores (outbox)
--
-- Decisoes:
--   - Status como VARCHAR + CHECK (e nao ENUM) para facilitar evolucao e a
--     futura migracao para Node/TypeScript (string union types).
--   - TIMESTAMPTZ em todas as datas: worker, API e prefeituras trabalham com
--     fuso horario; evita ambiguidade no monitor.
--   - Identificadores fiscais (CNPJ, chave, numero) sempre como texto.
--     Ver docs/compatibilidade-fiscal-2026.md.
--   - IDs publicos (lote/documento/evento) em UUID para nao expor sequencia.
--
-- Requisitos:
--   - PostgreSQL 13+ (gen_random_uuid() nativo).
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ============================================================================
-- nfse_lotes
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_lotes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Sistema que enviou o lote (ERP, FuraFila...). Vem da autenticacao,
    -- nunca do corpo da requisicao.
    consumidor VARCHAR(60) NOT NULL,
    quantidade_documentos INTEGER NOT NULL,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfse_lotes_quantidade_chk
        CHECK (quantidade_documentos > 0)
);

-- ============================================================================
-- nfse_documentos
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_documentos (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lote_id UUID NOT NULL REFERENCES public.nfse_lotes (id),

    consumidor VARCHAR(60) NOT NULL,
    padrao VARCHAR(20) NOT NULL,
    tp_amb SMALLINT NOT NULL,
    emitente_cnpj VARCHAR(14) NOT NULL,

    -- Identificador do documento no sistema de origem (pedido, OS...).
    -- Garante idempotencia: o mesmo referencia nunca gera duas notas.
    referencia VARCHAR(60) NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'RECEBIDO',

    -- JSON original recebido (contrato da API, ja validado).
    payload JSONB NOT NULL,

    -- Numeracao do documento de entrada (DPS no Nacional, RPS em Salvador).
    serie VARCHAR(5) NOT NULL,
    numero BIGINT NOT NULL,

    -- Preenchidos conforme o padrao avanca:
    id_dps VARCHAR(45),              -- Nacional: Id da DPS (DPS + 42 digitos)
    protocolo_lote VARCHAR(50),      -- Salvador: protocolo do EnviarLoteRPS
    chave_acesso VARCHAR(50),        -- Nacional: chave da NFS-e (50 digitos)
    numero_nfse VARCHAR(20),         -- Numero oficial da NFS-e
    codigo_verificacao VARCHAR(60),  -- Salvador: codigo de verificacao
    data_autorizacao TIMESTAMPTZ,

    -- Ultimo retorno do fisco (codigo/mensagem) e lista completa.
    codigo_retorno VARCHAR(20),
    mensagem_retorno TEXT,
    mensagens JSONB NOT NULL DEFAULT '[]'::jsonb,

    -- XMLs. Guardados no banco para que API e worker possam rodar em
    -- servidores diferentes sem compartilhar disco.
    xml_enviado TEXT,
    xml_autorizado TEXT,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfse_documentos_padrao_chk
        CHECK (padrao IN ('NACIONAL', 'SALVADOR')),

    CONSTRAINT nfse_documentos_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT nfse_documentos_cnpj_chk
        CHECK (emitente_cnpj ~ '^[A-Z0-9]{14}$'),

    CONSTRAINT nfse_documentos_status_chk
        CHECK (status IN (
            'RECEBIDO',
            'PROCESSANDO',
            'AUTORIZADO',
            'REJEITADO',
            'ERRO',
            'CANCELADO',
            'SUBSTITUIDO'
        )),

    CONSTRAINT nfse_documentos_numero_chk
        CHECK (numero > 0),

    CONSTRAINT nfse_documentos_chave_chk
        CHECK (chave_acesso IS NULL OR chave_acesso ~ '^[0-9]{50}$'),

    -- Idempotencia por sistema de origem.
    CONSTRAINT nfse_documentos_referencia_uk
        UNIQUE (consumidor, emitente_cnpj, padrao, tp_amb, referencia),

    -- Um numero de DPS/RPS nunca pode ser usado por dois documentos.
    CONSTRAINT nfse_documentos_numeracao_uk
        UNIQUE (emitente_cnpj, padrao, tp_amb, serie, numero)
);

CREATE INDEX IF NOT EXISTS nfse_documentos_lote_idx
    ON public.nfse_documentos (lote_id);

CREATE INDEX IF NOT EXISTS nfse_documentos_status_idx
    ON public.nfse_documentos (status, atualizado_em DESC);

CREATE INDEX IF NOT EXISTS nfse_documentos_chave_idx
    ON public.nfse_documentos (chave_acesso)
    WHERE chave_acesso IS NOT NULL;

-- ============================================================================
-- nfse_numeracao
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_numeracao (
    emitente_cnpj VARCHAR(14) NOT NULL,
    padrao VARCHAR(20) NOT NULL,
    tp_amb SMALLINT NOT NULL,
    serie VARCHAR(5) NOT NULL,

    ultimo_numero BIGINT NOT NULL DEFAULT 0,

    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (emitente_cnpj, padrao, tp_amb, serie),

    CONSTRAINT nfse_numeracao_padrao_chk
        CHECK (padrao IN ('NACIONAL', 'SALVADOR')),

    CONSTRAINT nfse_numeracao_tp_amb_chk
        CHECK (tp_amb IN (1, 2)),

    CONSTRAINT nfse_numeracao_ultimo_chk
        CHECK (ultimo_numero >= 0)
);

-- ============================================================================
-- nfse_eventos
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_eventos (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    documento_id UUID NOT NULL REFERENCES public.nfse_documentos (id),

    -- Codigo do evento no padrao nacional (ex.: 101101 = cancelamento).
    -- Para Salvador usamos o mesmo codigo, para manter um unico contrato.
    tipo_evento VARCHAR(6) NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'RECEBIDO',

    motivo_codigo VARCHAR(2),
    motivo_texto TEXT,

    codigo_retorno VARCHAR(20),
    mensagem_retorno TEXT,
    mensagens JSONB NOT NULL DEFAULT '[]'::jsonb,

    xml_enviado TEXT,
    xml_retorno TEXT,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfse_eventos_tipo_chk
        CHECK (tipo_evento ~ '^[0-9]{6}$'),

    CONSTRAINT nfse_eventos_status_chk
        CHECK (status IN (
            'RECEBIDO',
            'PROCESSANDO',
            'REGISTRADO',
            'REJEITADO',
            'ERRO'
        ))
);

CREATE INDEX IF NOT EXISTS nfse_eventos_documento_idx
    ON public.nfse_eventos (documento_id, criado_em DESC);

-- ============================================================================
-- nfse_jobs (fila)
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_jobs (
    id BIGSERIAL PRIMARY KEY,

    tipo VARCHAR(30) NOT NULL,
    documento_id UUID REFERENCES public.nfse_documentos (id),
    evento_id UUID REFERENCES public.nfse_eventos (id),

    status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',

    tentativas INTEGER NOT NULL DEFAULT 0,
    max_tentativas INTEGER NOT NULL DEFAULT 5,

    -- O worker so pega jobs com executar_apos <= agora.
    executar_apos TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    travado_em TIMESTAMPTZ,
    travado_por VARCHAR(100),
    ultimo_erro TEXT,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfse_jobs_tipo_chk
        CHECK (tipo IN (
            'EMITIR',
            'CONSULTAR_PROCESSAMENTO',
            'CONSULTAR',
            'EVENTO'
        )),

    CONSTRAINT nfse_jobs_status_chk
        CHECK (status IN ('PENDENTE', 'EXECUTANDO', 'CONCLUIDO', 'FALHOU')),

    CONSTRAINT nfse_jobs_tentativas_chk
        CHECK (tentativas >= 0 AND max_tentativas > 0)
);

-- Indice usado pelo worker para pegar o proximo job.
CREATE INDEX IF NOT EXISTS nfse_jobs_proximo_idx
    ON public.nfse_jobs (executar_apos, id)
    WHERE status = 'PENDENTE';

CREATE INDEX IF NOT EXISTS nfse_jobs_travados_idx
    ON public.nfse_jobs (travado_em)
    WHERE status = 'EXECUTANDO';

CREATE INDEX IF NOT EXISTS nfse_jobs_documento_idx
    ON public.nfse_jobs (documento_id);

-- ============================================================================
-- nfse_monitor (feed do painel)
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_monitor (
    id BIGSERIAL PRIMARY KEY,

    documento_id UUID NOT NULL REFERENCES public.nfse_documentos (id),
    lote_id UUID NOT NULL,
    consumidor VARCHAR(60) NOT NULL,

    padrao VARCHAR(20) NOT NULL,
    referencia VARCHAR(60) NOT NULL,

    -- O que aconteceu: RECEBIMENTO, EMISSAO, CONSULTA, EVENTO.
    operacao VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL,
    mensagem TEXT,

    numero_nfse VARCHAR(20),
    chave_acesso VARCHAR(50),

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfse_monitor_operacao_chk
        CHECK (operacao IN ('RECEBIMENTO', 'EMISSAO', 'CONSULTA', 'EVENTO'))
);

CREATE INDEX IF NOT EXISTS nfse_monitor_documento_idx
    ON public.nfse_monitor (documento_id, id);

CREATE INDEX IF NOT EXISTS nfse_monitor_criado_idx
    ON public.nfse_monitor (criado_em DESC);

CREATE INDEX IF NOT EXISTS nfse_monitor_consumidor_idx
    ON public.nfse_monitor (consumidor, id);

-- ============================================================================
-- nfse_webhooks_saida (outbox)
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.nfse_webhooks_saida (
    id BIGSERIAL PRIMARY KEY,

    documento_id UUID NOT NULL REFERENCES public.nfse_documentos (id),
    consumidor VARCHAR(60) NOT NULL,
    url TEXT NOT NULL,
    corpo JSONB NOT NULL,

    status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
    tentativas INTEGER NOT NULL DEFAULT 0,
    proxima_tentativa_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_http_status INTEGER,
    ultimo_erro TEXT,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nfse_webhooks_saida_status_chk
        CHECK (status IN ('PENDENTE', 'ENTREGUE', 'FALHOU'))
);

CREATE INDEX IF NOT EXISTS nfse_webhooks_saida_pendentes_idx
    ON public.nfse_webhooks_saida (proxima_tentativa_em, id)
    WHERE status = 'PENDENTE';

COMMIT;
