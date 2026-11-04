<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/lote.php?id=<lote_id>[&aguardar=30][&xml=1]
 *
 * Situacao de todos os documentos de um lote.
 * xml=1 inclui o XML autorizado de cada documento.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$acesso = $api->autenticar();

$id = (string)($_GET['id'] ?? '');
$repositorio = new DocumentoRepositorio($api->pdo());
$lote = DocumentoRepositorio::uuidValido($id) ? $repositorio->buscarLote($id) : null;
if ($lote === null || (!$acesso['painel'] && $lote['consumidor'] !== $acesso['consumidor'])) {
    $api->erro(404, 'NAO_ENCONTRADO', 'Lote nao encontrado.');
}

$api->aguardar((int)($_GET['aguardar'] ?? 0), static function () use ($repositorio, $id): bool {
    foreach ($repositorio->listarDoLote($id) as $doc) {
        if (!DocumentoApresentador::documento($doc)['finalizado']) {
            return false;
        }
    }
    return true;
});

$incluirXml = ($_GET['xml'] ?? '') === '1';
$documentos = array_map(
    static fn(array $doc) => DocumentoApresentador::documento($doc, $incluirXml),
    $repositorio->listarDoLote($id)
);

$api->responder(200, [
    'lote_id' => $lote['id'],
    'criado_em' => $lote['criado_em'],
    'resumo' => array_count_values(array_column($documentos, 'status')),
    'documentos' => $documentos,
]);
