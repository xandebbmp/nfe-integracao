<?php
declare(strict_types=1);

/** POST /v1/emissor/sair.php  (encerra a sessao do cabecalho X-EMISSOR-SESSAO) */

use Xande\NfeIntegracao\Nfse\Api\Api;

$metodos = ['POST'];
require __DIR__ . '/_inicio.php';

$emissor->sair(Api::lerCabecalho('X-EMISSOR-SESSAO'));
$api->responder(200, ['mensagem' => 'Sessao encerrada.']);
