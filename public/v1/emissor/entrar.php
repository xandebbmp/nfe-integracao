<?php
declare(strict_types=1);

/**
 * POST /v1/emissor/entrar.php   { "email": "...", "senha": "..." }
 * Resposta: { "token": "..." } -> envie em X-EMISSOR-SESSAO nas outras rotas.
 * 5 senhas erradas seguidas bloqueiam o login por 15 minutos.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;

$metodos = ['POST'];
require __DIR__ . '/_inicio.php';

$corpo = $api->corpoJson();
$responderResultado($emissor->entrar((string)($corpo['email'] ?? ''), (string)($corpo['senha'] ?? ''), Api::ip()));
