<?php
declare(strict_types=1);

/**
 * Portal do cliente - quem esta logado e dados da empresa (topo e menu).
 *
 * GET /v1/portal/sessao.php
 *
 * 200 { usuario: {...}, empresa: {...} }
 * 401 SESSAO_EXPIRADA  (o portal mostra a tela de login)
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalInicio;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
// Responde mesmo com senha provisoria: o portal usa trocar_senha para pedir a troca.
$usuario = PortalSessao::exigir($api, true);

$api->responder(200, [
    'usuario' => [
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'trocar_senha' => $usuario['trocar_senha'],
    ],
    'empresa' => (new PortalInicio($api->pdo(), $api->cfg))->empresa($usuario['cnpj']),
]);
