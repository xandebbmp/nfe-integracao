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

    /** @return list<array> ultimas notas de servico da empresa, mais recentes primeiro */
    public function notas(string $cnpj, int $limite = 10): array
    {
        $documentos = (new DocumentoRepositorio($this->pdo))->listar(['emitente_cnpj' => $cnpj], $limite * 2);
        $saida = [];
        foreach ($documentos as $d) {
            if (!in_array($d['padrao'], Padrao::NFSE, true) || (int)$d['tp_amb'] !== $this->tpAmb) {
                continue;
            }
            $apresentado = DocumentoApresentador::documento($d);
            $payload = is_array($d['payload'] ?? null) ? $d['payload'] : [];
            $mensagem = $apresentado['mensagens'][0] ?? null;
            $saida[] = [
                'id' => $d['id'],
                'numero' => $apresentado['nfse']['numero'] ?? null,
                'dps_rps' => $apresentado['dps_rps']['numero'] ?? null,
                'tomador' => (string)($payload['tomador']['nome'] ?? ''),
                'valor' => (float)($payload['valores']['valor_servicos'] ?? 0),
                'status' => $d['status'],
                'finalizado' => $apresentado['finalizado'],
                'motivo' => is_array($mensagem)
                    ? trim(($mensagem['mensagem'] ?? '') . ' ' . ($mensagem['correcao'] ?? ''))
                    : ($d['mensagem_retorno'] ?? null),
                'criado_em' => $d['criado_em'],
                'links' => self::links($d['id'], $apresentado['links']['pdf'] !== null),
            ];
            if (count($saida) >= $limite) {
                break;
            }
        }
        return $saida;
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
