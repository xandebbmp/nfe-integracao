<?php
declare(strict_types=1);

/**
 * POST /v1/emissor/emitir.php
 * {
 *   "valor": "1.500,00",
 *   "tomador": { "documento": "...", "nome": "...", "email": "...", "endereco": {...} },
 *   "descricao": "Servico prestado...",
 *   "servico_id": 3,            (opcional: sem ele, usa o servico padrao)
 *   "chave": "id-do-formulario" (o mesmo em nova tentativa: nao duplica)
 * }
 *
 * Coloca a nota na fila e espera ate 20 s pelo resultado.
 * Resposta: { "nota": { "status": "AUTORIZADO" | "PROCESSANDO" | "REJEITADO"..., ... } }
 */

$metodos = ['POST'];
require __DIR__ . '/_inicio.php';

$usuario = $exigirUsuario();
$r = $emissor->emitir($usuario, $api->corpoJson());
if (!$r['ok']) {
    $responderResultado($r);
}

$api->aguardar(20, static function () use ($emissor, $usuario, $r): bool {
    $nota = $emissor->nota($usuario, $r['id']);
    return $nota !== null && !in_array($nota['status'], ['RECEBIDO', 'PROCESSANDO'], true);
});

$api->responder(200, ['situacao_recebimento' => $r['situacao_recebimento'], 'nota' => $emissor->nota($usuario, $r['id'])]);
