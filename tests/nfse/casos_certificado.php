<?php
declare(strict_types=1);

use NFePHP\Common\Certificate;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\PfxCompativel;

/**
 * Certificado .pfx antigo (criptografia RC2/3DES): o PHP com OpenSSL 3 nao
 * abre direto; PfxCompativel converte para o formato atual.
 */
function testesCertificadoUnidade(): void
{
    echo "\n[Certificado] .pfx antigo (RC2) convertido automaticamente\n";

    $pasta = sys_get_temp_dir() . '/pfx_teste_' . getmypid();
    @mkdir($pasta, 0700, true);
    $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'EMPRESA ANTIGA:35400194000105'], $chave), null, $chave, 365);
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($chave, $chavePem);
    file_put_contents("{$pasta}/entrada.pem", $certPem . $chavePem);

    // Gera um .pfx no formato antigo com o programa openssl (precisa de -legacy no OpenSSL 3).
    $gerou = false;
    foreach ([['-legacy'], []] as $extra) {
        $cmd = array_merge(['openssl', 'pkcs12', '-export', '-in', "{$pasta}/entrada.pem", '-out', "{$pasta}/antigo.pfx",
            '-passout', 'pass:senha123', '-certpbe', 'PBE-SHA1-RC2-40', '-keypbe', 'PBE-SHA1-3DES'], $extra);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t);
        if (is_resource($p)) {
            stream_get_contents($t[1]);
            stream_get_contents($t[2]);
            if (proc_close($p) === 0 && is_file("{$pasta}/antigo.pfx")) {
                $gerou = true;
                break;
            }
        }
    }
    if (!$gerou) {
        echo "  (pulado: programa openssl indisponivel)\n";
        exec('rm -rf ' . escapeshellarg($pasta));
        return;
    }

    $antigo = (string)file_get_contents("{$pasta}/antigo.pfx");
    $lido = [];
    $phpAbreDireto = @openssl_pkcs12_read($antigo, $lido, 'senha123');
    while (openssl_error_string() !== false) {
    }

    $novo = PfxCompativel::normalizar($antigo, 'senha123');
    $cert = Certificate::readPfx($novo, 'senha123');
    Apoio::igual('35400194000105', $cert->getCnpj() ?: preg_replace('/\D/', '', (string)$cert->getCompanyName()), 'pfx antigo abre depois de convertido');
    Apoio::verificar($phpAbreDireto ? $novo === $antigo : $novo !== $antigo, 'so converte quando o PHP nao abre o original');
    Apoio::igual($novo, PfxCompativel::normalizar($novo, 'senha123'), 'pfx atual nao e alterado');
    Apoio::igual($antigo, PfxCompativel::normalizar($antigo, 'senha-errada'), 'senha errada devolve o original (erro aparece na leitura)');

    $cliente = CertificadoCliente::doConteudo($antigo, 'senha123');
    Apoio::verificar(str_contains($cliente->certificado()->getCompanyName(), 'EMPRESA ANTIGA'), 'CertificadoCliente le o pfx antigo');

    exec('rm -rf ' . escapeshellarg($pasta));
}
