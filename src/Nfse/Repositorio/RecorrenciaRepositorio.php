<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL das notas recorrentes do portal (script 0011).
 * Toda consulta da tela recebe o CNPJ da empresa logada.
 */
final class RecorrenciaRepositorio
{
    /** Trava do PostgreSQL para dois workers nao emitirem a mesma recorrencia juntos. */
    private const TRAVA = 70080011;

    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0011 ja foi aplicado? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.portal_recorrencias') IS NOT NULL")->fetchColumn();
    }

    /** @return list<array> recorrencias da empresa com o nome do servico e a situacao da ultima nota */
    public function listar(string $cnpj): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, s.nome AS servico_nome, d.status AS ultimo_status
               FROM portal_recorrencias r
               JOIN portal_servicos s ON s.id = r.servico_id
               LEFT JOIN nfse_documentos d ON d.id = r.ultimo_documento_id
              WHERE r.cnpj = :cnpj
              ORDER BY r.ativa DESC, r.proxima_em, r.nome'
        );
        $stmt->execute([':cnpj' => $cnpj]);
        return array_map([self::class, 'decodificar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function buscar(string $cnpj, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_recorrencias WHERE cnpj = :cnpj AND id = :id');
        $stmt->execute([':cnpj' => $cnpj, ':id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::decodificar($linha);
    }

    /** Cria (sem id) ou altera (com id). Devolve o id. */
    public function salvar(string $cnpj, array $r, ?int $id, ?int $criadoPor): int
    {
        $campos = [
            ':nome' => $r['nome'],
            ':tomador' => json_encode($r['tomador'], JSON_UNESCAPED_UNICODE),
            ':servico_id' => $r['servico_id'],
            ':descricao' => $r['descricao'],
            ':valores' => json_encode($r['valores']),
            ':dia' => $r['dia'],
            ':competencia' => $r['competencia'],
            ':modo' => $r['modo'],
            ':proxima_em' => $r['proxima_em'],
            ':fim' => $r['fim'],
            ':cnpj' => $cnpj,
        ];
        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_recorrencias (cnpj, nome, tomador, servico_id, descricao, valores, dia, competencia, modo, proxima_em, fim, criado_por)
                 VALUES (:cnpj, :nome, CAST(:tomador AS JSONB), :servico_id, :descricao, CAST(:valores AS JSONB), :dia, :competencia, :modo, :proxima_em, :fim, :criado_por)
                 RETURNING id'
            );
            $stmt->execute($campos + [':criado_por' => $criadoPor]);
            return (int)$stmt->fetchColumn();
        }
        // Editar recomeca as tentativas automaticas (o erro pode ter sido corrigido).
        $stmt = $this->pdo->prepare(
            'UPDATE portal_recorrencias
                SET nome = :nome, tomador = CAST(:tomador AS JSONB), servico_id = :servico_id, descricao = :descricao,
                    valores = CAST(:valores AS JSONB), dia = :dia, competencia = :competencia, modo = :modo,
                    proxima_em = :proxima_em, fim = :fim, tentativa_em = NULL, ultima_mensagem = NULL,
                    ativa = TRUE, atualizado_em = CURRENT_TIMESTAMP
              WHERE cnpj = :cnpj AND id = :id'
        );
        $stmt->execute($campos + [':id' => $id]);
        return $id;
    }

    /** Pausa (ativa=false) ou retoma com a proxima data recalculada. */
    public function definirAtiva(string $cnpj, int $id, bool $ativa, ?string $proximaEm = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE portal_recorrencias
                SET ativa = :ativa, proxima_em = COALESCE(CAST(:proxima AS DATE), proxima_em),
                    tentativa_em = NULL, atualizado_em = CURRENT_TIMESTAMP
              WHERE cnpj = :cnpj AND id = :id'
        );
        $stmt->bindValue(':ativa', $ativa, PDO::PARAM_BOOL);
        $stmt->bindValue(':proxima', $proximaEm);
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function remover(string $cnpj, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM portal_recorrencias WHERE cnpj = :cnpj AND id = :id');
        $stmt->execute([':cnpj' => $cnpj, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * O mes foi resolvido: segue para a proxima data.
     * $documentoId = null quando a pessoa pulou o mes.
     * $ativa = false quando passou do fim.
     */
    public function avancar(int $id, string $proximaEm, ?string $documentoId, bool $ativa): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE portal_recorrencias
                SET proxima_em = :proxima, ativa = :ativa, tentativa_em = NULL, ultima_mensagem = NULL,
                    ultimo_documento_id = COALESCE(CAST(:documento AS UUID), ultimo_documento_id),
                    emitidas = emitidas + CASE WHEN CAST(:documento_conta AS UUID) IS NULL THEN 0 ELSE 1 END,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->bindValue(':proxima', $proximaEm);
        $stmt->bindValue(':ativa', $ativa, PDO::PARAM_BOOL);
        $stmt->bindValue(':documento', $documentoId);
        $stmt->bindValue(':documento_conta', $documentoId);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** A tentativa automatica de hoje falhou: guarda o motivo e so tenta de novo amanha. */
    public function registrarFalha(int $id, string $mensagem, string $hoje): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE portal_recorrencias SET tentativa_em = :hoje, ultima_mensagem = :mensagem, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([':hoje' => $hoje, ':mensagem' => mb_substr($mensagem, 0, 500), ':id' => $id]);
    }

    /** @return list<array> automaticas que chegaram no dia e ainda nao foram tentadas hoje */
    public function automaticasDoDia(string $hoje, int $limite): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM portal_recorrencias
              WHERE ativa AND modo = 'AUTOMATICA' AND proxima_em <= :hoje
                AND (tentativa_em IS NULL OR tentativa_em < :hoje_tentativa)
              ORDER BY proxima_em, id
              LIMIT :limite"
        );
        $stmt->bindValue(':hoje', $hoje);
        $stmt->bindValue(':hoje_tentativa', $hoje);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'decodificar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return int quantas recorrencias da empresa estao esperando (dia chegou e a nota nao saiu) */
    public function pendentes(string $cnpj, string $hoje): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM portal_recorrencias WHERE cnpj = :cnpj AND ativa AND proxima_em <= :hoje');
        $stmt->execute([':cnpj' => $cnpj, ':hoje' => $hoje]);
        return (int)$stmt->fetchColumn();
    }

    public function travar(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT pg_try_advisory_lock(:trava, :id)');
        $stmt->bindValue(':trava', self::TRAVA, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    public function liberar(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:trava, :id)');
        $stmt->bindValue(':trava', self::TRAVA, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function decodificar(array $r): array
    {
        $r['id'] = (int)$r['id'];
        $r['servico_id'] = (int)$r['servico_id'];
        $r['dia'] = (int)$r['dia'];
        $r['emitidas'] = (int)$r['emitidas'];
        $r['ativa'] = in_array($r['ativa'], [true, 't', 'true', 1, '1'], true);
        $r['tomador'] = json_decode((string)$r['tomador'], true) ?: [];
        $r['valores'] = json_decode((string)$r['valores'], true) ?: [];
        $r['criado_por'] = $r['criado_por'] !== null ? (int)$r['criado_por'] : null;
        return $r;
    }
}
