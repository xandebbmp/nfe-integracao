<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Salvador;

use DOMXPath;
use Xande\NfeIntegracao\Nfse\Infra\RespostaHttp;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;
use Xande\NfeIntegracao\Nfse\Infra\Xml;

/**
 * Chamadas SOAP 1.1 ao webservice da NFS-e de Salvador.
 *
 * O XML da ABRASF vai como TEXTO (dentro de CDATA) em um parametro do
 * metodo SOAP, e a resposta tambem volta como texto. Esta classe embrulha
 * e desembrulha; quem interpreta o conteudo e o ProvedorSalvador.
 *
 * Servicos disponiveis em Salvador (cancelamento NAO existe por webservice):
 *   EnviarLoteRPS, ConsultarSituacaoLoteRPS, ConsultarLoteRPS,
 *   ConsultarNfseRPS, ConsultarNfse
 */
final class SalvadorSoapCliente
{
    /**
     * operacao => [caminho, SOAPAction, nome do parametro]
     */
    private const OPERACOES = [
        'EnviarLoteRPS' => ['/rps/ENVIOLOTERPS/EnvioLoteRPS.svc', 'http://tempuri.org/IEnvioLoteRPS/EnviarLoteRPS', 'loteXML'],
        'ConsultarSituacaoLoteRPS' => ['/rps/CONSULTASITUACAOLOTERPS/ConsultaSituacaoLoteRPS.svc', 'http://tempuri.org/IConsultaSituacaoLoteRPS/ConsultarSituacaoLoteRPS', 'loteXML'],
        'ConsultarLoteRPS' => ['/rps/CONSULTALOTERPS/ConsultaLoteRPS.svc', 'http://tempuri.org/IConsultaLoteRPS/ConsultarLoteRPS', 'loteXML'],
        'ConsultarNfseRPS' => ['/rps/CONSULTANFSERPS/ConsultaNfseRPS.svc', 'http://tempuri.org/IConsultaNfseRPS/ConsultarNfseRPS', 'consultaxml'],
        'ConsultarNfse' => ['/rps/CONSULTANFSE/ConsultaNfse.svc', 'http://tempuri.org/IConsultaNfse/ConsultarNfse', 'consultaxml'],
    ];

    public function __construct(private TransporteHttp $http, private string $urlBase)
    {
    }

    /**
     * @return array{resposta: RespostaHttp, xml: ?string} xml = conteudo ABRASF de retorno
     */
    public function chamar(string $operacao, string $xmlAbrasf): array
    {
        [$caminho, $acao, $parametro] = self::OPERACOES[$operacao];

        // "]]>" nao pode aparecer dentro de CDATA; o XML ABRASF nunca deveria
        // conter isso, mas protegemos mesmo assim.
        $conteudo = str_replace(']]>', ']]]]><![CDATA[>', $xmlAbrasf);

        $envelope = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">'
            . '<soapenv:Header/><soapenv:Body>'
            . "<tem:{$operacao}><tem:{$parametro}><![CDATA[{$conteudo}]]></tem:{$parametro}></tem:{$operacao}>"
            . '</soapenv:Body></soapenv:Envelope>';

        $resposta = $this->http->enviar('POST', $this->urlBase . $caminho, [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"' . $acao . '"',
        ], $envelope);

        return ['resposta' => $resposta, 'xml' => self::extrairResultado($resposta->corpo, $operacao . 'Result')];
    }

    /**
     * Pega o conteudo de <XxxResult> no envelope SOAP. Pode vir como texto
     * escapado (o mais comum) ou como XML aninhado.
     */
    public static function extrairResultado(string $envelope, string $elementoResultado): ?string
    {
        $dom = Xml::carregar($envelope);
        if ($dom === null) {
            return null;
        }

        $nos = (new DOMXPath($dom))->query("//*[local-name()='{$elementoResultado}']");
        if ($nos === false || $nos->length === 0) {
            return null;
        }

        $resultado = $nos->item(0);
        foreach ($resultado->childNodes as $filho) {
            if ($filho->nodeType === XML_ELEMENT_NODE) {
                return $dom->saveXML($filho);
            }
        }

        $texto = trim($resultado->textContent);
        return $texto === '' ? null : $texto;
    }
}
