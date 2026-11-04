<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use NFePHP\Common\Soap\SoapCurl;
use Throwable;

/**
 * Cliente SOAP da NF-e (sped-nfe) que GRAVA cada chamada a SEFAZ.
 *
 * E o SoapCurl normal da biblioteca; so acrescenta a gravacao. Usado pelo
 * bin/nfse_homologar.php --padrao=NFE. Para cada chamada cria:
 *
 *   01-SOAP-nfeAutorizacaoLote.txt   operacao, URL, requisicao e resposta
 *
 * Nao grava certificado nem senha (vao por arquivo para o cURL).
 */
final class SoapGravador extends SoapCurl
{
    private int $sequencia = 0;
    private string $pasta = '';

    public function gravarEm(string $pasta): self
    {
        $this->pasta = $pasta;
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
        $inicio = microtime(true);
        $erro = null;
        $resposta = '';
        try {
            $resposta = (string)parent::send($url, $operation, $action, $soapver, $parameters, $namespaces, $request, $soapheader);
            return $resposta;
        } catch (Throwable $e) {
            $erro = $e;
            throw $e;
        } finally {
            if ($this->pasta !== '') {
                $this->sequencia++;
                $ms = (int)round((microtime(true) - $inicio) * 1000);
                $arquivo = sprintf('%s/%02d-SOAP-%s.txt', rtrim($this->pasta, '/'), $this->sequencia, preg_replace('/[^A-Za-z0-9]/', '', (string)$operation) ?: 'chamada');
                file_put_contents($arquivo, "=== REQUISICAO ===\n{$operation}\n{$url}\n\n{$request}\n\n"
                    . "=== RESPOSTA ({$ms} ms) ===\n" . ($erro !== null ? 'ERRO: ' . $erro->getMessage() : $resposta) . "\n");
            }
        }
    }
}
