<?php
declare(strict_types=1);

/**
 * POST /v1/emissor/cancelar.php
 * { "id": "...", "motivo_codigo": "1" (erro na emissao) | "2" (servico nao prestado) | "9" (outros),
 *   "motivo": "texto de 15 a 255 caracteres" }
 */

$metodos = ['POST'];
require __DIR__ . '/_inicio.php';

$corpo = $api->corpoJson();
$responderResultado($emissor->cancelar($exigirUsuario(), (string)($corpo['id'] ?? ''),
    (string)($corpo['motivo_codigo'] ?? '9'), (string)($corpo['motivo'] ?? '')));
