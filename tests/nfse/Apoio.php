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
     */
    public static function certificado(): Certificate
    {
        static $cert = null;
        if ($cert !== null) {
            return $cert;
        }
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'EMPRESA TESTE:41986662000160'], $chave);
        $x509 = openssl_csr_sign($csr, null, $chave, 365);
        openssl_pkcs12_export($x509, $pfx, $chave, 'teste');
        return $cert = Certificate::readPfx($pfx, 'teste');
    }

    public static function config(): array
    {
        $nfse = require dirname(__DIR__, 2) . '/config/nfse.php';
        $nfse['emitente']['inscricao_municipal'] = '12345678';
        $nfse['consumidores'] = ['TESTE' => ['token' => 'token-teste']];
        return NfseConfig::montar(
            ['tpAmb' => 2, 'cnpj' => '41986662000160', 'razao' => 'EMPRESA TESTE LTDA'],
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
