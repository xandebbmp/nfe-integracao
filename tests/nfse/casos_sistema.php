<?php
declare(strict_types=1);

/**
 * Testes da tela "Sistema" do painel (script 0007): sinal de vida do
 * worker, pausar, retomar, reiniciar e resumo da fila.
 * Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\ControleSistema;

function testesSistemaIntegracao(PDO $pdo): void
{
    echo "\n[12] Painel: tela Sistema (worker e fila)\n";

    $pdo->exec('DELETE FROM sistema_workers');
    $pdo->exec("UPDATE sistema_controle SET valor = '0' WHERE chave = 'worker_pausado'");
    $pdo->exec("DELETE FROM sistema_controle WHERE chave = 'worker_reiniciar'");

    $repo = new SistemaRepositorio($pdo);
    $controle = new ControleSistema($pdo);
    Apoio::verificar($controle->instalado(), 'tabelas do script 0007 existem');

    $s = $controle->status();
    Apoio::igual('PARADO', $s['situacao'], 'sem sinal de worker: PARADO');
    Apoio::verificar(str_contains($s['texto'], 'Nenhum worker ligou'), 'explica que o worker nunca ligou');

    // Worker da sinal de vida.
    $repo->batimento('srv:100', 'srv', 100, 'AGUARDANDO', 0);
    $s = $controle->status();
    Apoio::igual('LIGADO', $s['situacao'], 'com sinal recente: LIGADO');
    Apoio::igual(1, count($s['workers']), '1 worker listado');
    Apoio::igual('AGUARDANDO', $s['workers'][0]['estado'], 'estado do worker aparece');

    $repo->batimento('srv:100', 'srv', 100, 'TRABALHANDO', 3);
    Apoio::igual(3, $controle->status()['workers'][0]['jobs_feitos'], 'batimento atualiza a mesma linha');

    // Pausar e retomar.
    $r = $controle->executar('pausar', '10.0.0.1');
    Apoio::verificar($r['ok'] && $r['status']['situacao'] === 'PAUSADO', 'pausar: situacao PAUSADO');
    Apoio::verificar($repo->ordensDoWorker()['pausado'], 'worker le a ordem de pausa');
    $r = $controle->executar('retomar', '10.0.0.1');
    Apoio::verificar($r['ok'] && $r['status']['situacao'] === 'LIGADO', 'retomar: volta a LIGADO');
    Apoio::verificar(!$repo->ordensDoWorker()['pausado'], 'worker le a ordem de retomar');

    // Reiniciar: o worker antigo (ligado antes do pedido) deve sair.
    $pdo->exec("UPDATE sistema_workers SET iniciado_em = CURRENT_TIMESTAMP - INTERVAL '10 minutes'");
    $r = $controle->executar('reiniciar', '10.0.0.1');
    Apoio::igual('REINICIANDO', $r['status']['situacao'], 'reiniciar: aguardando o worker voltar');
    $pedido = $repo->ordensDoWorker()['reiniciar'];
    Apoio::verificar($pedido > time() - 600, 'worker ligado antes do pedido percebe que deve sair');
    $repo->removerWorker('srv:100');
    $repo->batimento('srv:200', 'srv', 200, 'AGUARDANDO', 0);
    Apoio::igual('LIGADO', $controle->status()['situacao'], 'worker novo ligado depois do pedido: LIGADO');

    // Historico das ordens.
    $acoes = array_column($controle->status()['acoes_recentes'], 'acao');
    Apoio::igual(['WORKER_REINICIADO', 'WORKER_RETOMADO', 'WORKER_PAUSADO'], array_slice($acoes, 0, 3), 'ordens ficam no historico (mais nova primeiro)');

    $r = $controle->executar('desligar_tudo', null);
    Apoio::verificar(!$r['ok'] && $r['http'] === 400, 'acao desconhecida recusada');

    // Worker que morreu sem se despedir (fora da janela de reinicio).
    $pdo->exec("DELETE FROM sistema_controle WHERE chave = 'worker_reiniciar'");
    $pdo->exec("UPDATE sistema_workers SET batimento_em = CURRENT_TIMESTAMP - INTERVAL '2 minutes'");
    $s = $controle->status();
    Apoio::igual('PARADO', $s['situacao'], 'sem sinal ha 2 minutos: PARADO');
    Apoio::verificar(str_contains($s['texto'], 'deixou de dar sinal'), 'explica que o worker parou de dar sinal');
    Apoio::igual(1, $repo->apagarWorkersMortos(60), 'limpeza apaga worker morto');

    // Resumo da fila (le nfse_jobs).
    $f = $controle->status()['fila'];
    Apoio::verificar(isset($f['esperando'], $f['executando'], $f['agendados'], $f['falhas_24h']), 'resumo da fila com os 4 numeros');

    $pdo->exec('DELETE FROM sistema_workers');
}
