<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;

/**
 * Tela "Minha empresa" do portal: o cadastro da empresa como o painel admin
 * guardou, so para ver. Quem muda e o suporte, no painel admin.
 *
 * Nunca devolve segredos (certificado, token, segredo do webhook).
 * Os codigos fiscais vao com o texto pronto para a tela.
 */
final class PortalEmpresa
{
    /** Opcao pelo Simples Nacional (opSimpNac do Padrao Nacional). */
    public const SIMPLES = [1 => 'Não optante', 2 => 'MEI', 3 => 'Microempresa ou EPP'];

    /** Regime de apuracao de quem e ME/EPP (regApTribSN). */
    public const APURACAO = [
        1 => 'Tributos federais e ISS pelo Simples Nacional',
        2 => 'Tributos federais pelo Simples, ISS fora dele',
        3 => 'Tributos federais e ISS fora do Simples',
    ];

    /** Regime especial de tributacao da NFS-e Nacional (regEspTrib). */
    public const REGIME_ESPECIAL = [
        0 => 'Nenhum',
        1 => 'Ato cooperado (cooperativa)',
        2 => 'Estimativa',
        3 => 'Microempresa municipal',
        4 => 'Notário ou registrador',
        5 => 'Profissional autônomo',
        6 => 'Sociedade de profissionais',
    ];

    /** Texto da situacao de emissao para o cliente (regras em ControleEmissao). */
    private const SITUACAO = [
        ControleEmissao::LIBERADA => 'Liberada',
        ControleEmissao::BLOQUEADA => 'Bloqueada',
        ControleEmissao::VENCIDA => 'Período de uso vencido',
        ControleEmissao::LIMITE_ATINGIDO => 'Limite do mês atingido',
    ];

    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    /** @return array|null null = empresa nao cadastrada */
    public function ficha(string $cnpj): ?array
    {
        $c = (new ClienteRepositorio($this->pdo))->buscar($cnpj);
        if ($c === null) {
            return null;
        }
        $tpAmb = (int)$this->cfg['tpAmb'];
        $situacao = (new ControleEmissao($this->pdo, $tpAmb))->situacao($c);

        $validade = $c['certificado_validade'] ?? null;
        $dias = $validade !== null ? (int)floor((strtotime((string)$validade) - time()) / 86400) : null;
        $simples = (int)$c['opcao_simples_nacional'];
        $apuracao = $c['regime_apuracao_simples'] !== null ? (int)$c['regime_apuracao_simples'] : null;

        return [
            'cadastro' => [
                'cnpj' => $c['cnpj'],
                'razao_social' => $c['razao_social'],
                'nome_fantasia' => $c['nome_fantasia'] ?? null,
                'inscricao_municipal' => $c['inscricao_municipal'] ?? null,
                'inscricao_estadual' => $c['ie'] ?? null,
                'codigo_municipio' => $c['codigo_municipio'],
                'municipio' => $c['codigo_municipio'] === '2927408' ? 'Salvador / BA' : null,
                'email' => $c['email'] ?? null,
                'telefone' => $c['telefone'] ?? null,
            ],
            'fiscal' => [
                'simples_nacional' => self::SIMPLES[$simples] ?? (string)$simples,
                // A apuracao so faz sentido para ME/EPP.
                'apuracao' => $simples === 3 && $apuracao !== null ? (self::APURACAO[$apuracao] ?? (string)$apuracao) : null,
                'regime_especial' => self::REGIME_ESPECIAL[(int)$c['regime_especial_tributacao']] ?? (string)$c['regime_especial_tributacao'],
                'incentivador_cultural' => (bool)$c['incentivador_cultural'],
                'padroes' => array_values(array_intersect([Padrao::NACIONAL, Padrao::SALVADOR], $c['padroes_permitidos'] ?? [])),
                'padrao_portal' => (new PortalEmissao($this->pdo, $this->cfg))->padraoDaEmpresa($c),
                'ambiente' => $tpAmb === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
            ],
            'certificado' => [
                'enviado' => (bool)($c['tem_certificado'] ?? false),
                'titular' => $c['certificado_titular'] ?? null,
                'validade' => $validade !== null ? substr((string)$validade, 0, 10) : null,
                'dias_restantes' => $dias,
                'atualizado_em' => isset($c['certificado_atualizado_em']) ? substr((string)$c['certificado_atualizado_em'], 0, 10) : null,
            ],
            'plano' => [
                'nome' => $c['plano'] ?? null,
                'limite_mensal' => $c['limite_mensal'] !== null ? (int)$c['limite_mensal'] : null,
                'usado_no_mes' => $situacao['consumo_mes'],
                'liberado_ate' => $c['liberado_ate'] ?? null,
                'situacao' => $situacao['situacao'],
                'situacao_texto' => self::SITUACAO[$situacao['situacao']] ?? $situacao['situacao'],
                'motivo_bloqueio' => $situacao['situacao'] === ControleEmissao::BLOQUEADA ? ($c['motivo_bloqueio'] ?? null) : null,
            ],
        ];
    }
}
