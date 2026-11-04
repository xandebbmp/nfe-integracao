<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * Feed do monitor fiscal (tabela nfse_monitor).
 *
 * Cada mudanca relevante (recebido, enviado, autorizado, consultado,
 * cancelado...) vira UMA linha nesta tabela e um aviso pg_notify no canal
 * "nfse_monitor". O endpoint SSE escuta esse canal e empurra as linhas novas
 * para o painel.
 *
 * Como o feed e uma tabela com id crescente, o painel pode reconectar e
 * pedir "tudo depois do id X" sem perder nada.
 */
final class MonitorRepositorio
{
    public const CANAL = 'nfse_monitor';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string $operacao RECEBIMENTO | EMISSAO | CONSULTA | EVENTO
     */
    public function registrar(array $documento, string $operacao, string $status, ?string $mensagem = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfse_monitor (
                documento_id, lote_id, consumidor, emitente_cnpj, padrao, referencia, operacao, status, mensagem, numero_nfse, chave_acesso
             ) VALUES (
                :documento_id, :lote_id, :consumidor, :emitente_cnpj, :padrao, :referencia, :operacao, :status, :mensagem, :numero_nfse, :chave_acesso
             ) RETURNING id'
        );
        $stmt->execute([
            ':documento_id' => $documento['id'],
            ':lote_id' => $documento['lote_id'],
            ':consumidor' => $documento['consumidor'],
            ':emitente_cnpj' => $documento['emitente_cnpj'] ?? null,
            ':padrao' => $documento['padrao'],
            ':referencia' => $documento['referencia'],
            ':operacao' => $operacao,
            ':status' => $status,
            ':mensagem' => $mensagem !== null ? mb_substr($mensagem, 0, 1000) : null,
            ':numero_nfse' => $documento['numero_nfse'] ?? null,
            ':chave_acesso' => $documento['chave_acesso'] ?? null,
        ]);
        $id = (int)$stmt->fetchColumn();

        // Aviso leve: so o id. Quem escuta busca a linha completa.
        $this->pdo->query("SELECT pg_notify('" . self::CANAL . "', '{$id}')");

        return $id;
    }

    /**
     * Linhas com id maior que $ultimoId. $consumidor = null traz de todos.
     *
     * @return list<array>
     */
    public function listarDepoisDe(int $ultimoId, ?string $consumidor = null, int $limite = 200): array
    {
        $sql = 'SELECT * FROM nfse_monitor WHERE id > :id'
            . ($consumidor !== null ? ' AND consumidor = :consumidor' : '')
            . ' ORDER BY id LIMIT ' . max(1, min($limite, 500));
        $stmt = $this->pdo->prepare($sql);
        $params = [':id' => $ultimoId];
        if ($consumidor !== null) {
            $params[':consumidor'] = $consumidor;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Id a partir do qual mostrar as ultimas $quantidade linhas (carga inicial do painel). */
    public function idParaUltimos(int $quantidade): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM nfse_monitor');
        $stmt->execute();
        return max(0, (int)$stmt->fetchColumn() - max(0, $quantidade));
    }

    /** Historico de um documento (linha do tempo). */
    public function historico(string $documentoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nfse_monitor WHERE documento_id = :id ORDER BY id');
        $stmt->execute([':id' => $documentoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
