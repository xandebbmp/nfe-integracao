<?php
declare(strict_types=1);

/**
 * Portal do cliente - tela "Minhas notas".
 *
 * GET /v1/portal/notas.php?status=&busca=&mes=AAAA-MM&pagina=1
 *       status: AUTORIZADAS | EM_ANDAMENTO | COM_PROBLEMA | CANCELADAS (vazio = todas)
 *       busca:  nome ou CPF/CNPJ do tomador, ou numero da NFS-e/DPS
 * GET /v1/portal/notas.php?id=UUID     detalhe de uma nota
 *
 * So aparecem notas da empresa de quem esta logado, no ambiente atual.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalInicio;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);
$inicio = new PortalInicio($api->pdo(), $api->cfg);

if (isset($_GET['id'])) {
    $nota = $inicio->nota($usuario['cnpj'], (string)$_GET['id']);
    if ($nota === null) {
        $api->erro(404, 'NOTA_NAO_ENCONTRADA', 'Nota não encontrada.');
    }
    $api->responder(200, ['nota' => $nota]);
}

$api->responder(200, $inicio->listarNotas($usuario['cnpj'], [
    'status' => (string)($_GET['status'] ?? ''),
    'busca' => (string)($_GET['busca'] ?? ''),
    'mes' => (string)($_GET['mes'] ?? ''),
    'pagina' => (int)($_GET['pagina'] ?? 1),
]));
