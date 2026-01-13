<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xande\NfeIntegracao\NfeService;
use Xande\NfeIntegracao\XmlHelper;
use Xande\NfeIntegracao\Support\Io;

header('Content-Type: text/plain; charset=utf-8');

$cfg = require __DIR__ . '/../config/nfe.php';
$svc = new NfeService($cfg);

// aceita chave por CLI (php public/consultar.php CHAVE) ou por GET (?chave=)
if (PHP_SAPI === 'cli') {
    $chave = Io::onlyDigits((string)($argv[1] ?? ''));
} else {
    $chave = Io::onlyDigits((string)($_GET['chave'] ?? ''));
}

if (!preg_match('/^\d{44}$/', $chave)) {
    http_response_code(400);
    echo "Uso:\n";
    echo "- CLI: php public/consultar.php <chave44>\n";
    echo "- WEB: http://localhost/nfe-integracao/public/consultar.php?chave=<chave44>\n";
    exit;
}

try {
    $ret = $svc->tools()->sefazConsultaChave($chave);

    // storage/xml/retornos
    $dirRetornos = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'retornos';
    $filename = "ret-consulta-{$chave}.xml";
    $file = Io::save($dirRetornos, $filename, (string)$ret);

    $info = XmlHelper::extract($ret, ['cStat','xMotivo','chNFe','cSitNFe','nProt','dhRecbto']);

    echo "cStat={$info['cStat']}\n";
    echo "xMotivo={$info['xMotivo']}\n";
    echo "chNFe={$info['chNFe']}\n";
    echo "cSitNFe={$info['cSitNFe']}\n";
    echo "nProt={$info['nProt']}\n";
    echo "dhRecbto={$info['dhRecbto']}\n\n";

    echo "OK: retorno salvo em: {$file}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "ERRO: " . $e->getMessage() . PHP_EOL;
}
