<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Salvador;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Xande\NfeIntegracao\Nfse\Contrato\ProvedorNfse;
use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\Capacidades;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Infra\AssinadorXml;
use Xande\NfeIntegracao\Nfse\Infra\RespostaHttp;
use Xande\NfeIntegracao\Nfse\Infra\ValidadorXsd;
use Xande\NfeIntegracao\Nfse\Infra\Xml;
use Xande\NfeIntegracao\Nfse\Pdf\EspelhoNfsePdf;

/**
 * NFS-e de Salvador-BA (webservice ABRASF 1.00 da prefeitura).
 *
 * Caracteristicas do padrao:
 *   - Emissao ASSINCRONA: enviamos o lote, recebemos um PROTOCOLO e depois
 *     consultamos ate o lote ser processado. O worker cuida dessas consultas.
 *   - Situacao do lote: 1=Nao recebido, 2=Nao processado, 3=Processado com
 *     erro, 4=Processado com sucesso.
 *   - NAO ha cancelamento por webservice: a prefeitura so cancela pelo
 *     portal / processo administrativo. A API responde isso claramente.
 *   - NAO ha PDF oficial por webservice: geramos um espelho (documento
 *     auxiliar) a partir do XML autorizado.
 */
final class ProvedorSalvador implements ProvedorNfse
{
    // Situacoes do lote (tsSituacaoLoteRps)
    private const LOTE_NAO_RECEBIDO = '1';
    private const LOTE_NAO_PROCESSADO = '2';
    private const LOTE_COM_ERRO = '3';
    private const LOTE_COM_SUCESSO = '4';

    private string $xsd;

    public function __construct(
        private array $cfg,
        private SalvadorSoapCliente $cliente,
        private AssinadorXml $assinador
    ) {
        $this->xsd = dirname(__DIR__, 3) . '/schemas/nfse/salvador/1.00/nfse.xsd';
    }

    public function padrao(): string
    {
        return Padrao::SALVADOR;
    }

    // =================================================================
    // Emissao
    // =================================================================

    public function emitir(array $documento): RetornoProvedor
    {
        // Ja temos protocolo? Entao o lote foi recebido: so acompanhar.
        if (!empty($documento['protocolo_lote'])) {
            return $this->consultarProcessamento($documento);
        }

        // Ja tentamos enviar mas caiu antes do protocolo? Verifica se o RPS
        // virou nota antes de reenviar (evita duplicidade).
        if (!empty($documento['xml_enviado'])) {
            $existente = $this->consultarPorRps($documento);
            if ($existente->status !== RetornoProvedor::NAO_ENCONTRADO) {
                return $existente;
            }
        }

        $montado = (new RpsXmlBuilder($this->cfg))->montarEnvioLote($documento);
        /** @var DOMDocument $dom */
        $dom = $montado['dom'];
        $this->assinador->assinarNo($dom, $montado['infRps'], $montado['rps'], 'id');
        $this->assinador->assinarNo($dom, $montado['loteRps'], $dom->documentElement, 'id');
        $xmlAssinado = $dom->saveXML($dom->documentElement);

        $dadosEnvio = ['xml_enviado' => $xmlAssinado];

        $errosXsd = ValidadorXsd::validar($xmlAssinado, $this->xsd);
        if ($errosXsd !== []) {
            return RetornoProvedor::rejeitado(
                array_map(static fn($e) => ['codigo' => 'XSD', 'mensagem' => $e], $errosXsd),
                $dadosEnvio
            );
        }

        $chamada = $this->cliente->chamar('EnviarLoteRPS', $xmlAssinado);
        $resposta = $chamada['resposta'];

        if ($resposta->falhaTemporaria() || $chamada['xml'] === null) {
            return RetornoProvedor::falhaTemporaria($this->descreverFalha($resposta), $dadosEnvio);
        }

        $retorno = Xml::carregar($chamada['xml']);
        $mensagens = $retorno ? self::mensagensRetorno($retorno) : [];
        $protocolo = Xml::valor($chamada['xml'], 'Protocolo');

        if ($protocolo !== null && $protocolo !== '') {
            return RetornoProvedor::processando(
                $dadosEnvio + ['protocolo_lote' => $protocolo],
                (int)$this->cfg['salvador']['consulta_lote_primeira_em']
            );
        }

        return RetornoProvedor::rejeitado($mensagens ?: [[
            'codigo' => 'RETORNO_INESPERADO',
            'mensagem' => 'Salvador nao devolveu protocolo nem mensagens de erro.',
        ]], $dadosEnvio);
    }

