<?php
declare(strict_types=1);

/**
 * POST /v1/nfse/emitir.php
 *
 * Recebe um lote com 1 ou mais documentos NFS-e e coloca na fila.
 *
 * Corpo:
 *   {
 *     "padrao": "NACIONAL" | "SALVADOR",     (pode ser sobrescrito por documento)
 *     "documentos": [ { "referencia": "PEDIDO-123", "servico": {...}, "valores": {...}, "tomador": {...} } ]
 *   }
 *
 * Query opcional:
 *   ?aguardar=30  espera ate 30s pelo resultado (max. config api.max_aguardar_segundos)
 *
 * Respostas:
 *   202  lote aceito (documentos ainda processando)
 *   200  lote aceito e todos os documentos ja finalizados (quando usar aguardar)
 *   422  algum documento invalido: NADA foi gravado, veja erro.documentos
 *
 * Exemplo completo: docs/nfse-guia-api.md
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\RecebimentoLote;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$consumidor = $api->exigirConsumidor($api->autenticar());
$corpo = $api->corpoJson();

$resultado = (new RecebimentoLote($api->pdo(), $api->cfg))->receber($consumidor, $corpo);

if (!$resultado['ok']) {
    $api->erro(422, 'DOCUMENTOS_INVALIDOS', 'Um ou mais documentos sao invalidos. Nenhum documento foi gravado.', [
        'documentos' => $resultado['erros'],
    ]);
}

$repositorio = new DocumentoRepositorio($api->pdo());
$ids = array_column($resultado['documentos'], 'id');
$situacao = array_column($resultado['documentos'], 'situacao_recebimento', 'id');

$carregar = static function () use ($repositorio, $ids): array {
    return array_map(static fn(string $id) => $repositorio->buscar($id), $ids);
};

$api->aguardar((int)($_GET['aguardar'] ?? 0), static function () use ($carregar): bool {
    foreach ($carregar() as $doc) {
        if (!DocumentoApresentador::documento($doc)['finalizado']) {
            return false;
        }
    }
    return true;
});

$documentos = [];
$todosFinalizados = true;
foreach ($carregar() as $doc) {
    $saida = DocumentoApresentador::documento($doc, true);
    $saida['situacao_recebimento'] = $situacao[$doc['id']];
    $todosFinalizados = $todosFinalizados && $saida['finalizado'];
    $documentos[] = $saida;
}

$api->responder($todosFinalizados ? 200 : 202, [
    'lote_id' => $resultado['lote_id'],
    'resumo' => array_count_values(array_column($documentos, 'status')),
    'documentos' => $documentos,
    'links' => ['lote' => '/v1/nfse/lote.php?id=' . $resultado['lote_id']],
]);
