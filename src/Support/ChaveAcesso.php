<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Support;

use NFePHP\Common\Keys;

final class ChaveAcesso
{
    /**
     * Deriva um cNF estavel para (emitente, ambiente, modelo, serie, nNF).
     *
     * Retentativas da mesma nota geram a mesma chave de acesso, entao a SEFAZ
     * responde duplicidade (204) em vez de autorizar/rejeitar uma chave nova (539).
     * O HMAC com segredo mantem o cNF imprevisivel para terceiros.
     */
    public static function cnfDeterministico(
        string $secret,
        string $cnpj,
        int $tpAmb,
        int $mod,
        int $serie,
        int $nNF
    ): string {
        if ($secret === '') {
            throw new \InvalidArgumentException('Segredo do cNF vazio.');
        }

        for ($tentativa = 0; $tentativa < 100; $tentativa++) {
            $msg = implode('|', [$cnpj, $tpAmb, $mod, $serie, $nNF, $tentativa]);
            $hash = hash_hmac('sha256', $msg, $secret);
            $cnf = str_pad((string)(hexdec(substr($hash, 0, 12)) % 100000000), 8, '0', STR_PAD_LEFT);

            if ((int)$cnf !== $nNF && Keys::cNFIsValid($cnf)) {
                return $cnf;
            }
        }

        throw new \RuntimeException('Nao foi possivel derivar um cNF valido.');
    }

    /**
     * Segredo do cNF: cnfSecret da config ou, na falta dele, hash do certificado PFX.
     */
    public static function segredo(array $cfg): string
    {
        $secret = trim((string)($cfg['cnfSecret'] ?? ''));
        if ($secret !== '') {
            return $secret;
        }

        $pfxPath = (string)($cfg['certPfxPath'] ?? '');
        $pfx = is_file($pfxPath) ? @file_get_contents($pfxPath) : false;
        if ($pfx === false || $pfx === '') {
            throw new \RuntimeException('Defina cnfSecret em config/nfe.local.php (certificado PFX indisponivel).');
        }

        return hash('sha256', $pfx);
    }

    /**
     * CNPJ sem mascara, preservando letras (CNPJ alfanumerico a partir de 07/2026).
     */
    public static function normalizarCnpj(string $cnpj): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $cnpj) ?? '');
    }
}
