<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Salvador;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Xande\NfeIntegracao\Nfse\Infra\Xml;

/**
 * Monta os XMLs do webservice da NFS-e de Salvador (ABRASF 1.00).
 *
 * Particularidades de Salvador (ver schemas/nfse/salvador/1.00/nfse.xsd):
 *   - Todos os elementos ficam em um unico namespace (nfse.xsd).
 *   - Atributo de identificacao em minusculo: id (e nao Id).
 *   - CNPJ/CPF aceitam SOMENTE numeros (impacto no CNPJ alfanumerico!).
 *   - Tipo de RPS aceito: somente 1 (RPS).
 *   - Tomador e obrigatorio, com RazaoSocial.
 *   - Aliquota vai como fracao: 5% = 0.0500.
 *   - Item da lista de servico sem ponto: "01.01" -> "0101".
 *
 * Decisao: enviamos UM RPS POR LOTE. No ABRASF, se um RPS do lote tiver
 * erro o lote inteiro e rejeitado. Com um RPS por lote cada documento tem
 * seu proprio resultado, o que combina com o retorno "por documento" da API.
 */
final class RpsXmlBuilder
{
    public const NAMESPACE = 'http://www.abrasf.org.br/ABRASF/arquivos/nfse.xsd';

    public function __construct(private array $cfg)
    {
    }

    /**
     * @return array{dom: DOMDocument, infRps: DOMElement, rps: DOMElement, loteRps: DOMElement}
     *         Os nos sao devolvidos para o provedor assinar RPS e lote.
     */
    public function montarEnvioLote(array $documento, ?DateTimeImmutable $agora = null): array
    {
        $p = $documento['payload'];
        $emitente = $this->cfg['emitente'];
        $numero = (string)(int)$documento['numero'];

        $agora = ($agora ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('America/Bahia'));

        $dom = new DOMDocument('1.0', 'UTF-8');
        $raiz = $dom->createElementNS(self::NAMESPACE, 'EnviarLoteRpsEnvio');
        $dom->appendChild($raiz);

        $lote = Xml::filho($dom, $raiz, 'LoteRps');
        $lote->setAttribute('id', 'lote' . $numero);
        Xml::filho($dom, $lote, 'NumeroLote', $numero);
        Xml::filho($dom, $lote, 'Cnpj', (string)$emitente['cnpj']);
        Xml::filho($dom, $lote, 'InscricaoMunicipal', (string)$emitente['inscricao_municipal']);
        Xml::filho($dom, $lote, 'QuantidadeRps', '1');

        $lista = Xml::filho($dom, $lote, 'ListaRps');
        $rps = Xml::filho($dom, $lista, 'Rps');
        $inf = Xml::filho($dom, $rps, 'InfRps');
        $inf->setAttribute('id', 'rps' . $numero);

        $this->identificacaoRps($dom, $inf, $documento);
        Xml::filho($dom, $inf, 'DataEmissao', $agora->format('Y-m-d\TH:i:s'));
        // 1=Tributacao no municipio, 2=Fora do municipio, 3=Isencao, 4=Imune,
        // 5=Exigibilidade suspensa (judicial), 6=Exigibilidade suspensa (administrativo)
        Xml::filho($dom, $inf, 'NaturezaOperacao', (string)(int)($p['natureza_operacao'] ?? 1));
        Xml::filhoOpcional($dom, $inf, 'RegimeEspecialTributacao', $emitente['regime_especial_abrasf'] ?? null);
        $optante = in_array((int)($emitente['opcao_simples_nacional'] ?? 1), [2, 3], true);
        Xml::filho($dom, $inf, 'OptanteSimplesNacional', $optante ? '1' : '2');
        Xml::filho($dom, $inf, 'IncentivadorCultural', !empty($emitente['incentivador_cultural']) ? '1' : '2');
        Xml::filho($dom, $inf, 'Status', '1'); // 1 = Normal

        $this->servico($dom, $inf, $p, (string)$emitente['codigo_municipio']);

        $prest = Xml::filho($dom, $inf, 'Prestador');
        Xml::filho($dom, $prest, 'Cnpj', (string)$emitente['cnpj']);
        Xml::filho($dom, $prest, 'InscricaoMunicipal', (string)$emitente['inscricao_municipal']);

        $this->tomador($dom, $inf, $p['tomador'] ?? []);

        return ['dom' => $dom, 'infRps' => $inf, 'rps' => $rps, 'loteRps' => $lote];
    }

