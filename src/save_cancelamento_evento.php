<?php
declare(strict_types=1);

/**
 * Salva o XML do evento de cancelamento (procEventoNFe) em:
 * storage/xml/eventos/canc-<CHAVE>-procEvento.xml
 */

function salvarEventoCancelamento(string $baseDir, string $chave, string $xmlEventoProc): string
{
    $xmlDir = realpath($baseDir) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'xml';
    $dirEventos = $xmlDir . DIRECTORY_SEPARATOR . 'eventos';

    if (!is_dir($dirEventos)) {
        if (!@mkdir($dirEventos, 0775, true) && !is_dir($dirEventos)) {
            throw new RuntimeException("Falha ao criar diretório: {$dirEventos}");
        }
    }

    $arquivo = $dirEventos . DIRECTORY_SEPARATOR . "canc-{$chave}-procEvento.xml";

    if (file_put_contents($arquivo, $xmlEventoProc) === false) {
        throw new RuntimeException("Falha ao salvar XML do cancelamento em: {$arquivo}");
    }

    return $arquivo;
}
