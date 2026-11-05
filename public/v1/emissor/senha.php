<?php
declare(strict_types=1);

/** POST /v1/emissor/senha.php  { "atual": "...", "nova": "..." }  (minimo 8 caracteres) */

$metodos = ['POST'];
require __DIR__ . '/_inicio.php';

$corpo = $api->corpoJson();
$responderResultado($emissor->trocarSenha($exigirUsuario(), (string)($corpo['atual'] ?? ''), (string)($corpo['nova'] ?? '')));
