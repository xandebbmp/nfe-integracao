<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NFePHP\NFe\Complements;
use Xande\NfeIntegracao\NfeService;
use Xande\NfeIntegracao\Support\Io;

header('Content-Type: text/plain; charset=utf-8');

$cfg = require __DIR__ . '/../config/nfe.php';
$svc = new NfeService($cfg);

// ========================
// Parâmetros (GET)
// ========================
$ano   = trim((string)($_GET['ano'] ?? date('y'))); // ex: "26"
$serie = (int)($_GET['serie'] ?? 1);
$ini   = (int)($_GET['ini'] ?? 0);
$fim   = (int)($_GET['fim'] ?? 0);
$just  = trim((string)($_GET['just'] ?? 'Inutilização por numeração não utilizada.'));

if (!preg_match('/^\d{2}$/', $ano)) {
    http_response_code(400);
    exit("Ano inválido. Use 2 dígitos (ex: 26).\n");
}
if ($serie < 1 || $serie > 999) {
    http_response_code(400);
    exit("Série inválida (1..999).\n");
}
if ($ini < 1 || $fim < 1 || $fim < $ini) {
    http_response_code(400);
    exit("Faixa inválida. Ex: ini=10&fim=20.\n");
}
if (mb_strlen($just) < 15) {
    http_response_code(400);
    exit("Justificativa deve ter no mínimo 15 caracteres.\n");
}

// ========================
// Diretórios (OPÇÃO A)
// ========================
$xmlDirInut = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . 'inut';
$retDir     = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'retornos';

Io::ensureDir($xmlDirInut);
Io::ensureDir($retDir);

// Tag p/ arquivos (determinística e legível)
$tag = sprintf('A%s-S%03d-%09d-%09d', $ano, $serie, $ini, $fim);
$tag = Io::safeName($tag);

// ========================
// Execução
// ========================
try {
    $tools = $svc->tools();
    $ts = Io::ts();

    /**
     * Assinatura varia entre versões.
     * Tentamos 6 params (tpAmb + ano) e caímos para 5/4 se precisar.
     */
    try {
        $response = $tools->sefazInutiliza($serie, $ini, $fim, $just, (int)$cfg['tpAmb'], $ano);
    } catch (Throwable $e1) {
        try {
            $response = $tools->sefazInutiliza($serie, $ini, $fim, $just, (int)$cfg['tpAmb']);
        } catch (Throwable $e2) {
            $response = $tools->sefazInutiliza($serie, $ini, $fim, $just);
        }
    }

    // salva retorno bruto
    $retPath = Io::save($retDir, "ret-inut-{$tag}-{$ts}.xml", (string)$response);

    // request (lastRequest) é essencial pra montar procInut via Complements
