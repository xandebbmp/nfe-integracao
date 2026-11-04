-- ============================================================================
-- NfePHP
-- Script: 0005_cadastro_criar_empresas_sistemas.sql
--
-- Objetivo:
--   Cadastro permanente no banco, para ser mantido por telas do Scriptcase
--   (ou qualquer outra ferramenta), sem editar arquivo de configuracao:
--
--     fiscal_empresas              empresas emitentes (NF-e e NFS-e)
--     fiscal_empresas_certificados certificado A1 CRIPTOGRAFADO (so a API le)
--     fiscal_sistemas              sistemas que usam a API (tokens) e o painel
--     fiscal_sistemas_empresas     quais empresas cada sistema pode usar
--     fiscal_numeracao_inicial     ultimo numero ja usado por serie
--
-- Como o Scriptcase grava (resumo; detalhes em docs/cadastro-scriptcase.md):
--   - Certificado: o formulario grava o .pfx em fiscal_empresas.certificado_arquivo
--     e a senha em certificado_senha. O worker da API le, confere (senha, CNPJ,
--     validade), CRIPTOGRAFA em fiscal_empresas_certificados e APAGA os dois
--     campos. O resultado aparece em certificado_situacao/mensagem/validade.
--   - Token: o formulario grava em fiscal_sistemas.token. O gatilho guarda
--     so o hash (token_hash) e os 4 ultimos caracteres, e APAGA o token.
--   - Numeracao: ao gravar uma linha em fiscal_numeracao_inicial o gatilho
--     ajusta nfse_numeracao. Nunca volta numero para tras.
--
-- Pode rodar mais de uma vez.
-- Requisito: 0004 aplicado.
-- ============================================================================

BEGIN;

SET search_path TO public;

-- ----------------------------------------------------------------------------
-- fiscal_empresas
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_empresas (
    cnpj VARCHAR(18) PRIMARY KEY,
    razao_social VARCHAR(150) NOT NULL,

    -- NF-e
    uf CHAR(2),
    ie VARCHAR(20),

    -- NFS-e
    inscricao_municipal VARCHAR(20),
    codigo_municipio VARCHAR(7) NOT NULL DEFAULT '2927408',

    telefone VARCHAR(20),
    email VARCHAR(120),

    opcao_simples_nacional SMALLINT NOT NULL DEFAULT 1,
    regime_apuracao_simples SMALLINT,
    regime_especial_tributacao SMALLINT NOT NULL DEFAULT 0,
    incentivador_cultural BOOLEAN NOT NULL DEFAULT FALSE,
    regime_especial_abrasf SMALLINT,

    ativo BOOLEAN NOT NULL DEFAULT TRUE,

    -- Entrada do certificado (o worker apaga depois de processar).
    certificado_arquivo BYTEA,
    certificado_senha VARCHAR(200),

    -- Resultado do processamento (somente leitura para o Scriptcase).
    certificado_situacao VARCHAR(20) NOT NULL DEFAULT 'SEM_CERTIFICADO',
    certificado_mensagem VARCHAR(500),
    certificado_validade DATE,
    certificado_titular VARCHAR(200),
    certificado_processado_em TIMESTAMPTZ,

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_empresas_cnpj_chk CHECK (cnpj ~ '^[0-9A-Z]{14}$'),
    CONSTRAINT fiscal_empresas_uf_chk CHECK (uf IS NULL OR uf ~ '^[A-Z]{2}$'),
    CONSTRAINT fiscal_empresas_municipio_chk CHECK (codigo_municipio ~ '^[0-9]{7}$'),
    CONSTRAINT fiscal_empresas_simples_chk CHECK (opcao_simples_nacional IN (1, 2, 3)),
    CONSTRAINT fiscal_empresas_apuracao_chk CHECK (regime_apuracao_simples IS NULL OR regime_apuracao_simples IN (1, 2, 3)),
    CONSTRAINT fiscal_empresas_regesp_chk CHECK (regime_especial_tributacao BETWEEN 0 AND 6),
    CONSTRAINT fiscal_empresas_regesp_abrasf_chk CHECK (regime_especial_abrasf IS NULL OR regime_especial_abrasf BETWEEN 1 AND 6),
    CONSTRAINT fiscal_empresas_cert_situacao_chk
        CHECK (certificado_situacao IN ('SEM_CERTIFICADO', 'AGUARDANDO', 'OK', 'VENCIDO', 'ERRO'))
);

