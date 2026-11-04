<?php
declare(strict_types=1);

/**
 * Configuracao da emissao de NFS-e (Padrao Nacional e Salvador-BA).
 *
 * Dados do emitente (CNPJ, razao social, certificado, ambiente) sao
 * reaproveitados de config/nfe.php para nao duplicar.
 *
 * NUNCA coloque segredos aqui. Use config/nfse.local.php (fora do Git),
 * a partir de config/nfse.local.php.example.
 */

$cfg = [
    // Sobrescreve o tpAmb do nfe.php se informado (1=Producao, 2=Homologacao).
    'tpAmb' => null,

    // ---------------------------------------------------------------
    // Emitente (prestador de servico)
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
        // DANFSe (PDF oficial) disponibilizado pelo ADN.
        'url_danfse' => [
            1 => 'https://adn.nfse.gov.br',
            2 => 'https://adn.producaorestrita.nfse.gov.br',
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
    //   ],
    // Se ficar vazio, o apiToken do config/nfe.php vale como consumidor 'PADRAO'.
    // ---------------------------------------------------------------
    'consumidores' => [],

    // Token para o painel do monitor (pode ir na query string do EventSource).
    'monitor_token' => '',
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
