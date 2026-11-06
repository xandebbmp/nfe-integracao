<?php
declare(strict_types=1);

/**
 * Portal do cliente - baixar o PDF ou o XML de uma nota da empresa.
 *
 * GET /v1/portal/arquivo.php?id=<documento_id>&formato=pdf[&download=1]
 * GET /v1/portal/arquivo.php?id=<documento_id>&formato=xml
 *
 * Igual a /v1/nfse/pdf.php e documento.php?formato=xml, mas com a sessao do
 * portal: so abre notas da empresa do usuario logado.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\NomeArquivoXml;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);

$id = (string)($_GET['id'] ?? '');
$documento = preg_match('/^[0-9a-f-]{36}$/i', $id) ? (new DocumentoRepositorio($api->pdo()))->buscar($id) : null;
// 404 (e nao 403) para nao revelar notas de outras empresas.
if ($documento === null || $documento['emitente_cnpj'] !== $usuario['cnpj']) {
    $api->erro(404, 'NAO_ENCONTRADO', 'Nota não encontrada.');
}
if (!in_array($documento['status'], StatusNfse::COM_NFSE, true)) {
    $api->erro(409, 'SEM_NFSE', 'Arquivo disponível apenas para nota autorizada.', ['status' => $documento['status']]);
}

if (($_GET['formato'] ?? 'pdf') === 'xml') {
    if (empty($documento['xml_autorizado'])) {
        $api->erro(404, 'SEM_XML', 'Nota ainda sem XML autorizado.');
    }
    $xml = (string)$documento['xml_autorizado'];
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . NomeArquivoXml::documento($documento) . '"');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
}

try {
    $pdf = (new ProvedorFactory($api->cfg))->para($documento['padrao'], $documento['emitente_cnpj'])->gerarPdf($documento);
} catch (RuntimeException $e) {
    $api->erro(502, 'PDF_INDISPONIVEL', $e->getMessage());
}
$nome = 'nfse-' . ($documento['numero_nfse'] ?: $documento['id']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
