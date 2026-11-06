<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nacional;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Xande\NfeIntegracao\Nfse\Infra\Xml;

/**
 * Monta o XML da DPS (Declaracao de Prestacao de Servico) do Padrao Nacional,
 * a partir do JSON padronizado da API.
 *
 * A ORDEM dos elementos segue o XSD (DPS_v1.01.xsd / tiposComplexos).
 * Se mudar a ordem, o XSD rejeita. Rode os testes depois de qualquer ajuste.
 *
 * Regras importantes aplicadas aqui:
 *   - Quando o emitente e o proprio prestador (tpEmit=1), NAO informamos
 *     nome/endereco do prestador: o Sefin usa o cadastro nacional.
 *   - dhEmi recebe 1 minuto a menos que o relogio do servidor, para evitar
 *     rejeicao "data de emissao posterior ao processamento" por diferenca de
 *     relogio entre nosso servidor e o Sefin.
 */
final class DpsXmlBuilder
{
    public const NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';
    public const VERSAO_APLICATIVO = 'NfePHP-NFSe-1.0';

    public function __construct(private array $cfg)
    {
    }

    /**
     * Id da DPS: "DPS" + municipio(7) + tipo inscricao(1) + inscricao(14) + serie(5) + numero(15).
     * Tambem e usado para consultar a DPS no Sefin (GET /dps/{id}).
     */
    public static function montarIdDps(string $codigoMunicipio, string $cnpj, string $serie, int $numero): string
    {
        return 'DPS'
            . str_pad($codigoMunicipio, 7, '0', STR_PAD_LEFT)
            . '2' // 2 = CNPJ (1 seria CPF)
            . str_pad($cnpj, 14, '0', STR_PAD_LEFT)
            . str_pad($serie, 5, '0', STR_PAD_LEFT)
            . str_pad((string)$numero, 15, '0', STR_PAD_LEFT);
    }

    /**
     * @param array $documento linha de nfse_documentos (payload ja decodificado)
     * @return array{xml: string, id_dps: string} XML ainda NAO assinado
     */
    public function montar(array $documento, ?DateTimeImmutable $agora = null): array
    {
        $p = $documento['payload'];
        $emitente = $this->cfg['emitente'];
        $serie = (string)$documento['serie'];
        $numero = (int)$documento['numero'];

        $idDps = self::montarIdDps(
            (string)$emitente['codigo_municipio'],
            (string)$emitente['cnpj'],
            $serie,
            $numero
        );

        $fuso = new DateTimeZone('America/Bahia');
        $agora = ($agora ?? new DateTimeImmutable('now', $fuso))->setTimezone($fuso)->modify('-1 minute');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dps = $dom->createElementNS(self::NAMESPACE, 'DPS');
        $dps->setAttribute('versao', (string)$this->cfg['nacional']['versao_leiaute']);
        $dom->appendChild($dps);

        $inf = Xml::filho($dom, $dps, 'infDPS');
        $inf->setAttribute('Id', $idDps);

        Xml::filho($dom, $inf, 'tpAmb', (string)$this->cfg['tpAmb']);
        Xml::filho($dom, $inf, 'dhEmi', $agora->format('Y-m-d\TH:i:sP'));
        Xml::filho($dom, $inf, 'verAplic', self::VERSAO_APLICATIVO);
        Xml::filho($dom, $inf, 'serie', $serie);
        Xml::filho($dom, $inf, 'nDPS', (string)$numero);
        Xml::filho($dom, $inf, 'dCompet', (string)($p['competencia'] ?? $agora->format('Y-m-d')));
        Xml::filho($dom, $inf, 'tpEmit', '1'); // 1 = prestador
        // Finalidade da NFS-e: obrigatoria no XSD de 20/10/2026 (hoje so na
        // Producao Restrita; a producao ainda recusa o campo). 0 = NFS-e regular.
        if (self::leiaute($this->cfg)['finNFSe']) {
            Xml::filho($dom, $inf, 'finNFSe', '0');
        }
        Xml::filho($dom, $inf, 'cLocEmi', (string)$emitente['codigo_municipio']);

        $this->montarSubstituicao($dom, $inf, $p);
        $this->montarPrestador($dom, $inf, $emitente);
        $this->montarTomador($dom, $inf, $p['tomador'] ?? null);
        $this->montarServico($dom, $inf, $p['servico'], (string)$emitente['codigo_municipio']);
        $this->montarValores($dom, $inf, self::valoresComPadraoDoEmitente($p['valores'], $emitente), $emitente);

        return ['xml' => $dom->saveXML($dom->documentElement), 'id_dps' => $idDps];
    }

