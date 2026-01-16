<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * POST /v1/nfe/cce_padrao
 * (Também aceita GET para facilitar testes)
 *
 * Entrada:
 *  - POST JSON: {"chave":"44digitos","texto":"...>=15...","seq":1}
 *  - ou GET: ?chave=...&texto=...&seq=1
 *
 * Execução:
 *  - NÃO altera o script fiscal.
 *  - Apenas inclui o script existente e captura stdout.
 *
 * Saída:
 *  - Retorno padronizado + raw (exitCode/output).
 *  - paths inclui xmlGerado (procEvento) e retEnvio (retorno SEFAZ), além de extras (pdf/request).
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
$texto = '';
$seq = 1;

if ($method === 'GET') {
    $chave = (string)($_GET['chave'] ?? '');
    $texto = (string)($_GET['texto'] ?? '');
    $seq   = (int)($_GET['seq'] ?? 1);
} else {
    $rawBody = file_get_contents('php://input') ?: '';
    $rawBodyTrim = trim($rawBody);

    if ($rawBodyTrim !== '') {
        $decoded = json_decode($rawBodyTrim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $chave = (string)($decoded['chave'] ?? '');
            $texto = (string)($decoded['texto'] ?? '');
            $seq   = (int)($decoded['seq'] ?? 1);
        }
    }

    // fallback form-data
    if ($chave === '') $chave = (string)($_POST['chave'] ?? '');
    if ($texto === '') $texto = (string)($_POST['texto'] ?? '');
    if (!isset($decoded['seq'])) $seq = (int)($_POST['seq'] ?? $seq);
}

$chave = onlyDigits($chave);
$texto = trim($texto);
if ($seq <= 0) $seq = 1;

if ($chave === '' || !preg_match('/^\d{44}$/', $chave)) {
    http_response_code(400);
    echo json_encode(['error' => 'Chave inválida (precisa ter 44 dígitos).', 'received' => $chave], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (mb_strlen($texto) < 15) {
    http_response_code(400);
    echo json_encode(['error' => 'Texto de correção deve ter ao menos 15 caracteres.', 'receivedLen' => mb_strlen($texto)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($seq < 1 || $seq > 999) {
    http_response_code(400);
    echo json_encode(['error' => 'Seq inválida (1..999).', 'received' => $seq], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$baseDir = dirname(__DIR__, 3); // public/v1/nfe -> raiz do projeto

// Script fiscal existente (o que você colou)
$scriptWeb = $baseDir . '/public/cce.php';
if (!is_file($scriptWeb)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Script fiscal CC-e não encontrado',
        'expected' => $scriptWeb,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ========= Execução: include + captura stdout =========
$exitCode = 0;

// Backup de superglobals
$oldGet  = $_GET;
$oldPost = $_POST;

// Alimenta GET como o script fiscal espera
$_GET['chave'] = $chave;
$_GET['texto'] = $texto;
$_GET['seq']   = (string)$seq;

ob_start();
try {
    require $scriptWeb;
} catch (Throwable $e) {
    echo "\n[WRAPPER_EXCEPTION] " . $e->getMessage();
    $exitCode = 1;
}
$output = (string)ob_get_clean();

// Restaura superglobals
$_GET  = $oldGet;
$_POST = $oldPost;

// ========= Parser (derivado; não altera fiscal) =========
$pickFirst = static function (string $pattern, string $text): ?string {
    if (preg_match($pattern, $text, $m)) {
        return trim((string)$m[1]);
    }
    return null;
};

$has = static function (string $text, string $needle): bool {
    return stripos($text, $needle) !== false;
};

// Formatos do seu script CC-e:
//
// Sucesso:
//   "CC-e OK."
//   "cStatEvento=135"
//   "XML gerado: ...procEvento.xml"
//   "PDF gerado: ...pdf"
//   "Request salvo: ...xml"
//   "Retorno salvo: ...xml"
//
// Cache:
//   "CC-e já existe."
//   "Arquivo XML: ...procEvento.xml"
//   "PDF (cache): ...pdf"

$xmlGerado = $pickFirst('/^\s*(?:XML gerado|Arquivo XML)\s*:\s*(.+)$/im', $output);
$pdfPath   = $pickFirst('/^\s*(?:PDF gerado|PDF \(cache\))\s*:\s*(.+)$/im', $output);
$reqPath   = $pickFirst('/^\s*Request salvo\s*:\s*(.+)$/im', $output);
$retPath   = $pickFirst('/^\s*Retorno salvo\s*:\s*(.+)$/im', $output);

$cStatEvt  = $pickFirst('/^\s*cStatEvento\s*=\s*(\d+)\s*$/im', $output);
if ($cStatEvt === null) {
    // às vezes aparece em mensagens "cStat=135 ..." (fallback genérico)
    $cStatEvt = $pickFirst('/cStat\s*=\s*(\d+)/i', $output);
}

// status para integração (classificação simples, baseada no output)
$status = 'DESCONHECIDO';
if ($has($output, 'CC-e OK') || $has($output, 'CC-e já existe')) {
    $status = 'OK';
} elseif ($has($output, 'Erro:') || $has($output, 'ERRO') || $exitCode !== 0) {
    $status = 'ERRO';
}

$ok = ($status !== 'ERRO');

// Paths no padrão do emitir_padrao (sempre presentes)
$paths = [
    'xmlGerado'   => $xmlGerado,  // procEvento
    'retEnvio'    => $retPath,    // retorno SEFAZ salvo

    // extras do CC-e (não removem o padrão acima)
    'pdf'         => $pdfPath,
    'request'     => $reqPath,
];

http_response_code(200);
echo json_encode([
    'item' => [
        'ok' => $ok,
        'status' => $status,
        'cStat' => $cStatEvt,
        'chave' => $chave,
        'paths' => $paths,
        'raw' => [
            'exitCode' => $exitCode,
            'output' => $output,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
