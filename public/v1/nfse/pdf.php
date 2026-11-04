<?php
declare(strict_types=1);

/**
 * GET /v1/nfse/pdf.php?id=<documento_id>[&download=1]
 *
 * PDF da NFS-e autorizada:
 *   - Nacional: DANFSe oficial baixado do ADN;
 *   - Salvador: espelho gerado pela API (Salvador nao fornece PDF por webservice).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$documento = $api->documentoPermitido((string)($_GET['id'] ?? ''), $api->autenticar());

if (!in_array($documento['status'], StatusNfse::COM_NFSE, true)) {
    $api->erro(409, 'SEM_NFSE', 'PDF disponivel apenas para NFS-e autorizada.', ['status' => $documento['status']]);
}

try {
    $pdf = (new ProvedorFactory($api->cfg))->para($documento['padrao'])->gerarPdf($documento);
} catch (RuntimeException $e) {
    $api->erro(502, 'PDF_INDISPONIVEL', $e->getMessage());
}

$nome = 'nfse-' . ($documento['numero_nfse'] ?: $documento['id']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
