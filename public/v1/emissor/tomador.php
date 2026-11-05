<?php
declare(strict_types=1);

/**
 * GET /v1/emissor/tomador.php?documento=<CPF ou CNPJ>
 * Completa o tomador: primeiro os ja usados pela empresa; depois, se for
 * CNPJ, a base publica da Receita (BrasilAPI). "origem": SALVO, RECEITA ou null.
 */

$metodos = ['GET'];
require __DIR__ . '/_inicio.php';

$responderResultado($emissor->tomador($exigirUsuario(), (string)($_GET['documento'] ?? '')));
