<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\DFe;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class DfeControlStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function buscarBloqueioAtivo(string $cnpj, int $tpAmb, string $fonte): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM dfe_bloqueios
              WHERE cnpj = :cnpj
                AND tp_amb = :tp_amb
                AND fonte = :fonte
                AND bloqueado_ate > CURRENT_TIMESTAMP
              ORDER BY bloqueado_ate DESC
              LIMIT 1'
        );

        $stmt->execute([
            ':cnpj' => $cnpj,
            ':tp_amb' => $tpAmb,
            ':fonte' => $fonte,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function registrarBloqueio(
        string $cnpj,
        int $tpAmb,
        string $fonte,
        string $cstatOrigem,
        ?string $motivoOrigem,
        DateTimeImmutable $bloqueadoAte
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dfe_bloqueios (
                cnpj,
                tp_amb,
                fonte,
                cstat_origem,
                motivo_origem,
                bloqueado_ate,
                criado_em
            ) VALUES (
                :cnpj,
                :tp_amb,
                :fonte,
                :cstat_origem,
                :motivo_origem,
                :bloqueado_ate,
                CURRENT_TIMESTAMP
            )'
        );

        $stmt->execute([
            ':cnpj' => $cnpj,
            ':tp_amb' => $tpAmb,
            ':fonte' => $fonte,
            ':cstat_origem' => $cstatOrigem,
            ':motivo_origem' => $motivoOrigem,
            ':bloqueado_ate' => $this->formatDateTime($bloqueadoAte),
        ]);
    }

    public function buscarOuCriarControle(string $cnpj, int $tpAmb, string $fonte): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dfe_controles (
                cnpj,
                tp_amb,
                fonte,
                ult_nsu,
                max_nsu,
                criado_em,
                atualizado_em
            ) VALUES (
                :cnpj,
                :tp_amb,
                :fonte,
                :ult_nsu,
                :max_nsu,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            ON CONFLICT (cnpj, tp_amb, fonte) DO NOTHING'
        );

        $stmt->execute([
            ':cnpj' => $cnpj,
            ':tp_amb' => $tpAmb,
            ':fonte' => $fonte,
            ':ult_nsu' => '0',
            ':max_nsu' => '0',
        ]);

        $select = $this->pdo->prepare(
            'SELECT *
               FROM dfe_controles
              WHERE cnpj = :cnpj
                AND tp_amb = :tp_amb
                AND fonte = :fonte
              LIMIT 1'
        );

        $select->execute([
            ':cnpj' => $cnpj,
            ':tp_amb' => $tpAmb,
            ':fonte' => $fonte,
        ]);

        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Falha ao buscar ou criar controle NSU DF-e.');
        }

        return $row;
    }

    public function atualizarControleNsu(
        string $cnpj,
        int $tpAmb,
        string $fonte,
        string $ultNsu,
        string $maxNsu
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dfe_controles (
                cnpj,
                tp_amb,
                fonte,
                ult_nsu,
                max_nsu,
                criado_em,
                atualizado_em
            ) VALUES (
                :cnpj,
                :tp_amb,
                :fonte,
                :ult_nsu,
                :max_nsu,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            ON CONFLICT (cnpj, tp_amb, fonte) DO UPDATE
                SET ult_nsu = EXCLUDED.ult_nsu,
                    max_nsu = EXCLUDED.max_nsu,
                    atualizado_em = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            ':cnpj' => $cnpj,
            ':tp_amb' => $tpAmb,
            ':fonte' => $fonte,
            ':ult_nsu' => $ultNsu,
            ':max_nsu' => $maxNsu,
        ]);
    }

    public function registrarHistorico(array $dados): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dfe_historicos (
                cnpj,
                tp_amb,
                fonte,
                modo,
                chave,
                num_nsu,
                ult_nsu_enviado,
                cstat,
                x_motivo,
                status_operacional,
                houve_chamada_sefaz,
                criado_em
            ) VALUES (
                :cnpj,
                :tp_amb,
                :fonte,
                :modo,
                :chave,
                :num_nsu,
                :ult_nsu_enviado,
                :cstat,
                :x_motivo,
                :status_operacional,
                :houve_chamada_sefaz,
                CURRENT_TIMESTAMP
            )'
        );

        $stmt->bindValue(':cnpj', $dados['cnpj'] ?? null);
        $stmt->bindValue(':tp_amb', $dados['tp_amb'] ?? $dados['tpAmb'] ?? null);
        $stmt->bindValue(':fonte', $dados['fonte'] ?? null);
        $stmt->bindValue(':modo', $dados['modo'] ?? null);
        $stmt->bindValue(':chave', $dados['chave'] ?? null);
        $stmt->bindValue(':num_nsu', $dados['num_nsu'] ?? $dados['numNsu'] ?? null);
        $stmt->bindValue(':ult_nsu_enviado', $dados['ult_nsu_enviado'] ?? $dados['ultNsuEnviado'] ?? null);
        $stmt->bindValue(':cstat', $dados['cstat'] ?? $dados['cStat'] ?? null);
        $stmt->bindValue(':x_motivo', $dados['x_motivo'] ?? $dados['xMotivo'] ?? null);
        $stmt->bindValue(':status_operacional', $dados['status_operacional'] ?? $dados['statusOperacional'] ?? null);
        $stmt->bindValue(
            ':houve_chamada_sefaz',
            (bool)($dados['houve_chamada_sefaz'] ?? $dados['houveChamadaSefaz'] ?? false),
            PDO::PARAM_BOOL
        );

        $stmt->execute();
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:sP');
    }
}
