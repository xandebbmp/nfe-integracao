<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;
use RuntimeException;

/**
 * Acesso as tabelas nfse_lotes, nfse_documentos e nfse_numeracao.
 *
 * Todo SQL fica aqui (e nos outros repositorios). Servicos e endpoints
 * nao escrevem SQL.
 */
final class DocumentoRepositorio
{
    /** Colunas que o worker pode atualizar a partir do retorno do fisco. */
    public const COLUNAS_ATUALIZAVEIS = [
        'status', 'id_dps', 'protocolo_lote', 'chave_acesso', 'numero_nfse',
        'codigo_verificacao', 'data_autorizacao', 'codigo_retorno', 'mensagem_retorno',
        'mensagens', 'xml_enviado', 'xml_autorizado', 'payload', 'lote_id',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function criarLote(string $consumidor, int $quantidade): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfse_lotes (consumidor, quantidade_documentos) VALUES (:consumidor, :quantidade) RETURNING id'
        );
        $stmt->execute([':consumidor' => $consumidor, ':quantidade' => $quantidade]);
        return (string)$stmt->fetchColumn();
    }

    public function buscarLote(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nfse_lotes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Reserva o proximo numero de DPS/RPS. Deve rodar DENTRO da mesma
     * transacao que insere o documento: se algo falhar, o numero volta.
     */
    public function proximoNumero(string $cnpj, string $padrao, int $tpAmb, string $serie): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfse_numeracao (emitente_cnpj, padrao, tp_amb, serie, ultimo_numero)
             VALUES (:cnpj, :padrao, :tp_amb, :serie, 1)
             ON CONFLICT (emitente_cnpj, padrao, tp_amb, serie)
             DO UPDATE SET ultimo_numero = nfse_numeracao.ultimo_numero + 1,
                           atualizado_em = CURRENT_TIMESTAMP
             RETURNING ultimo_numero'
        );
        $stmt->execute([':cnpj' => $cnpj, ':padrao' => $padrao, ':tp_amb' => $tpAmb, ':serie' => $serie]);
        return (int)$stmt->fetchColumn();
    }

    public function inserir(array $dados): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfse_documentos (
                lote_id, consumidor, padrao, tp_amb, emitente_cnpj, referencia,
                status, payload, serie, numero
            ) VALUES (
                :lote_id, :consumidor, :padrao, :tp_amb, :emitente_cnpj, :referencia,
                :status, :payload, :serie, :numero
            ) RETURNING id'
        );
        $stmt->execute([
            ':lote_id' => $dados['lote_id'],
            ':consumidor' => $dados['consumidor'],
            ':padrao' => $dados['padrao'],
            ':tp_amb' => $dados['tp_amb'],
            ':emitente_cnpj' => $dados['emitente_cnpj'],
            ':referencia' => $dados['referencia'],
            ':status' => $dados['status'],
            ':payload' => json_encode($dados['payload'], JSON_UNESCAPED_UNICODE),
            ':serie' => $dados['serie'],
            ':numero' => $dados['numero'],
        ]);
        return (string)$stmt->fetchColumn();
    }

    /**
     * Busca pela chave de idempotencia. FOR UPDATE trava a linha ate o fim
     * da transacao, evitando que duas requisicoes iguais criem duas notas.
     */
    public function buscarPorReferenciaParaAtualizar(
        string $consumidor,
        string $cnpj,
        string $padrao,
        int $tpAmb,
        string $referencia
    ): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM nfse_documentos
              WHERE consumidor = :consumidor AND emitente_cnpj = :cnpj
                AND padrao = :padrao AND tp_amb = :tp_amb AND referencia = :referencia
              FOR UPDATE'
        );
        $stmt->execute([
            ':consumidor' => $consumidor, ':cnpj' => $cnpj, ':padrao' => $padrao,
            ':tp_amb' => $tpAmb, ':referencia' => $referencia,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decodificar($row);
    }

    public function buscar(string $id): ?array
    {
        if (!self::uuidValido($id)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM nfse_documentos WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decodificar($row);
    }

    /** @return list<array> */
    public function listarDoLote(string $loteId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nfse_documentos WHERE lote_id = :lote ORDER BY criado_em, numero');
        $stmt->execute([':lote' => $loteId]);
        return array_map([self::class, 'decodificar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lista para o painel. Filtros opcionais: status, padrao, consumidor.
     *
     * @return list<array>
     */
    public function listar(array $filtros, int $limite = 100): array
    {
        $where = [];
        $params = [];
        foreach (['status', 'padrao', 'consumidor'] as $campo) {
            if (!empty($filtros[$campo])) {
                $where[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $filtros[$campo];
            }
        }
        $sql = 'SELECT * FROM nfse_documentos'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY atualizado_em DESC LIMIT ' . max(1, min($limite, 500));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'decodificar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Atualiza somente as colunas informadas (e o atualizado_em).
     */
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
            $params[":{$coluna}"] = in_array($coluna, ['mensagens', 'payload'], true)
                ? json_encode($valor, JSON_UNESCAPED_UNICODE)
                : $valor;
        }
        $sets[] = 'atualizado_em = CURRENT_TIMESTAMP';

        $stmt = $this->pdo->prepare('UPDATE nfse_documentos SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Documento {$id} nao encontrado para atualizar.");
        }
    }

    public static function uuidValido(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    private static function decodificar(array $row): array
    {
        $row['payload'] = json_decode((string)$row['payload'], true) ?? [];
        $row['mensagens'] = json_decode((string)($row['mensagens'] ?? '[]'), true) ?? [];
        $row['tp_amb'] = (int)$row['tp_amb'];
        $row['numero'] = (int)$row['numero'];
        return $row;
    }
}
