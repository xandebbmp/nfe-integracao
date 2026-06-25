<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/HttpSecurity.php';
nfe_require_fiscal_access();

require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/nfe.php';

$svc = new Xande\NfeIntegracao\NfeService($cfg);
echo $svc->ping() . PHP_EOL;
