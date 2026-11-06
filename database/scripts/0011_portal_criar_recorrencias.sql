-- ============================================================================
-- NfePHP
-- Script: 0011_portal_criar_recorrencias.sql
--
-- Objetivo:
--   Tela "Recorrencias" do portal: notas que se repetem todo mes (mesmo
--   tomador, servico e valor), por exemplo uma mensalidade.
--
--     portal_recorrencias   uma linha por nota mensal. Guarda o que vai na
--                           nota (tomador, servico, descricao e valores) e o
--                           dia do mes.
--
--   Como funciona:
--     - proxima_em e o dia da proxima nota. Quando chega, a recorrencia fica
--       "pendente" ate a nota sair.
--     - modo AUTOMATICA: o worker emite sozinho (uma tentativa por dia; se der
--       erro, fica pendente e aparece para o cliente na tela inicial).
--     - modo CONFIRMAR: a nota fica pronta e so sai quando o cliente clica em
--       "Emitir" (ou "Pular este mes").
--     - Cada mes vira a referencia PORTAL-rec-<id>-<AAAAMM>: a mesma nota nunca
--       sai duas vezes, nem com dois workers ou dois cliques.
--     - Depois de fim (se informado) a recorrencia se encerra sozinha.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0006 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.portal_recorrencias (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL REFERENCES public.fiscal_clientes (cnpj) ON DELETE CASCADE,
    nome VARCHAR(120) NOT NULL,                 -- ex.: "Mensalidade Clinica Vida"

    -- O que vai na nota (mesmo formato do formulario "Emitir nota").
    tomador JSONB NOT NULL,                     -- cpf_cnpj, nome, email, endereco...
    servico_id BIGINT NOT NULL REFERENCES public.portal_servicos (id),
    descricao VARCHAR(2000) NOT NULL,           -- pode ter {mes}
    valores JSONB NOT NULL,                     -- valor_servicos, iss_retido, retencoes

    -- Quando
    dia SMALLINT NOT NULL,                      -- 1 a 28 (todo mes tem)
    competencia VARCHAR(8) NOT NULL DEFAULT 'ATUAL',   -- ATUAL = mes da emissao; ANTERIOR = mes anterior
    modo VARCHAR(12) NOT NULL DEFAULT 'CONFIRMAR',     -- AUTOMATICA | CONFIRMAR
    proxima_em DATE NOT NULL,
    fim DATE,                                   -- ultima nota ate esta data (vazio = sem fim)
    ativa BOOLEAN NOT NULL DEFAULT TRUE,

    -- Ultima vez
    tentativa_em DATE,                          -- dia da ultima tentativa automatica
    ultimo_documento_id UUID,
    ultima_mensagem VARCHAR(500),               -- erro da ultima tentativa (vazio = deu certo)
    emitidas INTEGER NOT NULL DEFAULT 0,

    criado_por BIGINT REFERENCES public.portal_usuarios (id) ON DELETE SET NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT portal_recorrencias_dia_chk CHECK (dia BETWEEN 1 AND 28),
    CONSTRAINT portal_recorrencias_competencia_chk CHECK (competencia IN ('ATUAL', 'ANTERIOR')),
    CONSTRAINT portal_recorrencias_modo_chk CHECK (modo IN ('AUTOMATICA', 'CONFIRMAR'))
);

CREATE INDEX IF NOT EXISTS portal_recorrencias_cnpj_idx ON public.portal_recorrencias (cnpj, ativa);
CREATE INDEX IF NOT EXISTS portal_recorrencias_proxima_idx ON public.portal_recorrencias (proxima_em) WHERE ativa;

COMMENT ON TABLE public.portal_recorrencias IS 'Notas mensais do portal do cliente (emitidas sozinhas ou com confirmacao).';

COMMIT;
