<?php
declare(strict_types=1);

/**
 * Portal do cliente - trocar a propria senha.
 *
 * POST /v1/portal/senha.php   (cabecalho X-Portal: 1)
 *   { "senha_atual": "...", "senha_nova": "..." }
 *
 * Obrigatorio no 1o acesso (senha provisoria criada no painel admin).
 * Os outros navegadores logados com a senha antiga saem.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$usuario = PortalSessao::exigir($api, true);

$corpo = $api->corpoJson();
$r = (new PortalAcesso($api->pdo(), $api->cfg))->trocarSenha(
    $usuario['id'],
    (string)($corpo['senha_atual'] ?? ''),
    (string)($corpo['senha_nova'] ?? ''),
    PortalSessao::token()
);
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, ['mensagem' => $r['mensagem']]);
