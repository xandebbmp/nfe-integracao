<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

/**
 * Deixa um certificado A1 (.pfx) legivel pelo PHP com OpenSSL 3.
 *
 * Por que existe: muitos .pfx emitidos pelas certificadoras usam uma
 * criptografia antiga (RC2-40 / 3DES). O OpenSSL 3 nao abre esses arquivos
 * sem o "provider legacy" e o PHP devolve "digital envelope routines::unsupported",
 * mesmo com a senha certa.
 *
 * O que faz:
 *   1. Se o PHP ja abre o arquivo, devolve o mesmo conteudo (nada muda).
 *   2. Se nao abre, usa o programa "openssl" do computador (com a opcao
 *      -legacy, ou o LibreSSL do macOS, que abre esses arquivos direto)
 *      para extrair certificado e chave, e monta um .pfx novo, com a mesma
 *      senha e criptografia atual.
 *   3. Se nada disso funcionar (ex.: senha errada), devolve o original e o
 *      erro normal aparece depois, na leitura.
 *
 * A senha vai por variavel de ambiente (nao aparece na lista de processos) e
 * o arquivo temporario e apagado na hora.
 */
final class PfxCompativel
{
    /** Onde procurar o programa openssl (Mac com Homebrew, Linux). */
    private const PROGRAMAS = ['/opt/homebrew/bin/openssl', '/usr/local/bin/openssl', '/usr/bin/openssl', 'openssl'];

    public static function normalizar(string $pfx, string $senha): string
    {
        $lido = [];
        if (@openssl_pkcs12_read($pfx, $lido, $senha)) {
            return $pfx;
        }
        while (openssl_error_string() !== false) {
            // limpa a fila de erros do OpenSSL para nao poluir a proxima leitura
        }

        $pem = self::extrairPem($pfx, $senha);
        if ($pem === null) {
            return $pfx;
        }

        $chave = openssl_pkey_get_private($pem);
        $certificados = self::certificadosDoPem($pem);
        if ($chave === false || $certificados === []) {
            return $pfx;
        }

        // O certificado da empresa e o que combina com a chave; os outros sao a cadeia.
        $principal = null;
        $cadeia = [];
        foreach ($certificados as $cert) {
            if ($principal === null && openssl_x509_check_private_key($cert, $chave)) {
                $principal = $cert;
            } else {
                $cadeia[] = $cert;
            }
        }
        if ($principal === null) {
            return $pfx;
        }

        $novo = '';
        $opcoes = $cadeia !== [] ? ['extracerts' => $cadeia] : [];
        if (!openssl_pkcs12_export($principal, $novo, $chave, $senha, $opcoes)) {
            return $pfx;
        }
        return $novo;
    }

    /** Usa o programa openssl para abrir o .pfx antigo. Devolve PEM (certificados + chave) ou null. */
    private static function extrairPem(string $pfx, string $senha): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $arquivo = tempnam(sys_get_temp_dir(), 'pfx_');
        if ($arquivo === false) {
            return null;
        }
        try {
            @chmod($arquivo, 0600);
            file_put_contents($arquivo, $pfx);

            foreach (self::PROGRAMAS as $programa) {
                if ($programa !== 'openssl' && !is_executable($programa)) {
                    continue;
                }
                // Primeiro com -legacy (OpenSSL 3); depois sem (LibreSSL / OpenSSL 1.1).
                foreach ([['-legacy'], []] as $extra) {
                    $comando = array_merge([$programa, 'pkcs12', '-in', $arquivo, '-nodes', '-passin', 'env:PFX_SENHA'], $extra);
                    $saida = self::executar($comando, $senha);
                    if ($saida !== null && str_contains($saida, 'PRIVATE KEY') && str_contains($saida, 'BEGIN CERTIFICATE')) {
                        return $saida;
                    }
                }
            }
            return null;
        } finally {
            @unlink($arquivo);
        }
    }

    private static function executar(array $comando, string $senha): ?string
    {
        $ambiente = ['PFX_SENHA' => $senha, 'PATH' => (string)(getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin:/opt/homebrew/bin')];
        $processo = @proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, null, $ambiente);
        if (!is_resource($processo)) {
            return null;
        }
        $saida = (string)stream_get_contents($tubos[1]);
        stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        return proc_close($processo) === 0 ? $saida : null;
    }

    /** @return list<string> certificados em PEM, na ordem do arquivo */
    private static function certificadosDoPem(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
        return $m[0];
    }
}
