-- ============================================================================
-- NfePHP
-- Script: 0005_controle_criar_clientes_uso.sql
--
-- Objetivo:
--   Controle de emissao por EMPRESA CLIENTE (modelo de servico / cobranca):
--
--     fiscal_clientes   cada empresa que usa a API: dados fiscais, certificado
--                       (criptografado), token proprio (so o hash), webhook e
--                       as regras de emissao:
--                         - emissao_liberada  bloqueio manual
--                         - liberado_ate      bloqueio por data (vencimento)
--                         - limite_mensal     franquia de notas por mes
--                         - padroes_permitidos tipos de nota contratados
--     fiscal_uso        UMA linha por nota AUTORIZADA (base da cobranca).
--                       Preenchida por gatilho em nfse_documentos: nao depende
--                       de codigo PHP e nunca conta a mesma nota duas vezes.
--     fiscal_admin_log  quem mudou o que no painel admin (auditoria).
--
--   Mantido pelo painel /admin/ (rotas /v1/admin/*.php).
--
-- Pode rodar mais de uma vez.
-- Requisito: 0004 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ----------------------------------------------------------------------------
-- fiscal_clientes
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_clientes (
    cnpj VARCHAR(14) PRIMARY KEY,
    razao_social VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150),
    email VARCHAR(120),
    telefone VARCHAR(20),

    -- NF-e
    uf CHAR(2),
    ie VARCHAR(20),

    -- NFS-e
    inscricao_municipal VARCHAR(20),
    codigo_municipio VARCHAR(7) NOT NULL DEFAULT '2927408',
    opcao_simples_nacional SMALLINT NOT NULL DEFAULT 1,
    regime_apuracao_simples SMALLINT,
    regime_especial_tributacao SMALLINT NOT NULL DEFAULT 0,
    incentivador_cultural BOOLEAN NOT NULL DEFAULT FALSE,
    regime_especial_abrasf SMALLINT,

    -- Certificado A1 + senha, criptografados pela API (libsodium).
    certificado_cifrado BYTEA,
    certificado_validade TIMESTAMPTZ,
    certificado_titular VARCHAR(200),
    certificado_atualizado_em TIMESTAMPTZ,

    -- Acesso: o token aparece UMA vez no painel; aqui fica so o sha256.
    token_hash CHAR(64),
    token_final VARCHAR(4),
    token_gerado_em TIMESTAMPTZ,

    webhook_url VARCHAR(500),
    webhook_segredo VARCHAR(200),

    -- Regras de emissao (cobranca).
    emissao_liberada BOOLEAN NOT NULL DEFAULT TRUE,
    motivo_bloqueio VARCHAR(300),
    liberado_ate DATE,
    limite_mensal INTEGER,
    padroes_permitidos VARCHAR(20)[] NOT NULL DEFAULT ARRAY['NFE', 'NACIONAL', 'SALVADOR']::VARCHAR(20)[],
    plano VARCHAR(60),
    observacao TEXT,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_clientes_cnpj_chk CHECK (cnpj ~ '^[0-9A-Z]{14}$'),
    CONSTRAINT fiscal_clientes_uf_chk CHECK (uf IS NULL OR uf ~ '^[A-Z]{2}$'),
    CONSTRAINT fiscal_clientes_municipio_chk CHECK (codigo_municipio ~ '^[0-9]{7}$'),
    CONSTRAINT fiscal_clientes_simples_chk CHECK (opcao_simples_nacional IN (1, 2, 3)),
    CONSTRAINT fiscal_clientes_apuracao_chk CHECK (regime_apuracao_simples IS NULL OR regime_apuracao_simples IN (1, 2, 3)),
    CONSTRAINT fiscal_clientes_regesp_chk CHECK (regime_especial_tributacao BETWEEN 0 AND 6),
    CONSTRAINT fiscal_clientes_regesp_abrasf_chk CHECK (regime_especial_abrasf IS NULL OR regime_especial_abrasf BETWEEN 1 AND 6),
    CONSTRAINT fiscal_clientes_limite_chk CHECK (limite_mensal IS NULL OR limite_mensal > 0),
    CONSTRAINT fiscal_clientes_padroes_chk
        CHECK (padroes_permitidos <@ ARRAY['NFE', 'NACIONAL', 'SALVADOR']::VARCHAR(20)[]),
    CONSTRAINT fiscal_clientes_webhook_chk CHECK (webhook_url IS NULL OR webhook_url ~ '^https?://'),
    CONSTRAINT fiscal_clientes_token_uk UNIQUE (token_hash)
);

COMMENT ON TABLE public.fiscal_clientes IS 'Empresas clientes da API: dados fiscais, certificado, token e regras de emissao (bloqueio, vencimento, franquia, tipos).';
COMMENT ON COLUMN public.fiscal_clientes.emissao_liberada IS 'FALSE = bloqueio manual: a API recusa novas notas (consulta, PDF, XML e cancelamento continuam).';
COMMENT ON COLUMN public.fiscal_clientes.liberado_ate IS 'Ultimo dia liberado (ex.: fim do periodo pago). Vazio = sem vencimento.';
COMMENT ON COLUMN public.fiscal_clientes.limite_mensal IS 'Franquia: maximo de notas AUTORIZADAS por mes (somando as em processamento). Vazio = sem limite.';
COMMENT ON COLUMN public.fiscal_clientes.padroes_permitidos IS 'Tipos contratados: NFE, NACIONAL (NFS-e Nacional), SALVADOR (NFS-e Salvador).';

-- ----------------------------------------------------------------------------
-- fiscal_uso (base da cobranca)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_uso (
    documento_id UUID PRIMARY KEY REFERENCES public.nfse_documentos (id),
    emitente_cnpj VARCHAR(14) NOT NULL,
    consumidor VARCHAR(60) NOT NULL,
    padrao VARCHAR(20) NOT NULL,
    tp_amb SMALLINT NOT NULL,
    autorizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS fiscal_uso_cnpj_data_idx
    ON public.fiscal_uso (emitente_cnpj, tp_amb, autorizado_em);

COMMENT ON TABLE public.fiscal_uso IS
    'Uma linha por nota autorizada (mesmo que cancelada depois). Base da cobranca. Preenchida por gatilho.';

-- Gatilho: ao virar AUTORIZADO pela primeira vez, registra o uso.
CREATE OR REPLACE FUNCTION public.fiscal_uso_registrar()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.status = 'AUTORIZADO' AND OLD.status IS DISTINCT FROM 'AUTORIZADO' THEN
        INSERT INTO public.fiscal_uso (documento_id, emitente_cnpj, consumidor, padrao, tp_amb, autorizado_em)
        VALUES (NEW.id, NEW.emitente_cnpj, NEW.consumidor, NEW.padrao, NEW.tp_amb,
                COALESCE(NEW.data_autorizacao, CURRENT_TIMESTAMP))
        ON CONFLICT (documento_id) DO NOTHING;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS fiscal_uso_registrar_trg ON public.nfse_documentos;
CREATE TRIGGER fiscal_uso_registrar_trg
    AFTER UPDATE OF status ON public.nfse_documentos
    FOR EACH ROW EXECUTE FUNCTION public.fiscal_uso_registrar();

-- Notas autorizadas antes deste script tambem entram (uma vez so).
INSERT INTO public.fiscal_uso (documento_id, emitente_cnpj, consumidor, padrao, tp_amb, autorizado_em)
SELECT id, emitente_cnpj, consumidor, padrao, tp_amb, COALESCE(data_autorizacao, atualizado_em)
  FROM public.nfse_documentos
 WHERE status IN ('AUTORIZADO', 'CANCELADO', 'SUBSTITUIDO')
ON CONFLICT (documento_id) DO NOTHING;

-- ----------------------------------------------------------------------------
-- fiscal_admin_log
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_admin_log (
    id BIGSERIAL PRIMARY KEY,
    cnpj VARCHAR(14),
    acao VARCHAR(40) NOT NULL,
    detalhes JSONB NOT NULL DEFAULT '{}'::JSONB,
    ip VARCHAR(45),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS fiscal_admin_log_cnpj_idx
    ON public.fiscal_admin_log (cnpj, id DESC);

COMMENT ON TABLE public.fiscal_admin_log IS 'Historico das acoes no painel admin (cadastro, bloqueio, token, certificado).';

COMMIT;
