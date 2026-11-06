<?php
declare(strict_types=1);

/**
 * Portal do cliente - tela inicial: numeros do mes, avisos e ultimas notas.
 *
 * GET /v1/portal/inicio.php
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalInicio;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);

$inicio = new PortalInicio($api->pdo(), $api->cfg);
$api->responder(200, [
    'resumo' => $inicio->resumo($usuario['cnpj']),
    'notas' => $inicio->notas($usuario['cnpj'], 5),
]);
