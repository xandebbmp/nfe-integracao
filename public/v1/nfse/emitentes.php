<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/emitentes.php
 *
 * Emitentes (CNPJs) que quem chama pode usar:
 *   - sistema consumidor: os CNPJs liberados para ele (informe um deles em
 *     "emitente_cnpj" ao emitir, quando houver mais de um);
 *   - token do painel: todos (usado no filtro do monitor).
 *
 * Nunca devolve dados do certificado.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\NfseConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$acesso = $api->autenticar(true);

$cnpjs = $acesso['painel']
    ? array_map('strval', array_keys($api->cfg['emitentes']))
    : NfseConfig::cnpjsPermitidos($api->cfg, (string)$acesso['consumidor']);

$emitentes = [];
foreach ($cnpjs as $cnpj) {
    $e = $api->cfg['emitentes'][$cnpj];
    $emitentes[] = [
        'cnpj' => (string)$e['cnpj'],
        'razao_social' => $e['razao_social'],
        'inscricao_municipal' => $e['inscricao_municipal'],
        'codigo_municipio' => $e['codigo_municipio'],
    ];
}

$api->responder(200, ['emitentes' => $emitentes]);
