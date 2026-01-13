<?php
declare(strict_types=1);

date_default_timezone_set('America/Bahia');

require __DIR__ . '/../vendor/autoload.php';

use NFePHP\DA\NFe\Danfe;
use Xande\NfeIntegracao\NfeService;

$cfg = require __DIR__ . '/../config/nfe.php';
$nfe = new NfeService($cfg);

// chave via GET ?chave=...
$chave = isset($_GET['chave']) ? preg_replace('/\D+/', '', (string)$_GET['chave']) : '';
if ($chave === '' || strlen($chave) !== 44) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Informe a chave via ?chave=CHAVE44";
    exit;
}

// Padrão novo do projeto:
$xmlDir = $nfe->pathXml('autorizados');
$pdfDir = $nfe->pathPdf('danfe');

$filename = "NFe-{$chave}-procNFe.xml";
$xmlPath  = $xmlDir . DIRECTORY_SEPARATOR . $filename;

if (!is_file($xmlPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "XML não encontrado: {$xmlPath}";
    exit;
}

$xml = file_get_contents($xmlPath);
if ($xml === false || trim($xml) === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falha ao ler XML: {$xmlPath}";
    exit;
}

try {
    // DANFE a partir do nfeProc
    $danfe = new Danfe($xml);

    // Se você tiver logo no config, pode habilitar:
    // if (!empty($cfg['logoPath']) && is_file($cfg['logoPath'])) {
    //     $danfe->setLogo($cfg['logoPath']);
    // }

    $pdfContent = $danfe->render();

    // Salva em disco (cache)
    $pdfPath = $pdfDir . DIRECTORY_SEPARATOR . "DANFE-{$chave}.pdf";
    if (file_put_contents($pdfPath, $pdfContent) === false) {
        throw new RuntimeException("Falha ao salvar PDF em: {$pdfPath}");
    }

    // Stream no navegador
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="DANFE-' . $chave . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro gerando DANFE: " . $e->getMessage();
}
