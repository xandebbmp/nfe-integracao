<?php
declare(strict_types=1);

/**
 * Portal do cliente - sair (encerra a sessao deste navegador).
 *
 * POST /v1/portal/sair.php   (cabecalho X-Portal: 1)
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
PortalSessao::exigirPortalInstalado($api);
PortalSessao::exigirCabecalhoEmAlteracao($api);

(new PortalAcesso($api->pdo(), $api->cfg))->sair(PortalSessao::token());
PortalSessao::apagarCookie();
$api->responder(200, ['mensagem' => 'Sessão encerrada.']);
