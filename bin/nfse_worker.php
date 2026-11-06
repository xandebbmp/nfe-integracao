<?php
declare(strict_types=1);

/**
 * Worker da NFS-e: consome a fila (nfse_jobs) e entrega webhooks.
 *
 * Uso:
 *   php bin/nfse_worker.php              # roda para sempre (producao, via systemd/supervisor)
 *   php bin/nfse_worker.php --uma-vez    # processa o que houver e sai (testes/cron)
 *   php bin/nfse_worker.php --pelo-painel  # ligado pelo botao "Ligar worker" do painel:
 *                                          # no "Reiniciar" ele mesmo liga um novo
 *
 * Pode rodar mais de um worker ao mesmo tempo: a fila usa
 * "FOR UPDATE SKIP LOCKED", entao dois workers nunca pegam o mesmo job.
 *
 * Para parar com seguranca envie SIGTERM (systemctl stop): o worker termina
 * o job atual antes de sair.
 *
 * Painel admin, tela "Sistema" (database/scripts/0007):
 *   - a cada 10 s o worker grava um sinal de vida em sistema_workers;
 *   - "Pausar envios": o worker para de pegar trabalhos (a fila espera);
 *   - "Reiniciar": o worker termina o trabalho atual e sai. O systemd
 *     (Restart=always) ou o pm2 liga de novo, ja com o codigo novo. O que foi
 *     ligado pelo painel (--pelo-painel) liga o novo ele mesmo;
 *   - "Ligar worker" / "Desligar worker" (sem terminal).
 *
 * Notas recorrentes do portal (script 0011): a cada 5 minutos o worker emite
 * as AUTOMATICAS que chegaram no dia. Pausado pelo painel, nao emite.
 *
 * E-mails (script 0012, "Esqueci a senha" do portal): a cada volta o worker
 * envia os e-mails da fila. Continua enviando mesmo com "Pausar envios"
 * (a pausa e para as notas, nao para o login dos clientes).
 */

use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\EntregadorWebhook;
use Xande\NfeIntegracao\Nfse\Servico\EnvioEmail;
use Xande\NfeIntegracao\Nfse\Servico\FilaEmails;
use Xande\NfeIntegracao\Nfse\Servico\IniciadorWorker;
use Xande\NfeIntegracao\Nfse\Servico\PortalRecorrencias;
use Xande\NfeIntegracao\Nfse\Servico\ProcessadorJobs;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$umaVez = in_array('--uma-vez', $argv, true);
$peloPainel = in_array('--pelo-painel', $argv, true);
$servidor = (string)gethostname();
$workerId = $servidor . ':' . getmypid();
$iniciadoEm = time();

$pdo = ConnectionFactory::make();
$cfgArquivos = NfseConfig::carregar();
$fila = new FilaRepositorio($pdo);

// Controle pelo painel. Se o script 0007 ainda nao foi aplicado, o worker
// funciona como antes (sem pausar/reiniciar pelo painel).
$sistema = new SistemaRepositorio($pdo);
if (!$sistema->existe()) {
    $sistema = null;
}
$jobsFeitos = 0;
$estadoAnterior = '';
$ultimoBatimento = 0;
$ordens = ['pausado' => false, 'reiniciar' => 0, 'desligar' => 0];

// Le as ordens do painel e da sinal de vida (no maximo a cada 10 s, ou na
// hora quando o estado muda).
$sinalDeVida = static function (string $estado) use (&$sistema, $servidor, $workerId, &$jobsFeitos, &$estadoAnterior, &$ultimoBatimento): void {
    if ($sistema === null || ($estado === $estadoAnterior && time() - $ultimoBatimento < 10)) {
        return;
    }
    $sistema->batimento($workerId, $servidor, (int)getmypid(), $estado, $jobsFeitos);
    $estadoAnterior = $estado;
    $ultimoBatimento = time();
};
$lerOrdens = static function () use (&$sistema, &$ordens): void {
    if ($sistema !== null) {
        $ordens = $sistema->ordensDoWorker();
    }
};

