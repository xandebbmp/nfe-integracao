<?php
declare(strict_types=1);

/**
 * Painel admin - botao "Atualizar banco" (tela Sistema).
 *
 * GET  /v1/admin/banco.php                    scripts aplicados e pendentes
 * POST /v1/admin/banco.php  { "acao": "aplicar" }   roda os pendentes, em ordem
 *
 * Faz o mesmo que deploy/aplicar_banco.sh (ver src/Nfse/Servico/AtualizacaoBanco.php).
 * Funciona mesmo com o banco desatualizado: so precisa conectar.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AtualizacaoBanco;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$banco = new AtualizacaoBanco($api->pdo());

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, $banco->status());
}

$corpo = $api->corpoJson();
if (($corpo['acao'] ?? '') !== 'aplicar') {
    $api->erro(400, 'ACAO_INVALIDA', 'Use acao: aplicar.');
}
$r = $banco->aplicar(Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
unset($r['ok']);
$api->responder(200, $r);
