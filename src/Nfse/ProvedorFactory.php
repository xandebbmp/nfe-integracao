<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse;

use RuntimeException;
use Xande\NfeIntegracao\Nfse\Contrato\ProvedorNfse;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Infra\AssinadorXml;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;
use Xande\NfeIntegracao\Nfse\Nacional\ProvedorNacional;
use Xande\NfeIntegracao\Nfse\Nacional\SefinNacionalCliente;
use Xande\NfeIntegracao\Nfse\Salvador\ProvedorSalvador;
use Xande\NfeIntegracao\Nfse\Salvador\SalvadorSoapCliente;

/**
 * Cria o provedor certo para cada padrao E emitente (CNPJ).
 *
 * Cada emitente tem o proprio certificado digital, usado para assinar o XML
 * e para a conexao mTLS. O certificado so e lido quando o provedor e usado.
 *
 * Nos testes passamos $certificadoFixo e $transporte falsos.
 */
final class ProvedorFactory
{
    /** @var array<string, ProvedorNfse> chave: "PADRAO|CNPJ" */
    private array $provedores = [];

    /** @var array<string, CertificadoCliente> chave: CNPJ */
    private array $certificados = [];

    public function __construct(
        private array $cfg,
        private ?CertificadoCliente $certificadoFixo = null,
        private ?TransporteHttp $transporte = null
    ) {
    }

    public function para(string $padrao, string $cnpj): ProvedorNfse
    {
        $cfgEmitente = NfseConfig::paraEmitente($this->cfg, $cnpj);
        $chave = $padrao . '|' . $cfgEmitente['emitente']['cnpj'];

        return $this->provedores[$chave] ??= match ($padrao) {
            Padrao::NACIONAL => $this->criarNacional($cfgEmitente),
            Padrao::SALVADOR => $this->criarSalvador($cfgEmitente),
            default => throw new RuntimeException("Padrao de NFS-e desconhecido: {$padrao}"),
        };
    }

    private function criarNacional(array $cfg): ProvedorNacional
    {
        $c = $cfg['nacional'];
        $tpAmb = (int)$cfg['tpAmb'];
        $certificado = $this->certificado($cfg['emitente']);

        return new ProvedorNacional(
            $cfg,
            new SefinNacionalCliente(
                $this->transporte ?? new CurlTransporte($certificado, (int)$c['timeout_segundos']),
                rtrim((string)$c['url_sefin'][$tpAmb], '/'),
                rtrim((string)$c['url_danfse'][$tpAmb], '/')
            ),
            new AssinadorXml($certificado->certificado(), (string)$c['algoritmo_assinatura'])
        );
    }

    private function criarSalvador(array $cfg): ProvedorSalvador
    {
        $c = $cfg['salvador'];
        $tpAmb = (int)$cfg['tpAmb'];
        $certificado = $this->certificado($cfg['emitente']);

        return new ProvedorSalvador(
            $cfg,
            new SalvadorSoapCliente(
                $this->transporte ?? new CurlTransporte($certificado, (int)$c['timeout_segundos']),
                rtrim((string)$c['url_base'][$tpAmb], '/')
            ),
            new AssinadorXml($certificado->certificado(), (string)$c['algoritmo_assinatura'])
        );
    }

    private function certificado(array $emitente): CertificadoCliente
    {
        if ($this->certificadoFixo !== null) {
            return $this->certificadoFixo;
        }

        return $this->certificados[$emitente['cnpj']] ??= CertificadoCliente::doArquivo(
            (string)($emitente['certificado']['arquivo'] ?? ''),
            (string)($emitente['certificado']['senha'] ?? '')
        );
    }
}