    public function consultarProcessamento(array $documento): RetornoProvedor
    {
        $protocolo = (string)($documento['protocolo_lote'] ?? '');
        if ($protocolo === '') {
            return $this->consultarPorRps($documento);
        }

        $builder = new RpsXmlBuilder($this->cfg);

        // 1) Situacao do lote
        $chamada = $this->cliente->chamar('ConsultarSituacaoLoteRPS', $builder->montarConsultaSituacaoLote($protocolo));
        if ($chamada['resposta']->falhaTemporaria() || $chamada['xml'] === null) {
            return RetornoProvedor::falhaTemporaria($this->descreverFalha($chamada['resposta']));
        }

        $situacao = Xml::valor($chamada['xml'], 'Situacao');
        $intervalo = (int)$this->cfg['salvador']['consulta_lote_intervalo'];

        if ($situacao === self::LOTE_NAO_RECEBIDO || $situacao === self::LOTE_NAO_PROCESSADO) {
            return RetornoProvedor::processando([], $intervalo);
        }

        if ($situacao !== self::LOTE_COM_ERRO && $situacao !== self::LOTE_COM_SUCESSO) {
            $retorno = Xml::carregar($chamada['xml']);
            $mensagens = $retorno ? self::mensagensRetorno($retorno) : [];
            return RetornoProvedor::falhaTemporaria(
                'Situacao do lote nao informada: ' . ($mensagens[0]['mensagem'] ?? 'retorno inesperado')
            );
        }

        // 2) Lote processado (com erro ou sucesso): busca o resultado completo.
        $chamada = $this->cliente->chamar('ConsultarLoteRPS', $builder->montarConsultaLote($protocolo));
        if ($chamada['resposta']->falhaTemporaria() || $chamada['xml'] === null) {
            return RetornoProvedor::falhaTemporaria($this->descreverFalha($chamada['resposta']));
        }

        return $this->interpretarCompNfse($chamada['xml'], $situacao === self::LOTE_COM_ERRO);
    }

    // =================================================================
    // Consulta
    // =================================================================

    public function consultar(array $documento): RetornoProvedor
    {
        if (empty($documento['numero_nfse']) && !empty($documento['protocolo_lote'])) {
            return $this->consultarProcessamento($documento);
        }
        return $this->consultarPorRps($documento);
    }

    public function registrarEvento(array $documento, array $evento): RetornoProvedor
    {
        $motivo = Capacidades::doPadrao(Padrao::SALVADOR)['eventos_nao_suportados'][$evento['tipo_evento']] ?? 'Evento nao suportado em Salvador.';
        return RetornoProvedor::eventoRejeitado([['codigo' => 'NAO_SUPORTADO_PELO_PADRAO', 'mensagem' => $motivo]]);
    }

