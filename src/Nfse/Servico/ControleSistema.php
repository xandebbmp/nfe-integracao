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
 *   executar('reiniciar') workers saem e o systemd/pm2 liga de novo
 *                       (usar depois de atualizar o codigo ou a config)
 *
 * O painel nao liga nem desliga processos do servidor (isso exigiria dar
 * poder de administrador do servidor ao site). Ele grava uma ORDEM no
 * banco e o worker obedece. Quem liga o worker quando o servidor inicia,
 * e de novo apos "reiniciar", e o systemd (deploy/systemd) ou o pm2.
 */
final class ControleSistema
{
    /** Sem sinal ha mais que isso = worker parado. O worker da sinal a cada 10 s. */
    public const SEGUNDOS_VIVO = 30;

    /** Depois de "reiniciar", espera isso antes de dizer que nao voltou. */
    public const SEGUNDOS_REINICIO = 60;

    private SistemaRepositorio $sistema;

    public function __construct(private PDO $pdo)
    {
        $this->sistema = new SistemaRepositorio($pdo);
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
                ? 'Nenhum worker ligou ainda. Ele é instalado uma vez no servidor (manual de implantação, passo do worker). Enquanto isso, as notas ficam esperando na fila.'
                : 'O worker deixou de dar sinal. As notas ficam esperando na fila. Se não voltar em 1 minuto, fale com quem cuida do servidor.'],
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

    public function executar(string $acao, ?string $ip): array
    {
        switch ($acao) {
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
                return ['ok' => false, 'http' => 400, 'codigo' => 'ACAO_INVALIDA', 'mensagem' => 'Use acao: pausar, retomar ou reiniciar.'];
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
