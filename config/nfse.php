<?php
declare(strict_types=1);

/**
 * Configuracao da emissao de NFS-e (Padrao Nacional e Salvador-BA).
 *
 * Empresa unica: preencha 'emitente' abaixo. CNPJ, razao social e
 * certificado sao reaproveitados de config/nfe.php.
 *
 * Varias empresas: preencha 'emitentes' (em config/nfse.local.php), um
 * bloco por CNPJ, cada um com seu certificado. Nesse caso 'emitente' e
 * ignorado. Ver config/nfse.local.php.example.
 *
 * NUNCA coloque segredos aqui. Use config/nfse.local.php (fora do Git),
 * a partir de config/nfse.local.php.example.
 */

$cfg = [
    // Sobrescreve o tpAmb do nfe.php se informado (1=Producao, 2=Homologacao).
    'tpAmb' => null,

    // ---------------------------------------------------------------
    // Emitente unico (prestador de servico). Usado quando 'emitentes' esta vazio.
    // Os mesmos campos valem para cada item de 'emitentes'.
    // ---------------------------------------------------------------
    'emitente' => [
        // Vazio = usa o CNPJ do config/nfe.php.
        'cnpj' => '',
        'inscricao_municipal' => '',
        // Codigo IBGE do municipio do emitente. Salvador = 2927408.
        'codigo_municipio' => '2927408',
        'telefone' => '',
        'email' => '',

        // Regime tributario (Padrao Nacional - campo regTrib):
        //   opSimpNac: 1=Nao optante, 2=MEI, 3=ME/EPP optante do Simples
        'opcao_simples_nacional' => 1,
        //   regApTribSN (somente se opSimpNac=3): 1, 2 ou 3. null = nao informar.
        'regime_apuracao_simples' => null,
        //   pTotTribSN: percentual aproximado dos tributos do Simples (Lei 12.741).
        //   OBRIGATORIO para ME/EPP (opSimpNac=3): sem ele o Sefin rejeita (E0712).
        //   Usado quando a nota nao envia valores.percentual_tributos_simples. Ex.: 2.0
        'percentual_tributos_simples' => null,
        //   regEspTrib: 0=Nenhum, 1=Ato cooperado, 2=Estimativa, 3=ME municipal,
        //   4=Notario/registrador, 5=Profissional autonomo, 6=Sociedade de profissionais
        'regime_especial_tributacao' => 0,

        // Salvador (ABRASF): incentivador cultural (true/false).
        'incentivador_cultural' => false,
        // Salvador (ABRASF): RegimeEspecialTributacao. A tabela e DIFERENTE da nacional:
        //   1=Microempresa municipal, 2=Estimativa, 3=Sociedade de profissionais,
        //   4=Cooperativa, 5=MEI, 6=ME/EPP do Simples. null = nao informar.
        'regime_especial_abrasf' => null,
    ],

    // Varias empresas: CNPJ => mesmos campos de 'emitente' + certificado.
    // Preencha em config/nfse.local.php (contem senha do certificado).
    'emitentes' => [],

    // ---------------------------------------------------------------
    // Padrao Nacional (Sefin Nacional / ADN)
    // ---------------------------------------------------------------
    'nacional' => [
        'versao_leiaute' => '1.01',
        'serie_dps' => '1',
        'algoritmo_assinatura' => 'sha256',
        'timeout_segundos' => 30,
        'url_sefin' => [
            1 => 'https://sefin.nfse.gov.br/SefinNacional',
            2 => 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional',
        ],
    ],

    // ---------------------------------------------------------------
    // Salvador-BA (ABRASF 1.00 com particularidades do municipio)
    // ---------------------------------------------------------------
    'salvador' => [
        'serie_rps' => '1',
        'algoritmo_assinatura' => 'sha1',
        'timeout_segundos' => 60,
        'url_base' => [
            1 => 'https://nfse.salvador.ba.gov.br',
            2 => 'https://notahml.salvador.ba.gov.br',
        ],
        // Segundos ate a primeira consulta do lote e intervalo entre consultas.
        'consulta_lote_primeira_em' => 15,
        'consulta_lote_intervalo' => 30,
        // Quantas vezes consultar o lote antes de desistir (vira ERRO).
        'consulta_lote_max' => 20,
        // Link de visualizacao no portal da prefeitura (exibido no espelho PDF).
        // Placeholders: {numero}, {inscricao}, {verificacao}. Vazio = nao exibe.
        'url_visualizacao' => '',
    ],

    // ---------------------------------------------------------------
    // Fila / worker
    // ---------------------------------------------------------------
    'fila' => [
        'max_tentativas' => 5,
        // Espera entre tentativas apos falha temporaria: base * 2^(tentativa-1)
        'espera_base_segundos' => 30,
        // Job "EXECUTANDO" ha mais tempo que isto e considerado travado
        // (worker morreu) e volta para a fila.
        'job_travado_minutos' => 10,
    ],

    // ---------------------------------------------------------------
    // API
    // ---------------------------------------------------------------
    'api' => [
        'max_documentos_por_lote' => 50,
        // Tempo maximo que a API espera quando o consumidor pede ?aguardar=N.
        'max_aguardar_segundos' => 60,
    ],

    // ---------------------------------------------------------------
    // Consumidores da API (sistemas externos).
    // Defina em config/nfse.local.php. Exemplo:
    //   'ERP' => [
    //       'token' => 'token-forte',
    //       'webhook_url' => 'https://erp.empresa/webhooks/nfse',
    //       'webhook_segredo' => 'segredo-hmac',
    //       // CNPJs que este sistema pode usar. Obrigatorio quando ha
    //       // mais de um emitente configurado.
    //       'cnpjs' => ['41986662000160'],
    //   ],
    // Se ficar vazio, o apiToken do config/nfe.php vale como consumidor 'PADRAO'.
    // ---------------------------------------------------------------
    'consumidores' => [],

    // ---------------------------------------------------------------
    // Arquivamento dos XMLs fora do banco (guarda legal de 5 anos).
    //   tipo: 'nenhum' | 'local' | 's3'
    //   's3' funciona com AWS S3 e servicos compativeis (MinIO, Wasabi,
    //   Cloudflare R2, DigitalOcean Spaces...). Credenciais em nfse.local.php.
    // ---------------------------------------------------------------
    'armazenamento' => [
        'tipo' => 'nenhum',
        'local' => [
            'diretorio' => __DIR__ . '/../storage/xml/nfse',
        ],
        's3' => [
            'endpoint' => 'https://s3.amazonaws.com',
            'regiao' => 'us-east-1',
            'bucket' => '',
            'prefixo' => 'nfse',
            'chave_acesso' => '',
            'chave_secreta' => '',
            // true para MinIO e alguns compativeis (https://host/bucket/arquivo).
            'path_style' => false,
        ],
    ],

    // Token para o painel do monitor (pode ir na query string do EventSource).
    'monitor_token' => '',

    // Painel de administracao (/admin/): cadastro das empresas clientes,
    // tokens, certificados, bloqueios, limites e uso para cobranca.
    // Vazio = painel desligado. Gere com: openssl rand -hex 32
    'admin_token' => '',

    // Chave que criptografa os certificados enviados pelo painel admin.
    // Gere UMA vez com:  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
    // Guarde copia com os backups (sem ela, os certificados precisam ser reenviados).
    'chave_certificados' => '',

    // Portal do cliente (/portal/): a empresa entra com e-mail e senha e
    // emite a propria NFS-e. Usuarios sao criados no painel admin.
    'portal' => [
        // Sessao normal (minutos) e com "manter conectado" (dias).
        'sessao_minutos' => 720,
        'sessao_lembrar_dias' => 30,
        // Senhas erradas seguidas antes de bloquear, e por quantos minutos.
        'tentativas_login' => 5,
        'bloqueio_minutos' => 15,
        // Vazio = Nacional (ou Salvador, se for o unico contratado).
        // 'NACIONAL' ou 'SALVADOR' forca para todas as empresas.
        'padrao' => '',
        // Consultas publicas para preencher o tomador sozinho.
        'url_cnpj' => 'https://brasilapi.com.br/api/cnpj/v1/{cnpj}',
        'url_cep' => 'https://viacep.com.br/ws/{cep}/json/',
    ],

    // E-mail (SMTP) para o "Esqueci a senha" do portal. Quem envia e o worker.
    // A SENHA fica no arquivo .env da raiz (SMTP_PASSWORD; modelo em
    // .env.example) ou no config/nfse.local.php. Nunca no Git.
    // O resto tambem pode ser mudado no painel: Sistema > Configuracoes.
    // Campo vazio aqui usa o .env: SMTP_HOST, SMTP_PORT, SMTP_SECURE,
    // SMTP_USER, SMTP_PASSWORD, SMTP_FROM, PORTAL_URL.
    'email' => [
        'host' => '',                 // ex.: mail.suaempresa.com.br
        'porta' => null,              // 465 = TLS direto; 587 = STARTTLS (vazio = .env ou 465)
        'seguranca' => null,          // ssl (465) | tls (587) | '' (sem criptografia); vazio = .env ou ssl
        'usuario' => '',              // geralmente o proprio e-mail da conta
        'senha' => '',                // so no nfse.local.php!
        'remetente' => '',            // so o endereco, ex.: contato@suaempresa.com.br
        'nome_remetente' => 'Gestão Fiscal',
        // Endereco publico do portal (vai no link do e-mail), ex.:
        // https://fiscal.suaempresa.com.br/portal/
        'url_portal' => '',
        'timeout_segundos' => 20,
    ],
];

$localConfigFile = __DIR__ . '/nfse.local.php';
if (is_file($localConfigFile)) {
    $localCfg = require $localConfigFile;
    if (!is_array($localCfg)) {
        throw new RuntimeException('config/nfse.local.php deve retornar um array.');
    }
    $cfg = array_replace_recursive($cfg, $localCfg);
}

return $cfg;
