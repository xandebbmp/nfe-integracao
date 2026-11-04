<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse;

use RuntimeException;

/**
 * Junta config/nfe.php (emitente, certificado, ambiente) com config/nfse.php.
 *
 * Retorna um array simples. Chaves principais:
 *   tpAmb, certPfxPath, certPassword, emitente[], nacional[], salvador[],
 *   fila[], api[], consumidores[], monitor_token.
 */
final class NfseConfig
{
    private function __construct()
    {
    }

    public static function carregar(?string $diretorioConfig = null): array
    {
        $dir = $diretorioConfig ?? dirname(__DIR__, 2) . '/config';

        $nfe = is_file($dir . '/nfe.php') ? require $dir . '/nfe.php' : [];
        $nfse = is_file($dir . '/nfse.php') ? require $dir . '/nfse.php' : [];

        if (!is_array($nfe) || !is_array($nfse)) {
            throw new RuntimeException('Arquivos de configuracao fiscal invalidos.');
        }

        return self::montar($nfe, $nfse);
    }

    /**
     * Separado de carregar() para poder ser usado nos testes.
     */
    public static function montar(array $nfe, array $nfse): array
    {
        $cfg = $nfse;

        $cfg['tpAmb'] = (int)($nfse['tpAmb'] ?? 0) ?: (int)($nfe['tpAmb'] ?? 2);
        $cfg['certPfxPath'] = (string)($nfe['certPfxPath'] ?? '');
        $cfg['certPassword'] = (string)($nfe['certPassword'] ?? '');

        $cfg['emitente'] = $nfse['emitente'] ?? [];
        $cnpj = (string)($cfg['emitente']['cnpj'] ?? '');
        if ($cnpj === '') {
            $cnpj = (string)($nfe['cnpj'] ?? '');
        }
        $cfg['emitente']['cnpj'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cnpj) ?? '');
        $cfg['emitente']['razao_social'] = (string)($cfg['emitente']['razao_social'] ?? ($nfe['razao'] ?? ''));

        // Consumidor padrao: quem ja usa o apiToken da NF-e continua funcionando.
        $consumidores = $nfse['consumidores'] ?? [];
        $tokenNfe = trim((string)($nfe['apiToken'] ?? ''));
        if ($consumidores === [] && $tokenNfe !== '') {
            $consumidores['PADRAO'] = ['token' => $tokenNfe];
        }
        $cfg['consumidores'] = $consumidores;

        if (!in_array($cfg['tpAmb'], [1, 2], true)) {
            throw new RuntimeException('tpAmb invalido na configuracao (use 1 ou 2).');
        }

        return $cfg;
    }
}
