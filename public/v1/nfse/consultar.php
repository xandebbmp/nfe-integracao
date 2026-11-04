<?php
declare(strict_types=1);

/**
 * POST /v1/nfse/consultar.php
 *   { "documento_id": "<uuid>", "aguardar": 20 }
 *
 * Pede ao worker para consultar o documento no fisco:
 *   - documento sem NFS-e (PROCESSANDO/ERRO/REJEITADO): verifica se o envio
 *     virou nota (recupera notas autorizadas apos timeout);
 *   - documento com NFS-e: verifica a situacao atual (cancelada? substituida?).
 *
 * "aguardar" (opcional, segundos) espera o resultado da consulta.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\Solicitacoes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$acesso = $api->autenticar();
$corpo = $api->corpoJson();
$documento = $api->documentoPermitido((string)($corpo['documento_id'] ?? ''), $acesso);

$resultado = (new Solicitacoes($api->pdo(), $api->cfg))->consultar($documento);
if (!$resultado['ok']) {
    $api->erro(409, 'CONSULTA_NAO_PERMITIDA', $resultado['mensagem'], [
        'documento' => DocumentoApresentador::documento($documento),
    ]);
}

$fila = new FilaRepositorio($api->pdo());
$api->aguardar((int)($corpo['aguardar'] ?? 0), static fn(): bool => !$fila->existePendente(FilaRepositorio::CONSULTAR, $documento['id']));

$atual = (new DocumentoRepositorio($api->pdo()))->buscar($documento['id']);
$api->responder(202, [
    'mensagem' => $resultado['mensagem'],
    'consulta_concluida' => !$fila->existePendente(FilaRepositorio::CONSULTAR, $documento['id']),
    'documento' => DocumentoApresentador::documento($atual, true),
]);
