<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * Fila de trabalho em PostgreSQL (tabela nfse_jobs).
 *
 * Por que uma tabela e nao RabbitMQ/Redis?
 *   - O PostgreSQL ja e obrigatorio no projeto: zero infraestrutura nova.
 *   - O job e gravado na MESMA transacao do documento: nunca existe documento
 *     sem job, nem job sem documento.
 *   - "FOR UPDATE SKIP LOCKED" permite varios workers em paralelo sem que
 *     dois peguem o mesmo job.
 *   - O mesmo desenho funciona igual em Node/TypeScript.
 *
 * Tipos de job:
 *   EMITIR                   envia o documento ao fisco
 *   CONSULTAR_PROCESSAMENTO  acompanha envio assincrono (lote Salvador)
 *   CONSULTAR                consulta pedida pelo consumidor
 *   EVENTO                   registra evento (cancelamento...)
 *   ARQUIVAR                 copia os XMLs para o armazenamento externo
 */
final class FilaRepositorio
{
    public const EMITIR = 'EMITIR';
    public const CONSULTAR_PROCESSAMENTO = 'CONSULTAR_PROCESSAMENTO';
    public const CONSULTAR = 'CONSULTAR';
    public const EVENTO = 'EVENTO';
    public const ARQUIVAR = 'ARQUIVAR';

    public function __construct(private PDO $pdo)
    {
    }

    public function enfileirar(
        string $tipo,
        string $documentoId,
        ?string $eventoId = null,
        int $atrasoSegundos = 0,
        int $maxTentativas = 5
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO nfse_jobs (tipo, documento_id, evento_id, max_tentativas, executar_apos)
             VALUES (:tipo, :documento_id, :evento_id, :max, CURRENT_TIMESTAMP + make_interval(secs => :atraso))
             RETURNING id"
        );
        $stmt->execute([
            ':tipo' => $tipo,
            ':documento_id' => $documentoId,
            ':evento_id' => $eventoId,
            ':max' => $maxTentativas,
            ':atraso' => $atrasoSegundos,
        ]);
        $id = (int)$stmt->fetchColumn();

        // Acorda workers que estejam esperando (LISTEN nfse_jobs).
        $this->pdo->query("SELECT pg_notify('nfse_jobs', '{$id}')");

        return $id;
    }

    /**
     * Ja existe job desse tipo aguardando para o documento? (evita duplicar
     * consultas quando o consumidor clica varias vezes).
     */
    public function existePendente(string $tipo, string $documentoId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM nfse_jobs
              WHERE tipo = :tipo AND documento_id = :doc AND status IN ('PENDENTE', 'EXECUTANDO')
              LIMIT 1"
        );
        $stmt->execute([':tipo' => $tipo, ':doc' => $documentoId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Pega o proximo job disponivel e marca como EXECUTANDO, de forma atomica.
     */
    public function pegarProximo(string $workerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE nfse_jobs
                SET status = 'EXECUTANDO',
                    tentativas = tentativas + 1,
                    travado_em = CURRENT_TIMESTAMP,
                    travado_por = :worker,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = (
                    SELECT id FROM nfse_jobs
                     WHERE status = 'PENDENTE' AND executar_apos <= CURRENT_TIMESTAMP
                     ORDER BY executar_apos, id
                     FOR UPDATE SKIP LOCKED
                     LIMIT 1
              )
          RETURNING *"
        );
        $stmt->execute([':worker' => $workerId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return $job === false ? null : $job;
    }

    public function concluir(int $id): void
    {
        $this->mudarStatus($id, 'CONCLUIDO', null);
    }

    public function falhar(int $id, string $erro): void
    {
        $this->mudarStatus($id, 'FALHOU', $erro);
    }

    /** Devolve o job para a fila depois de alguns segundos. */
    public function reagendar(int $id, int $segundos, string $erro): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE nfse_jobs
                SET status = 'PENDENTE', travado_em = NULL, travado_por = NULL,
                    ultimo_erro = :erro,
                    executar_apos = CURRENT_TIMESTAMP + make_interval(secs => :segundos),
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id"
        );
        $stmt->execute([':id' => $id, ':segundos' => $segundos, ':erro' => $erro]);
    }

    /**
     * Jobs presos em EXECUTANDO (worker morreu no meio) voltam para a fila.
     * E seguro: os provedores verificam no fisco antes de reenviar.
     */
    public function liberarTravados(int $minutos): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE nfse_jobs
                SET status = 'PENDENTE', travado_em = NULL, travado_por = NULL,
                    ultimo_erro = 'Liberado apos travamento do worker',
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE status = 'EXECUTANDO'
                AND travado_em < CURRENT_TIMESTAMP - make_interval(mins => :minutos)"
        );
        $stmt->execute([':minutos' => $minutos]);
        return $stmt->rowCount();
    }

    public function contar(string $tipo, string $documentoId): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM nfse_jobs WHERE tipo = :tipo AND documento_id = :doc');
        $stmt->execute([':tipo' => $tipo, ':doc' => $documentoId]);
        return (int)$stmt->fetchColumn();
    }

    private function mudarStatus(int $id, string $status, ?string $erro): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE nfse_jobs
                SET status = :status, ultimo_erro = COALESCE(:erro, ultimo_erro),
                    travado_em = NULL, travado_por = NULL, atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':status' => $status, ':erro' => $erro]);
    }
}
