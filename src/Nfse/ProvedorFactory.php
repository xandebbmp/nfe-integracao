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
 * Cria o provedor certo para cada padrao.
 *
 * O certificado so e lido quando um provedor e realmente usado (ex.: a rota
 * de capacidades nao precisa dele).
 *
 * Nos testes passamos $certificado e $transporte falsos.
 */
final class ProvedorFactory
{
    /** @var array<string, ProvedorNfse> */
    private array $cache = [];

    public function __construct(
        private array $cfg,
        private ?CertificadoCliente $certificado = null,
        private ?TransporteHttp $transporte = null
    ) {
    }

    public function para(string $padrao): ProvedorNfse
    {
        return $this->cache[$padrao] ??= match ($padrao) {
            Padrao::NACIONAL => $this->criarNacional(),
            Padrao::SALVADOR => $this->criarSalvador(),
            default => throw new RuntimeException("Padrao de NFS-e desconhecido: {$padrao}"),
        };
    }

    private function criarNacional(): ProvedorNacional
    {
        $c = $this->cfg['nacional'];
        $tpAmb = (int)$this->cfg['tpAmb'];

        return new ProvedorNacional(
            $this->cfg,
            new SefinNacionalCliente(
                $this->transporte((int)$c['timeout_segundos']),
                rtrim((string)$c['url_sefin'][$tpAmb], '/'),
                rtrim((string)$c['url_danfse'][$tpAmb], '/')
            ),
            new AssinadorXml($this->certificado()->certificado(), (string)$c['algoritmo_assinatura'])
        );
    }

    private function criarSalvador(): ProvedorSalvador
    {
        $c = $this->cfg['salvador'];
        $tpAmb = (int)$this->cfg['tpAmb'];

        return new ProvedorSalvador(
            $this->cfg,
            new SalvadorSoapCliente(
                $this->transporte((int)$c['timeout_segundos']),
                rtrim((string)$c['url_base'][$tpAmb], '/')
            ),
            new AssinadorXml($this->certificado()->certificado(), (string)$c['algoritmo_assinatura'])
        );
    }

    private function certificado(): CertificadoCliente
    {
        return $this->certificado ??= CertificadoCliente::doArquivo(
            (string)$this->cfg['certPfxPath'],
            (string)$this->cfg['certPassword']
        );
    }

    private function transporte(int $timeout): TransporteHttp
    {
        return $this->transporte ?? new CurlTransporte($this->certificado(), $timeout);
    }
}
