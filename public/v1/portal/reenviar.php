<?php
declare(strict_types=1);

/**
 * Portal do cliente - botao "Reenviar" de uma nota rejeitada ou com erro
 * (detalhe e linha de "Minhas notas").
 *
 * POST /v1/portal/reenviar.php   (qualquer usuario da empresa; X-Portal: 1)   { "id": "<uuid>" }
 *
 * Manda de novo os mesmos dados (mesma referencia e mesmo numero). Regras em
 * src/Nfse/Servico/ReenvioDocumento.php.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\ReenvioDocumento;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$usuario = PortalSessao::exigir($api);

\Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio::$autor = trim(($usuario['nome'] ?? '') . ' <' . ($usuario['email'] ?? '') . '> (portal)');
$r = (new ReenvioDocumento($api->pdo(), $api->cfg))->reenviar((string)($api->corpoJson()['id'] ?? ''), (string)$usuario['cnpj'], Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, $r);
