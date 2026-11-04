<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/monitor.php?token=<monitor_token>[&desde=<id>|agora]
 *
 * Stream em tempo real (Server-Sent Events) para o painel do monitor fiscal.
 * Cada mudanca de documento (recebido, enviado, autorizado, rejeitado,
 * consultado, cancelado...) chega como um evento "nfse".
 *
 * Navegador:
 *   const fonte = new EventSource('/v1/nfse/monitor.php?token=XYZ');
 *   fonte.addEventListener('nfse', (e) => atualizarLinha(JSON.parse(e.data)));
 *
 * Reconexao: o navegador reconecta sozinho enviando o cabecalho
 * Last-Event-ID, e continuamos exatamente de onde parou (nada se perde).
 * Sem Last-Event-ID, enviamos as ultimas 50 linhas e seguimos ao vivo.
 *
 * A conexao e encerrada pelo servidor apos ~5 minutos (o navegador reconecta)
 * para nao prender processos PHP-FPM indefinidamente.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

const DURACAO_MAXIMA_SEGUNDOS = 300;
const PING_SEGUNDOS = 15;

$api = Api::iniciar(['GET']);
$acesso = $api->autenticar(true);
$consumidor = $acesso['painel'] ? null : $acesso['consumidor'];

$pdo = $api->pdo();
$monitor = new MonitorRepositorio($pdo);

// De onde comecar:
//   Last-Event-ID (reconexao automatica do navegador) > ?desde=<id> >
//   ?desde=agora (so eventos novos) > ultimas 50 linhas.
$desde = (string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['desde'] ?? ''));
if ($desde === 'agora') {
    $ultimoId = $monitor->idParaUltimos(0);
} elseif (ctype_digit($desde)) {
    $ultimoId = (int)$desde;
} else {
    $ultimoId = $monitor->idParaUltimos(50);
}

// Cabecalhos SSE. X-Accel-Buffering desliga o buffer do nginx.
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
set_time_limit(DURACAO_MAXIMA_SEGUNDOS + 30);
while (ob_get_level() > 0) {
    ob_end_flush();
}

echo "retry: 3000\n\n";
flush();

$pdo->exec('LISTEN ' . MonitorRepositorio::CANAL);
$inicio = time();

while (!connection_aborted() && time() - $inicio < DURACAO_MAXIMA_SEGUNDOS) {
    $linhas = $monitor->listarDepoisDe($ultimoId, $consumidor);

    foreach ($linhas as $linha) {
        $ultimoId = (int)$linha['id'];
        echo "id: {$ultimoId}\n";
        echo "event: nfse\n";
        echo 'data: ' . json_encode(DocumentoApresentador::monitor($linha), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    if ($linhas === []) {
        // Espera um aviso do banco (pg_notify) ou o tempo do ping.
        $aviso = $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, PING_SEGUNDOS * 1000);
        if ($aviso === false) {
            echo ": ping\n\n"; // comentario SSE: mantem a conexao viva
        }
    }

    flush();
}
