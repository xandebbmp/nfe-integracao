<?php
declare(strict_types=1);

/**
 * Portal do cliente - cancelar uma nota (botao no detalhe de "Minhas notas").
 *
 * POST /v1/portal/cancelar.php      (qualquer usuario da empresa; X-Portal: 1)
 *   { "id": "<uuid>", "motivo_codigo": "1"|"2"|"9", "motivo": "15 a 255 letras",
 *     "numero_processo": "..." }    (numero_processo: so Salvador, protocolo da prefeitura)
 *
 * Padrao Nacional: o pedido vai para a fila (o worker envia ao fisco).
 * Salvador: so registra o cancelamento ja feito no site da prefeitura.
 * Regras em Servico/PortalCancelamento.php.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalCancelamento;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$usuario = PortalSessao::exigir($api);

$r = (new PortalCancelamento($api->pdo(), $api->cfg))->cancelar($usuario, $api->corpoJson());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}
$api->responder(200, $r);
