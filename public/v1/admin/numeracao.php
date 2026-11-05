<?php
declare(strict_types=1);

/**
 * Painel admin - numeracao inicial de uma serie.
 *
 * POST /v1/admin/numeracao.php
 *      { "cnpj": "...", "padrao": "NFE", "serie": "1", "ultimo_numero": 1520 }
 *
 * Informe o ULTIMO numero ja usado (no sistema antigo, no portal...). A
 * proxima nota emitida pela API sai com o numero seguinte (1521).
 * Nunca volta para tras: se a API ja usou um numero maior, ele e mantido.
 * Vale para o ambiente atual do servidor (homologacao ou producao).
 *
 * As series em uso aparecem em GET /v1/admin/cliente.php?cnpj=... ("numeracao").
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

$r = (new AdministracaoClientes($api->pdo(), $api->cfg))->definirNumeracao($api->corpoJson(), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, [
    'aplicado' => $r['aplicado'],
    'proximo_numero' => $r['proximo_numero'],
    'mensagem' => $r['mensagem'],
    'cliente' => $r['cliente'],
]);
