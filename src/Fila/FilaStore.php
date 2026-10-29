<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Fila;

use PDO;
use Xande\NfeIntegracao\Nfe\DocumentoStore;

/**
 * Fila de emissao (tabelas emissao_lotes / emissao_fila).
 */
final class FilaStore
{
    // Job travado ha mais que isso = worker morreu no meio; volta para a fila.
    public const TRAVA_EXPIRA_SEGUNDOS = 600;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Enfileira as notas de um lote. Retorna um item por nota, na mesma ordem.
     *
     * Por idIntegracao:
     *  - novo: cria job (NF-e: reserva o nNF agora e grava no payload);
     *  - PENDENTE/PROCESSANDO ou CONCLUIDO autorizada/denegada: devolve o job existente;
     *  - FALHOU ou CONCLUIDO rejeitada: reenfileira com o payload novo e o mesmo nNF.
     *
     * @param array<int, array> $notas
     * @return array<int, array>
     */
    public function enfileirarNfe(string $cnpj, int $tpAmb, array $notas): array
    {
        $docs = new DocumentoStore($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO emissao_lotes (cnpj, tp_amb, tipo_documento, total, criado_em)
                 VALUES (:cnpj, :tp_amb, \'NFE\', :total, CURRENT_TIMESTAMP)
                 RETURNING id'
            );
            $stmt->execute([':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':total' => count($notas)]);
            $loteId = (int)$stmt->fetchColumn();

            $vincular = $this->pdo->prepare(
                'INSERT INTO emissao_lote_itens (lote_id, fila_id, acao) VALUES (:lote_id, :fila_id, :acao)'
            );

            $itens = [];
            foreach ($notas as $nota) {
                $item = $this->enfileirarUmaNfe($docs, $loteId, $cnpj, $tpAmb, $nota);
                $vincular->execute([':lote_id' => $loteId, ':fila_id' => $item['jobId'], ':acao' => $item['acao']]);
                $itens[] = $item;
            }

            $this->pdo->commit();
            return ['loteId' => $loteId, 'itens' => $itens];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function enfileirarUmaNfe(DocumentoStore $docs, int $loteId, string $cnpj, int $tpAmb, array $nota): array
    {
        $idIntegracao = (string)$nota['idIntegracao'];
        $modelo = (int)($nota['ide']['mod'] ?? 55);
        $serie = (int)($nota['ide']['serie'] ?? 1);

        $existente = $this->buscarJob($cnpj, $tpAmb, 'NFE', $idIntegracao, true);

        if ($existente !== null) {
            $reenfileirar = $existente['status'] === 'FALHOU'
                || ($existente['status'] === 'CONCLUIDO' && $existente['status_documento'] === 'REJEITADO');

            // Mantém o número já reservado para esta nota.
            $payloadExistente = json_decode((string)$existente['payload'], true) ?: [];
            $nota['ide']['nNF'] = (int)($payloadExistente['ide']['nNF'] ?? 0) ?: (int)($nota['ide']['nNF'] ?? 0);

            if (!$reenfileirar) {
                $item = $this->item($existente, $nota == $payloadExistente ? 'existente' : 'conflito');
                if ($item['acao'] === 'conflito') {
                    $item['erro'] = "idIntegracao já enfileirado ({$existente['status']}) com conteúdo diferente; alteração ignorada.";
                }
                return $item;
            }

            $stmt = $this->pdo->prepare(
                'UPDATE emissao_fila
                    SET lote_id = :lote_id, payload = :payload, status = \'PENDENTE\', tentativas = 0,
                        disponivel_em = CURRENT_TIMESTAMP, travado_em = NULL, travado_por = NULL,
                        ultimo_erro = NULL, atualizado_em = CURRENT_TIMESTAMP
                  WHERE id = :id
                  RETURNING *'
            );
            $stmt->execute([
                ':lote_id' => $loteId,
                ':payload' => self::json($nota),
                ':id' => $existente['id'],
            ]);
            return $this->item($stmt->fetch(PDO::FETCH_ASSOC), 'reenfileirado');
        }

        // Nota já emitida por outro caminho (ex.: emitir_padrao síncrono): mantém o número dela.
        $doc = $docs->buscarPorIdIntegracao($cnpj, $tpAmb, $idIntegracao);
        $nNF = (int)($nota['ide']['nNF'] ?? 0);
        if ($doc !== null) {
            $nota['ide']['nNF'] = (int)$doc['nnf'];
        } elseif ($nNF > 0) {
            $docs->acompanharNumero($cnpj, $tpAmb, $modelo, $serie, $nNF);
        } else {
            $nota['ide']['nNF'] = $docs->reservarNumero($cnpj, $tpAmb, $modelo, $serie);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO emissao_fila (
                lote_id, cnpj, tp_amb, tipo_documento, id_integracao, payload, criado_em, atualizado_em
            ) VALUES (
                :lote_id, :cnpj, :tp_amb, \'NFE\', :id_integracao, :payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            RETURNING *'
        );
        $stmt->execute([
            ':lote_id' => $loteId,
            ':cnpj' => $cnpj,
            ':tp_amb' => $tpAmb,
            ':id_integracao' => $idIntegracao,
            ':payload' => self::json($nota),
        ]);
        return $this->item($stmt->fetch(PDO::FETCH_ASSOC), 'novo');
    }

    /**
     * Pega o proximo job disponivel (varios workers podem rodar em paralelo).
     */
    public function pegarProximo(string $worker): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE emissao_fila
                SET status = \'PROCESSANDO\',
                    tentativas = tentativas + 1,
                    travado_em = CURRENT_TIMESTAMP,
                    travado_por = :worker,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = (
                    SELECT id FROM emissao_fila
                     WHERE status = \'PENDENTE\' AND disponivel_em <= CURRENT_TIMESTAMP
                     ORDER BY disponivel_em, id
                     FOR UPDATE SKIP LOCKED
                     LIMIT 1
              )
              RETURNING *'
        );
        $stmt->execute([':worker' => $worker]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function concluir(int $id, array $doc, string $saida): void
    {
        $this->finalizar($id, 'CONCLUIDO', $doc, null, $saida);
    }

    public function falhar(int $id, string $erro, string $saida, array $doc = []): void
    {
        $this->finalizar($id, 'FALHOU', $doc, $erro, $saida);
    }

    /**
     * Resultado incerto/tecnico: tenta de novo com espera crescente (1, 2, 4, 8... min, teto 30).
     * Esgotadas as tentativas, o job fica FALHOU.
     */
    public function reagendar(array $job, string $erro, string $saida, array $doc = []): bool
    {
        $tentativas = (int)$job['tentativas'];
        if ($tentativas >= (int)$job['max_tentativas']) {
            $this->falhar((int)$job['id'], "Tentativas esgotadas: {$erro}", $saida, $doc);
            return false;
        }

        $espera = min(60 * (2 ** ($tentativas - 1)), 1800);
        $stmt = $this->pdo->prepare(
            'UPDATE emissao_fila
                SET status = \'PENDENTE\',
                    disponivel_em = CURRENT_TIMESTAMP + make_interval(secs => :espera),
                    travado_em = NULL, travado_por = NULL,
                    status_documento = :status_documento, chave = :chave, cstat = :cstat, x_motivo = :x_motivo,
                    ultimo_erro = :erro, saida = :saida,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->execute([
            ':espera' => $espera,
            ':status_documento' => $doc['status'] ?? null,
            ':chave' => $doc['chave'] ?? null,
            ':cstat' => $doc['cstat'] ?? null,
            ':x_motivo' => $doc['x_motivo'] ?? null,
            ':erro' => $erro,
            ':saida' => self::cortar($saida),
            ':id' => $job['id'],
        ]);
        return true;
    }

    /**
     * Devolve para a fila jobs presos em PROCESSANDO (worker caiu no meio).
     * O reprocessamento é seguro: a emissão reusa o mesmo XML (duplicidade 204).
     */
    public function recuperarTravados(): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE emissao_fila
                SET status = \'PENDENTE\', travado_em = NULL, travado_por = NULL,
                    ultimo_erro = \'Worker interrompido; reprocessando.\',
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE status = \'PROCESSANDO\'
                AND travado_em < CURRENT_TIMESTAMP - make_interval(secs => :expira)'
        );
        $stmt->execute([':expira' => self::TRAVA_EXPIRA_SEGUNDOS]);
        return $stmt->rowCount();
    }

    public function buscarLote(string $cnpj, int $loteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM emissao_lotes WHERE id = :id AND cnpj = :cnpj');
        $stmt->execute([':id' => $loteId, ':cnpj' => $cnpj]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lote === false) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT f.*, li.acao
               FROM emissao_lote_itens li
               JOIN emissao_fila f ON f.id = li.fila_id
              WHERE li.lote_id = :id
              ORDER BY li.id'
        );
        $stmt->execute([':id' => $loteId]);
        $lote['itens'] = array_map(fn(array $j) => $this->item($j, $j['acao']), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $lote;
    }

    private function buscarJob(string $cnpj, int $tpAmb, string $tipo, string $idIntegracao, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM emissao_fila
              WHERE cnpj = :cnpj AND tp_amb = :tp_amb AND tipo_documento = :tipo AND id_integracao = :id'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':tipo' => $tipo, ':id' => $idIntegracao]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function finalizar(int $id, string $status, array $doc, ?string $erro, string $saida): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE emissao_fila
                SET status = :status,
                    travado_em = NULL, travado_por = NULL,
                    status_documento = :status_documento, chave = :chave, cstat = :cstat, x_motivo = :x_motivo,
                    ultimo_erro = :erro, saida = :saida,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':status_documento' => $doc['status'] ?? null,
            ':chave' => $doc['chave'] ?? null,
            ':cstat' => $doc['cstat'] ?? null,
            ':x_motivo' => $doc['x_motivo'] ?? null,
            ':erro' => $erro,
            ':saida' => self::cortar($saida),
            ':id' => $id,
        ]);
    }

    /**
     * Representacao publica de um job (sem payload nem saida bruta).
     */
    private function item(array $job, ?string $acao = null): array
    {
        $payload = json_decode((string)$job['payload'], true) ?: [];
        $item = [
            'jobId' => (int)$job['id'],
            'idIntegracao' => $job['id_integracao'],
            'serie' => isset($payload['ide']['serie']) ? (int)$payload['ide']['serie'] : null,
            'nNF' => isset($payload['ide']['nNF']) ? (int)$payload['ide']['nNF'] : null,
            'status' => $job['status'],
            'statusDocumento' => $job['status_documento'],
            'chave' => $job['chave'],
            'cStat' => $job['cstat'],
            'xMotivo' => $job['x_motivo'],
            'erro' => $job['ultimo_erro'],
            'tentativas' => (int)$job['tentativas'],
            'atualizadoEm' => $job['atualizado_em'],
        ];
        if ($acao !== null) {
            $item['acao'] = $acao;
        }
        return $item;
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function cortar(string $s): string
    {
        return mb_substr($s, -20000);
    }
}
