<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xande\NfeIntegracao\NfeService;

$cfg = require __DIR__ . '/../config/nfe.php';
$svc = new NfeService($cfg);

$tools = $svc->tools();
$methods = get_class_methods($tools);
sort($methods);

foreach ($methods as $m) {
    if (stripos($m, 'sefaz') !== false || stripos($m, 'sign') !== false) {
        echo $m . PHP_EOL;
    }
}
