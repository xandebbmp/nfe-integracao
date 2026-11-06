<?php
declare(strict_types=1);

/**
 * Portal do cliente - tela "Usuarios": quem da empresa entra no portal.
 *
 * GET  /v1/portal/usuarios.php
 *   { "usuarios": [...], "pode_gerenciar": true|false }
 * POST /v1/portal/usuarios.php      (so administrador da empresa; X-Portal: 1)
 *   { "acao": "convidar", "nome": "...", "email": "...", "administrador": false }
 *   { "acao": "redefinir_senha" | "desativar" | "ativar" | "tornar_admin" | "remover_admin", "id": 1 }
 *
 * "convidar" e "redefinir_senha" devolvem uma senha PROVISORIA que aparece
 * so nesta resposta. A pessoa troca no primeiro acesso.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalUsuarios;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$usuario = PortalSessao::exigir($api);
$usuarios = new PortalUsuarios($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, $usuarios->listar($usuario));
}

$r = $usuarios->executar($usuario, $api->corpoJson(), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, $r);
