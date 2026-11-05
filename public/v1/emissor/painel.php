<?php
declare(strict_types=1);

/**
 * GET /v1/emissor/painel.php
 * Tudo o que a tela precisa: empresa (e se pode emitir), servicos,
 * tomadores recentes e as ultimas notas.
 */

$metodos = ['GET'];
require __DIR__ . '/_inicio.php';

$api->responder(200, $emissor->painel($exigirUsuario()));
