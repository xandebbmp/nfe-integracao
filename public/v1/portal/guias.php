<?php
declare(strict_types=1);

/**
 * Portal do cliente - guias DAS do Simples que o contador anexou.
 * Regras em Servico/GuiasDas.php.
 *
 * GET /v1/portal/guias.php                     lista (mais nova primeiro)
 * GET /v1/portal/guias.php?id=12[&download=1]  PDF da guia
 * POST {"acao": "pagar", "id": 12, "data": "2026-12-18"}  "Já paguei" (data vazia = hoje)
 * POST {"acao": "desfazer_pagamento", "id": 12}
 *
 * So mostra guias da empresa do usuario logado.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\GuiasDas;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$usuario = PortalSessao::exigir($api);
$guias = new GuiasDas($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $corpo = $api->corpoJson();
    $quem = "Portal: {$usuario['nome']} <{$usuario['email']}>";
    $acao = (string)($corpo['acao'] ?? '');
    if (!in_array($acao, ['pagar', 'desfazer_pagamento'], true)) {
        $api->erro(422, 'ACAO_INVALIDA', 'Ação desconhecida.');
    }
    $data = $acao === 'pagar' ? (string)($corpo['data'] ?? '') : null;
    $r = $guias->definirPagamento((int)($corpo['id'] ?? 0), $data, $quem, Api::ip(), $usuario['cnpj']);
    if (!$r['ok']) {
        $api->erro($r['http'], $r['codigo'], $r['mensagem']);
    }
    $api->responder(200, ['mensagem' => 'Feito.']);
}

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

$api->responder(200, [
    'pagamento_instalado' => $guias->pagamentoInstalado(),
    'guias' => array_map(static function (array $g): array {
        unset($g['enviado_por']); // nome/e-mail de quem anexou fica so no painel
        return $g;
    }, $guias->daEmpresa($usuario['cnpj'])),
]);