COMMENT ON TABLE public.fiscal_empresas IS 'Empresas emitentes de NF-e/NFS-e. Mantida pelo Scriptcase.';
COMMENT ON COLUMN public.fiscal_empresas.cnpj IS 'CNPJ (pode digitar com pontos; o banco guarda so letras e numeros).';
COMMENT ON COLUMN public.fiscal_empresas.uf IS 'NF-e: UF da empresa (SEFAZ que autoriza). Ex.: BA.';
COMMENT ON COLUMN public.fiscal_empresas.ie IS 'NF-e: inscricao estadual (so numeros).';
COMMENT ON COLUMN public.fiscal_empresas.inscricao_municipal IS 'NFS-e: inscricao municipal (obrigatoria em Salvador).';
COMMENT ON COLUMN public.fiscal_empresas.codigo_municipio IS 'NFS-e: codigo IBGE do municipio (Salvador = 2927408).';
COMMENT ON COLUMN public.fiscal_empresas.opcao_simples_nacional IS '1 = nao optante; 2 = MEI; 3 = ME/EPP (Simples Nacional).';
COMMENT ON COLUMN public.fiscal_empresas.regime_apuracao_simples IS 'Somente optante ME/EPP: 1, 2 ou 3 (regApTribSN do Padrao Nacional).';
COMMENT ON COLUMN public.fiscal_empresas.regime_especial_tributacao IS 'Padrao Nacional regEspTrib: 0 = nenhum (padrao) ... 6.';
COMMENT ON COLUMN public.fiscal_empresas.regime_especial_abrasf IS 'Salvador (ABRASF): 1 a 6, ou vazio.';
COMMENT ON COLUMN public.fiscal_empresas.ativo IS 'Desmarcado = a API deixa de emitir por esta empresa.';
COMMENT ON COLUMN public.fiscal_empresas.certificado_arquivo IS 'Upload do .pfx (campo Documento no banco). Apagado apos processar.';
COMMENT ON COLUMN public.fiscal_empresas.certificado_senha IS 'Senha do .pfx (campo senha). Apagada apos processar.';
COMMENT ON COLUMN public.fiscal_empresas.certificado_situacao IS 'SEM_CERTIFICADO, AGUARDANDO, OK, VENCIDO ou ERRO (ver mensagem).';

