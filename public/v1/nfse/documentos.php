<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/documentos.php[?status=AUTORIZADO][&padrao=SALVADOR][&limite=100]
 *
 * Lista os documentos mais recentes (carga inicial do painel do monitor).
 * Consumidor ve so os seus; token do painel ve todos.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$acesso = $api->autenticar(true);

$filtros = [
    'status' => strtoupper((string)($_GET['status'] ?? '')),
    'padrao' => strtoupper((string)($_GET['padrao'] ?? '')),
    'consumidor' => $acesso['painel'] ? (string)($_GET['consumidor'] ?? '') : $acesso['consumidor'],
];

$documentos = (new DocumentoRepositorio($api->pdo()))->listar($filtros, (int)($_GET['limite'] ?? 100));

$api->responder(200, [
    'documentos' => array_map(static fn(array $d) => DocumentoApresentador::documento($d), $documentos),
]);
