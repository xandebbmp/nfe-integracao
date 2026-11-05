<?php
declare(strict_types=1);

/**
 * Painel admin - uma empresa: cadastro, situacao, uso dos ultimos 12 meses
 * por tipo de nota, documentos do mes por status e historico de alteracoes.
 *
 * GET /v1/admin/cliente.php?cnpj=...
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$api->exigirAdmin();

$cliente = (new AdministracaoClientes($api->pdo(), $api->cfg))->detalhe((string)($_GET['cnpj'] ?? ''));
if ($cliente === null) {
    $api->erro(404, 'NAO_ENCONTRADO', 'Empresa nao cadastrada.');
}
$api->responder(200, ['cliente' => $cliente]);