// Monta (ou remonta) o que depende do cadastro de empresas clientes.
// O painel admin pode incluir empresas, trocar certificado ou webhook a
// qualquer hora: o worker rele a cada minuto, sem precisar reiniciar.
$cfg = [];
$processador = null;
$entregador = null;
$assinaturaCadastro = '';
$recarregar = static function () use ($pdo, $cfgArquivos, $workerId, &$cfg, &$processador, &$entregador, &$assinaturaCadastro): void {
    $novo = NfseConfig::comClientes($cfgArquivos, $pdo);
    // Empresas e configuracoes mudadas no painel (fila, salvador...).
    $assinatura = md5(serialize([$novo['emitentes'], $novo['consumidores'], $novo['fila'], $novo['salvador']]));
    if ($assinatura === $assinaturaCadastro) {
        return;
    }
    if ($assinaturaCadastro !== '') {
        registrar('Empresas ou configuracoes mudaram no painel: configuracao recarregada.');
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
if ($sistema !== null) {
    $pdo->exec('LISTEN ' . SistemaRepositorio::CANAL);
}

registrar("Worker {$workerId} iniciado (tpAmb={$cfg['tpAmb']}).");
$ultimaLimpeza = 0;
$ultimasRecorrencias = 0;

while ($continuar) {
    // A cada minuto: devolve para a fila jobs de workers que morreram.
    if (time() - $ultimaLimpeza >= 60) {
        $liberados = $fila->liberarTravados((int)$cfg['fila']['job_travado_minutos']);
        if ($liberados > 0) {
            registrar("{$liberados} job(s) travado(s) devolvido(s) para a fila.");
        }
        $recarregar();
        $sistema?->apagarWorkersMortos(7 * 86400);
        $ultimaLimpeza = time();
    }

    // Ordens do painel.
    $lerOrdens();
    if ($ordens['desligar'] >= $iniciadoEm) {
        registrar('Desligado pelo painel.');
        break;
    }
    if ($ordens['reiniciar'] > $iniciadoEm) {
        if ($peloPainel) {
            $falha = IniciadorWorker::iniciar();
            registrar($falha === null ? 'Reinicio pedido pelo painel: novo worker ligado, saindo.' : "Reinicio: nao liguei o novo worker ({$falha}).");
        } else {
            registrar('Reinicio pedido pelo painel: saindo (o systemd/pm2 liga de novo).');
        }
        break;
    }
    // E-mails da fila (link de "Esqueci a senha"). A config vem de $cfg, que
    // e relida a cada minuto: mudou no painel, vale sem reiniciar.
    try {
        $r = (new FilaEmails($pdo, new EnvioEmail($cfg)))->processar();
        if ($r['enviados'] + $r['falhas'] > 0) {
            registrar("E-mails: {$r['enviados']} enviado(s), {$r['falhas']} com falha (tenta de novo).");
        }
    } catch (Throwable $erro) {
        registrar('E-mails: ' . $erro->getMessage());
    }

    if ($ordens['pausado']) {
        $sinalDeVida('PAUSADO');
        if ($umaVez) {
            break;
        }
        $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 5000);
        continue;
    }

    // A cada 5 minutos: notas recorrentes automaticas do dia (entram na fila).
    if (time() - $ultimasRecorrencias >= 300) {
        try {
            $r = (new PortalRecorrencias($pdo, $cfg))->executarAutomaticas();
            if ($r['emitidas'] + $r['falhas'] > 0) {
                registrar("Recorrencias: {$r['emitidas']} nota(s) na fila, {$r['falhas']} com problema (aparecem para o cliente).");
            }
        } catch (Throwable $erro) {
            registrar('Recorrencias: ' . $erro->getMessage());
        }
        $ultimasRecorrencias = time();
    }

    $processouAlgo = false;
    while ($continuar && $processador->processarProximo()) {
        $processouAlgo = true;
        $jobsFeitos++;
        $sinalDeVida('TRABALHANDO');
        // Pausa pedida no meio de muitas notas: para depois da atual.
        $lerOrdens();
        if ($ordens['pausado'] || $ordens['reiniciar'] > $iniciadoEm || $ordens['desligar'] >= $iniciadoEm) {
            continue 2;
        }
    }

    $entregador->entregarPendentes();

    if ($umaVez) {
        break;
    }

    if (!$processouAlgo) {
        $sinalDeVida('AGUARDANDO');
        // Dorme ate chegar um aviso de job novo ou 5 segundos (jobs agendados
        // para o futuro, como as consultas de lote de Salvador).
        $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 5000);
    }
}

$sistema?->removerWorker($workerId);
registrar("Worker {$workerId} finalizado.");

function registrar(string $mensagem): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$mensagem}\n");
}
