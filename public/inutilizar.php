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

// Tag p/ arquivos de retorno
$tag = sprintf('A%s-S%03d-%09d-%09d', $ano, $serie, $ini, $fim);

// ========================
// Execução
// ========================
try {
    $tools = $svc->tools();

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
    $retPath = $retDir . DIRECTORY_SEPARATOR . "ret-inut-{$tag}-" . date('Ymd_His') . ".xml";
    @file_put_contents($retPath, $response);

    // request (lastRequest) é essencial pra montar procInut via Complements
    $req = $tools->lastRequest ?? '';
    if (trim((string)$req) === '') {
        throw new RuntimeException("tools->lastRequest veio vazio. Não dá pra montar procInut. Retorno salvo em: {$retPath}");
    }

    $reqPath = $retDir . DIRECTORY_SEPARATOR . "req-inut-{$tag}-" . date('Ymd_His') . ".xml";
    @file_put_contents($reqPath, $req);

    // monta procInut
    $procInutXml = Complements::toAuthorize($req, $response);
    if (trim((string)$procInutXml) === '' || mb_strlen((string)$procInutXml) < 80) {
        $badPath = $retDir . DIRECTORY_SEPARATOR . "proc-inut-{$tag}-gerado-" . date('Ymd_His') . ".xml";
        @file_put_contents($badPath, (string)$procInutXml);
        throw new RuntimeException("procInut gerado vazio/pequeno. Debug salvo em retornos/.");
    }

    // extrai Id (infInut/@Id) pra nomear bonito
    $idInut = '';
    $sx = simplexml_load_string((string)$procInutXml);
    if ($sx !== false) {
        $idNode = $sx->xpath('//*[local-name()="infInut"]/@Id');
        $idFull = isset($idNode[0]) ? (string)$idNode[0] : '';
        if ($idFull !== '') {
            $idInut = preg_replace('/^ID/i', '', $idFull) ?? '';
        }
    }

    // fallback determinístico
    if ($idInut === '') {
        $idInut = $tag;
    }
    // sanitiza (Windows friendly)
    $idInut = Io::safeName($idInut);

    // salva procInut na árvore Opção A
    $xmlFile = $xmlDirInut . DIRECTORY_SEPARATOR . "inut-{$idInut}-procInut.xml";
    $bytes = file_put_contents($xmlFile, (string)$procInutXml);
    if ($bytes === false || $bytes < 80) {
        throw new RuntimeException("Falha ao salvar procInut em: {$xmlFile}");
    }

    // saída final
    echo "Inutilização OK (XML).\n";
    echo "idInut={$idInut}\n";
    echo "XML gerado: {$xmlFile}\n";
    echo "Retorno SEFAZ salvo: {$retPath}\n";
    echo "Request salvo: {$reqPath}\n";
    echo "Abrir via evento.php (XML): http://localhost/nfe-integracao/public/evento.php?tipo=inut&idInut={$idInut}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro: " . $e->getMessage() . "\n";
}
