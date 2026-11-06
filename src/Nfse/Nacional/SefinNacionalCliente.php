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
 *   GET  {base}/nfse/{chave}/eventos/{tipo}/{seq}  consultar um evento da NFS-e
 *   GET  {url_danfse}/danfse/{chave}          PDF oficial (DANFSe)
 */
final class SefinNacionalCliente
{
    private const JSON = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

    /** 101101 cancelamento, 105102 cancelamento por substituicao, 105104 cancelamento deferido, 305101 cancelamento de oficio. */
    private const EVENTOS_QUE_MUDAM_STATUS = ['101101', '105102', '105104', '305101'];

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

    /**
     * O Sefin nao lista todos os eventos de uma vez: a consulta e por tipo e
     * sequencia (GET /nfse/{chave}/eventos/{tipo}/{seq}). Consultamos os tipos
     * que mudam o status da nota e juntamos tudo em uma resposta so,
     * no formato {"eventos": [...]}. HTTP 404 = nao ha evento daquele tipo.
     */
    public function listarEventos(string $chave): RespostaHttp
    {
        $eventos = [];
        foreach (self::EVENTOS_QUE_MUDAM_STATUS as $tipo) {
            $url = $this->urlSefin . '/nfse/' . rawurlencode($chave) . '/eventos/' . $tipo . '/1';
            $resposta = $this->http->enviar('GET', $url, self::JSON);
            if ($resposta->status === 404) {
                continue;
            }
            if (!$resposta->sucesso()) {
                return $resposta; // falha: quem chamou decide (tenta de novo depois)
            }
            foreach ((array)(($resposta->json() ?? [])['eventos'] ?? []) as $evento) {
                $eventos[] = $evento;
            }
        }
        return new RespostaHttp(200, (string)json_encode(['eventos' => $eventos]), null, 'application/json');
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
