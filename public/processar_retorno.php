<?php
declare(strict_types=1);

date_default_timezone_set('America/Bahia');

require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/nfe.php';

$xmlAssinadoPath = $cfg['pathXml'] . '/nfe-assinada.xml';
$retEnvioPath    = $cfg['pathXml'] . '/ret-enviolote.xml';

$xmlAssinado = file_get_contents($xmlAssinadoPath);
if ($xmlAssinado === false) {
    throw new RuntimeException("Não consegui ler: $xmlAssinadoPath");
}

$retEnvio = file_get_contents($retEnvioPath);
if ($retEnvio === false) {
    throw new RuntimeException("Não consegui ler: $retEnvioPath");
}

// --- Pega protNFe dentro do SOAP ---
$soap = new SimpleXMLElement($retEnvio);

// encontra protNFe em qualquer profundidade
$protNodes = $soap->xpath('//*[local-name()="protNFe"]');
if (!$protNodes || !isset($protNodes[0])) {
    throw new RuntimeException("Não encontrei <protNFe> no retorno. Verifique ret-enviolote.xml");
}

$protNFeXml = $protNodes[0]->asXML();
if ($protNFeXml === false) {
    throw new RuntimeException("Falha ao extrair protNFe XML.");
}

// extrai dados úteis do protNFe
$prot = new SimpleXMLElement($protNFeXml);

$chNFe   = (string)($prot->infProt->chNFe ?? '');
$cStat   = (string)($prot->infProt->cStat ?? '');
$xMotivo = (string)($prot->infProt->xMotivo ?? '');
$nProt   = (string)($prot->infProt->nProt ?? '');

if ($chNFe === '') {
    throw new RuntimeException("protNFe sem chNFe. Verifique protNFe.xml extraído.");
}

// --- Monta nfeProc (NF-e processada) ---
// remove declaração XML do assinado, se existir, para embutir limpo
$xmlAssinadoLimpo = preg_replace('/<\?xml[^>]+?\?>\s*/', '', $xmlAssinado);
$protLimpo        = preg_replace('/<\?xml[^>]+?\?>\s*/', '', $protNFeXml);

$nfeProc = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
    . $xmlAssinadoLimpo
    . $protLimpo
    . '</nfeProc>';

// garante pasta
@mkdir($cfg['pathXml'], 0777, true);

// nome padrão: NFe-<chave>-procNFe.xml
$procFile = $cfg['pathXml'] . '/NFe-' . $chNFe . '-procNFe.xml';
file_put_contents($procFile, $nfeProc);

// também salva o protNFe sozinho (ajuda debug/auditoria)
file_put_contents($cfg['pathXml'] . '/protNFe-' . $chNFe . '.xml', $protNFeXml);

header('Content-Type: text/plain; charset=utf-8');
echo "CHAVE:   $chNFe\n";
echo "CSTAT:   $cStat\n";
echo "MOTIVO:  $xMotivo\n";
echo "NPROT:   $nProt\n\n";
echo "✅ Salvo: $procFile\n";

// dica de status
if ($cStat === '100') {
    echo "✅ AUTORIZADA\n";
} else {
    echo "❌ NÃO AUTORIZADA (rejeição/denegação)\n";
}
