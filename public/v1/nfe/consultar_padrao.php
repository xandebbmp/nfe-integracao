<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * /v1/nfe/consultar_padrao
 *
 * Entrada (aceita ambos):
 *  - GET ?chave=44digitos
 *  - POST JSON {"chave":"44digitos"}
 *
 * Execução (tentativas, sem alterar fiscal):
 *  A) Se existir CLI em public/consultar.php -> roda: php public/consultar.php <chave>
 *  B) Senão, se existir script web em public/v1/nfe/consultar.php -> include com output buffering
 *
 * Retorno:
 *  - campos derivados + raw (exitCode/output)
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function onlyDigits(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}

$chave = '';

if ($method === 'GET') {
    $chave = (string)($_GET['chave'] ?? '');
} else { // POST
    $rawBody = file_get_contents('php://input') ?: '';
    $rawBodyTrim = trim($rawBody);
    if ($rawBodyTrim !== '') {
        $decoded = json_decode($rawBodyTrim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $chave = (string)($decoded['chave'] ?? '');
        }
    }
    // fallback: aceita form-data também
    if ($chave === '') {
        $chave = (string)($_POST['chave'] ?? '');
    }
}

$chave = onlyDigits($chave);

if ($chave === '' || strlen($chave) !== 44) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Parâmetro "chave" inválido (esperado 44 dígitos)',
        'received' => $chave,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$baseDir = dirname(__DIR__, 3); // public/v1/nfe -> raiz do projeto
$cliScript = $baseDir . '/public/consultar.php';
$webScript = $baseDir . '/public/v1/nfe/consultar.php';

$exitCode = 0;
$output = '';

/**
 * Execução sem tocar na lógica fiscal:
 * - Preferimos CLI se existir.
 * - Senão, incluímos o script web existente (se existir).
 */
if (is_file($cliScript)) {
    $cmd = sprintf('php %s %s', escapeshellarg($cliScript), escapeshellarg($chave));
    $outLines = [];
    $code = 0;
    exec($cmd . ' 2>&1', $outLines, $code);
    $exitCode = (int)$code;
    $output = implode("\n", $outLines);
} elseif (is_file($webScript)) {
    // include do script web existente, capturando stdout sem alterar o script
    $oldGet = $_GET;
    $oldPost = $_POST;

    $_GET['chave'] = $chave;

    ob_start();
    try {
        require $webScript;
    } catch (Throwable $e) {
        // mantém "conteúdo" (raw) ainda assim
        echo "\n[WRAPPER_EXCEPTION] " . $e->getMessage();
        $exitCode = 1;
    }
    $output = (string)ob_get_clean();

    $_GET = $oldGet;
    $_POST = $oldPost;
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Nenhum script de consulta encontrado',
        'expected' => [
            $cliScript,
            $webScript,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ========= parser derivado (não fiscal; apenas leitura de texto) =========

$pickFirst = static function (string $pattern, string $text): ?string {
    if (preg_match($pattern, $text, $m)) {
        return trim((string)$m[1]);
    }
    return null;
};

$pickLastPair = static function (string $pattern, string $text): array {
    if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
        $last = $all[count($all) - 1];
        return [trim((string)($last[1] ?? '')), trim((string)($last[2] ?? ''))];
    }
    return [null, null];
};

$has = static function (string $text, string $needle): bool {
    return stripos($text, $needle) !== false;
};

// tenta achar cStat/xMotivo em formatos comuns
[$cStat1, $xMotivo1] = $pickLastPair('/cStat\s*=\s*(\d+)\s*(?:xMotivo\s*=\s*|xMotivo:\s*)([^\r\n]+)/i', $output);
[$cStat2, $xMotivo2] = $pickLastPair('/cStat\s*:\s*(\d+)\s*(?:xMotivo\s*:\s*)([^\r\n]+)/i', $output);

$cStat = $cStat1 ?: $cStat2;
$xMotivo = $xMotivo1 ?: $xMotivo2;

// tenta capturar o protocolo (nProt) em formatos comuns
$nProt = $pickFirst('/nProt\s*=\s*(\d+)/i', $output)
      ?: $pickFirst('/nProt\s*:\s*(\d+)/i', $output);


// chave (garante)
$chaveOut = $pickFirst('/(\d{44})/i', $output);
if ($chaveOut === null) $chaveOut = $chave;

// Para o output REAL do seu consultar (ex.: "OK: retorno salvo em: C:\...\ret-consulta-....xml")
$xmlPath  = $pickFirst('/retorno salvo em:\s*(.+)$/im', $output);

// nfeProc: seu consultar não informa no output; então fica null (a menos que você imprima isso no futuro)
$procPath = $pickFirst('/nfeProc\s*(?:salvo|path)\s*:\s*(.+)$/im', $output);


// status para integração (apenas classificação)
$status = 'DESCONHECIDO';
if ($has($output, 'ERRO') || $exitCode !== 0) {
    $status = 'ERRO';
} elseif ($cStat !== null && $cStat !== '') {
    // Consulta normalmente retorna 100/101/110/135/217 etc — aqui não “interpreta”, só sinaliza “TEM CSTAT”
    $status = 'OK';
}

http_response_code(200);
echo json_encode([
    'item' => [
        'ok' => ($status !== 'ERRO'),
        'status' => $status,
        'cStat' => $cStat,
        'xMotivo' => $xMotivo,
        'chave' => $chaveOut,
        'protocolo' => $nProt,
        'paths' => [
            'xml' => $xmlPath,
        ],
        'raw' => [
            'exitCode' => $exitCode,
            'output' => $output,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
