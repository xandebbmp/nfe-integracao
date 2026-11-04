<?php
declare(strict_types=1);

/**
 * POST /v1/nfse/evento.php
 *   {
 *     "documento_id": "<uuid>",
 *     "tipo_evento": "101101",          (101101 = cancelamento; padrao)
 *     "motivo_codigo": "1",             (1=Erro na emissao, 2=Servico nao prestado, 9=Outros)
 *     "motivo": "Texto de 15 a 255 caracteres",
 *     "aguardar": 20                    (opcional)
 *   }
 *
 * Eventos aceitos dependem do padrao: veja GET /v1/nfse/capacidades.php.
 * Ex.: Salvador NAO aceita cancelamento por webservice -> HTTP 422 com a
 * orientacao da prefeitura.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\Solicitacoes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$acesso = $api->autenticar();
$api->exigirConsumidor($acesso);
$corpo = $api->corpoJson();
$documento = $api->documentoPermitido((string)($corpo['documento_id'] ?? ''), $acesso);

$resultado = (new Solicitacoes($api->pdo(), $api->cfg))->registrarEvento(
    $documento,
    (string)($corpo['tipo_evento'] ?? TipoEvento::CANCELAMENTO),
    (string)($corpo['motivo_codigo'] ?? ''),
    (string)($corpo['motivo'] ?? '')
);

if (!$resultado['ok']) {
    $api->erro($resultado['http'], $resultado['codigo'], $resultado['mensagem']);
}

$eventos = new EventoRepositorio($api->pdo());
$eventoId = (string)$resultado['evento']['id'];
$api->aguardar((int)($corpo['aguardar'] ?? 0), static function () use ($eventos, $eventoId): bool {
    $evento = $eventos->buscar($eventoId);
    return $evento !== null && !in_array($evento['status'], [StatusEvento::RECEBIDO, StatusEvento::PROCESSANDO], true);
});

$evento = $eventos->buscar($eventoId);
$api->responder($resultado['http'], [
    'mensagem' => $resultado['mensagem'],
    'evento' => DocumentoApresentador::evento($evento),
    'documento' => DocumentoApresentador::documento((new DocumentoRepositorio($api->pdo()))->buscar($documento['id'])),
]);
