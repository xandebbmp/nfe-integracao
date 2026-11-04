<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Armazenamento;

use RuntimeException;

/**
 * Grava os arquivos em uma pasta (padrao: storage/xml/nfse).
 */
final class ArmazenamentoLocal implements ArmazenamentoArquivos
{
    public function __construct(private string $diretorio)
    {
    }

    public function salvar(string $caminho, string $conteudo, string $contentType = 'application/xml'): void
    {
        if (str_contains($caminho, '..')) {
            throw new RuntimeException('Caminho de arquivo invalido.');
        }

        $destino = rtrim($this->diretorio, '/\\') . '/' . ltrim($caminho, '/');
        $pasta = dirname($destino);
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new RuntimeException("Falha ao criar pasta: {$pasta}");
        }

        // Grava em arquivo temporario e renomeia: nunca fica arquivo pela metade.
        $temporario = $destino . '.tmp';
        if (file_put_contents($temporario, $conteudo) === false || !rename($temporario, $destino)) {
            @unlink($temporario);
            throw new RuntimeException("Falha ao gravar arquivo: {$destino}");
        }
    }
}
