<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Dados que o portal mostra fora do formulario:
 *
 *   empresa()  topo e menu: razao social, CNPJ, ambiente, certificado
 *   resumo()   tela inicial: numeros do mes e o que precisa de atencao
 *   notas()    ultimas notas da empresa (de qualquer origem: portal ou API)
 *   listarNotas()  tela "Minhas notas" (busca, filtros, paginas)
 *   nota()     detalhe de uma nota
 */
final class PortalInicio
{
    private ClienteRepositorio $clientes;
    private int $tpAmb;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->clientes = new ClienteRepositorio($pdo);
        $this->tpAmb = (int)$cfg['tpAmb'];
    }

    public function empresa(string $cnpj): ?array
    {
        $c = $this->clientes->buscar($cnpj);
        if ($c === null) {
            return null;
        }
        $validade = $c['certificado_validade'] ?? null;
        $dias = $validade !== null ? (int)floor((strtotime((string)$validade) - time()) / 86400) : null;

        return [
            'cnpj' => $c['cnpj'],
            'razao_social' => $c['razao_social'],
            'nome_fantasia' => $c['nome_fantasia'] ?? null,
            'padrao' => (new PortalEmissao($this->pdo, $this->cfg))->padraoDaEmpresa($c),
            'ambiente' => $this->tpAmb === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
            'certificado' => [
                'validade' => $validade !== null ? substr((string)$validade, 0, 10) : null,
                'dias_restantes' => $dias,
            ],
        ];
    }

    public function resumo(string $cnpj): array
    {
        $cliente = $this->clientes->buscar($cnpj) ?? [];
        $status = $this->clientes->statusDoMes($cnpj, $this->tpAmb);
        $empresa = $this->empresa($cnpj);

        $avisos = [];
        foreach ($this->notas($cnpj, 50) as $nota) {
            if (in_array($nota['status'], [StatusNfse::REJEITADO, StatusNfse::ERRO], true) && count($avisos) < 3) {
                $avisos[] = [
                    'tipo' => 'NOTA_REJEITADA',
                    'titulo' => 'Nota para ' . ($nota['tomador'] ?: 'tomador sem nome') . ' não foi autorizada',
                    'texto' => $nota['motivo'] ?? 'Abra a nota para ver o motivo.',
                    'id' => $nota['id'],
                ];
            }
        }
        $dias = $empresa['certificado']['dias_restantes'] ?? null;
        if ($dias === null) {
            $avisos[] = ['tipo' => 'SEM_CERTIFICADO', 'titulo' => 'Certificado digital não enviado', 'texto' => 'Sem ele as notas não são assinadas. Fale com o suporte.'];
        } elseif ($dias <= 30) {
            $avisos[] = ['tipo' => 'CERTIFICADO_VENCENDO', 'titulo' => $dias < 0 ? 'Certificado vencido' : "Certificado vence em {$dias} dias",
                'texto' => 'Renove o certificado A1 e envie ao suporte para não parar de emitir.'];
        }

        return [
            'mes' => AdministracaoClientes::mesAtual(),
            'notas_no_mes' => array_sum($status),
            'por_status' => $status,
            'em_andamento' => ($status[StatusNfse::RECEBIDO] ?? 0) + ($status[StatusNfse::PROCESSANDO] ?? 0),
            'faturado_no_mes' => (new PortalRepositorio($this->pdo))->faturadoDoMes($cnpj, $this->tpAmb),
            'franquia' => [
                'usado' => $this->clientes->consumoDoMes($cnpj, $this->tpAmb),
                'limite' => isset($cliente['limite_mensal']) ? (int)$cliente['limite_mensal'] : null,
            ],
            'avisos' => $avisos,
        ];
    }

    /** Grupos de status do filtro da tela "Minhas notas". */
    public const FILTROS_STATUS = [
        'AUTORIZADAS' => [StatusNfse::AUTORIZADO],
        'EM_ANDAMENTO' => StatusNfse::EM_ANDAMENTO,
        'COM_PROBLEMA' => [StatusNfse::REJEITADO, StatusNfse::ERRO, StatusNfse::DENEGADO],
        'CANCELADAS' => [StatusNfse::CANCELADO, StatusNfse::SUBSTITUIDO],
    ];

    public const POR_PAGINA = 20;

    /** @return list<array> ultimas notas de servico da empresa, mais recentes primeiro */
    public function notas(string $cnpj, int $limite = 10): array
    {
        $r = (new PortalRepositorio($this->pdo))->notas($cnpj, $this->tpAmb, Padrao::NFSE, [], '', '', $limite, 0);
        return array_map([self::class, 'resumoNota'], $r['notas']);
    }

    /**
     * Tela "Minhas notas": busca, filtro por situacao e mes, paginas de 20.
     *
     * @param array{status?: string, busca?: string, mes?: string, pagina?: int|string} $filtros
     */
    public function listarNotas(string $cnpj, array $filtros): array
    {
        $grupo = (string)($filtros['status'] ?? '');
        $pagina = max(1, (int)($filtros['pagina'] ?? 1));
        $r = (new PortalRepositorio($this->pdo))->notas(
            $cnpj,
            $this->tpAmb,
            Padrao::NFSE,
            self::FILTROS_STATUS[$grupo] ?? [],
            mb_substr(trim((string)($filtros['busca'] ?? '')), 0, 60),
            (string)($filtros['mes'] ?? ''),
            self::POR_PAGINA,
            ($pagina - 1) * self::POR_PAGINA
        );
        return [
            'notas' => array_map([self::class, 'resumoNota'], $r['notas']),
            'total' => $r['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int)ceil($r['total'] / self::POR_PAGINA)),
        ];
    }

    /** Detalhe de uma nota da empresa (null = nao existe ou e de outra empresa). */
    public function nota(string $cnpj, string $id): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return null;
        }
        $d = (new DocumentoRepositorio($this->pdo))->buscar($id);
        if ($d === null || $d['emitente_cnpj'] !== $cnpj || !in_array($d['padrao'], Padrao::NFSE, true)) {
            return null;
        }
        $apresentado = DocumentoApresentador::documento($d);
        $payload = is_array($d['payload'] ?? null) ? $d['payload'] : [];
        return self::resumoNota($d) + [
            'padrao' => $d['padrao'],
            'tomador_completo' => $payload['tomador'] ?? [],
            'servico' => $payload['servico'] ?? [],
            'valores' => $payload['valores'] ?? [],
            'competencia' => $payload['competencia'] ?? null,
            'nfse' => $apresentado['nfse'],
            'mensagens' => $apresentado['mensagens'],
        ];
    }

    /** Uma linha da lista de notas. */
    private static function resumoNota(array $d): array
    {
        $apresentado = DocumentoApresentador::documento($d);
        $payload = is_array($d['payload'] ?? null) ? $d['payload'] : [];
        $mensagem = $apresentado['mensagens'][0] ?? null;
        return [
            'id' => $d['id'],
            'numero' => $apresentado['nfse']['numero'] ?? null,
            'dps_rps' => $apresentado['dps_rps']['numero'] ?? null,
            'tomador' => (string)($payload['tomador']['nome'] ?? ''),
            'tomador_documento' => (string)($payload['tomador']['cpf_cnpj'] ?? ''),
            'valor' => (float)($payload['valores']['valor_servicos'] ?? 0),
            'status' => $d['status'],
            'finalizado' => $apresentado['finalizado'],
            'motivo' => is_array($mensagem)
                ? trim(($mensagem['mensagem'] ?? '') . ' ' . ($mensagem['correcao'] ?? ''))
                : ($d['mensagem_retorno'] ?? null),
            'criado_em' => $d['criado_em'],
            'links' => self::links($d['id'], $apresentado['links']['pdf'] !== null),
        ];
    }

    /** Links de PDF/XML com a sessao do portal (public/v1/portal/arquivo.php). */
    public static function links(string $id, bool $temArquivo): array
    {
        return [
            'pdf' => $temArquivo ? '/v1/portal/arquivo.php?id=' . $id . '&formato=pdf' : null,
            'xml' => $temArquivo ? '/v1/portal/arquivo.php?id=' . $id . '&formato=xml' : null,
        ];
    }
}
