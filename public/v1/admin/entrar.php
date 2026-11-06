<?php
declare(strict_types=1);

/**
 * Painel admin - login com e-mail e senha (script 0014).
 *
 * POST   /v1/admin/entrar.php  { "email": "...", "senha": "...", "lembrar": true }
 *   devolve { token, minutos, pessoa }. O painel guarda o token e manda no
 *   cabecalho X-ADMIN-TOKEN (o mesmo de antes).
 * GET    /v1/admin/entrar.php  (com X-ADMIN-TOKEN)
 *   quem esta logado: { pessoa | null (chave de emergencia), tem_pessoas, instalado }
 * DELETE /v1/admin/entrar.php  (com X-ADMIN-TOKEN)   sair
 *
 * O admin_token de config/nfse.local.php continua entrando como CHAVE DE
 * EMERGENCIA (cadastrar a primeira pessoa, ou se todos ficarem sem acesso).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\PainelAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST', 'DELETE']);
$acesso = new PainelAcesso($api->pdo(), $api->cfg);
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {
    $corpo = $api->corpoJson();
    $r = $acesso->entrar((string)($corpo['email'] ?? ''), (string)($corpo['senha'] ?? ''), (bool)($corpo['lembrar'] ?? false),
        Api::ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!$r['ok']) {
        $api->erro($r['http'], $r['codigo'], $r['mensagem']);
    }
    unset($r['ok']);
    $api->responder(200, $r);
}

$api->exigirAdmin(true);
if ($metodo === 'DELETE') {
    $acesso->sair((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    $api->responder(200, ['mensagem' => 'Até logo.']);
}
$api->responder(200, [
    'pessoa' => $api->pessoaPainel,
    'chave_emergencia' => $api->pessoaPainel === null,
    'instalado' => $acesso->instalado(),
    'tem_pessoas' => $acesso->temPessoas(),
]);