    public function gerarPdf(array $documento): string
    {
        $url = (string)($this->cfg['salvador']['url_visualizacao'] ?? '');
        if ($url !== '') {
            $url = strtr($url, [
                '{numero}' => (string)$documento['numero_nfse'],
                '{inscricao}' => (string)$this->cfg['emitente']['inscricao_municipal'],
                '{verificacao}' => (string)$documento['codigo_verificacao'],
            ]);
        }
        return (new EspelhoNfsePdf())->gerar($documento, $this->cfg, $url !== '' ? $url : null);
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    private function consultarPorRps(array $documento): RetornoProvedor
    {
        $xml = (new RpsXmlBuilder($this->cfg))->montarConsultaNfsePorRps($documento);
        $chamada = $this->cliente->chamar('ConsultarNfseRPS', $xml);

        if ($chamada['resposta']->falhaTemporaria() || $chamada['xml'] === null) {
            return RetornoProvedor::falhaTemporaria($this->descreverFalha($chamada['resposta']));
        }

        if (Xml::valor($chamada['xml'], 'CodigoVerificacao') === null) {
            $retorno = Xml::carregar($chamada['xml']);
            return RetornoProvedor::situacao(
                RetornoProvedor::NAO_ENCONTRADO,
                [],
                ($retorno ? self::mensagensRetorno($retorno) : []) ?: [[
                    'codigo' => 'RPS_NAO_LOCALIZADO',
                    'mensagem' => 'RPS nao localizado em Salvador.',
                ]]
            );
        }

        return $this->interpretarCompNfse($chamada['xml'], false);
    }

    /**
     * Le um retorno que pode trazer <CompNfse> (sucesso) ou
     * <ListaMensagemRetorno> (erro).
     */
    private function interpretarCompNfse(string $xmlRetorno, bool $loteComErro): RetornoProvedor
    {
        $dom = Xml::carregar($xmlRetorno);
        if ($dom === null) {
            return RetornoProvedor::falhaTemporaria('Retorno de Salvador nao e um XML valido.');
        }

        $xpath = new DOMXPath($dom);
        $comp = $xpath->query("//*[local-name()='CompNfse']")->item(0);

        if (!$comp instanceof DOMElement) {
            $mensagens = self::mensagensRetorno($dom);
            if ($loteComErro || $mensagens !== []) {
                return RetornoProvedor::rejeitado($mensagens ?: [[
                    'codigo' => 'LOTE_COM_ERRO',
                    'mensagem' => 'Lote processado com erro, sem mensagem detalhada.',
                ]]);
            }
            return RetornoProvedor::falhaTemporaria('Lote processado, mas a NFS-e nao veio no retorno.');
        }

        $xmlNfse = $dom->saveXML($comp);
        $dataEmissao = Xml::valor($xmlNfse, 'DataEmissao');

        $dados = [
            'xml_autorizado' => $xmlNfse,
            'numero_nfse' => Xml::valor($xmlNfse, 'Numero'),
            'codigo_verificacao' => Xml::valor($xmlNfse, 'CodigoVerificacao'),
            'data_autorizacao' => $dataEmissao !== null
                ? (new DateTimeImmutable($dataEmissao, new DateTimeZone('America/Bahia')))->format(DATE_ATOM)
                : null,
        ];

        // Se a prefeitura informar cancelamento no retorno, respeitamos.
        $status = $xpath->query("//*[local-name()='NfseCancelamento']")->length > 0
            ? StatusNfse::CANCELADO
            : StatusNfse::AUTORIZADO;

        return $status === StatusNfse::AUTORIZADO
            ? RetornoProvedor::autorizado($dados)
            : RetornoProvedor::situacao($status, $dados);
    }

    /**
     * Le <MensagemRetorno> (Codigo/Mensagem/Correcao) de qualquer retorno ABRASF.
     */
    private static function mensagensRetorno(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $mensagens = [];
        foreach ($xpath->query("//*[local-name()='MensagemRetorno' or local-name()='MensagemRetornoLote']") as $no) {
            $item = [];
            foreach ($no->childNodes as $filho) {
                if ($filho->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $mapa = ['Codigo' => 'codigo', 'Mensagem' => 'mensagem', 'Correcao' => 'correcao'];
                if (isset($mapa[$filho->localName])) {
                    $item[$mapa[$filho->localName]] = trim($filho->textContent);
                }
            }
            if ($item !== []) {
                $mensagens[] = $item;
            }
        }
        return $mensagens;
    }

    private function descreverFalha(RespostaHttp $resposta): string
    {
        if ($resposta->status === 0) {
            return 'Sem resposta da prefeitura de Salvador: ' . ($resposta->erroRede ?? 'erro de rede');
        }
        return "Salvador respondeu HTTP {$resposta->status}: " . mb_substr(trim(strip_tags($resposta->corpo)), 0, 300);
    }
}
