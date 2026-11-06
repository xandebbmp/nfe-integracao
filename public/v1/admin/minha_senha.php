<?php
declare(strict_types=1);

/**
 * Painel admin - a pessoa logada troca a propria senha
 * (obrigatorio no 1o acesso, com a senha provisoria).
 *
 * POST /v1/admin/minha_senha.php  { "atual": "...", "nova": "..." }
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\PainelAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin(true);
if ($api->pessoaPainel === null) {
    $api->erro(409, 'CHAVE_EMERGENCIA', 'A chave de emergência não tem senha. Entre com o seu e-mail para trocar a sua.');
}
$corpo = $api->corpoJson();
$r = (new PainelAcesso($api->pdo(), $api->cfg))->trocarSenha($api->pessoaPainel, (string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''),
    (string)($corpo['atual'] ?? ''), (string)($corpo['nova'] ?? ''));
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, ['mensagem' => $r['mensagem']]);
