<?php
declare(strict_types=1);

/**
 * Portal do cliente - tomadores salvos (preenchidos sozinhos na emissao).
 *
 * GET /v1/portal/tomadores.php?busca=texto
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);

$busca = trim((string)($_GET['busca'] ?? ''));
$api->responder(200, ['tomadores' => (new PortalRepositorio($api->pdo()))->tomadores($usuario['cnpj'], mb_substr($busca, 0, 60))]);
