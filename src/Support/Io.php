<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Support;

final class Io
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Falha ao criar diretório: $dir");
            }
        }
    }

    public static function join(string ...$parts): string
    {
        $parts = array_map(fn($p) => trim($p, "/\\"), $parts);
        return implode(DIRECTORY_SEPARATOR, array_filter($parts, fn($p) => $p !== ''));
    }

    public static function ts(): string
    {
        return date('Ymd_His');
    }

    public static function save(string $dir, string $filename, string $content): string
    {
        self::ensureDir($dir);
        $filename = self::safeName($filename);
        $path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $filename;

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Falha ao gravar arquivo: $path");
        }
        return $path;
    }

    public static function onlyDigits(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }

    public static function safeName(string $s): string
    {
        $s = preg_replace('/[^a-z0-9._-]/i', '_', $s) ?? 'x';
        return trim($s, '_');
    }
}
