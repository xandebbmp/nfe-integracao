<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use NFePHP\Common\Certificate;
use RuntimeException;

/**
 * Carrega o certificado A1 (.pfx) do emitente e prepara o que cada uso precisa:
 *
 *   - certificado(): objeto do sped-common, usado para ASSINAR XML;
 *   - arquivoPem():  arquivo temporario PEM (certificado + chave), usado pelo
 *                    cURL na conexao mTLS (o Sefin Nacional e Salvador exigem
 *                    o certificado do cliente na conexao HTTPS).
 *
 * O arquivo PEM e criado com permissao 0600 e apagado ao final do processo.
 */
final class CertificadoCliente
{
    private Certificate $certificado;
    private ?string $arquivoPem = null;

    public function __construct(Certificate $certificado)
    {
        $this->certificado = $certificado;
    }

    /**
     * Le o .pfx do disco. Falha cedo se o arquivo nao existir ou estiver vencido.
     */
    public static function doArquivo(string $caminhoPfx, string $senha): self
    {
        $conteudo = is_file($caminhoPfx) ? file_get_contents($caminhoPfx) : false;
        if ($conteudo === false || $conteudo === '') {
            throw new RuntimeException('Certificado digital (.pfx) nao encontrado ou vazio. Verifique o caminho do certificado do emitente (certPfxPath ou emitentes.*.certificado).');
        }

        return self::doConteudo($conteudo, $senha);
    }

    /**
     * Certificado de um emitente da configuracao: do cadastro no banco
     * (criptografado) ou do arquivo .pfx no disco.
     *
     * @param string $chaveCadastro chave do cofre (cfg['cadastro']['chave'])
     */
    public static function doEmitente(array $emitente, string $chaveCadastro): self
    {
        $cifrado = $emitente['certificado']['cifrado'] ?? null;
        if (is_string($cifrado) && $cifrado !== '') {
            $aberto = (new CofreCertificado($chaveCadastro))->decifrar($cifrado);
            return self::doConteudo($aberto['pfx'], $aberto['senha']);
        }
        $arquivo = (string)($emitente['certificado']['arquivo'] ?? '');
        if ($arquivo === '') {
            throw new RuntimeException('A empresa ' . ($emitente['cnpj'] ?? '') . ' nao tem certificado: envie o .pfx pelo cadastro de empresas (ou configure o arquivo).');
        }
        return self::doArquivo($arquivo, (string)($emitente['certificado']['senha'] ?? ''));
    }

    public static function doConteudo(string $conteudo, string $senha): self
    {
        $certificado = Certificate::readPfx($conteudo, $senha);
        if ($certificado->isExpired()) {
            throw new RuntimeException('Certificado digital vencido em ' . $certificado->getValidTo()->format('d/m/Y') . '.');
        }

        return new self($certificado);
    }

    public function certificado(): Certificate
    {
        return $this->certificado;
    }

    public function arquivoPem(): string
    {
        if ($this->arquivoPem !== null && is_file($this->arquivoPem)) {
            return $this->arquivoPem;
        }

        $arquivo = tempnam(sys_get_temp_dir(), 'nfse_cert_');
        if ($arquivo === false) {
            throw new RuntimeException('Falha ao criar arquivo temporario do certificado.');
        }
        chmod($arquivo, 0600);

        $pem = (string)$this->certificado->publicKey . "\n" . (string)$this->certificado->privateKey;
        if (file_put_contents($arquivo, $pem) === false) {
            @unlink($arquivo);
            throw new RuntimeException('Falha ao gravar arquivo temporario do certificado.');
        }

        $this->arquivoPem = $arquivo;
        register_shutdown_function(static function () use ($arquivo): void {
            @unlink($arquivo);
        });

        return $arquivo;
    }

    public function __destruct()
    {
        if ($this->arquivoPem !== null) {
            @unlink($this->arquivoPem);
        }
    }
}
