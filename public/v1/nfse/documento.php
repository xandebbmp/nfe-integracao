<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/documento.php?id=<documento_id>[&formato=xml][&historico=1][&xml=0]
 * GET /v1/nfse/documento.php?id=<documento_id>&evento=<evento_id>&formato=xml
 *
 *   formato=xml  devolve o XML autorizado puro (Content-Type: application/xml).
 *                NF-e: <chave>-procNFe.xml (nota + protocolo da SEFAZ).
 *   evento=...   com formato=xml: XML do evento registrado (cancelamento,
 *                Carta de Correcao...). NF-e: procEventoNFe.
 *   historico=1  inclui a linha do tempo do monitor (envios, consultas...)
 *   xml=0        nao inclui o XML autorizado no JSON (usado pelo painel)
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\NomeArquivoXml;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$acesso = $api->autenticar();
$documento = $api->documentoPermitido((string)($_GET['id'] ?? ''), $acesso);

if (($_GET['formato'] ?? '') === 'xml') {
    $eventoId = trim((string)($_GET['evento'] ?? ''));

    if ($eventoId !== '') {
        // XML de um evento: precisa ser deste documento e estar registrado.
        $evento = preg_match('/^[0-9a-f-]{36}$/i', $eventoId) ? (new EventoRepositorio($api->pdo()))->buscar($eventoId) : null;
        if ($evento === null || $evento['documento_id'] !== $documento['id']) {
            $api->erro(404, 'NAO_ENCONTRADO', 'Evento nao encontrado neste documento.');
        }
        if ($evento['status'] !== StatusEvento::REGISTRADO || empty($evento['xml_retorno'])) {
            $api->erro(404, 'SEM_XML', 'Evento sem XML registrado (ainda em andamento, rejeitado ou registrado manualmente).', ['status' => $evento['status']]);
        }
        $xml = (string)$evento['xml_retorno'];
        $nome = NomeArquivoXml::evento($documento, $evento);
    } else {
        if (empty($documento['xml_autorizado'])) {
            $api->erro(404, 'SEM_XML', 'Documento ainda nao possui XML autorizado.', ['status' => $documento['status']]);
        }
        $xml = (string)$documento['xml_autorizado'];
        $nome = NomeArquivoXml::documento($documento);
    }

    header('Content-Type: application/xml; charset=utf-8');
    // ?download=1 forca "salvar como"; sem ele o navegador pode mostrar o XML.
    $disposicao = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
    header("Content-Disposition: {$disposicao}; filename=\"{$nome}\"");
    echo $xml;
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
