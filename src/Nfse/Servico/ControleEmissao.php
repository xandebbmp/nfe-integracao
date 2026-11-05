<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;

/**
 * Regras de emissao por empresa cliente (cadastro do painel admin).
 *
 * Ordem das checagens (a primeira que falhar e a resposta):
 *
 *   1. BLOQUEADA        emissao_liberada = false (bloqueio manual)
 *   2. VENCIDA          hoje passou de liberado_ate
 *   3. TIPO_NAO_CONTRATADO  padrao fora de padroes_permitidos
 *   4. LIMITE_MENSAL    franquia do mes atingida (autorizadas no mes +
 *                       em andamento + as aceitas neste mesmo lote)
 *
 * So vale para CNPJs cadastrados em fiscal_clientes. Emitentes que estao so
 * no config/nfse.local.php nao tem essas regras.
 *
 * Bloqueio NAO impede consulta, PDF, XML nem cancelamento: so novas notas.
 */
final class ControleEmissao
{
    public const LIBERADA = 'LIBERADA';
    public const BLOQUEADA = 'BLOQUEADA';
    public const VENCIDA = 'VENCIDA';
    public const LIMITE_ATINGIDO = 'LIMITE_ATINGIDO';

    private ClienteRepositorio $clientes;

    public function __construct(PDO $pdo, private int $tpAmb)
    {
        $this->clientes = new ClienteRepositorio($pdo);
    }

    /**
     * Situacao geral de um cliente (painel e bloqueio da requisicao inteira).
     *
     * @return array{situacao: string, mensagem: string, consumo_mes: int}
     */
    public function situacao(array $cliente): array
    {
        $consumo = $this->clientes->consumoDoMes($cliente['cnpj'], $this->tpAmb);

        if (!$cliente['emissao_liberada']) {
            $motivo = rtrim(trim((string)($cliente['motivo_bloqueio'] ?? '')), '.');
            return ['situacao' => self::BLOQUEADA, 'consumo_mes' => $consumo,
                'mensagem' => 'Emissao bloqueada' . ($motivo !== '' ? ": {$motivo}." : '.') . ' Fale com o suporte para liberar.'];
        }
        if (!empty($cliente['liberado_ate']) && self::hoje() > (string)$cliente['liberado_ate']) {
            return ['situacao' => self::VENCIDA, 'consumo_mes' => $consumo,
                'mensagem' => 'Periodo de uso vencido em ' . self::dataBr((string)$cliente['liberado_ate']) . '. Fale com o suporte para renovar.'];
        }
        $limite = $cliente['limite_mensal'] ?? null;
        if ($limite !== null && $consumo >= (int)$limite) {
            return ['situacao' => self::LIMITE_ATINGIDO, 'consumo_mes' => $consumo,
                'mensagem' => "Limite mensal de {$limite} notas atingido. Fale com o suporte para ampliar o plano."];
        }
        return ['situacao' => self::LIBERADA, 'consumo_mes' => $consumo, 'mensagem' => 'Emissao liberada.'];
    }

    /**
     * Bloqueio da requisicao inteira (HTTP 403) para o token de um cliente:
     * bloqueio manual ou vencimento. Limite e tipo sao conferidos por documento.
     *
     * @return array{codigo: string, mensagem: string}|null
     */
    public function bloqueioDoCliente(string $cnpj): ?array
    {
        $cliente = $this->cliente($cnpj);
        if ($cliente === null) {
            return null;
        }
        $s = $this->situacao($cliente);
        return in_array($s['situacao'], [self::BLOQUEADA, self::VENCIDA], true)
            ? ['codigo' => 'EMISSAO_' . $s['situacao'], 'mensagem' => $s['mensagem']]
            : null;
    }

    /**
     * Pode emitir este documento? $aceitosNoLote = quantos deste CNPJ ja foram
     * aceitos no mesmo lote (contam para a franquia).
     *
     * @return array{codigo: string, campo: string, mensagem: string, correcao: string}|null
     */
    public function recusaDocumento(string $cnpj, string $padrao, int $aceitosNoLote): ?array
    {
        $cliente = $this->cliente($cnpj);
        if ($cliente === null) {
            return null; // emitente so do arquivo de configuracao: sem regras
        }

        $s = $this->situacao($cliente);
        if (in_array($s['situacao'], [self::BLOQUEADA, self::VENCIDA], true)) {
            return ['codigo' => 'EMISSAO_' . $s['situacao'], 'campo' => 'emitente_cnpj', 'mensagem' => $s['mensagem'],
                'correcao' => 'Regularize o cadastro da empresa com o suporte e reenvie com a mesma referencia.'];
        }

        if (!in_array($padrao, (array)$cliente['padroes_permitidos'], true)) {
            return ['codigo' => 'TIPO_NAO_CONTRATADO', 'campo' => 'padrao',
                'mensagem' => "Tipo de nota {$padrao} nao contratado para esta empresa.",
                'correcao' => 'Tipos liberados: ' . (implode(', ', (array)$cliente['padroes_permitidos']) ?: 'nenhum') . '. Fale com o suporte para incluir.'];
        }

        $limite = $cliente['limite_mensal'] ?? null;
        if ($limite !== null && $s['consumo_mes'] + $aceitosNoLote >= (int)$limite) {
            return ['codigo' => 'LIMITE_MENSAL', 'campo' => 'emitente_cnpj',
                'mensagem' => "Limite mensal de {$limite} notas atingido (usadas: " . ($s['consumo_mes'] + $aceitosNoLote) . ').',
                'correcao' => 'Aguarde o proximo mes ou peca ao suporte para ampliar o limite; depois reenvie com a mesma referencia.'];
        }

        return null;
    }

    /** Sempre le do banco: o painel pode ter acabado de bloquear/liberar. */
    private function cliente(string $cnpj): ?array
    {
        return $this->clientes->existe() ? $this->clientes->buscar($cnpj) : null;
    }

    private static function hoje(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Bahia')))->format('Y-m-d');
    }

    private static function dataBr(string $data): string
    {
        return (new DateTimeImmutable($data))->format('d/m/Y');
    }
}
