<?php
declare(strict_types=1);

/**
 * Painel admin - secao "Configuracoes" (tela Sistema).
 *
 * GET  /v1/admin/configuracoes.php                 lista com valor atual e do arquivo
 * POST /v1/admin/configuracoes.php
 *   { "valores": { "portal.tentativas_login": 5, ... } }
 *
 * So as chaves de src/Nfse/Dominio/AjustesPainel.php sao aceitas.
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
    $api->responder(200, $configuracoes->listar());
}

$corpo = $api->corpoJson();
$r = $configuracoes->salvar(is_array($corpo['valores'] ?? null) ? $corpo['valores'] : [], Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], ['erros' => $r['erros'] ?? []]);
}
unset($r['ok']);
$api->responder(200, $r);
