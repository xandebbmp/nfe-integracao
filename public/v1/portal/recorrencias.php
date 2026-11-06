<?php
declare(strict_types=1);

/**
 * Portal do cliente - tela "Recorrencias": notas que se repetem todo mes.
 *
 * GET  /v1/portal/recorrencias.php                 lista
 * POST /v1/portal/recorrencias.php                 (cabecalho X-Portal: 1)
 *   { "acao": "salvar", "id"?, "nome", "tomador": {...}, "servico_id", "descricao",
 *     "valores": {...}, "primeira_em": "AAAA-MM-DD", "competencia": "ATUAL|ANTERIOR",
 *     "modo": "AUTOMATICA|CONFIRMAR", "fim"?: "AAAA-MM" }
 *   { "acao": "emitir" | "pular" | "pausar" | "retomar" | "remover", "id": 1 }
 *
 * "emitir" e "pular" so valem quando o dia da nota chegou (situacao PENDENTE).
 * As AUTOMATICAS sao emitidas pelo worker (bin/nfse_worker.php).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Repositorio\RecorrenciaRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\PortalRecorrencias;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$usuario = PortalSessao::exigir($api);
if (!(new RecorrenciaRepositorio($api->pdo()))->existe()) {
    $api->erro(503, 'RECORRENCIAS_NAO_INSTALADAS', 'Recorrências sem banco: aplique o script 0011 (botão "Atualizar banco" no painel).');
}
$recorrencias = new PortalRecorrencias($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, ['recorrencias' => $recorrencias->listar($usuario['cnpj'])]);
}

$corpo = $api->corpoJson();
$id = (int)($corpo['id'] ?? 0);
$r = match ((string)($corpo['acao'] ?? '')) {
    'salvar' => $recorrencias->salvar($usuario, $corpo),
    'emitir' => $recorrencias->emitirMes($usuario, $id),
    'pular' => $recorrencias->pular($usuario, $id),
    'pausar' => $recorrencias->pausar($usuario, $id),
    'retomar' => $recorrencias->retomar($usuario, $id),
    'remover' => $recorrencias->remover($usuario, $id),
    default => ['ok' => false, 'http' => 422, 'codigo' => 'ACAO_INVALIDA', 'mensagem' => 'Ação desconhecida.'],
};
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}
$api->responder(200, $r);
