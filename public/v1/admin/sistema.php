<?php
declare(strict_types=1);

/**
 * Painel admin - tela "Sistema": situacao e comandos do worker.
 *
 * GET  /v1/admin/sistema.php                      situacao do worker e da fila
 * POST /v1/admin/sistema.php  { "acao": "ligar" | "desligar" | "pausar" | "retomar" | "reiniciar",
 *                                "confirmar_producao": true }   (so no "ligar", com notas de producao na fila)
 *
 * Ver src/Nfse/Servico/ControleSistema.php.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\ControleSistema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$controle = new ControleSistema($api->pdo());

if (!$controle->instalado()) {
    $api->erro(503, 'SISTEMA_NAO_INSTALADO', 'Falta aplicar database/scripts/0007_sistema_criar_controle_worker.sql.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, $controle->status());
}

$corpo = $api->corpoJson();
$r = $controle->executar((string)($corpo['acao'] ?? ''), Api::ip(), ['confirmar_producao' => (bool)($corpo['confirmar_producao'] ?? false)]);
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['producao']) ? ['producao' => $r['producao']] : []);
}
unset($r['ok']);
$api->responder(200, $r);
