<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * Acesso a tabela nfse_eventos (cancelamento e demais eventos fiscais).
 */
final class EventoRepositorio
{
    private const COLUNAS_ATUALIZAVEIS = [
        'status', 'codigo_retorno', 'mensagem_retorno', 'mensagens', 'xml_enviado', 'xml_retorno',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{origem?: string, status?: string, numero_processo?: ?string, observacao?: ?string, registrado_por?: ?string, sequencia?: ?int} $extras
     *   origem EXTERNO = cancelamento feito fora do webservice (portal da prefeitura).
     */
    public function criar(string $documentoId, string $tipo, string $motivoCodigo, string $motivoTexto, array $extras = []): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfse_eventos (
                documento_id, tipo_evento, motivo_codigo, motivo_texto,
                origem, status, numero_processo, observacao, registrado_por, sequencia
             ) VALUES (
                :doc, :tipo, :codigo, :texto,
                :origem, :status, :processo, :observacao, :registrado_por, :sequencia
             ) RETURNING id'
        );
        $stmt->execute([
            ':doc' => $documentoId,
            ':tipo' => $tipo,
            ':codigo' => $motivoCodigo !== '' ? $motivoCodigo : null,
            ':texto' => $motivoTexto,
            ':origem' => $extras['origem'] ?? 'API',
            ':status' => $extras['status'] ?? 'RECEBIDO',
            ':processo' => $extras['numero_processo'] ?? null,
            ':observacao' => $extras['observacao'] ?? null,
            ':registrado_por' => $extras['registrado_por'] ?? null,
            ':sequencia' => $extras['sequencia'] ?? null,
        ]);
        return (string)$stmt->fetchColumn();
    }

    public function buscar(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nfse_eventos WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decodificar($row);
    }

    /**
     * Evento do mesmo tipo ainda em andamento ou ja registrado (para nao duplicar).
     * $soEmAndamento = true ignora os ja registrados (ex.: nova Carta de Correcao).
     */
    public function buscarAtivo(string $documentoId, string $tipo, bool $soEmAndamento = false): ?array
    {
        $status = $soEmAndamento ? "('RECEBIDO', 'PROCESSANDO')" : "('RECEBIDO', 'PROCESSANDO', 'REGISTRADO')";
        $stmt = $this->pdo->prepare(
            "SELECT * FROM nfse_eventos
              WHERE documento_id = :doc AND tipo_evento = :tipo
                AND status IN {$status}
              ORDER BY criado_em DESC LIMIT 1"
        );
        $stmt->execute([':doc' => $documentoId, ':tipo' => $tipo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decodificar($row);
    }

    /** Quantos eventos deste tipo ja foram REGISTRADOS (sequencia da CC-e). */
    public function contarDoTipo(string $documentoId, string $tipo): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(sequencia), 0) FROM nfse_eventos
              WHERE documento_id = :doc AND tipo_evento = :tipo AND status = 'REGISTRADO'"
        );
        $stmt->execute([':doc' => $documentoId, ':tipo' => $tipo]);
        return (int)$stmt->fetchColumn();
    }

    /** @return list<array> */
    public function listarDoDocumento(string $documentoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nfse_eventos WHERE documento_id = :doc ORDER BY criado_em');
        $stmt->execute([':doc' => $documentoId]);
        return array_map([self::class, 'decodificar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function atualizar(string $id, array $campos): void
    {
        $campos = array_intersect_key($campos, array_flip(self::COLUNAS_ATUALIZAVEIS));
        if ($campos === []) {
            return;
        }
        $sets = [];
        $params = [':id' => $id];
        foreach ($campos as $coluna => $valor) {
            $sets[] = "{$coluna} = :{$coluna}";
            $params[":{$coluna}"] = $coluna === 'mensagens' ? json_encode($valor, JSON_UNESCAPED_UNICODE) : $valor;
        }
        $sets[] = 'atualizado_em = CURRENT_TIMESTAMP';
        $this->pdo->prepare('UPDATE nfse_eventos SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    private static function decodificar(array $row): array
    {
        $row['mensagens'] = json_decode((string)($row['mensagens'] ?? '[]'), true) ?? [];
        return $row;
    }
}
