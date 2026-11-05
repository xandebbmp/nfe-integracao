<?php
declare(strict_types=1);

/**
 * Painel admin - gera um token NOVO para a empresa usar a API.
 *
 * POST /v1/admin/token.php   { "cnpj": "..." }
 *
 * O token anterior para de funcionar na hora. O novo aparece SO nesta
 * resposta (o banco guarda apenas o hash): copie e entregue ao cliente.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

$corpo = $api->corpoJson();
$r = (new AdministracaoClientes($api->pdo(), $api->cfg))->gerarToken((string)($corpo['cnpj'] ?? ''), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, ['token' => $r['token'], 'mensagem' => $r['mensagem']]);