    public function montarConsultaSituacaoLote(string $protocolo): string
    {
        return $this->montarConsultaPorProtocolo('ConsultarSituacaoLoteRpsEnvio', $protocolo);
    }

    public function montarConsultaLote(string $protocolo): string
    {
        return $this->montarConsultaPorProtocolo('ConsultarLoteRpsEnvio', $protocolo);
    }

    public function montarConsultaNfsePorRps(array $documento): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $raiz = $dom->createElementNS(self::NAMESPACE, 'ConsultarNfseRpsEnvio');
        $dom->appendChild($raiz);
        $this->identificacaoRps($dom, $raiz, $documento);
        $this->prestador($dom, $raiz);
        return $dom->saveXML($dom->documentElement);
    }

    // -----------------------------------------------------------------

    private function montarConsultaPorProtocolo(string $raizNome, string $protocolo): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $raiz = $dom->createElementNS(self::NAMESPACE, $raizNome);
        $dom->appendChild($raiz);
        $this->prestador($dom, $raiz);
        Xml::filho($dom, $raiz, 'Protocolo', $protocolo);
        return $dom->saveXML($dom->documentElement);
    }

    private function identificacaoRps(DOMDocument $dom, DOMElement $pai, array $documento): void
    {
        $id = Xml::filho($dom, $pai, 'IdentificacaoRps');
        Xml::filho($dom, $id, 'Numero', (string)(int)$documento['numero']);
        Xml::filho($dom, $id, 'Serie', (string)$documento['serie']);
        Xml::filho($dom, $id, 'Tipo', '1'); // Salvador aceita somente 1 = RPS
    }

    private function prestador(DOMDocument $dom, DOMElement $pai): void
    {
        $prest = Xml::filho($dom, $pai, 'Prestador');
        Xml::filho($dom, $prest, 'Cnpj', (string)$this->cfg['emitente']['cnpj']);
        Xml::filho($dom, $prest, 'InscricaoMunicipal', (string)$this->cfg['emitente']['inscricao_municipal']);
    }

    private function servico(DOMDocument $dom, DOMElement $inf, array $p, string $municipioEmitente): void
    {
        $v = $p['valores'];
        $servico = $p['servico'];

        $valorServicos = (float)$v['valor_servicos'];
        $deducoes = (float)($v['deducoes'] ?? 0);
        $descIncond = (float)($v['desconto_incondicionado'] ?? 0);
        $descCond = (float)($v['desconto_condicionado'] ?? 0);
        $aliquotaPercentual = (float)($v['aliquota_iss'] ?? 0);
        $issRetido = !empty($v['iss_retido']);

        $baseCalculo = round($valorServicos - $deducoes - $descIncond, 2);
        $valorIss = round($baseCalculo * $aliquotaPercentual / 100, 2);

        $retencoesFederais = [
            'ValorPis' => (float)($v['valor_pis'] ?? 0),
            'ValorCofins' => (float)($v['valor_cofins'] ?? 0),
            'ValorInss' => (float)($v['valor_inss'] ?? 0),
            'ValorIr' => (float)($v['valor_ir'] ?? 0),
            'ValorCsll' => (float)($v['valor_csll'] ?? 0),
        ];
        $outras = (float)($v['outras_retencoes'] ?? 0);

        // Formula ABRASF do valor liquido.
        $valorLiquido = $valorServicos
            - array_sum($retencoesFederais)
            - $outras
            - ($issRetido ? $valorIss : 0)
            - $descIncond
            - $descCond;

        $serv = Xml::filho($dom, $inf, 'Servico');
        $val = Xml::filho($dom, $serv, 'Valores');

        // A ordem abaixo e a do XSD (tcValores). Nao altere.
        Xml::filho($dom, $val, 'ValorServicos', Xml::decimal($valorServicos));
        $this->valorOpcional($dom, $val, 'ValorDeducoes', $deducoes);
        foreach ($retencoesFederais as $tag => $valor) {
            $this->valorOpcional($dom, $val, $tag, $valor);
        }
        Xml::filho($dom, $val, 'IssRetido', $issRetido ? '1' : '2');
        Xml::filho($dom, $val, 'ValorIss', Xml::decimal($valorIss));
        if ($issRetido) {
            Xml::filho($dom, $val, 'ValorIssRetido', Xml::decimal($valorIss));
        }
        $this->valorOpcional($dom, $val, 'OutrasRetencoes', $outras);
        Xml::filho($dom, $val, 'BaseCalculo', Xml::decimal($baseCalculo));
        Xml::filho($dom, $val, 'Aliquota', Xml::decimal($aliquotaPercentual / 100, 4));
        Xml::filho($dom, $val, 'ValorLiquidoNfse', Xml::decimal($valorLiquido));
        $this->valorOpcional($dom, $val, 'DescontoIncondicionado', $descIncond);
        $this->valorOpcional($dom, $val, 'DescontoCondicionado', $descCond);

        Xml::filho($dom, $serv, 'ItemListaServico', (string)Xml::somenteDigitos($servico['item_lista_servico'] ?? ''));
        Xml::filhoOpcional($dom, $serv, 'CodigoCnae', Xml::somenteDigitos($servico['codigo_cnae'] ?? null));
        Xml::filhoOpcional($dom, $serv, 'CodigoTributacaoMunicipio', $servico['codigo_tributacao_municipal'] ?? null);
        Xml::filho($dom, $serv, 'Discriminacao', (string)$servico['descricao']);
        Xml::filho($dom, $serv, 'CodigoMunicipio', (string)($servico['codigo_municipio_prestacao'] ?? $municipioEmitente));
    }

    private function tomador(DOMDocument $dom, DOMElement $inf, array $tomador): void
    {
        $toma = Xml::filho($dom, $inf, 'Tomador');

        $documento = Xml::somenteDigitos($tomador['cpf_cnpj'] ?? null);
        if ($documento !== null) {
            $ident = Xml::filho($dom, $toma, 'IdentificacaoTomador');
            $cpfCnpj = Xml::filho($dom, $ident, 'CpfCnpj');
            Xml::filho($dom, $cpfCnpj, strlen($documento) === 11 ? 'Cpf' : 'Cnpj', $documento);
            Xml::filhoOpcional($dom, $ident, 'InscricaoMunicipal', $tomador['inscricao_municipal'] ?? null);
        }

        Xml::filho($dom, $toma, 'RazaoSocial', (string)($tomador['nome'] ?? ''));

        $e = $tomador['endereco'] ?? null;
        if (is_array($e) && !empty($e['logradouro'])) {
            $end = Xml::filho($dom, $toma, 'Endereco');
            Xml::filho($dom, $end, 'Endereco', (string)$e['logradouro']);
            Xml::filhoOpcional($dom, $end, 'Numero', $e['numero'] ?? null);
            Xml::filhoOpcional($dom, $end, 'Complemento', $e['complemento'] ?? null);
            Xml::filhoOpcional($dom, $end, 'Bairro', $e['bairro'] ?? null);
            Xml::filhoOpcional($dom, $end, 'CodigoMunicipio', $e['codigo_municipio'] ?? null);
            Xml::filhoOpcional($dom, $end, 'Uf', isset($e['uf']) ? strtoupper((string)$e['uf']) : null);
            Xml::filhoOpcional($dom, $end, 'Cep', Xml::somenteDigitos($e['cep'] ?? null));
        }

        $telefone = Xml::somenteDigitos($tomador['telefone'] ?? null);
        $email = $tomador['email'] ?? null;
        if ($telefone !== null || !empty($email)) {
            $contato = Xml::filho($dom, $toma, 'Contato');
            Xml::filhoOpcional($dom, $contato, 'Telefone', $telefone);
            Xml::filhoOpcional($dom, $contato, 'Email', $email);
        }
    }

    private function valorOpcional(DOMDocument $dom, DOMElement $pai, string $tag, float $valor): void
    {
        if ($valor > 0) {
            Xml::filho($dom, $pai, $tag, Xml::decimal($valor));
        }
    }
}
