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
 *   202  lote aceito (ha documentos processando ou recusados)
 *   200  lote aceito e todos os documentos ja finalizados (quando usar aguardar)
 *   422  requisicao invalida, ou nenhum documento pode ser gravado
 *
 * Documento com dados invalidos NAO derruba o lote: ele e gravado como
 * REJEITADO (situacao_recebimento = INVALIDO), aparece no monitor com o
 * motivo e a sugestao de correcao, e pode ser reenviado com a mesma
 * referencia. So vai para "recusados" o que nem da para gravar
 * (padrao desconhecido, emitente nao liberado, referencia repetida no lote).
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
    $api->erro(422, 'LOTE_INVALIDO', $resultado['erro']);
}

// Separa o que foi gravado (aparece no monitor) do que foi recusado sem gravar.
$gravados = array_values(array_filter($resultado['documentos'], static fn($d) => isset($d['id']) && $d['situacao_recebimento'] !== RecebimentoLote::RECUSADO));
$recusados = array_values(array_filter($resultado['documentos'], static fn($d) => $d['situacao_recebimento'] === RecebimentoLote::RECUSADO));

if ($gravados === []) {
    $api->erro(422, 'DOCUMENTOS_RECUSADOS', 'Nenhum documento pode ser gravado. Veja o motivo de cada um.', [
        'recusados' => $recusados,
    ]);
}

$repositorio = new DocumentoRepositorio($api->pdo());
$carregar = static fn(): array => array_map(static fn(array $d) => $repositorio->buscar($d['id']), $gravados);

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
foreach ($carregar() as $i => $doc) {
    $saida = DocumentoApresentador::documento($doc, true);
    $saida['indice'] = $gravados[$i]['indice'];
    $saida['situacao_recebimento'] = $gravados[$i]['situacao_recebimento'];
    $todosFinalizados = $todosFinalizados && $saida['finalizado'];
    $documentos[] = $saida;
}

$api->responder($todosFinalizados && $recusados === [] ? 200 : 202, [
    'lote_id' => $resultado['lote_id'],
    'resumo' => array_count_values(array_column($documentos, 'status')) + ($recusados !== [] ? ['RECUSADO' => count($recusados)] : []),
    'documentos' => $documentos,
    'recusados' => $recusados,
    'links' => ['lote' => '/v1/nfse/lote.php?id=' . $resultado['lote_id']],
]);
