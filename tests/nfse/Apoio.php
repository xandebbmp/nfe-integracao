<?php
declare(strict_types=1);

/**
 * Apoio para os testes da NFS-e (sem dependencias externas).
 *
 * Rodar: php tests/nfse/executar.php
 */

use NFePHP\Common\Certificate;
use Xande\NfeIntegracao\Nfse\Infra\RespostaHttp;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;
use Xande\NfeIntegracao\Nfse\NfseConfig;

final class Apoio
{
    public static int $ok = 0;
    public static array $falhas = [];

    public static function verificar(bool $condicao, string $descricao): void
    {
        if ($condicao) {
            self::$ok++;
            echo "  ok  - {$descricao}\n";
        } else {
            self::$falhas[] = $descricao;
            echo "  FALHOU - {$descricao}\n";
        }
    }

    public static function igual(mixed $esperado, mixed $obtido, string $descricao): void
    {
        $ok = $esperado === $obtido;
        self::verificar($ok, $descricao . ($ok ? '' : ' (esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true) . ')'));
    }

    /**
     * Certificado autoassinado gerado em memoria (NUNCA usar fora de teste).
     *
     * Leva o identificador ICP-Brasil do CNPJ (OID 2.16.76.1.3.3), como um
     * e-CNPJ real: a biblioteca da NF-e usa isso para saber que o autor dos
     * eventos e pessoa juridica (tag CNPJ).
     */
    public static function certificado(): Certificate
    {
        static $cert = null;
        if ($cert !== null) {
            return $cert;
        }
        $arquivoConfig = tempnam(sys_get_temp_dir(), 'icp_');
        file_put_contents($arquivoConfig, "[req]\ndistinguished_name = dn\n[dn]\n[icp]\nbasicConstraints = CA:FALSE\n"
            . "keyUsage = digitalSignature, keyEncipherment\nsubjectAltName = otherName:2.16.76.1.3.3;UTF8:41986662000160\n");
        $opcoes = ['config' => $arquivoConfig, 'x509_extensions' => 'icp', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $chave = openssl_pkey_new($opcoes);
        $csr = openssl_csr_new(['commonName' => 'EMPRESA TESTE:41986662000160'], $chave, $opcoes);
        $x509 = openssl_csr_sign($csr, null, $chave, 365, $opcoes);
        openssl_pkcs12_export($x509, $pfx, $chave, 'teste');
        @unlink($arquivoConfig);
        return $cert = Certificate::readPfx($pfx, 'teste');
    }

    public static function config(): array
    {
        $nfse = require dirname(__DIR__, 2) . '/config/nfse.php';
        // Isola os testes da config local do desenvolvedor (nfse.local.php).
        $nfse['emitentes'] = [];
        $nfse['armazenamento']['tipo'] = 'nenhum';
        $nfse['emitente']['inscricao_municipal'] = '12345678';
        $nfse['consumidores'] = ['TESTE' => ['token' => 'token-teste']];
        $nfse['email'] = [];   // nunca usa o SMTP de verdade nos testes
        return NfseConfig::montar(
            ['tpAmb' => 2, 'cnpj' => '41986662000160', 'razao' => 'EMPRESA TESTE LTDA', 'siglaUF' => 'BA', 'ie' => '31638351'],
            $nfse
        );
    }

    public static function documentoNacional(array $sobrescrever = []): array
    {
        return array_replace([
            'id' => '00000000-0000-0000-0000-000000000001',
            'padrao' => 'NACIONAL',
            'serie' => '1',
            'numero' => 15,
            'id_dps' => null,
            'xml_enviado' => null,
            'chave_acesso' => null,
            'payload' => [
                'referencia' => 'PEDIDO-1',
                'competencia' => '2026-11-04',
                'tomador' => [
                    'cpf_cnpj' => '11.222.333/0001-81',
                    'nome' => 'Cliente & Filhos Ltda',
                    'email' => 'financeiro@cliente.com.br',
                    'endereco' => [
                        'logradouro' => 'Av. Tancredo Neves',
                        'numero' => '1000',
                        'bairro' => 'Caminho das Arvores',
                        'codigo_municipio' => '2927408',
                        'uf' => 'BA',
                        'cep' => '41820-020',
                    ],
                ],
                'servico' => [
                    'descricao' => 'Desenvolvimento de software sob encomenda',
                    'codigo_tributacao_nacional' => '010101',
                    'item_lista_servico' => '0101',
                ],
                'valores' => [
                    'valor_servicos' => 1500.0,
                    'aliquota_iss' => 5,
                    'iss_retido' => false,
                ],
            ],
        ], $sobrescrever);
    }
}

/**
 * Transporte HTTP falso: devolve respostas na ordem em que foram enfileiradas
 * e guarda as requisicoes feitas, para conferencia.
 */
final class TransporteFalso implements TransporteHttp
{
    /** @var list<RespostaHttp> */
    public array $respostas = [];
    /** @var list<array{metodo: string, url: string, corpo: ?string, cabecalhos: array}> */
    public array $requisicoes = [];

    public function responder(int $status, string $corpo, string $contentType = 'application/json'): self
    {
        $this->respostas[] = new RespostaHttp($status, $corpo, $status === 0 ? 'timeout simulado' : null, $contentType);
        return $this;
    }

    public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp
    {
        $this->requisicoes[] = compact('metodo', 'url', 'corpo', 'cabecalhos');
        if ($this->respostas === []) {
            throw new RuntimeException("Requisicao inesperada no teste: {$metodo} {$url}");
        }
        return array_shift($this->respostas);
    }
}

/**
 * SOAP falso para a NF-e (biblioteca sped-nfe). Cada resposta enfileirada
 * pode ser: string (XML de retorno), Throwable (simula queda de rede) ou
 * callable(string $requisicao): string (monta o retorno a partir do que foi
 * enviado - necessario para o digVal da assinatura bater).
 */
final class SoapNfeFalso extends \NFePHP\Common\Soap\SoapBase
{
    /** @var list<string|Throwable|callable> */
    public array $respostas = [];
    /** @var list<array{operacao: string, requisicao: string}> */
    public array $requisicoes = [];

    public function responder(string|Throwable|callable $resposta): self
    {
        $this->respostas[] = $resposta;
        return $this;
    }

    public function send(
        $url,
        $operation = '',
        $action = '',
        $soapver = SOAP_1_2,
        $parameters = [],
        $namespaces = [],
        $request = '',
        $soapheader = null
    ) {
        $this->requisicoes[] = ['operacao' => (string)$operation, 'requisicao' => (string)$request];
        if ($this->respostas === []) {
            throw new RuntimeException("Chamada SOAP inesperada no teste: {$operation}");
        }
        $resposta = array_shift($this->respostas);
        if ($resposta instanceof Throwable) {
            throw $resposta;
        }
        $corpo = is_callable($resposta) ? $resposta((string)$request) : $resposta;
        // Igual a SEFAZ: retorno dentro de um envelope SOAP.
        return '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            . '<nfeResultMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/x">' . $corpo . '</nfeResultMsg>'
            . '</soap:Body></soap:Envelope>';
    }

    // ---- Retornos prontos da SEFAZ ----

    /** retEnviNFe sincrono (cStat do lote 104) com protocolo cStat informado. */
    public static function retornoEnvio(string $cStatNota, string $xMotivo = 'Autorizado o uso da NF-e', string $cStatLote = '104'): callable
    {
        return static function (string $requisicao) use ($cStatNota, $xMotivo, $cStatLote): string {
            [$chave, $digest] = self::chaveEDigest($requisicao);
            $prot = $cStatLote === '104' ? self::protNFe($chave, $digest, $cStatNota, $xMotivo) : '';
            return '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>BA-TESTE</verAplic>'
                . "<cStat>{$cStatLote}</cStat><xMotivo>" . ($cStatLote === '104' ? 'Lote processado' : 'Lote recebido com sucesso') . '</xMotivo><cUF>29</cUF>'
                . '<dhRecbto>2026-11-04T10:00:00-03:00</dhRecbto>'
                . ($cStatLote === '103' ? '<infRec><nRec>291000000000001</nRec><tMed>1</tMed></infRec>' : '')
                . $prot . '</retEnviNFe>';
        };
    }

    /** retConsSitNFe para a chave do XML assinado informado. */
    public static function retornoConsulta(string $xmlAssinado, string $cStat, string $xMotivo): string
    {
        [$chave, $digest] = self::chaveEDigest($xmlAssinado);
        $prot = in_array($cStat, ['100', '150', '101', '110'], true) ? self::protNFe($chave, $digest, $cStat === '101' ? '100' : $cStat, 'Autorizado o uso da NF-e') : '';
        return '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>2</tpAmb><verAplic>BA-TESTE</verAplic>'
            . "<cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo><cUF>29</cUF><dhRecbto>2026-11-04T10:00:00-03:00</dhRecbto>"
            . "<chNFe>{$chave}</chNFe>{$prot}</retConsSitNFe>";
    }

    /** retEnvEvento (lote 128) com o cStat do evento informado. */
    public static function retornoEvento(string $cStatEvento, string $xMotivo = 'Evento registrado e vinculado a NF-e'): callable
    {
        return static function (string $requisicao) use ($cStatEvento, $xMotivo): string {
            preg_match('/<chNFe>(\d{44})<\/chNFe>/', $requisicao, $c);
            preg_match('/<tpEvento>(\d{6})<\/tpEvento>/', $requisicao, $t);
            preg_match('/<nSeqEvento>(\d+)<\/nSeqEvento>/', $requisicao, $n);
            preg_match('/<idLote>(\d+)<\/idLote>/', $requisicao, $l); // a SEFAZ devolve o mesmo idLote
            return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00"><idLote>' . ($l[1] ?? '1') . '</idLote><tpAmb>2</tpAmb><verAplic>BA-TESTE</verAplic>'
                . '<cOrgao>29</cOrgao><cStat>128</cStat><xMotivo>Lote de Evento Processado</xMotivo>'
                . '<retEvento versao="1.00"><infEvento><tpAmb>2</tpAmb><verAplic>BA-TESTE</verAplic><cOrgao>29</cOrgao>'
                . "<cStat>{$cStatEvento}</cStat><xMotivo>{$xMotivo}</xMotivo><chNFe>" . ($c[1] ?? '') . '</chNFe>'
                . '<tpEvento>' . ($t[1] ?? '') . '</tpEvento><xEvento>Evento</xEvento><nSeqEvento>' . ($n[1] ?? '1') . '</nSeqEvento>'
                . '<dhRegEvento>2026-11-04T11:00:00-03:00</dhRegEvento><nProt>129261000000099</nProt></infEvento></retEvento></retEnvEvento>';
        };
    }

    private static function protNFe(string $chave, string $digest, string $cStat, string $xMotivo): string
    {
        return '<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><verAplic>BA-TESTE</verAplic>'
            . "<chNFe>{$chave}</chNFe><dhRecbto>2026-11-04T10:00:01-03:00</dhRecbto><nProt>129261000000042</nProt>"
            . "<digVal>{$digest}</digVal><cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo></infProt></protNFe>";
    }

    /** @return array{0: string, 1: string} chave (44) e DigestValue do XML assinado */
    private static function chaveEDigest(string $xml): array
    {
        preg_match('/Id="NFe(\d{44})"/', $xml, $c);
        preg_match('/<DigestValue>([^<]+)<\/DigestValue>/', $xml, $d);
        return [$c[1] ?? '', $d[1] ?? ''];
    }
}
