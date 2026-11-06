<?php
declare(strict_types=1);

/**
 * Painel admin - tela "Sistema", bloco E-mail.
 *
 * GET  /v1/admin/email.php
 *   situacao do SMTP (sem a senha), se o "Esqueci a senha" esta ligado e os
 *   ultimos e-mails da fila (sem o conteudo, que pode ter link de senha).
 * POST /v1/admin/email.php  { "para": "voce@empresa.com.br" }
 *   envia AGORA um e-mail de teste (nao passa pela fila) e devolve o erro do
 *   servidor de e-mail, se houver.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\EmailRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\EnvioEmail;
use Xande\NfeIntegracao\Nfse\Servico\PortalEsqueciSenha;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$envio = new EnvioEmail($api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fila = new EmailRepositorio($api->pdo());
    $instalado = $fila->existe();
    $api->responder(200, [
        'instalado' => $instalado,
        'configurado' => $envio->configurado(),
        'faltam' => $envio->pendencias(),
        'remetente' => $envio->remetente(),
        'esqueci_ligado' => (new PortalEsqueciSenha($api->pdo(), $api->cfg))->disponivel(),
        'url_portal' => (string)($api->cfg['email']['url_portal'] ?? ''),
        'ultimos' => $instalado ? $fila->ultimos(8) : [],
    ]);
}

$para = trim((string)($api->corpoJson()['para'] ?? ''));
if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
    $api->erro(422, 'EMAIL_INVALIDO', 'Digite um e-mail válido para receber o teste.');
}
try {
    $envio->enviar($para, 'Teste de e-mail do sistema fiscal',
        "Este é um e-mail de teste enviado pelo painel admin.\nSe chegou, o \"Esqueci a senha\" do portal consegue enviar e-mails.\n");
} catch (Throwable $e) {
    $api->erro(502, 'EMAIL_FALHOU', 'O servidor de e-mail recusou: ' . $e->getMessage());
}
$api->responder(200, ['mensagem' => "E-mail de teste enviado para {$para}. Confira a caixa de entrada (e o spam)."]);
