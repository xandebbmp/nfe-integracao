-- ============================================================================
-- NfePHP
-- Script: 0007_sistema_criar_controle_worker.sql
--
-- Objetivo:
--   Controlar o worker pelo painel admin (tela "Sistema"), sem terminal.
--
--     sistema_workers   cada worker ligado grava aqui um "batimento" a cada
--                       poucos segundos. Batimento recente = worker vivo.
--                       Ao sair, o worker apaga a propria linha.
--     sistema_controle  ordens do painel para os workers:
--                         worker_pausado     '1' = nao envia nada (fila espera)
--                         worker_reiniciar   data/hora: workers ligados antes
--                                            dela saem e o systemd/pm2 os
--                                            liga de novo (codigo atualizado)
--
--   O painel avisa no canal pg_notify 'nfse_controle' e os workers obedecem
--   na hora (eles ja ficam escutando o banco).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0006 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

CREATE TABLE IF NOT EXISTS public.sistema_workers (
    worker_id VARCHAR(100) PRIMARY KEY,     -- "servidor:pid"
    servidor VARCHAR(100) NOT NULL,
    pid INTEGER NOT NULL,
    estado VARCHAR(20) NOT NULL,            -- TRABALHANDO | AGUARDANDO | PAUSADO
    jobs_feitos INTEGER NOT NULL DEFAULT 0,
    iniciado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    batimento_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT sistema_workers_estado_chk CHECK (estado IN ('TRABALHANDO', 'AGUARDANDO', 'PAUSADO'))
);

COMMENT ON TABLE public.sistema_workers IS 'Workers ligados e o ultimo sinal de vida de cada um (tela Sistema do painel).';

CREATE TABLE IF NOT EXISTS public.sistema_controle (
    chave VARCHAR(40) PRIMARY KEY,
    valor VARCHAR(200) NOT NULL,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE public.sistema_controle IS 'Ordens do painel admin para os workers (pausar, reiniciar).';

INSERT INTO public.sistema_controle (chave, valor) VALUES ('worker_pausado', '0')
ON CONFLICT (chave) DO NOTHING;

COMMIT;
