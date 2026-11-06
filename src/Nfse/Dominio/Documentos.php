<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * Conferencia dos digitos verificadores de CPF e CNPJ (so numeros).
 * A mesma regra existe no portal (public/portal/portal.js) para avisar
 * na hora; aqui ela vale de verdade.
 */
final class Documentos
{
    public static function cpfValido(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int)$cpf[$i] * ($t + 1 - $i);
            }
            if (((10 * $soma) % 11) % 10 !== (int)$cpf[$t]) {
                return false;
            }
        }
        return true;
    }

    public static function cnpjValido(string $cnpj): bool
    {
        if (!preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        $base = substr($cnpj, 0, 12);
        $d1 = self::digitoCnpj($base);
        $d2 = self::digitoCnpj($base . $d1);
        return substr($cnpj, 12) === $d1 . $d2;
    }

    /** CPF (11) ou CNPJ (14) valido. */
    public static function valido(string $numero): bool
    {
        return strlen($numero) === 11 ? self::cpfValido($numero) : self::cnpjValido($numero);
    }

    private static function digitoCnpj(string $base): string
    {
        $pesos = strlen($base) === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        foreach (str_split($base) as $i => $digito) {
            $soma += (int)$digito * $pesos[$i];
        }
        $resto = $soma % 11;
        return (string)($resto < 2 ? 0 : 11 - $resto);
    }
}
