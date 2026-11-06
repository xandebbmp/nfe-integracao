<?php
declare(strict_types=1);

/**
 * Painel admin / monitor - botao "Reenviar" de uma nota rejeitada ou com erro.
 *
 * POST /v1/admin/reenviar.php  (X-ADMIN-TOKEN)   { "id": "<uuid do documento>" }
 *
 * Manda de novo os mesmos dados (mesma referencia e mesmo numero). Regras em
 * src/Nfse/Servico/ReenvioDocumento.php.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\ReenvioDocumento;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

$r = (new ReenvioDocumento($api->pdo(), $api->cfg))->reenviar((string)($api->corpoJson()['id'] ?? ''), null, Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
unset($r['ok']);
$api->responder(200, $r);
