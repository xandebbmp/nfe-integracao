<?php
declare(strict_types=1);

/** GET /v1/emissor/pdf.php?id=...   DANFSe (PDF) de uma nota autorizada da empresa. */

use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;

$metodos = ['GET'];
require __DIR__ . '/_inicio.php';

$documento = $emissor->documentoDaEmpresa($exigirUsuario(), (string)($_GET['id'] ?? ''));
if ($documento === null) {
    $api->erro(404, 'NAO_ENCONTRADO', 'Nota nao encontrada.');
}
if (!in_array($documento['status'], StatusNfse::COM_NFSE, true)) {
    $api->erro(409, 'SEM_NFSE', 'O PDF fica disponivel quando a nota for autorizada.');
}
try {
    $pdf = (new ProvedorFactory($api->cfg))->para($documento['padrao'], $documento['emitente_cnpj'])->gerarPdf($documento);
} catch (RuntimeException $e) {
    $api->erro(502, 'PDF_INDISPONIVEL', 'O PDF nao pode ser gerado agora. Tente de novo em alguns minutos.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="nfse-' . preg_replace('/\D/', '', (string)$documento['numero_nfse']) . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
