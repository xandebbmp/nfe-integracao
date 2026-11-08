<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\GuiaDasRepositorio;

/**
 * Fechamento do mes (painel admin, botao "Fechamento do mes").
 *
 * No fim do mes o contador ve, para cada empresa, quanto ela faturou com as
 * notas emitidas pelo sistema. Com isso ele confere e faz a declaracao do
 * Simples (PGDAS-D) no site da Receita, que gera a guia DAS.
 *
 * O "DAS estimado" e so uma ESTIMATIVA: receita do mes x percentual
 * aproximado do Simples cadastrado na empresa (o mesmo usado na nota).
 * O valor certo sai do PGDAS-D, que considera a faixa dos ultimos 12 meses,
 * o anexo de cada atividade, o fator R e receitas fora do sistema.
 */
final class FechamentoMes
{
    /** opcao_simples_nacional do cadastro (mesmos codigos da NFS-e Nacional). */
    public const SIMPLES = [1 => 'Não optante', 2 => 'MEI', 3 => 'ME/EPP'];

    public function __construct(private PDO $pdo, private int $tpAmb)
    {
    }

    /**
     * Uma linha por empresa cadastrada (+ emitentes que estao so no arquivo
     * de configuracao) e o total geral.
     *
     * @param string $mes AAAA-MM
     */
    public function montar(string $mes): array
    {
        $clientes = new ClienteRepositorio($this->pdo);
        $receitas = $clientes->receitaPorMes($mes, $this->tpAmb);
        // Guia DAS ja anexada pelo contador (script 0015; sem ele, nenhuma).
        $repoGuias = new GuiaDasRepositorio($this->pdo);
        $guiasInstalado = $repoGuias->existe();
        $guias = $guiasInstalado ? $repoGuias->doMes($mes) : [];

        $linhas = [];
        foreach ($clientes->listar() as $c) {
            $linhas[] = self::linha($c['cnpj'], $c['razao_social'], (int)$c['opcao_simples_nacional'],
                $c['percentual_tributos_simples'], $receitas[$c['cnpj']] ?? []) + ['guia' => $guias[$c['cnpj']] ?? null];
            unset($receitas[$c['cnpj']]);
        }
        foreach ($receitas as $cnpj => $r) {
            $linhas[] = self::linha((string)$cnpj, '(fora do cadastro)', null, null, $r) + ['guia' => null];
        }

        return [
            'mes' => $mes,
            'guias_instalado' => $guiasInstalado,
            'empresas' => $linhas,
            'totais' => [
                'notas' => array_sum(array_column($linhas, 'notas')),
                'receita_mes' => round(array_sum(array_column($linhas, 'receita_mes')), 2),
                'das_estimado' => round(array_sum(array_map(static fn($l) => $l['das_estimado'] ?? 0, $linhas)), 2),
            ],
        ];
    }

    /**
     * Junta os numeros do banco com o cadastro e calcula a estimativa.
     *
     * @param array $r saida de ClienteRepositorio::receitaPorMes() para o CNPJ (vazio = sem notas)
     */
    public static function linha(string $cnpj, string $razao, ?int $simples, mixed $percentual, array $r): array
    {
        $receita = round((float)($r['nfse_valor'] ?? 0) + (float)($r['nfe_valor'] ?? 0), 2);
        $percentual = $percentual === null || $percentual === '' ? null : (float)$percentual;

        // So ME/EPP tem DAS calculado sobre a receita. MEI paga valor fixo (DAS-MEI)
        // e nao optante nao tem DAS. Sem percentual cadastrado, nao da para estimar.
        $estimado = ($simples === 3 && $percentual !== null) ? round($receita * $percentual / 100, 2) : null;

        return [
            'cnpj' => $cnpj,
            'razao_social' => $razao,
            'simples' => $simples !== null ? (self::SIMPLES[$simples] ?? null) : null,
            'nfse' => ['quantidade' => (int)($r['nfse_qtd'] ?? 0), 'valor' => (float)($r['nfse_valor'] ?? 0)],
            'nfe' => ['quantidade' => (int)($r['nfe_qtd'] ?? 0), 'valor' => (float)($r['nfe_valor'] ?? 0)],
            'notas' => (int)($r['nfse_qtd'] ?? 0) + (int)($r['nfe_qtd'] ?? 0),
            'canceladas' => (int)($r['canceladas'] ?? 0),
            'receita_mes' => $receita,
            'receita_12_meses' => (float)($r['rbt12'] ?? 0),
            'percentual_simples' => $percentual,
            'das_estimado' => $estimado,
        ];
    }
}
