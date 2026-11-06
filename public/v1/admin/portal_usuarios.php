<?php
declare(strict_types=1);

/**
 * Painel admin - usuarios do portal do cliente de uma empresa.
 *
 * GET  /v1/admin/portal_usuarios.php?cnpj=...           lista
 * POST /v1/admin/portal_usuarios.php
 *   { "acao": "criar", "cnpj": "...", "nome": "...", "email": "..." }
 *   { "acao": "redefinir_senha", "cnpj": "...", "id": 1 }
 *   { "acao": "ativar" | "desativar", "cnpj": "...", "id": 1 }
 *   { "acao": "tornar_admin" | "remover_admin", "cnpj": "...", "id": 1 }
 *
 * O primeiro usuario de uma empresa sem administrador ativo vira
 * administrador sozinho (pode convidar colegas pela tela Usuarios do portal).
 *
 * "criar" e "redefinir_senha" devolvem uma senha PROVISORIA que aparece so
 * nesta resposta. Entregue a pessoa: ela troca no primeiro acesso.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
PortalSessao::exigirPortalInstalado($api);
$acesso = new PortalAcesso($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, ['usuarios' => $acesso->usuarios((string)($_GET['cnpj'] ?? ''))]);
}

$corpo = $api->corpoJson();
$cnpj = (string)($corpo['cnpj'] ?? '');
$id = (int)($corpo['id'] ?? 0);

$r = match ((string)($corpo['acao'] ?? '')) {
    'criar' => $acesso->criarUsuario($cnpj, (string)($corpo['nome'] ?? ''), (string)($corpo['email'] ?? ''), Api::ip()),
    'redefinir_senha' => $acesso->redefinirSenha($cnpj, $id, Api::ip()),
    'ativar' => $acesso->definirAtivo($cnpj, $id, true, Api::ip()),
    'desativar' => $acesso->definirAtivo($cnpj, $id, false, Api::ip()),
    'tornar_admin' => $acesso->definirAdministrador($cnpj, $id, true, Api::ip()),
    'remover_admin' => $acesso->definirAdministrador($cnpj, $id, false, Api::ip()),
    default => ['ok' => false, 'http' => 400, 'codigo' => 'ACAO_INVALIDA', 'mensagem' => 'Use acao: criar, redefinir_senha, ativar, desativar, tornar_admin ou remover_admin.'],
};
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
unset($r['ok']);
$api->responder(200, $r);
