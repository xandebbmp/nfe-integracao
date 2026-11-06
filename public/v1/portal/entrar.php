<?php
declare(strict_types=1);

/**
 * Portal do cliente - entrar.
 *
 * POST /v1/portal/entrar.php   (cabecalho X-Portal: 1)
 *   { "email": "...", "senha": "...", "lembrar": true }
 *
 * 200 { usuario: {...}, trocar_senha: bool } + cookie de sessao (HttpOnly)
 * 401 LOGIN_INVALIDO   e-mail ou senha errados
 * 429 LOGIN_BLOQUEADO  muitas tentativas (espera alguns minutos)
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
PortalSessao::exigirPortalInstalado($api);
PortalSessao::exigirCabecalhoEmAlteracao($api);

$corpo = $api->corpoJson();
$r = (new PortalAcesso($api->pdo(), $api->cfg))->entrar(
    (string)($corpo['email'] ?? ''),
    (string)($corpo['senha'] ?? ''),
    !empty($corpo['lembrar']),
    Api::ip(),
    (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
);
if (!$r['ok']) {
    usleep(300_000); // dificulta tentativa e erro
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}

// "Manter conectado": cookie com validade. Sem isso, some ao fechar o navegador.
PortalSessao::gravarCookie($r['token'], $r['lembrar'] ? $r['minutos'] : null);
$api->responder(200, ['usuario' => $r['usuario'], 'trocar_senha' => $r['usuario']['trocar_senha']]);
