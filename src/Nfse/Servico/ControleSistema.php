<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;

/**
 * Tela "Sistema" do painel admin: ver e comandar o worker sem terminal.
 *
 *   status()            o worker esta ligado? quantas notas na fila?
 *   executar('pausar')  workers param de enviar (a fila so espera)
 *   executar('retomar') voltam a enviar
 *   executar('reiniciar') workers saem e voltam (o ligado pelo painel se
 *                       religa sozinho; no servidor, o systemd/pm2 religa)
 *   executar('ligar')   liga o worker pelo painel (IniciadorWorker), com o
 *                       mesmo usuario do site. Com notas de PRODUCAO na fila,
 *                       so liga com confirmar_producao = true.
 *   executar('desligar') workers terminam a nota atual e saem
 *
 * Pausar, reiniciar e desligar gravam uma ORDEM no banco e o worker obedece.
 * No servidor de verdade o ideal e o systemd (deploy/systemd) ligar o worker
 * quando a maquina liga; o botao "Ligar" serve para os dois casos.
 */
final class ControleSistema
{
    /** Sem sinal ha mais que isso = worker parado. O worker da sinal a cada 10 s. */
    public const SEGUNDOS_VIVO = 30;

    /** Depois de "reiniciar", espera isso antes de dizer que nao voltou. */
    public const SEGUNDOS_REINICIO = 60;

    private SistemaRepositorio $sistema;

    /** @var callable(): ?string  liga o processo; devolve null ou o motivo da falha */
    private $iniciador;

    public function __construct(private PDO $pdo, ?callable $iniciador = null)
    {
        $this->sistema = new SistemaRepositorio($pdo);
        $this->iniciador = $iniciador ?? [IniciadorWorker::class, 'iniciar'];
    }

    public function instalado(): bool
    {
        return $this->sistema->existe();
    }

    public function status(): array
    {
        $ordens = $this->sistema->ordensDoWorker();
        $workers = $this->sistema->workersVivos(self::SEGUNDOS_VIVO);
        $fila = $this->sistema->resumoFila();
        $reiniciando = $ordens['reiniciar'] > 0 && time() - $ordens['reiniciar'] < self::SEGUNDOS_REINICIO
            && !self::algumIniciadoDepois($workers, $ordens['reiniciar']);

        [$situacao, $titulo, $texto] = match (true) {
            $reiniciando => ['REINICIANDO', 'Reiniciando o worker', 'Ele deve voltar em alguns segundos.'],
            $workers === [] => ['PARADO', 'Worker parado', $this->sistema->ultimoBatimento() === null
                ? 'Nenhum worker ligou ainda. Clique em "Ligar worker". Enquanto ele estiver desligado, as notas ficam esperando na fila.'
                : 'O worker está desligado. As notas ficam esperando na fila até você clicar em "Ligar worker".'],
            $ordens['pausado'] => ['PAUSADO', 'Envios pausados', 'As notas novas entram na fila, mas nada é enviado à prefeitura até você clicar em "Retomar envios".'],
            default => ['LIGADO', 'Worker ligado', count($workers) === 1 ? '1 worker enviando as notas.' : count($workers) . ' workers enviando as notas.'],
        };

        return [
            'situacao' => $situacao,
            'titulo' => $titulo,
            'texto' => $texto,
            'pausado' => $ordens['pausado'],
            'workers' => $workers,
            'fila' => $fila,
            'acoes_recentes' => $this->sistema->acoesRecentes(8),
            'agora' => date('c'),
        ];
    }

    public function executar(string $acao, ?string $ip, array $opcoes = []): array
    {
        switch ($acao) {
            case 'ligar':
                if ($this->sistema->workersVivos(self::SEGUNDOS_VIVO) !== []) {
                    return ['ok' => false, 'http' => 409, 'codigo' => 'WORKER_JA_LIGADO', 'mensagem' => 'O worker já está ligado.'];
                }
                $producao = $this->sistema->resumoFila()['producao'];
                if ($producao > 0 && empty($opcoes['confirmar_producao'])) {
                    return ['ok' => false, 'http' => 409, 'codigo' => 'CONFIRMAR_PRODUCAO', 'producao' => $producao,
                        'mensagem' => "Há {$producao} nota(s) de PRODUÇÃO na fila. Ao ligar, elas vão para a prefeitura e valem de verdade."];
                }
                // Ordem de desligar antiga nao pode derrubar o worker novo.
                $this->sistema->definirControle('worker_desligar', '0');
                $falha = ($this->iniciador)();
                if ($falha !== null) {
                    return ['ok' => false, 'http' => 500, 'codigo' => 'WORKER_NAO_LIGOU', 'mensagem' => $falha];
                }
                $log = 'WORKER_LIGADO';
                $mensagem = 'Ligando o worker. Ele aparece aqui em alguns segundos.';
                break;
            case 'desligar':
                $this->sistema->definirControle('worker_desligar', (string)time());
                $log = 'WORKER_DESLIGADO';
                $mensagem = 'Pedido enviado. O worker termina a nota atual e desliga. As notas novas ficam na fila.';
                break;
            case 'pausar':
                $this->sistema->definirControle('worker_pausado', '1');
                $log = 'WORKER_PAUSADO';
                $mensagem = 'Envios pausados. As notas ficam na fila.';
                break;
            case 'retomar':
                $this->sistema->definirControle('worker_pausado', '0');
                $log = 'WORKER_RETOMADO';
                $mensagem = 'Envios retomados.';
                break;
            case 'reiniciar':
                $this->sistema->definirControle('worker_reiniciar', (string)time());
                $log = 'WORKER_REINICIADO';
                $mensagem = 'Pedido enviado. O worker termina a nota atual e volta sozinho.';
                break;
            default:
                return ['ok' => false, 'http' => 400, 'codigo' => 'ACAO_INVALIDA', 'mensagem' => 'Use acao: ligar, desligar, pausar, retomar ou reiniciar.'];
        }
        (new ClienteRepositorio($this->pdo))->registrarLog(null, $log, [], $ip);
        return ['ok' => true, 'mensagem' => $mensagem, 'status' => $this->status()];
    }

    private static function algumIniciadoDepois(array $workers, int $momento): bool
    {
        foreach ($workers as $w) {
            if (strtotime((string)$w['iniciado_em']) >= $momento) {
                return true;
            }
        }
        return false;
    }
}
