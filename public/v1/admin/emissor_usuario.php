<?php
declare(strict_types=1);

/**
 * Painel admin - acessos ao emissor simples de uma empresa.
 *
 * POST /v1/admin/emissor_usuario.php
 *   { "acao": "CRIAR", "cnpj": "...", "nome": "...", "email": "..." }   -> devolve "senha" (UMA vez)
 *   { "acao": "NOVA_SENHA" | "ATIVAR" | "DESATIVAR", "cnpj": "...", "id": 12 }
 * A lista sai em GET /v1/admin/cliente.php?cnpj=... ("emissor.usuarios").
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoEmissor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

$r = (new AdministracaoEmissor($api->pdo()))->usuario($api->corpoJson(), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
unset($r['ok']);
$http = $r['http'];
unset($r['http']);
$api->responder($http, $r);
