<?php
declare(strict_types=1);

/** GET /v1/emissor/xml.php?id=...   XML autorizado de uma nota da empresa. */

use Xande\NfeIntegracao\Nfse\Servico\NomeArquivoXml;

$metodos = ['GET'];
require __DIR__ . '/_inicio.php';

$documento = $emissor->documentoDaEmpresa($exigirUsuario(), (string)($_GET['id'] ?? ''));
if ($documento === null || empty($documento['xml_autorizado'])) {
    $api->erro(404, 'SEM_XML', 'XML disponivel quando a nota for autorizada.');
}
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . NomeArquivoXml::documento($documento) . '"');
echo $documento['xml_autorizado'];
