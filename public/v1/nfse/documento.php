<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/documento.php?id=<documento_id>[&formato=xml][&historico=1][&xml=0]
 *
 *   formato=xml  devolve o XML autorizado puro (Content-Type: application/xml)
 *   historico=1  inclui a linha do tempo do monitor (envios, consultas...)
 *   xml=0        nao inclui o XML autorizado no JSON (usado pelo painel)
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$acesso = $api->autenticar();
$documento = $api->documentoPermitido((string)($_GET['id'] ?? ''), $acesso);

if (($_GET['formato'] ?? '') === 'xml') {
    if (empty($documento['xml_autorizado'])) {
        $api->erro(404, 'SEM_XML', 'Documento ainda nao possui XML autorizado.', ['status' => $documento['status']]);
    }
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: inline; filename="nfse-' . $documento['id'] . '.xml"');
    echo $documento['xml_autorizado'];
    exit;
}

$saida = DocumentoApresentador::documento(
    $documento,
    ($_GET['xml'] ?? '1') !== '0',
    (new EventoRepositorio($api->pdo()))->listarDoDocumento($documento['id'])
);

if (($_GET['historico'] ?? '') === '1') {
    $saida['historico'] = array_map(
        [DocumentoApresentador::class, 'monitor'],
        (new MonitorRepositorio($api->pdo()))->historico($documento['id'])
    );
}

$api->responder(200, ['documento' => $saida]);
