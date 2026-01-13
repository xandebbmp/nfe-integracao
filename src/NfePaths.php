<?php
declare(strict_types=1);

namespace App;

final class NfePaths
{
    public static function ensure(array $cfg): void
    {
        $dirs = [
            self::xml($cfg, 'gerados'),
            self::xml($cfg, 'assinados'),
            self::xml($cfg, 'autorizados'),
            self::xml($cfg, 'eventos'),
            self::pdf($cfg, 'danfe'),
            self::pdf($cfg, 'eventos'),
            rtrim($cfg['pathLogs'], '/\\'),
        ];

        foreach ($dirs as $d) {
            if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
                throw new \RuntimeException("Falha ao criar pasta: $d");
            }
        }
    }

    public static function xml(array $cfg, string $subdir): string
    {
        return rtrim($cfg['pathXml'], '/\\') . DIRECTORY_SEPARATOR . $subdir;
    }

    public static function pdf(array $cfg, string $subdir): string
    {
        return rtrim($cfg['pathPdf'], '/\\') . DIRECTORY_SEPARATOR . $subdir;
    }
}
