<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/capacidades.php
 *
 * O que cada padrao suporta (emissao sincrona/assincrona, eventos aceitos,
 * eventos NAO suportados e o motivo, tipo de PDF). O sistema consumidor deve
 * usar esta rota para habilitar/desabilitar botoes (ex.: "Cancelar").
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Dominio\Capacidades;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$api->autenticar();

$api->responder(200, [
    'ambiente' => (int)$api->cfg['tpAmb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
    'padroes' => Capacidades::todos(),
]);
