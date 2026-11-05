<?php
declare(strict_types=1);

/** GET /v1/emissor/nota.php?id=...   situacao atual de uma nota da empresa. */

$metodos = ['GET'];
require __DIR__ . '/_inicio.php';

$nota = $emissor->nota($exigirUsuario(), (string)($_GET['id'] ?? ''));
if ($nota === null) {
    $api->erro(404, 'NAO_ENCONTRADO', 'Nota nao encontrada.');
}
$api->responder(200, ['nota' => $nota]);
