<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

/**
 * Contrato minimo para fazer uma chamada HTTP.
 *
 * Existe para que os provedores (Nacional/Salvador) nao dependam do cURL:
 *   - em producao usamos CurlTransporte;
 *   - nos testes usamos um transporte falso com respostas prontas.
 */
interface TransporteHttp
{
    /**
     * @param array<string, string> $cabecalhos
     */
    public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp;
}
