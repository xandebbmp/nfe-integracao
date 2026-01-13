<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xande\NfeIntegracao\NfeService;
use Xande\NfeIntegracao\Support\Io;

$cfg = require __DIR__ . '/../config/nfe.php';
$svc = new NfeService($cfg);

header('Content-Type: text/plain; charset=utf-8');

try {
    $ret = $svc->statusServico();

    // storage/xml/retornos
    $dirRetornos = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'retornos';

    $filename = 'ret-status-' . Io::ts() . '.xml';
    $file = Io::save($dirRetornos, $filename, (string)$ret);

    echo $ret . "\n\n";
    echo "OK: retorno salvo em: {$file}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "ERRO: " . $e->getMessage() . PHP_EOL;
}
