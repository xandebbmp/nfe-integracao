<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfe;

use PDO;

/**
 * Numeracao e estado das NF-e emitidas (tabelas nfe_numeracoes / nfe_documentos).
 */
final class DocumentoStore
{
    public const STATUS_FINAIS = ['AUTORIZADO', 'DENEGADO'];

    // Envio com resultado incerto: reenviar exatamente o mesmo XML.
    public const STATUS_PENDENTES = ['GERADO', 'ENVIADO', 'ERRO_ENVIO'];

    public function __construct(private PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Proximo nNF da serie. Atomico: o UPDATE trava a linha ate o fim da transacao.
     */
    public function reservarNumero(string $cnpj, int $tpAmb, int $modelo, int $serie): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfe_numeracoes (cnpj, tp_amb, modelo, serie, ultimo_nnf, criado_em, atualizado_em)
             VALUES (:cnpj, :tp_amb, :modelo, :serie, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (cnpj, tp_amb, modelo, serie) DO UPDATE
                SET ultimo_nnf = nfe_numeracoes.ultimo_nnf + 1,
                    atualizado_em = CURRENT_TIMESTAMP
             RETURNING ultimo_nnf'
        );
        $stmt->execute([':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':modelo' => $modelo, ':serie' => $serie]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Garante que a sequencia nunca reserve um numero informado manualmente.
     */
    public function acompanharNumero(string $cnpj, int $tpAmb, int $modelo, int $serie, int $nnf): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfe_numeracoes (cnpj, tp_amb, modelo, serie, ultimo_nnf, criado_em, atualizado_em)
             VALUES (:cnpj, :tp_amb, :modelo, :serie, :nnf, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (cnpj, tp_amb, modelo, serie) DO UPDATE
                SET ultimo_nnf = GREATEST(nfe_numeracoes.ultimo_nnf, EXCLUDED.ultimo_nnf),
                    atualizado_em = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':modelo' => $modelo, ':serie' => $serie, ':nnf' => $nnf,
        ]);
    }

    /**
     * Define o ultimo nNF ja usado (implantacao: continuar a numeracao de outro emissor).
     */
    public function definirUltimoNumero(string $cnpj, int $tpAmb, int $modelo, int $serie, int $nnf): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfe_numeracoes (cnpj, tp_amb, modelo, serie, ultimo_nnf, criado_em, atualizado_em)
             VALUES (:cnpj, :tp_amb, :modelo, :serie, :nnf, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT (cnpj, tp_amb, modelo, serie) DO UPDATE
                SET ultimo_nnf = EXCLUDED.ultimo_nnf,
                    atualizado_em = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':modelo' => $modelo, ':serie' => $serie, ':nnf' => $nnf,
        ]);
    }

    public function buscarPorIdIntegracao(string $cnpj, int $tpAmb, string $idIntegracao): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM nfe_documentos
              WHERE cnpj = :cnpj AND tp_amb = :tp_amb AND id_integracao = :id
              FOR UPDATE'
        );
        $stmt->execute([':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':id' => $idIntegracao]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function buscarPorNumero(string $cnpj, int $tpAmb, int $modelo, int $serie, int $nnf): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM nfe_documentos
              WHERE cnpj = :cnpj AND tp_amb = :tp_amb AND modelo = :modelo AND serie = :serie AND nnf = :nnf
              FOR UPDATE'
        );
        $stmt->execute([
            ':cnpj' => $cnpj, ':tp_amb' => $tpAmb, ':modelo' => $modelo, ':serie' => $serie, ':nnf' => $nnf,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function buscarPorChave(string $chave): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nfe_documentos WHERE chave = :chave');
        $stmt->execute([':chave' => $chave]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Registra (ou regrava, apos rejeicao) a nota gerada com status GERADO.
     */
    public function registrarGerado(array $d): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nfe_documentos (
                cnpj, tp_amb, modelo, serie, nnf, id_integracao, payload_hash, chave, status,
                dh_emi, v_nf, dest_documento, xml_gerado_path, criado_em, atualizado_em
            ) VALUES (
                :cnpj, :tp_amb, :modelo, :serie, :nnf, :id_integracao, :payload_hash, :chave, \'GERADO\',
                :dh_emi, :v_nf, :dest, :xml, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON CONFLICT (cnpj, tp_amb, modelo, serie, nnf) DO UPDATE
                SET id_integracao = EXCLUDED.id_integracao,
                    payload_hash = EXCLUDED.payload_hash,
                    chave = EXCLUDED.chave,
                    status = \'GERADO\',
                    cstat = NULL,
                    x_motivo = NULL,
                    dh_emi = EXCLUDED.dh_emi,
                    v_nf = EXCLUDED.v_nf,
                    dest_documento = EXCLUDED.dest_documento,
                    xml_gerado_path = EXCLUDED.xml_gerado_path,
                    atualizado_em = CURRENT_TIMESTAMP'
        );

        $stmt->bindValue(':cnpj', $d['cnpj']);
        $stmt->bindValue(':tp_amb', $d['tp_amb'], PDO::PARAM_INT);
        $stmt->bindValue(':modelo', $d['modelo'], PDO::PARAM_INT);
        $stmt->bindValue(':serie', $d['serie'], PDO::PARAM_INT);
        $stmt->bindValue(':nnf', $d['nnf'], PDO::PARAM_INT);
        $stmt->bindValue(':id_integracao', $d['id_integracao'] ?? null);
        $stmt->bindValue(':payload_hash', $d['payload_hash']);
        $stmt->bindValue(':chave', $d['chave']);
        $stmt->bindValue(':dh_emi', $d['dh_emi'] ?? null);
        $stmt->bindValue(':v_nf', $d['v_nf'] ?? null);
        $stmt->bindValue(':dest', $d['dest_documento'] ?? null);
        $stmt->bindValue(':xml', $d['xml_gerado_path'] ?? null);
        $stmt->execute();
    }

    public function atualizarStatus(
        string $chave,
        string $status,
        ?string $cStat = null,
        ?string $xMotivo = null,
        ?string $nProt = null,
        ?string $nfeProcPath = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE nfe_documentos
                SET status = :status,
                    cstat = COALESCE(:cstat, cstat),
                    x_motivo = COALESCE(:x_motivo, x_motivo),
                    n_prot = COALESCE(:n_prot, n_prot),
                    nfe_proc_path = COALESCE(:proc, nfe_proc_path),
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE chave = :chave'
        );
        $stmt->execute([
            ':status' => $status,
            ':cstat' => $cStat !== '' ? $cStat : null,
            ':x_motivo' => $xMotivo !== '' ? $xMotivo : null,
            ':n_prot' => $nProt !== '' ? $nProt : null,
            ':proc' => $nfeProcPath,
            ':chave' => $chave,
        ]);
    }
}
