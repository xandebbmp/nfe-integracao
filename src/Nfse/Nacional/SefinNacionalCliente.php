<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nacional;

use Xande\NfeIntegracao\Nfse\Infra\RespostaHttp;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;

/**
 * Chamadas HTTP da API REST do Sefin Nacional / ADN.
 *
 * Esta classe NAO interpreta regra fiscal: so monta a URL, compacta o XML
 * (gzip + base64, exigido pela API) e devolve a resposta HTTP.
 *
 * Endpoints (base = url_sefin do ambiente):
 *   POST {base}/nfse                          emitir (envia DPS, recebe NFS-e)
 *   GET  {base}/nfse/{chave}                  consultar NFS-e pela chave
 *   GET  {base}/dps/{idDps}                   descobrir a chave a partir da DPS
 *   POST {base}/nfse/{chave}/eventos          registrar evento
 *   GET  {base}/nfse/{chave}/eventos          listar eventos da NFS-e
 *   GET  {url_danfse}/danfse/{chave}          PDF oficial (DANFSe)
 */
final class SefinNacionalCliente
{
    private const JSON = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

    public function __construct(
        private TransporteHttp $http,
        private string $urlSefin,
        private string $urlDanfse
    ) {
    }

    public function emitir(string $dpsAssinada): RespostaHttp
    {
        $corpo = json_encode(['dpsXmlGZipB64' => self::compactar($dpsAssinada)]);
        return $this->http->enviar('POST', $this->urlSefin . '/nfse', self::JSON, (string)$corpo);
    }

    public function consultarNfse(string $chave): RespostaHttp
    {
        return $this->http->enviar('GET', $this->urlSefin . '/nfse/' . rawurlencode($chave), self::JSON);
    }

    public function consultarDps(string $idDps): RespostaHttp
    {
        return $this->http->enviar('GET', $this->urlSefin . '/dps/' . rawurlencode($idDps), self::JSON);
    }

    public function registrarEvento(string $chave, string $eventoAssinado): RespostaHttp
    {
        $corpo = json_encode(['pedidoRegistroEventoXmlGZipB64' => self::compactar($eventoAssinado)]);
        return $this->http->enviar(
            'POST',
            $this->urlSefin . '/nfse/' . rawurlencode($chave) . '/eventos',
            self::JSON,
            (string)$corpo
        );
    }

    public function listarEventos(string $chave): RespostaHttp
    {
        return $this->http->enviar('GET', $this->urlSefin . '/nfse/' . rawurlencode($chave) . '/eventos', self::JSON);
    }

    public function baixarDanfse(string $chave): RespostaHttp
    {
        return $this->http->enviar(
            'GET',
            $this->urlDanfse . '/danfse/' . rawurlencode($chave),
            ['Accept' => 'application/pdf']
        );
    }

    // ---------------------------------------------------------------
    // Compactacao exigida pela API: XML -> gzip -> base64 (e o inverso)
    // ---------------------------------------------------------------

    public static function compactar(string $xml): string
    {
        return base64_encode((string)gzencode($xml));
    }

    public static function descompactar(?string $base64): ?string
    {
        if ($base64 === null || $base64 === '') {
            return null;
        }
        $binario = base64_decode($base64, true);
        if ($binario === false) {
            return null;
        }
        $xml = @gzdecode($binario);
        return $xml === false ? null : $xml;
    }
}