    /**
     * Leiaute do ambiente (config nacional.leiaute_por_ambiente):
     * ['xsd' => pasta em schemas/nfse/nacional, 'finNFSe' => envia o campo?].
     */
    public static function leiaute(array $cfg): array
    {
        $tpAmb = (int)$cfg['tpAmb'];
        $padrao = ['xsd' => (string)$cfg['nacional']['versao_leiaute'], 'finNFSe' => true];
        return ($cfg['nacional']['leiaute_por_ambiente'][$tpAmb] ?? []) + $padrao;
    }

    /**
     * ME/EPP (opSimpNac 3) com o ISS apurado no Simples (regApTribSN 1) e sem
     * retencao: o Sefin recusa pAliq (o ISS vai no DAS). A aliquota fica so
     * nos dados da nota, para mostrar na tela.
     */
    public static function issPeloSimplesSemRetencao(array $valores, array $emitente): bool
    {
        return (int)($emitente['opcao_simples_nacional'] ?? 1) === 3
            && (int)($emitente['regime_apuracao_simples'] ?? 0) === 1
            && empty($valores['iss_retido']);
    }

    /**
     * Percentual de tributos do Simples (pTotTribSN): vale o da nota; se a
     * nota nao trouxer, usa o do cadastro da empresa (informado pelo contador).
     */
    public static function valoresComPadraoDoEmitente(array $valores, array $emitente): array
    {
        $daNota = $valores['percentual_tributos_simples'] ?? null;
        $doCadastro = $emitente['percentual_tributos_simples'] ?? null;
        if (($daNota === null || $daNota === '') && $doCadastro !== null && $doCadastro !== '') {
            $valores['percentual_tributos_simples'] = $doCadastro;
        }
        return $valores;
    }

    private function montarSubstituicao(DOMDocument $dom, DOMElement $inf, array $p): void
    {
        $sub = $p['substituicao'] ?? null;
        if (!is_array($sub) || empty($sub['chave_substituida'])) {
            return;
        }

        $subst = Xml::filho($dom, $inf, 'subst');
        Xml::filho($dom, $subst, 'chSubstda', (string)$sub['chave_substituida']);
        Xml::filho($dom, $subst, 'cMotivo', str_pad((string)$sub['codigo_motivo'], 2, '0', STR_PAD_LEFT));
        Xml::filhoOpcional($dom, $subst, 'xMotivo', $sub['motivo'] ?? null);
    }

    private function montarPrestador(DOMDocument $dom, DOMElement $inf, array $emitente): void
    {
        $prest = Xml::filho($dom, $inf, 'prest');
        Xml::filho($dom, $prest, 'CNPJ', (string)$emitente['cnpj']);
        Xml::filhoOpcional($dom, $prest, 'IM', $emitente['inscricao_municipal'] ?? null);
        Xml::filhoOpcional($dom, $prest, 'fone', Xml::somenteDigitos($emitente['telefone'] ?? null));
        Xml::filhoOpcional($dom, $prest, 'email', $emitente['email'] ?? null);

        $regTrib = Xml::filho($dom, $prest, 'regTrib');
        $opSimples = (int)($emitente['opcao_simples_nacional'] ?? 1);
        Xml::filho($dom, $regTrib, 'opSimpNac', (string)$opSimples);
        if ($opSimples === 3 && !empty($emitente['regime_apuracao_simples'])) {
            Xml::filho($dom, $regTrib, 'regApTribSN', (string)$emitente['regime_apuracao_simples']);
        }
        Xml::filho($dom, $regTrib, 'regEspTrib', (string)(int)($emitente['regime_especial_tributacao'] ?? 0));
    }

    private function montarTomador(DOMDocument $dom, DOMElement $inf, ?array $tomador): void
    {
        if (empty($tomador)) {
            return; // Tomador e opcional no Nacional (ex.: consumidor final nao identificado).
        }

        $toma = Xml::filho($dom, $inf, 'toma');
        $documento = Xml::somenteDigitos($tomador['cpf_cnpj'] ?? null) ?? '';
        Xml::filho($dom, $toma, strlen($documento) === 11 ? 'CPF' : 'CNPJ', $documento);
        Xml::filhoOpcional($dom, $toma, 'IM', $tomador['inscricao_municipal'] ?? null);
        Xml::filho($dom, $toma, 'xNome', (string)$tomador['nome']);

        $endereco = $tomador['endereco'] ?? null;
        if (is_array($endereco) && !empty($endereco['logradouro'])) {
            $end = Xml::filho($dom, $toma, 'end');
            $endNac = Xml::filho($dom, $end, 'endNac');
            Xml::filho($dom, $endNac, 'cMun', (string)$endereco['codigo_municipio']);
            Xml::filho($dom, $endNac, 'CEP', (string)Xml::somenteDigitos($endereco['cep'] ?? ''));
            Xml::filho($dom, $end, 'xLgr', (string)$endereco['logradouro']);
            Xml::filho($dom, $end, 'nro', (string)($endereco['numero'] ?? 'S/N'));
            Xml::filhoOpcional($dom, $end, 'xCpl', $endereco['complemento'] ?? null);
            Xml::filho($dom, $end, 'xBairro', (string)$endereco['bairro']);
        }

        Xml::filhoOpcional($dom, $toma, 'fone', Xml::somenteDigitos($tomador['telefone'] ?? null));
        Xml::filhoOpcional($dom, $toma, 'email', $tomador['email'] ?? null);
    }

