<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use Xande\NfeIntegracao\NfeService;

/**
 * Uso:
 *   php public/assinar_enviar_sincrono.php "storage/xml/gerados/NFe-....xml"
 * ou
 *   php public/assinar_enviar_sincrono.php "C:\wamp64\www\nfe-integracao\storage\xml\gerados\NFe-....xml"
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function normalizePath(string $path): string {
    return trim($path, " \t\n\r\0\x0B\"'");
}

// ===== LÊ ARGUMENTO =====
if ($argc < 2) {
    fail("Uso: php public/assinar_enviar_sincrono.php \"CAMINHO_DO_XML\"");
}

$argPath = normalizePath($argv[1]);
$baseDir = dirname(__DIR__);

// Se não for absoluto, assume relativo ao projeto
$isAbsWindows = preg_match('/^[A-Za-z]:\\\\/', $argPath) === 1;
$isAbsUnix    = str_starts_with($argPath, '/');

$xmlPath = ($isAbsWindows || $isAbsUnix)
    ? $argPath
    : ($baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $argPath));

if (!is_file($xmlPath)) {
    fail("XML não encontrado: {$xmlPath}");
}

$xml = file_get_contents($xmlPath);
if ($xml === false || trim($xml) === '') {
    fail("Não consegui ler ou XML está vazio: {$xmlPath}");
}

// ===== CONFIG + SERVICE =====
$cfgPhpPath = $baseDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'nfe.php';
if (!is_file($cfgPhpPath)) {
    fail("config/nfe.php não encontrado em: {$cfgPhpPath}");
}

$cfg = require $cfgPhpPath;
if (!is_array($cfg)) {
    fail("config/nfe.php não retornou array.");
}

$nfe = new NfeService($cfg);
$tools = $nfe->tools();

// ===== DIRETÓRIOS (PADRÃO DO PROJETO) =====
$dirAss = $nfe->pathXml('assinados');
$dirAut = $nfe->pathXml('autorizados');

// Retornos/logs (sem inventar pasta fora do padrão)
$dirRet = $nfe->pathLogs('retornos');

// ===== 1) ASSINAR =====
try {
    $xmlAssinado = $tools->signNFe($xml);
} catch (Throwable $e) {
    @file_put_contents($dirRet . DIRECTORY_SEPARATOR . 'erro_assinatura_' . date('Ymd_His') . '.txt', $e->getMessage());
    fail("Erro ao assinar: " . $e->getMessage());
}

// Salva assinado (mantém nome do arquivo)
$assPath = $dirAss . DIRECTORY_SEPARATOR . basename($xmlPath);
file_put_contents($assPath, $xmlAssinado);

// ===== 2) VALIDAR XSD (opcional) =====
if (method_exists($tools, 'validate')) {
    try {
        $tools->validate($xmlAssinado);
    } catch (Throwable $e) {
        @file_put_contents($dirRet . DIRECTORY_SEPARATOR . 'erro_xsd_' . date('Ymd_His') . '.txt', $e->getMessage());
        fail("Falha validação XSD: " . $e->getMessage());
    }
}

// ===== 3) ENVIAR SÍNCRONO =====
$idLote = str_pad((string)random_int(1, 999999999999999), 15, '0', STR_PAD_LEFT);
$indSinc = 1;

try {
    $response = $tools->sefazEnviaLote([$xmlAssinado], $idLote, $indSinc);
} catch (Throwable $e) {
    @file_put_contents($dirRet . DIRECTORY_SEPARATOR . 'erro_envio_' . $idLote . '_' . date('Ymd_His') . '.txt', $e->getMessage());
    fail("Erro ao enviar para SEFAZ: " . $e->getMessage());
}

// Salva retorno bruto
$retPath = $dirRet . DIRECTORY_SEPARATOR . 'ret_envio_' . $idLote . '_' . date('Ymd_His') . '.xml';
file_put_contents($retPath, $response);

// ===== 4) INTERPRETA RETORNO =====
$std = (new Standardize())->toStd($response);

$cStat   = (string)($std->cStat ?? '');
$xMotivo = (string)($std->xMotivo ?? '');

echo "Lote: {$idLote}\n";
echo "Retorno Lote: cStat={$cStat} xMotivo={$xMotivo}\n";
echo "XML Assinado: {$assPath}\n";
echo "Retorno salvo: {$retPath}\n";

if ($cStat !== '104') {
    echo "NF-e NÃO finalizada. (cStat do lote != 104). Veja o retorno salvo.\n";
    exit(2);
}

// protNFe pode vir como objeto ou lista
$protNFe = $std->protNFe ?? null;
if (!$protNFe) {
    fail("cStat=104 mas sem protNFe no retorno. Veja: {$retPath}");
}
if (is_array($protNFe)) {
    $protNFe = $protNFe[0] ?? null;
}

$infProt = $protNFe->infProt ?? null;
if (!$infProt) {
    fail("cStat=104 mas sem infProt no protNFe. Veja: {$retPath}");
}

$cStatProt   = (string)($infProt->cStat ?? '');
$xMotivoProt = (string)($infProt->xMotivo ?? '');

echo "Protocolo: cStat={$cStatProt} xMotivo={$xMotivoProt}\n";

$ok = in_array($cStatProt, ['100', '110', '301', '302'], true);
if (!$ok) {
    echo "NF-e não autorizada/denegada. Verifique retorno: {$retPath}\n";
    exit(2);
}

// ===== 5) MONTA NFEPROC E SALVA =====
try {
    if (method_exists($tools, 'addProt')) {
        // versões que têm addProt
        $nfeProc = $tools->addProt($xmlAssinado, $response);
    } else {
        // sua versão: usa Complements
        $nfeProc = Complements::toAuthorize($xmlAssinado, $response);
    }
} catch (Throwable $e) {
    @file_put_contents($dirRet . DIRECTORY_SEPARATOR . 'erro_addProt_' . date('Ymd_His') . '.txt', $e->getMessage());
    fail("Erro ao montar nfeProc: " . $e->getMessage());
}

// Salva com padrão: NFe-CHAVE-procNFe.xml
$autPath = $dirAut . DIRECTORY_SEPARATOR . basename($xmlPath, '.xml') . '-procNFe.xml';
file_put_contents($autPath, $nfeProc);

echo "nfeProc salvo: {$autPath}\n";
echo "OK ✅\n";
