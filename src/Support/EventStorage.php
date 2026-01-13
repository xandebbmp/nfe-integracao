<?php
declare(strict_types=1);

final class EventStorage
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Falha ao criar diretório: {$dir}");
            }
        }
    }

    public static function padSeq(int $seq): string
    {
        return str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    }

    public static function saveXml(string $baseDir, string $relativePath, string $xml): string
    {
        $dir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($relativePath, DIRECTORY_SEPARATOR);
        self::ensureDir($dir);

        // nome final do arquivo já deve vir pronto por quem chama
        $tmpFile = tempnam($dir, 'tmp_');
        if ($tmpFile === false) {
            throw new RuntimeException("Falha ao criar arquivo temporário em: {$dir}");
        }

        if (file_put_contents($tmpFile, $xml) === false) {
            @unlink($tmpFile);
            throw new RuntimeException("Falha ao gravar XML temporário em: {$tmpFile}");
        }

        return $tmpFile;
    }

    public static function moveTo(string $tmpFile, string $finalFile): void
    {
        $finalDir = dirname($finalFile);
        self::ensureDir($finalDir);

        if (!@rename($tmpFile, $finalFile)) {
            // fallback Windows
            if (!@copy($tmpFile, $finalFile)) {
                @unlink($tmpFile);
                throw new RuntimeException("Falha ao mover XML para: {$finalFile}");
            }
            @unlink($tmpFile);
        }
    }

    public static function pathEventoCanc(string $xmlDir, string $chave): string
    {
        return $xmlDir . DIRECTORY_SEPARATOR . "eventos" . DIRECTORY_SEPARATOR . "canc-{$chave}-procEvento.xml";
    }

    public static function pathEventoCce(string $xmlDir, string $chave, int $seq): string
    {
        $s = self::padSeq($seq);
        return $xmlDir . DIRECTORY_SEPARATOR . "eventos" . DIRECTORY_SEPARATOR . "cce-{$chave}-{$s}-procEvento.xml";
    }

    public static function pathInut(string $xmlDir, string $idInut): string
    {
        // idInut é algo que você já consegue montar no fluxo de inutilização
        return $xmlDir . DIRECTORY_SEPARATOR . "eventos" . DIRECTORY_SEPARATOR . "inut-{$idInut}-procInut.xml";
    }
}
