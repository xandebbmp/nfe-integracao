<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use DOMDocument;
use DOMElement;
use NFePHP\Common\Certificate;
use RuntimeException;

/**
 * Assinatura digital XMLDSig (enveloped) no padrao usado por SEFAZ/prefeituras.
 *
 * Por que nao usar NFePHP\Common\Signer direto?
 *   O Signer do sped-common ignora a assinatura quando o XML ja possui
 *   qualquer <Signature>. Salvador (ABRASF) exige DUAS assinaturas no mesmo
 *   XML (uma no RPS e outra no lote), entao precisamos assinar varios nos
 *   do mesmo documento. A logica abaixo e a mesma do Signer, so que por no.
 *
 * Uso:
 *   $assinador = new AssinadorXml($certificado, 'sha256');
 *   $assinador->assinarNo($dom, $noAssinado, $noOndeColocarAssinatura, 'Id');
 */
final class AssinadorXml
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private int $algoritmoOpenssl;
    private string $algoritmoDigest;
    private string $uriAssinatura;
    private string $uriDigest;

    /**
     * @param string $algoritmo 'sha1' ou 'sha256'
     */
    public function __construct(private Certificate $certificado, string $algoritmo = 'sha1')
    {
        if ($algoritmo === 'sha256') {
            $this->algoritmoOpenssl = OPENSSL_ALGO_SHA256;
            $this->algoritmoDigest = 'sha256';
            $this->uriAssinatura = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
            $this->uriDigest = 'http://www.w3.org/2001/04/xmlenc#sha256';
        } elseif ($algoritmo === 'sha1') {
            $this->algoritmoOpenssl = OPENSSL_ALGO_SHA1;
            $this->algoritmoDigest = 'sha1';
            $this->uriAssinatura = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
            $this->uriDigest = 'http://www.w3.org/2000/09/xmldsig#sha1';
        } else {
            throw new RuntimeException("Algoritmo de assinatura nao suportado: {$algoritmo}");
        }
    }

    /**
     * Assina $noAssinado e coloca o <Signature> como ultimo filho de $destino.
     *
     * @param string $atributoId nome do atributo de identificacao ('Id' ou 'id')
     */
    public function assinarNo(
        DOMDocument $dom,
        DOMElement $noAssinado,
        DOMElement $destino,
        string $atributoId
    ): void {
        $id = trim($noAssinado->getAttribute($atributoId));
        if ($id === '') {
            throw new RuntimeException("No <{$noAssinado->nodeName}> sem atributo {$atributoId} para assinar.");
        }

        // 1) Digest do no assinado (forma canonica C14N, sem comentarios).
        $canonico = $noAssinado->C14N(false, false);
        $digest = base64_encode(hash($this->algoritmoDigest, $canonico, true));

        // 2) Monta <Signature><SignedInfo>...</SignedInfo>...</Signature>
        $signature = $dom->createElementNS(self::NS_DSIG, 'Signature');
        $destino->appendChild($signature);

        $signedInfo = $dom->createElement('SignedInfo');
        $signature->appendChild($signedInfo);

        $this->filhoComAlgoritmo($dom, $signedInfo, 'CanonicalizationMethod', self::C14N);
        $this->filhoComAlgoritmo($dom, $signedInfo, 'SignatureMethod', $this->uriAssinatura);

        $reference = $dom->createElement('Reference');
        $reference->setAttribute('URI', '#' . $id);
        $signedInfo->appendChild($reference);

        $transforms = $dom->createElement('Transforms');
        $reference->appendChild($transforms);
        $this->filhoComAlgoritmo($dom, $transforms, 'Transform', self::ENVELOPED);
        $this->filhoComAlgoritmo($dom, $transforms, 'Transform', self::C14N);

        $this->filhoComAlgoritmo($dom, $reference, 'DigestMethod', $this->uriDigest);
        $reference->appendChild($dom->createElement('DigestValue', $digest));

        // 3) Assina o SignedInfo canonicalizado com a chave privada.
        $assinatura = $this->certificado->sign($signedInfo->C14N(false, false), $this->algoritmoOpenssl);
        $signature->appendChild($dom->createElement('SignatureValue', base64_encode($assinatura)));

        // 4) Certificado publico (sem cabecalho BEGIN/END).
        $keyInfo = $dom->createElement('KeyInfo');
        $x509Data = $dom->createElement('X509Data');
        $x509Data->appendChild($dom->createElement('X509Certificate', $this->certificado->publicKey->unFormated()));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);
    }

    private function filhoComAlgoritmo(DOMDocument $dom, DOMElement $pai, string $nome, string $algoritmo): void
    {
        $no = $dom->createElement($nome);
        $no->setAttribute('Algorithm', $algoritmo);
        $pai->appendChild($no);
    }
}
