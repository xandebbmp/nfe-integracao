<?php
declare(strict_types=1);

/**
 * Portal do cliente - guias DAS do Simples que o contador anexou.
 * Regras em Servico/GuiasDas.php.
 *
 * GET /v1/portal/guias.php                     lista (mais nova primeiro)
 * GET /v1/portal/guias.php?id=12[&download=1]  PDF da guia
 *
 * So mostra guias da empresa do usuario logado.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\GuiasDas;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);
$guias = new GuiasDas($api->pdo(), $api->cfg);

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $guia = $guias->buscar($id);
    // 404 (e nao 403) para nao revelar guias de outras empresas.
    if ($guia === null || $guia['cnpj'] !== $usuario['cnpj']) {
        $api->erro(404, 'NAO_ENCONTRADA', 'Guia não encontrada.');
    }
    $pdf = (string)$guias->pdf($id);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="das-' . $guia['competencia'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$api->responder(200, ['guias' => array_map(static function (array $g): array {
    unset($g['enviado_por']); // nome/e-mail de quem anexou fica so no painel
    return $g;
}, $guias->daEmpresa($usuario['cnpj']))]);
