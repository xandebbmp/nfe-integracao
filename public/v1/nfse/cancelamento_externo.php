<?php
declare(strict_types=1);

/**
 * POST /v1/nfse/cancelamento_externo.php
 *   {
 *     "documento_id": "<uuid>",
 *     "numero_processo": "2026/123456",   (protocolo do pedido na prefeitura)
 *     "motivo_codigo": "1",               (1=Erro na emissao, 2=Servico nao prestado, 9=Outros)
 *     "motivo": "Texto de 15 a 255 caracteres",
 *     "observacao": "opcional"
 *   }
 *
 * Para padroes que NAO cancelam por webservice (Salvador): depois que a
 * prefeitura cancelar a nota no portal, registre aqui para a API, o monitor
 * e os webhooks ficarem coerentes. A API nao fala com a prefeitura nesta rota.
 *
 * Respostas: 201 registrado | 409 nota nao esta AUTORIZADA |
 *            422 padrao cancela pela API (use evento.php) | 400 dados invalidos
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\Solicitacoes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$acesso = $api->autenticar();
$consumidor = $api->exigirConsumidor($acesso);
$corpo = $api->corpoJson();
$documento = $api->documentoPermitido((string)($corpo['documento_id'] ?? ''), $acesso);

$resultado = (new Solicitacoes($api->pdo(), $api->cfg))->registrarCancelamentoExterno(
    $documento,
    $consumidor,
    (string)($corpo['numero_processo'] ?? ''),
    (string)($corpo['motivo_codigo'] ?? ''),
    (string)($corpo['motivo'] ?? ''),
    isset($corpo['observacao']) ? (string)$corpo['observacao'] : null
);

if (!$resultado['ok']) {
    $api->erro($resultado['http'], $resultado['codigo'], $resultado['mensagem']);
}

$api->responder(201, [
    'mensagem' => $resultado['mensagem'],
    'evento' => DocumentoApresentador::evento($resultado['evento']),
    'documento' => DocumentoApresentador::documento((new DocumentoRepositorio($api->pdo()))->buscar($documento['id'])),
]);
