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

    public function __construct(private PDO $pdo)
    {
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
                    enviado_em = CURRENT_TIMESTAMP
             RETURNING id'
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
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM fiscal_guias_das WHERE id = :id');
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
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM fiscal_guias_das WHERE competencia = :c');
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
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM fiscal_guias_das WHERE cnpj = :cnpj ORDER BY competencia DESC LIMIT :limite');
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
