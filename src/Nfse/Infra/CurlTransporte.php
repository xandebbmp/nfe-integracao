<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

/**
 * Transporte HTTP real, via cURL, com certificado do cliente (mTLS).
 */
final class CurlTransporte implements TransporteHttp
{
    public function __construct(
        private ?CertificadoCliente $certificado,
        private int $timeoutSegundos = 30
    ) {
    }

    public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp
    {
        $curl = curl_init($url);

        $linhasCabecalho = [];
        foreach ($cabecalhos as $nome => $valor) {
            $linhasCabecalho[] = "{$nome}: {$valor}";
        }

        $opcoes = [
            CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $linhasCabecalho,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = $corpo;
        }

        if ($this->certificado !== null) {
            $opcoes[CURLOPT_SSLCERT] = $this->certificado->arquivoPem();
            $opcoes[CURLOPT_SSLCERTTYPE] = 'PEM';
        }

        curl_setopt_array($curl, $opcoes);

        $resposta = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $erro = curl_errno($curl) !== 0 ? curl_error($curl) : null;
        curl_close($curl);

        if ($resposta === false || $erro !== null) {
            return new RespostaHttp(0, '', $erro ?? 'Falha desconhecida na chamada HTTP.');
        }

        return new RespostaHttp($status, (string)$resposta, null, $contentType);
    }
}
