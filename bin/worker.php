<?php
declare(strict_types=1);

/**
 * Worker da fila de emissao (somente CLI). Rodar como servico (deploy/nfe-worker.service).
 *
 *   php bin/worker.php              # roda ate receber SIGTERM/SIGINT
 *   php bin/worker.php --once       # processa ate esvaziar a fila e sai
 *   php bin/worker.php --max-jobs=100 --sleep=2
 *
 * Varios workers podem rodar ao mesmo tempo (FOR UPDATE SKIP LOCKED).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Support/HttpSecurity.php';

use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Fila\FilaStore;
use Xande\NfeIntegracao\Fila\ProcessadorNfe;
use Xande\NfeIntegracao\Nfe\DocumentoStore;

$opts = getopt('', ['once', 'max-jobs:', 'sleep:']) ?: [];
$once = isset($opts['once']);
$maxJobs = (int)($opts['max-jobs'] ?? 0);
$sleep = max(1, (int)($opts['sleep'] ?? 2));

// Carrega config base (timezone, segredos); a empresa de cada job vai por NFE_EMPRESA_CNPJ.
nfe_load_config();

$pdo = ConnectionFactory::make();
$fila = new FilaStore($pdo);
$processador = new ProcessadorNfe($fila, new DocumentoStore($pdo), dirname(__DIR__));
$worker = gethostname() . ':' . getmypid();

$parar = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    // Termina o job atual antes de sair (systemctl stop/restart).
    pcntl_signal(SIGTERM, static function () use (&$parar): void { $parar = true; });
    pcntl_signal(SIGINT, static function () use (&$parar): void { $parar = true; });
}

$log = static function (string $msg): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$msg}" . PHP_EOL);
};

$log("Worker {$worker} iniciado.");
$processados = 0;
$ultimaRecuperacao = 0;

while (!$parar) {
    if (time() - $ultimaRecuperacao >= 60) {
        $recuperados = $fila->recuperarTravados();
        if ($recuperados > 0) {
            $log("{$recuperados} job(s) travado(s) devolvido(s) para a fila.");
        }
        $ultimaRecuperacao = time();
    }

    $job = $fila->pegarProximo($worker);
    if ($job === null) {
        if ($once) {
            break;
        }
        sleep($sleep);
        continue;
    }

    $inicio = microtime(true);
    try {
        if ($job['tipo_documento'] === 'NFE') {
            $resultado = $processador->processar($job);
        } else {
            $fila->falhar((int)$job['id'], "Tipo de documento ainda não suportado: {$job['tipo_documento']}", '');
            $resultado = 'FALHOU';
        }
    } catch (Throwable $e) {
        // Falha do próprio worker (ex.: banco): devolve o job e encerra para o systemd reiniciar.
        $fila->reagendar($job, 'Erro no worker: ' . $e->getMessage(), '');
        $log("Job {$job['id']} erro no worker: " . $e->getMessage());
        exit(1);
    }

    $processados++;
    $log(sprintf(
        'Job %d %s %s/%s tentativa %d -> %s (%.1fs)',
        $job['id'],
        $job['tipo_documento'],
        $job['cnpj'],
        $job['id_integracao'],
        $job['tentativas'],
        $resultado,
        microtime(true) - $inicio
    ));

    if ($maxJobs > 0 && $processados >= $maxJobs) {
        break;
    }
}

$log("Worker {$worker} encerrado ({$processados} job(s)).");
