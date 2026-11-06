<?php
declare(strict_types=1);

/**
 * Painel admin - "Pessoas do painel" (tela Sistema): quem entra no painel.
 *
 * GET  /v1/admin/pessoas.php                        lista
 * POST /v1/admin/pessoas.php
 *   { "acao": "criar", "nome": "...", "email": "..." }
 *   { "acao": "redefinir_senha", "id": 1 }
 *   { "acao": "ativar" | "desativar", "id": 1 }
 *   { "acao": "enviar_acesso", "id": 1, "senha": "<provisoria>", "link_painel": "..." }
 *
 * "criar" e "redefinir_senha" devolvem uma senha PROVISORIA que aparece so
 * nesta resposta. A pessoa troca no primeiro login.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\PainelAcesso;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$acesso = new PainelAcesso($api->pdo(), $api->cfg);
if (!$acesso->instalado()) {
    $api->erro(503, 'PAINEL_SEM_PESSOAS', 'Falta atualizar o banco: clique em "Atualizar banco" acima.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, ['pessoas' => $acesso->listar(), 'eu' => $api->pessoaPainel['id'] ?? null]);
}

$corpo = $api->corpoJson();
$id = (int)($corpo['id'] ?? 0);
$eu = $api->pessoaPainel['id'] ?? null;

$r = match ((string)($corpo['acao'] ?? '')) {
    'criar' => $acesso->criar((string)($corpo['nome'] ?? ''), (string)($corpo['email'] ?? ''), Api::ip()),
    'redefinir_senha' => $acesso->redefinirSenha($id, Api::ip()),
    'ativar' => $acesso->definirAtivo($id, true, $eu, Api::ip()),
    'desativar' => $acesso->definirAtivo($id, false, $eu, Api::ip()),
    'enviar_acesso' => $acesso->enviarAcessoPorEmail($id, (string)($corpo['senha'] ?? ''), (string)($corpo['link_painel'] ?? ''), Api::ip()),
    default => ['ok' => false, 'http' => 400, 'codigo' => 'ACAO_INVALIDA', 'mensagem' => 'Use acao: criar, redefinir_senha, ativar, desativar ou enviar_acesso.'],
};
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
unset($r['ok']);
$api->responder(200, $r);
