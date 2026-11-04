<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Dominio\Capacidades;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;

/**
 * Pedidos que o consumidor faz sobre um documento ja existente:
 * consultar no fisco e registrar evento (cancelamento...).
 *
 * Ambos so ENFILEIRAM o trabalho. Quem fala com o fisco e o worker.
 */
final class Solicitacoes
{
    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    /**
     * @return array{ok: bool, mensagem: string}
     */
    public function consultar(array $documento): array
    {
        if ($documento['status'] === StatusNfse::RECEBIDO) {
            return ['ok' => false, 'mensagem' => 'Documento ainda esta na fila para envio. Aguarde o processamento.'];
        }

        $fila = new FilaRepositorio($this->pdo);
        if (!$fila->existePendente(FilaRepositorio::CONSULTAR, $documento['id'])) {
            $fila->enfileirar(FilaRepositorio::CONSULTAR, $documento['id'], null, 0, (int)$this->cfg['fila']['max_tentativas']);
        }

        return ['ok' => true, 'mensagem' => 'Consulta enfileirada.'];
    }

    /**
     * @return array{ok: bool, http: int, mensagem: string, evento?: array}
     */
    public function registrarEvento(array $documento, string $tipo, string $motivoCodigo, string $motivoTexto): array
    {
        $capacidades = Capacidades::doPadrao($documento['padrao']);

        if (!isset($capacidades['eventos'][$tipo])) {
            return [
                'ok' => false,
                'http' => 422,
                'codigo' => 'NAO_SUPORTADO_PELO_PADRAO',
                'mensagem' => $capacidades['eventos_nao_suportados'][$tipo]
                    ?? "Evento {$tipo} nao suportado no padrao {$documento['padrao']}.",
            ];
        }

        if ($documento['status'] !== StatusNfse::AUTORIZADO) {
            return [
                'ok' => false,
                'http' => 409,
                'codigo' => 'STATUS_NAO_PERMITE',
                'mensagem' => "Evento so pode ser registrado em NFS-e AUTORIZADA (status atual: {$documento['status']}).",
            ];
        }

        if (!in_array($motivoCodigo, ['1', '2', '9'], true)) {
            return ['ok' => false, 'http' => 400, 'codigo' => 'MOTIVO_INVALIDO', 'mensagem' => 'motivo_codigo deve ser 1 (erro na emissao), 2 (servico nao prestado) ou 9 (outros).'];
        }

        $tamanho = mb_strlen(trim($motivoTexto));
        if ($tamanho < 15 || $tamanho > 255) {
            return ['ok' => false, 'http' => 400, 'codigo' => 'MOTIVO_INVALIDO', 'mensagem' => 'motivo deve ter entre 15 e 255 caracteres.'];
        }

        $eventos = new EventoRepositorio($this->pdo);

        $this->pdo->beginTransaction();
        try {
            // Evita dois cancelamentos em paralelo para a mesma nota.
            $this->pdo->prepare('SELECT 1 FROM nfse_documentos WHERE id = :id FOR UPDATE')->execute([':id' => $documento['id']]);

            $ativo = $eventos->buscarAtivo($documento['id'], $tipo);
            if ($ativo !== null) {
                $this->pdo->commit();
                return ['ok' => true, 'http' => 200, 'mensagem' => 'Evento ja solicitado anteriormente.', 'evento' => $ativo];
            }

            $eventoId = $eventos->criar($documento['id'], $tipo, $motivoCodigo, trim($motivoTexto));
            (new FilaRepositorio($this->pdo))->enfileirar(
                FilaRepositorio::EVENTO,
                $documento['id'],
                $eventoId,
                0,
                (int)$this->cfg['fila']['max_tentativas']
            );
            (new MonitorRepositorio($this->pdo))->registrar(
                $documento,
                'EVENTO',
                $documento['status'],
                "Evento {$tipo} solicitado"
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'ok' => true,
            'http' => 202,
            'mensagem' => 'Evento enfileirado.',
            'evento' => $eventos->buscar($eventoId) ?? ['id' => $eventoId, 'status' => StatusEvento::RECEBIDO],
        ];
    }
}
