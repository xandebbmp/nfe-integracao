<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * POST /v1/nfe/cancelar_padrao
 * (Também aceita GET para facilitar testes)
 *
 * Entrada:
 *  - POST JSON: {"chave":"44digitos","protocolo":"nProt","just":"...>=15..."}
 *  - ou GET: ?chave=...&protocolo=...&just=...
 *
 * Execução:
 *  - NÃO altera o script fiscal.
 *  - Apenas inclui o script existente e captura stdout.
 *
 * Saída:
 *  - Retorno padronizado + raw (exitCode/output).
 *  - paths no padrão (xmlGerado, xmlAssinado, retEnvio, nfeProc, relatorio) + extras (pdf, request).
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
$protocolo = '';
$just = 'Cancelamento por solicitação do cliente.';

if ($method === 'GET') {
    $chave     = (string)($_GET['chave'] ?? '');
    $protocolo = (string)($_GET['protocolo'] ?? '');
    $just      = (string)($_GET['just'] ?? $just);
} else {
    $rawBody = file_get_contents('php://input') ?: '';
    $rawBodyTrim = trim($rawBody);

    if ($rawBodyTrim !== '') {
        $decoded = json_decode($rawBodyTrim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $chave     = (string)($decoded['chave'] ?? '');
            $protocolo = (string)($decoded['protocolo'] ?? '');
            $just      = (string)($decoded['just'] ?? $just);
        }
    }

    // fallback form-data
    if ($chave === '') $chave = (string)($_POST['chave'] ?? '');
    if ($protocolo === '') $protocolo = (string)($_POST['protocolo'] ?? '');
    if (!isset($decoded['just'])) $just = (string)($_POST['just'] ?? $just);
}

$chave = onlyDigits($chave);
$protocolo = onlyDigits($protocolo);
$just = trim($just);

if ($chave === '' || !preg_match('/^\d{44}$/', $chave)) {
    http_response_code(400);
    echo json_encode(['error' => 'Chave inválida (precisa ter 44 dígitos).', 'received' => $chave], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($protocolo === '' || strlen($protocolo) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Protocolo (nProt) inválido.', 'received' => $protocolo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (mb_strlen($just) < 15) {
    http_response_code(400);
    echo json_encode(['error' => 'Justificativa deve ter ao menos 15 caracteres.', 'receivedLen' => mb_strlen($just)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$baseDir = dirname(__DIR__, 3); // public/v1/nfe -> raiz do projeto

// Script fiscal existente
$scriptWeb = $baseDir . '/public/cancelar.php';
if (!is_file($scriptWeb)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Script fiscal de cancelamento não encontrado',
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
$_GET['chave']     = $chave;
$_GET['protocolo'] = $protocolo;
$_GET['just']      = $just;

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

// Formatos do seu script:
//
// Sucesso:
//   "Cancelamento OK."
//   "cStatEvento=135"
//   "XML gerado: ...procEvento.xml"
//   "PDF gerado: ...pdf"
//   "Request salvo: ...xml"
//   "Retorno salvo: ...xml"
//
// Cache:
//   "Cancelamento já existe."
//   "Arquivo XML: ...procEvento.xml"
//   "PDF (cache): ...pdf"

$xmlGerado = $pickFirst('/^\s*(?:XML gerado|Arquivo XML)\s*:\s*(.+)$/im', $output);
$pdfPath   = $pickFirst('/^\s*(?:PDF gerado|PDF \(cache\))\s*:\s*(.+)$/im', $output);
$reqPath   = $pickFirst('/^\s*Request salvo\s*:\s*(.+)$/im', $output);
$retPath   = $pickFirst('/^\s*Retorno salvo\s*:\s*(.+)$/im', $output);

$cStatEvt  = $pickFirst('/^\s*cStatEvento\s*=\s*(\d+)\s*$/im', $output);
$xMotivo   = null;

// Se houver erro "Erro: .... cStat=xxx xMotivo=yyy", captura também
if ($cStatEvt === null) {
    $cStatEvt = $pickFirst('/cStat\s*=\s*(\d+)/i', $output);
}
$xMotivoFromErr = $pickFirst('/xMotivo\s*=\s*([^\r\n]+)/i', $output);
if ($xMotivoFromErr !== null) {
    $xMotivo = $xMotivoFromErr;
}

// status para integração (classificação simples, baseada no output)
$status = 'DESCONHECIDO';
if ($has($output, 'Cancelamento OK') || $has($output, 'Cancelamento já existe')) {
    $status = 'OK';
} elseif ($has($output, 'Erro:') || $has($output, 'ERRO') || $exitCode !== 0) {
    $status = 'ERRO';
}

$ok = ($status !== 'ERRO');

// Paths no padrão do emitir_padrao (sempre presentes)
$paths = [
    'xmlGerado'   => $xmlGerado,  // procEvento
    'retEnvio'    => $retPath,    // retorno SEFAZ salvo

    // extras do cancelamento
    'pdf'         => $pdfPath,
    'request'     => $reqPath,
];

http_response_code(200);
echo json_encode([
    'item' => [
        'ok' => $ok,
        'status' => $status,
        'cStat' => $cStatEvt,
        'xMotivo' => $xMotivo,
        'chave' => $chave,
        'paths' => $paths,
        'raw' => [
            'exitCode' => $exitCode,
            'output' => $output,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
