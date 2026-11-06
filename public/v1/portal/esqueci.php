<?php
declare(strict_types=1);

/**
 * Portal do cliente - "Esqueci a senha" (sem login; cabecalho X-Portal: 1).
 *
 * GET  /v1/portal/esqueci.php
 *   { "disponivel": true|false }   false = mostra "peca ao administrador"
 * POST /v1/portal/esqueci.php
 *   { "acao": "pedir", "email": "..." }              sempre a mesma resposta
 *   { "acao": "conferir", "codigo": "..." }          o link ainda vale?
 *   { "acao": "redefinir", "codigo": "...", "senha": "..." }
 *
 * Regras em Servico/PortalEsqueciSenha.php. O e-mail sai pelo worker.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalEsqueciSenha;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
PortalSessao::exigirPortalInstalado($api);
PortalSessao::exigirCabecalhoEmAlteracao($api);
$esqueci = new PortalEsqueciSenha($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, ['disponivel' => $esqueci->disponivel()]);
}

$corpo = $api->corpoJson();
$codigo = (string)($corpo['codigo'] ?? '');
$r = match ((string)($corpo['acao'] ?? '')) {
    'pedir' => $esqueci->pedir((string)($corpo['email'] ?? ''), Api::ip()),
    'conferir' => $esqueci->conferir($codigo),
    'redefinir' => $esqueci->redefinir($codigo, (string)($corpo['senha'] ?? '')),
    default => ['ok' => false, 'http' => 422, 'codigo' => 'ACAO_INVALIDA', 'mensagem' => 'Ação desconhecida.'],
};
if (!$r['ok']) {
    usleep(300_000); // dificulta tentativa e erro com codigos
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}
$api->responder(200, $r);
