<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL das declaracoes do Simples transmitidas pelo sistema
 * (fiscal_declaracoes_simples, script 0017).
 *
 * As listas NUNCA trazem os PDFs (colunas pesadas): so em pdf().
 */
final class DeclaracaoSimplesRepositorio
{
    private const COLUNAS = 'id, cnpj, competencia, teste, tipo_declaracao, entrada, valores_devidos, valor_total,
        numero_declaracao, transmitida_em, enviado_por, criado_em,
        recibo_pdf IS NOT NULL AS tem_recibo, declaracao_pdf IS NOT NULL AS tem_declaracao';

    public function __construct(private PDO $pdo)
    {
    }

    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.fiscal_declaracoes_simples') IS NOT NULL")->fetchColumn();
    }

    public function salvar(string $cnpj, string $competencia, bool $teste, int $tipo, array $entrada, array $valores,
        float $total, string $numero, string $transmitidaEm, ?string $recibo, ?string $declaracao, ?string $autor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_declaracoes_simples
                (cnpj, competencia, teste, tipo_declaracao, entrada, valores_devidos, valor_total,
                 numero_declaracao, transmitida_em, recibo_pdf, declaracao_pdf, enviado_por)
             VALUES (:cnpj, :comp, :teste, :tipo, :entrada, :valores, :total, :numero, :quando, :recibo, :declaracao, :autor)
             RETURNING id'
        );
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':comp', $competencia);
        $stmt->bindValue(':teste', $teste, PDO::PARAM_BOOL);
        $stmt->bindValue(':tipo', $tipo, PDO::PARAM_INT);
        $stmt->bindValue(':entrada', json_encode($entrada, JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(':valores', json_encode($valores));
        $stmt->bindValue(':total', $total);
        $stmt->bindValue(':numero', $numero !== '' ? $numero : null);
        $stmt->bindValue(':quando', $transmitidaEm !== '' ? $transmitidaEm : null);
        $stmt->bindValue(':recibo', $recibo, $recibo === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':declaracao', $declaracao, $declaracao === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':autor', $autor);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /** Ultima declaracao (de verdade, nao de teste) da empresa: preenche a proxima. */
    public function ultima(string $cnpj): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM fiscal_declaracoes_simples
            WHERE cnpj = :cnpj ORDER BY teste ASC, competencia DESC, criado_em DESC LIMIT 1');
        $stmt->execute([':cnpj' => $cnpj]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        return $l === false ? null : self::formatar($l);
    }

    /** Declaracoes de um mes da empresa (mais nova primeiro). */
    public function doMes(string $cnpj, string $competencia): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM fiscal_declaracoes_simples
            WHERE cnpj = :cnpj AND competencia = :comp ORDER BY criado_em DESC');
        $stmt->execute([':cnpj' => $cnpj, ':comp' => $competencia]);
        return array_map([self::class, 'formatar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param string $qual 'recibo' ou 'declaracao' */
    public function pdf(int $id, string $qual): ?array
    {
        $coluna = $qual === 'recibo' ? 'recibo_pdf' : 'declaracao_pdf';
        $stmt = $this->pdo->prepare("SELECT cnpj, competencia, {$coluna} AS pdf FROM fiscal_declaracoes_simples WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($l === false || $l['pdf'] === null) {
            return null;
        }
        $pdf = is_resource($l['pdf']) ? stream_get_contents($l['pdf']) : (string)$l['pdf'];
        return ['cnpj' => $l['cnpj'], 'competencia' => $l['competencia'], 'pdf' => $pdf];
    }

    private static function formatar(array $l): array
    {
        return [
            'id' => (int)$l['id'],
            'cnpj' => (string)$l['cnpj'],
            'competencia' => (string)$l['competencia'],
            'teste' => (bool)$l['teste'],
            'tipo_declaracao' => (int)$l['tipo_declaracao'],
            'entrada' => json_decode((string)$l['entrada'], true) ?: [],
            'valores_devidos' => json_decode((string)$l['valores_devidos'], true) ?: [],
            'valor_total' => (float)$l['valor_total'],
            'numero_declaracao' => $l['numero_declaracao'],
            'transmitida_em' => $l['transmitida_em'],
            'enviado_por' => $l['enviado_por'],
            'criado_em' => $l['criado_em'],
            'tem_recibo' => (bool)$l['tem_recibo'],
            'tem_declaracao' => (bool)$l['tem_declaracao'],
        ];
    }
}
