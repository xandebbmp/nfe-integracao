<?php
return [
  'tpAmb' => 2, // 2=Homologação | 1=Produção
  'siglaUF' => 'BA',
  'cnpj' => '41986662000160',
  'razao' => 'LEBRE TECNOLOGIA E INFORMATICA LTDA',
  'ie' => '31638351',
  'model' => 55,
  'nNF' => 2,

  'certPfxPath' => __DIR__ . '/../storage/certs/41986662000160.pfx',
  'certPassword' => '15026925',

  'pathLogs' => __DIR__ . '/../storage/logs',
  'pathXml'  => __DIR__ . '/../storage/xml',
  'pathPdf'  => __DIR__ . '/../storage/pdf',

  // >>> adiciona isso:
  'schemes' => 'PL_010_V1.30',
  'versao'  => '4.00',

  // BA exige autXML com CNPJ da SEFAZ BA
  'autxml_ba_cnpj' => '13937073000156',
];