-- ----------------------------------------------------------------------------
-- fiscal_empresas_certificados (NAO dar acesso ao usuario do Scriptcase)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_empresas_certificados (
    cnpj VARCHAR(18) PRIMARY KEY
        REFERENCES public.fiscal_empresas (cnpj) ON UPDATE CASCADE ON DELETE CASCADE,
    conteudo_cifrado BYTEA NOT NULL,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE public.fiscal_empresas_certificados IS
    'Certificado A1 + senha criptografados (libsodium) com a chave do servidor. Somente a API acessa.';

-- ----------------------------------------------------------------------------
-- fiscal_sistemas
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_sistemas (
    codigo VARCHAR(40) PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    perfil VARCHAR(10) NOT NULL DEFAULT 'SISTEMA',

    -- Entrada do token (o gatilho apaga e guarda so o hash).
    token VARCHAR(200),
    token_hash CHAR(64),
    token_final VARCHAR(4),

    webhook_url VARCHAR(500),
    webhook_segredo VARCHAR(200),

    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    observacao VARCHAR(500),

    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_sistemas_codigo_chk CHECK (codigo ~ '^[A-Z0-9_]{2,40}$'),
    CONSTRAINT fiscal_sistemas_perfil_chk CHECK (perfil IN ('SISTEMA', 'PAINEL')),
    CONSTRAINT fiscal_sistemas_webhook_chk CHECK (webhook_url IS NULL OR webhook_url ~ '^https?://'),
    CONSTRAINT fiscal_sistemas_token_hash_uk UNIQUE (token_hash)
);

COMMENT ON TABLE public.fiscal_sistemas IS 'Sistemas que usam a API (perfil SISTEMA) e acessos ao monitor (perfil PAINEL).';
COMMENT ON COLUMN public.fiscal_sistemas.codigo IS 'Codigo curto, MAIUSCULO, sem espaco. Ex.: ERP, LOJA_VIRTUAL.';
COMMENT ON COLUMN public.fiscal_sistemas.perfil IS 'SISTEMA = emite e ve as proprias notas; PAINEL = so abre o monitor (ve todas).';
COMMENT ON COLUMN public.fiscal_sistemas.token IS 'Digite/gere o token (minimo 32 caracteres). O banco guarda so o hash e apaga este campo.';
COMMENT ON COLUMN public.fiscal_sistemas.token_final IS 'Ultimos 4 caracteres do token, para conferencia.';
COMMENT ON COLUMN public.fiscal_sistemas.webhook_url IS 'Opcional: URL que recebe os avisos de mudanca de status.';
COMMENT ON COLUMN public.fiscal_sistemas.webhook_segredo IS 'Opcional: segredo da assinatura HMAC do webhook.';

-- ----------------------------------------------------------------------------
-- fiscal_sistemas_empresas
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_sistemas_empresas (
    sistema_codigo VARCHAR(40) NOT NULL
        REFERENCES public.fiscal_sistemas (codigo) ON UPDATE CASCADE ON DELETE CASCADE,
    empresa_cnpj VARCHAR(18) NOT NULL
        REFERENCES public.fiscal_empresas (cnpj) ON UPDATE CASCADE ON DELETE CASCADE,
    PRIMARY KEY (sistema_codigo, empresa_cnpj)
);

COMMENT ON TABLE public.fiscal_sistemas_empresas IS 'Quais empresas cada sistema pode usar como emitente.';

-- ----------------------------------------------------------------------------
-- fiscal_numeracao_inicial
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS public.fiscal_numeracao_inicial (
    id BIGSERIAL PRIMARY KEY,
    empresa_cnpj VARCHAR(18) NOT NULL
        REFERENCES public.fiscal_empresas (cnpj) ON UPDATE CASCADE ON DELETE CASCADE,
    padrao VARCHAR(20) NOT NULL,
    tp_amb SMALLINT NOT NULL DEFAULT 2,
    serie VARCHAR(5) NOT NULL DEFAULT '1',
    ultimo_numero BIGINT NOT NULL,

    resultado VARCHAR(300),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fiscal_numeracao_inicial_padrao_chk CHECK (padrao IN ('NFE', 'NACIONAL', 'SALVADOR')),
    CONSTRAINT fiscal_numeracao_inicial_tpamb_chk CHECK (tp_amb IN (1, 2)),
    CONSTRAINT fiscal_numeracao_inicial_serie_chk CHECK (serie ~ '^[0-9A-Za-z]{1,5}$'),
    CONSTRAINT fiscal_numeracao_inicial_numero_chk CHECK (ultimo_numero BETWEEN 0 AND 999999999)
);

COMMENT ON TABLE public.fiscal_numeracao_inicial IS
    'Informe o ULTIMO numero ja usado (NF-e, DPS ou RPS) por empresa/serie. A API continua do seguinte.';
COMMENT ON COLUMN public.fiscal_numeracao_inicial.padrao IS 'NFE = NF-e; NACIONAL = DPS da NFS-e Nacional; SALVADOR = RPS de Salvador.';
COMMENT ON COLUMN public.fiscal_numeracao_inicial.tp_amb IS '1 = producao; 2 = homologacao.';
COMMENT ON COLUMN public.fiscal_numeracao_inicial.resultado IS 'Preenchido pelo banco: o que foi aplicado.';

-- ----------------------------------------------------------------------------
-- Gatilhos
-- ----------------------------------------------------------------------------

-- Empresa: normaliza CNPJ/UF/IE e marca certificado novo como AGUARDANDO.
CREATE OR REPLACE FUNCTION public.fiscal_empresas_antes_gravar()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.cnpj := upper(regexp_replace(coalesce(NEW.cnpj, ''), '[^A-Za-z0-9]', '', 'g'));
    NEW.uf := nullif(upper(trim(coalesce(NEW.uf, ''))), '');
    NEW.ie := nullif(regexp_replace(coalesce(NEW.ie, ''), '[^A-Za-z0-9]', '', 'g'), '');
    NEW.inscricao_municipal := nullif(regexp_replace(coalesce(NEW.inscricao_municipal, ''), '[^A-Za-z0-9]', '', 'g'), '');
    NEW.razao_social := trim(NEW.razao_social);
    NEW.atualizado_em := CURRENT_TIMESTAMP;

    IF NEW.certificado_arquivo IS NOT NULL AND length(NEW.certificado_arquivo) > 0 THEN
        NEW.certificado_situacao := 'AGUARDANDO';
        NEW.certificado_mensagem := 'Certificado recebido. Sera conferido em alguns segundos.';
        -- Acorda o worker na hora.
        PERFORM pg_notify('nfse_jobs', 'certificado');
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS fiscal_empresas_antes_gravar_trg ON public.fiscal_empresas;
CREATE TRIGGER fiscal_empresas_antes_gravar_trg
    BEFORE INSERT OR UPDATE ON public.fiscal_empresas
    FOR EACH ROW EXECUTE FUNCTION public.fiscal_empresas_antes_gravar();

-- Sistema: guarda so o hash do token.
CREATE OR REPLACE FUNCTION public.fiscal_sistemas_antes_gravar()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_token TEXT := trim(coalesce(NEW.token, ''));
BEGIN
    NEW.codigo := upper(trim(NEW.codigo));
    NEW.perfil := upper(trim(coalesce(NEW.perfil, 'SISTEMA')));
    NEW.webhook_url := nullif(trim(coalesce(NEW.webhook_url, '')), '');
    NEW.webhook_segredo := nullif(trim(coalesce(NEW.webhook_segredo, '')), '');
    NEW.atualizado_em := CURRENT_TIMESTAMP;

    IF v_token <> '' THEN
        IF length(v_token) < 32 THEN
            RAISE EXCEPTION 'Token muito curto: use pelo menos 32 caracteres (gere um aleatorio).';
        END IF;
        NEW.token_hash := encode(sha256(convert_to(v_token, 'UTF8')), 'hex');
        NEW.token_final := right(v_token, 4);
    END IF;
    NEW.token := NULL;

    IF TG_OP = 'INSERT' AND NEW.token_hash IS NULL THEN
        RAISE EXCEPTION 'Informe o token do sistema %.', NEW.codigo;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS fiscal_sistemas_antes_gravar_trg ON public.fiscal_sistemas;
CREATE TRIGGER fiscal_sistemas_antes_gravar_trg
    BEFORE INSERT OR UPDATE ON public.fiscal_sistemas
    FOR EACH ROW EXECUTE FUNCTION public.fiscal_sistemas_antes_gravar();

-- Vinculo sistema x empresa: aceita CNPJ com pontuacao.
CREATE OR REPLACE FUNCTION public.fiscal_sistemas_empresas_antes_gravar()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.sistema_codigo := upper(trim(NEW.sistema_codigo));
    NEW.empresa_cnpj := upper(regexp_replace(coalesce(NEW.empresa_cnpj, ''), '[^A-Za-z0-9]', '', 'g'));
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS fiscal_sistemas_empresas_antes_gravar_trg ON public.fiscal_sistemas_empresas;
CREATE TRIGGER fiscal_sistemas_empresas_antes_gravar_trg
    BEFORE INSERT OR UPDATE ON public.fiscal_sistemas_empresas
    FOR EACH ROW EXECUTE FUNCTION public.fiscal_sistemas_empresas_antes_gravar();

-- Numeracao: aplica em nfse_numeracao sem nunca voltar para tras.
-- SECURITY DEFINER: roda com a permissao do dono das tabelas, assim o
-- usuario do Scriptcase nao precisa de acesso direto a nfse_numeracao.
CREATE OR REPLACE FUNCTION public.fiscal_numeracao_inicial_aplicar()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
    v_atual BIGINT;
    v_final BIGINT;
BEGIN
    NEW.empresa_cnpj := upper(regexp_replace(coalesce(NEW.empresa_cnpj, ''), '[^A-Za-z0-9]', '', 'g'));
    NEW.padrao := upper(trim(NEW.padrao));
    NEW.serie := trim(NEW.serie);
    IF NEW.padrao = 'NFE' THEN
        -- Serie da NF-e e numerica (0 a 999): guarda sem zeros a esquerda.
        IF NEW.serie !~ '^[0-9]{1,3}$' THEN
            RAISE EXCEPTION 'Serie da NF-e deve ser um numero de 0 a 999.';
        END IF;
        NEW.serie := (NEW.serie::INT)::TEXT;
    END IF;

    SELECT ultimo_numero INTO v_atual
      FROM public.nfse_numeracao
     WHERE emitente_cnpj = NEW.empresa_cnpj AND padrao = NEW.padrao
       AND tp_amb = NEW.tp_amb AND serie = NEW.serie
     FOR UPDATE;

    INSERT INTO public.nfse_numeracao (emitente_cnpj, padrao, tp_amb, serie, ultimo_numero)
    VALUES (NEW.empresa_cnpj, NEW.padrao, NEW.tp_amb, NEW.serie, NEW.ultimo_numero)
    ON CONFLICT (emitente_cnpj, padrao, tp_amb, serie)
    DO UPDATE SET ultimo_numero = GREATEST(public.nfse_numeracao.ultimo_numero, EXCLUDED.ultimo_numero),
                  atualizado_em = CURRENT_TIMESTAMP
    RETURNING ultimo_numero INTO v_final;

    IF v_atual IS NOT NULL AND v_atual > NEW.ultimo_numero THEN
        NEW.resultado := format('Mantido: a API ja usou ate %s nesta serie. Proximo numero: %s.', v_atual, v_final + 1);
    ELSE
        NEW.resultado := format('Aplicado. Proximo numero: %s.', v_final + 1);
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS fiscal_numeracao_inicial_aplicar_trg ON public.fiscal_numeracao_inicial;
CREATE TRIGGER fiscal_numeracao_inicial_aplicar_trg
    BEFORE INSERT OR UPDATE ON public.fiscal_numeracao_inicial
    FOR EACH ROW EXECUTE FUNCTION public.fiscal_numeracao_inicial_aplicar();

COMMIT;
