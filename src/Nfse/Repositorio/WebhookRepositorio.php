<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * Saida de webhooks (tabela nfse_webhooks_saida), no padrao "outbox":
 * a notificacao e gravada no banco junto com a mudanca do documento e um
 * processo separado faz a entrega com novas tentativas.
 */
final class WebhookRepositorio
{
    public function __construct(private PDO $pdo)
    {
    }

    public function enfileirar(string $documentoId, string $consumidor, string $url, array $corpo): void
    {
        $this->pdo->prepare(
            'INSERT INTO nfse_webhooks_saida (documento_id, consumidor, url, corpo)
             VALUES (:doc, :consumidor, :url, :corpo)'
        )->execute([
            ':doc' => $documentoId,
            ':consumidor' => $consumidor,
            ':url' => $url,
            ':corpo' => json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Pega webhooks vencidos e ja "empurra" a proxima tentativa para frente,
     * assim outro worker nao pega o mesmo enquanto este entrega.
     *
     * @return list<array>
     */
    public function pegarPendentes(int $limite = 10): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE nfse_webhooks_saida
                SET tentativas = tentativas + 1,
                    proxima_tentativa_em = CURRENT_TIMESTAMP + interval '2 minutes',
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id IN (
                    SELECT id FROM nfse_webhooks_saida
                     WHERE status = 'PENDENTE' AND proxima_tentativa_em <= CURRENT_TIMESTAMP
                     ORDER BY proxima_tentativa_em, id
                     FOR UPDATE SKIP LOCKED
                     LIMIT " . max(1, $limite) . "
              )
          RETURNING *"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarResultado(int $id, bool $entregue, int $httpStatus, ?string $erro, ?int $novaTentativaEmSegundos): void
    {
        $status = $entregue ? 'ENTREGUE' : ($novaTentativaEmSegundos === null ? 'FALHOU' : 'PENDENTE');
        $this->pdo->prepare(
            'UPDATE nfse_webhooks_saida
                SET status = :status, ultimo_http_status = :http, ultimo_erro = :erro,
                    proxima_tentativa_em = CURRENT_TIMESTAMP + make_interval(secs => :segundos),
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id'
        )->execute([
            ':id' => $id,
            ':status' => $status,
            ':http' => $httpStatus,
            ':erro' => $erro,
            ':segundos' => $novaTentativaEmSegundos ?? 0,
        ]);
    }
}
