<?php
declare(strict_types=1);

/**
 * Worker da NFS-e: consome a fila (nfse_jobs) e entrega webhooks.
 *
 * Uso:
 *   php bin/nfse_worker.php              # roda para sempre (producao, via systemd/supervisor)
 *   php bin/nfse_worker.php --uma-vez    # processa o que houver e sai (testes/cron)
 *
 * Pode rodar mais de um worker ao mesmo tempo: a fila usa
 * "FOR UPDATE SKIP LOCKED", entao dois workers nunca pegam o mesmo job.
 *
 * Para parar com seguranca envie SIGTERM (systemctl stop): o worker termina
 * o job atual antes de sair.
 */

use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\EntregadorWebhook;
use Xande\NfeIntegracao\Nfse\Servico\ProcessadorJobs;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$umaVez = in_array('--uma-vez', $argv, true);
$workerId = gethostname() . ':' . getmypid();

$pdo = ConnectionFactory::make();
$cfgArquivos = NfseConfig::carregar();
$fila = new FilaRepositorio($pdo);

// Monta (ou remonta) o que depende do cadastro de empresas clientes.
// O painel admin pode incluir empresas, trocar certificado ou webhook a
// qualquer hora: o worker rele a cada minuto, sem precisar reiniciar.
$cfg = [];
$processador = null;
$entregador = null;
$assinaturaCadastro = '';
$recarregar = static function () use ($pdo, $cfgArquivos, $workerId, &$cfg, &$processador, &$entregador, &$assinaturaCadastro): void {
    $novo = NfseConfig::comClientes($cfgArquivos, $pdo);
    $assinatura = md5(serialize([$novo['emitentes'], $novo['consumidores']]));
    if ($assinatura === $assinaturaCadastro) {
        return;
    }
    if ($assinaturaCadastro !== '') {
        registrar('Cadastro de empresas mudou: configuracao recarregada.');
    }
    $cfg = $novo;
    $processador = new ProcessadorJobs($pdo, $cfg, new ProvedorFactory($cfg), $workerId);
    $entregador = new EntregadorWebhook($pdo, $cfg);
    $assinaturaCadastro = $assinatura;
};
$recarregar();

// Parada graciosa.
$continuar = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $parar = static function () use (&$continuar): void {
        $continuar = false;
    };
    pcntl_signal(SIGTERM, $parar);
    pcntl_signal(SIGINT, $parar);
}

// Escuta o canal avisado a cada job novo: o worker acorda na hora,
// sem ficar consultando o banco em loop.
$pdo->exec('LISTEN nfse_jobs');

registrar("Worker {$workerId} iniciado (tpAmb={$cfg['tpAmb']}).");
$ultimaLimpeza = 0;

while ($continuar) {
    // A cada minuto: devolve para a fila jobs de workers que morreram.
    if (time() - $ultimaLimpeza >= 60) {
        $liberados = $fila->liberarTravados((int)$cfg['fila']['job_travado_minutos']);
        if ($liberados > 0) {
            registrar("{$liberados} job(s) travado(s) devolvido(s) para a fila.");
        }
        $recarregar();
        $ultimaLimpeza = time();
    }

    $processouAlgo = false;
    while ($continuar && $processador->processarProximo()) {
        $processouAlgo = true;
    }

    $entregador->entregarPendentes();

    if ($umaVez) {
        break;
    }

    if (!$processouAlgo) {
        // Dorme ate chegar um aviso de job novo ou 5 segundos (jobs agendados
        // para o futuro, como as consultas de lote de Salvador).
        $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 5000);
    }
}

registrar("Worker {$workerId} finalizado.");

function registrar(string $mensagem): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$mensagem}\n");
}
