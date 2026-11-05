<?php
declare(strict_types=1);

/**
 * Painel admin - empresas clientes.
 *
 * GET  /v1/admin/clientes.php   lista com situacao e notas autorizadas no mes
 * POST /v1/admin/clientes.php   cadastra (CNPJ novo) ou altera uma empresa
 *      { "cnpj": "...", "razao_social": "...", "emissao_liberada": true,
 *        "liberado_ate": "2026-12-31", "limite_mensal": 500,
 *        "padroes_permitidos": ["NFE","NACIONAL"], ... }
 *      So os campos enviados mudam.
 *
 * Cabecalho obrigatorio: X-ADMIN-TOKEN (admin_token em config/nfse.local.php).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$admin = new AdministracaoClientes($api->pdo(), $api->cfg);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $api->responder(200, [
        'ambiente' => (int)$api->cfg['tpAmb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
        'mes' => AdministracaoClientes::mesAtual(),
        'clientes' => $admin->listar(),
    ]);
}

$r = $admin->salvar($api->corpoJson(), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], array_diff_key($r, array_flip(['ok', 'http', 'codigo', 'mensagem'])));
}
$api->responder($r['http'], ['cliente' => $r['cliente']]);
