<?php
declare(strict_types=1);

/**
 * Painel admin - tela "Sistema", bloco Senha do Monitor.
 *
 * GET  /v1/admin/monitor_senha.php   a senha existe? quando foi gerada? (nunca a senha)
 * POST /v1/admin/monitor_senha.php   gera uma senha NOVA
 *
 * Funciona como o token das empresas: a senha aparece SO na resposta do
 * POST (o banco guarda apenas o hash). A anterior para de funcionar na hora.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Servico\ConfiguracoesPainel;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$configuracoes = new ConfiguracoesPainel($api->pdo(), NfseConfig::carregar());

if (!$configuracoes->instalado()) {
    $api->erro(503, 'CONFIGURACOES_NAO_INSTALADAS', 'Falta atualizar o banco: clique em "Atualizar banco" acima.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, $configuracoes->senhaMonitor());
}

$r = $configuracoes->gerarSenhaMonitor(Api::ip());
unset($r['ok']);
$api->responder(200, $r);