    private function montarServico(DOMDocument $dom, DOMElement $inf, array $servico, string $municipioEmitente): void
    {
        $serv = Xml::filho($dom, $inf, 'serv');

        $locPrest = Xml::filho($dom, $serv, 'locPrest');
        Xml::filho($dom, $locPrest, 'cLocPrestacao', (string)($servico['codigo_municipio_prestacao'] ?? $municipioEmitente));

        $cServ = Xml::filho($dom, $serv, 'cServ');
        Xml::filho($dom, $cServ, 'cTribNac', (string)$servico['codigo_tributacao_nacional']);
        Xml::filhoOpcional($dom, $cServ, 'cTribMun', $servico['codigo_tributacao_municipal'] ?? null);
        Xml::filho($dom, $cServ, 'xDescServ', (string)$servico['descricao']);
        Xml::filhoOpcional($dom, $cServ, 'cNBS', $servico['codigo_nbs'] ?? null);
    }

    private function montarValores(DOMDocument $dom, DOMElement $inf, array $valores, array $emitente = []): void
    {
        $val = Xml::filho($dom, $inf, 'valores');

        $vServPrest = Xml::filho($dom, $val, 'vServPrest');
        Xml::filho($dom, $vServPrest, 'vServ', Xml::decimal($valores['valor_servicos']));

        $descIncond = (float)($valores['desconto_incondicionado'] ?? 0);
        $descCond = (float)($valores['desconto_condicionado'] ?? 0);
        if ($descIncond > 0 || $descCond > 0) {
            $desc = Xml::filho($dom, $val, 'vDescCondIncond');
            if ($descIncond > 0) {
                Xml::filho($dom, $desc, 'vDescIncond', Xml::decimal($descIncond));
            }
            if ($descCond > 0) {
                Xml::filho($dom, $desc, 'vDescCond', Xml::decimal($descCond));
            }
        }

        $trib = Xml::filho($dom, $val, 'trib');

        // --- ISSQN (municipal) ---
        $tribMun = Xml::filho($dom, $trib, 'tribMun');
        // 1=Operacao tributavel, 2=Imunidade, 3=Exportacao, 4=Nao incidencia
        Xml::filho($dom, $tribMun, 'tribISSQN', (string)(int)($valores['tributacao_issqn'] ?? 1));
        // 1=Nao retido, 2=Retido pelo tomador, 3=Retido pelo intermediario
        Xml::filho($dom, $tribMun, 'tpRetISSQN', !empty($valores['iss_retido']) ? '2' : '1');
        if (isset($valores['aliquota_iss']) && $valores['aliquota_iss'] !== null && $valores['aliquota_iss'] !== ''
            && !self::issPeloSimplesSemRetencao($valores, $emitente)) {
            Xml::filho($dom, $tribMun, 'pAliq', Xml::decimal($valores['aliquota_iss']));
        }

        // --- Retencoes federais (somente se informadas) ---
        $retencoes = [
            'vRetCP' => $valores['valor_inss'] ?? null,
            'vRetIRRF' => $valores['valor_ir'] ?? null,
            'vRetCSLL' => $valores['valor_csll'] ?? null,
        ];
        $retencoes = array_filter($retencoes, static fn($v) => $v !== null && (float)$v > 0);
        if ($retencoes !== []) {
            $tribFed = Xml::filho($dom, $trib, 'tribFed');
            foreach ($retencoes as $tag => $valor) {
                Xml::filho($dom, $tribFed, $tag, Xml::decimal($valor));
            }
        }

        // --- Total aproximado de tributos (Lei 12.741) ---
        $totTrib = Xml::filho($dom, $trib, 'totTrib');
        if (isset($valores['percentual_tributos_simples'])) {
            Xml::filho($dom, $totTrib, 'pTotTribSN', Xml::decimal($valores['percentual_tributos_simples']));
        } else {
            // 0 = nao informar o valor aproximado dos tributos.
            Xml::filho($dom, $totTrib, 'indTotTrib', '0');
        }
    }
}
