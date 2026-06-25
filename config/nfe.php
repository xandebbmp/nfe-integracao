<?php
declare(strict_types=1);

$cfg = [
  'tpAmb' => 2, // 2=Homologação | 1=Produção
  'siglaUF' => 'BA',
  'cnpj' => '41986662000160',
  'razao' => 'LEBRE TECNOLOGIA E INFORMATICA LTDA',
  'ie' => '31638351',
  'model' => 55,
  'nNF' => 2,

  'certPfxPath' => __DIR__ . '/../storage/certs/empresa.pfx',
  'certPassword' => '',

  'pathLogs' => __DIR__ . '/../storage/logs',
  'pathXml'  => __DIR__ . '/../storage/xml',
  'pathPdf'  => __DIR__ . '/../storage/pdf',

  // >>> adiciona isso:
  'schemes' => 'PL_010_V1.30',
  'versao'  => '4.00',

  // BA exige autXML com CNPJ da SEFAZ BA
  'autxml_ba_cnpj' => '13937073000156',

  // Defina valores reais apenas em config/nfe.local.php.
  'apiToken' => '',
  'debugRawOutput' => false,
];

$localConfigFile = __DIR__ . '/nfe.local.php';
if (is_file($localConfigFile)) {
    $localCfg = require $localConfigFile;
    if (!is_array($localCfg)) {
        throw new RuntimeException('config/nfe.local.php deve retornar um array.');
    }
    $cfg = array_replace($cfg, $localCfg);
}

return $cfg;
