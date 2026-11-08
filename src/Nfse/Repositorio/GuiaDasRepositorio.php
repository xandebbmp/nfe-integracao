<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL das guias DAS do Simples (fiscal_guias_das, script 0015).
 *
 * As listas NUNCA trazem o PDF (coluna pesada): ele so e lido em pdf().
 */
final class GuiaDasRepositorio
{
    private const COLUNAS = 'id, cnpj, competencia, valor, vencimento, linha_digitavel, nome_arquivo, enviado_por, enviado_em';
    /** Colunas do script 0016 (pagamento). Sem ele, voltam vazias. */
    private const COLUNAS_PAGAMENTO = ', pago_em, pago_marcado_por';
    private const SEM_PAGAMENTO = ', NULL::date AS pago_em, NULL::text AS pago_marcado_por';

    /** @var array<int, bool> cache por conexao: o script 0016 ja foi aplicado? */
    private static array $temPagamento = [];

    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0016 (marcar como paga) ja foi aplicado? */
    public function temPagamento(): bool
    {
        return self::$temPagamento[spl_object_id($this->pdo)] ??= (bool)$this->pdo->query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'fiscal_guias_das' AND column_name = 'pago_em'"
        )->fetchColumn();
    }

    /** Colunas das listas (sem o PDF), com ou sem as do pagamento. */
    private function colunas(): string
    {
        return self::COLUNAS . ($this->temPagamento() ? self::COLUNAS_PAGAMENTO : self::SEM_PAGAMENTO);
    }

    /** Marca como paga ($data AAAA-MM-DD) ou desmarca ($data = null). */
    public function definirPagamento(int $id, ?string $data, ?string $quem): void
    {
        $this->pdo->prepare('UPDATE fiscal_guias_das SET pago_em = :data, pago_marcado_por = :quem WHERE id = :id')
            ->execute([':data' => $data, ':quem' => $data === null ? null : $quem, ':id' => $id]);
    }

    /** O script 0015 ja foi aplicado? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.fiscal_guias_das') IS NOT NULL")->fetchColumn();
    }

    /**
     * Grava a guia do mes. Se ja existir guia da empresa nesse mes, TROCA.
     *
     * @return int id da guia
     */
    public function salvar(string $cnpj, string $competencia, float $valor, string $vencimento, ?string $linha,
                           string $pdf, string $nomeArquivo, ?string $enviadoPor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_guias_das (cnpj, competencia, valor, vencimento, linha_digitavel, pdf, nome_arquivo, enviado_por)
             VALUES (:cnpj, :competencia, :valor, :vencimento, :linha, :pdf, :nome, :por)
             ON CONFLICT (cnpj, competencia) DO UPDATE
                SET valor = EXCLUDED.valor, vencimento = EXCLUDED.vencimento, linha_digitavel = EXCLUDED.linha_digitavel,
                    pdf = EXCLUDED.pdf, nome_arquivo = EXCLUDED.nome_arquivo, enviado_por = EXCLUDED.enviado_por,
                    enviado_em = CURRENT_TIMESTAMP'
            // Guia trocada (ex.: declaracao retificada) e outra guia: volta a "nao paga".
            . ($this->temPagamento() ? ', pago_em = NULL, pago_marcado_por = NULL' : '')
            . ' RETURNING id'
        );
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':competencia', $competencia);
        $stmt->bindValue(':valor', number_format($valor, 2, '.', ''));
        $stmt->bindValue(':vencimento', $vencimento);
        $stmt->bindValue(':linha', $linha);
        $stmt->bindValue(':pdf', $pdf, PDO::PARAM_LOB);
        $stmt->bindValue(':nome', mb_substr($nomeArquivo, 0, 200));
        $stmt->bindValue(':por', $enviadoPor);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . $this->colunas() . ' FROM fiscal_guias_das WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::formatar($linha);
    }

    /** Conteudo do PDF (null se a guia nao existir). */
    public function pdf(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT pdf FROM fiscal_guias_das WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $pdf = $stmt->fetchColumn();
        if ($pdf === false) {
            return null;
        }
        // O PostgreSQL devolve BYTEA como "stream".
        return is_resource($pdf) ? (string)stream_get_contents($pdf) : (string)$pdf;
    }

    public function remover(int $id): void
    {
        $this->pdo->prepare('DELETE FROM fiscal_guias_das WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Guias de um mes, de todas as empresas (tela Fechamento do mes).
     *
     * @return array<string, array> cnpj => guia
     */
    public function doMes(string $competencia): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . $this->colunas() . ' FROM fiscal_guias_das WHERE competencia = :c');
        $stmt->execute([':c' => $competencia]);
        $saida = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[(string)$l['cnpj']] = self::formatar($l);
        }
        return $saida;
    }

    /**
     * Guias de uma empresa, da mais nova para a mais antiga (portal).
     *
     * @return list<array>
     */
    public function daEmpresa(string $cnpj, int $limite = 24): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . $this->colunas() . ' FROM fiscal_guias_das WHERE cnpj = :cnpj ORDER BY competencia DESC LIMIT :limite');
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'formatar'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function formatar(array $l): array
    {
        $l['id'] = (int)$l['id'];
        $l['valor'] = (float)$l['valor'];
        return $l;
    }
}
