<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Armazenamento;

/**
 * Onde guardar copia dos XMLs fora do banco (guarda legal de 5 anos).
 *
 * Implementacoes:
 *   - ArmazenamentoLocal: pasta no servidor (ou disco montado).
 *   - ArmazenamentoS3: AWS S3 e compativeis (MinIO, Wasabi, R2, Spaces...).
 *
 * Para outro provedor de nuvem, crie uma classe com este metodo e registre
 * em ArmazenamentoFactory.
 */
interface ArmazenamentoArquivos
{
    /**
     * Grava (ou sobrescreve) o arquivo. Lanca excecao se falhar.
     *
     * @param string $caminho caminho relativo, com "/" (ex.: producao/41986662000160/2026/11/<id>/nfse.xml)
     */
    public function salvar(string $caminho, string $conteudo, string $contentType = 'application/xml'): void;
}
