<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * POST /v1/nfe/inutilizar_padrao
 * (Também aceita GET para facilitar testes)
 *
 * Estrutura espelhada do cancelar_padrao:
 * - inclui o fiscal existente (public/inutilizar.php) sem alterar lógica
 * - captura stdout
 * - deriva campos (status/cStat/xMotivo/paths/idInut) e preserva raw
 *
 * Entrada:
 *  - POST JSON: {"ano":"26","serie":1,"ini":10,"fim":20,"just":"...>=15..."}
 *  - ou GET: ?ano=26&serie=1&ini=10&fim=20&just=...
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ano   = '';
$serie = 1;
$ini   = 0;
$fim   = 0;
$just  = 'Inutilização por numeração não utilizada.';

if ($method === 'GET') {
    $ano   = (string)($_GET['ano'] ?? '');
    $serie = (int)($_GET['serie'] ?? 1);
    $ini   = (int)($_GET['ini'] ?? 0);
    $fim   = (int)($_GET['fim'] ?? 0);
    $just  = (string)($_GET['just'] ?? $just);
} else {
    $rawBody = file_get_contents('php://input') ?: '';
    $rawBodyTrim = trim($rawBody);

    if ($rawBodyTrim !== '') {
        $decoded = json_decode($rawBodyTrim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $ano   = (string)($decoded['ano'] ?? '');
            $serie = (int)($decoded['serie'] ?? 1);
            $ini   = (int)($decoded['ini'] ?? 0);
            $fim   = (int)($decoded['fim'] ?? 0);
            $just  = (string)($decoded['just'] ?? $just);
        }
    }

    // fallback form-data
    if ($ano === '') $ano = (string)($_POST['ano'] ?? '');
    if (!isset($decoded['serie'])) $serie = (int)($_POST['serie'] ?? $serie);
    if (!isset($decoded['ini']))   $ini   = (int)($_POST['ini'] ?? $ini);
    if (!isset($decoded['fim']))   $fim   = (int)($_POST['fim'] ?? $fim);
    if (!isset($decoded['just']))  $just  = (string)($_POST['just'] ?? $just);
}

$ano = trim($ano);
if ($ano === '') $ano = date('y'); // mesmo default do fiscal
$just = trim($just);

if (!preg_match('/^\d{2}$/', $ano)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ano inválido. Use 2 dígitos (ex: 26).', 'received' => $ano], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($serie < 1 || $serie > 999) {
    http_response_code(400);
    echo json_encode(['error' => 'Série inválida (1..999).', 'received' => $serie], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($ini < 1 || $fim < 1 || $fim < $ini) {
    http_response_code(400);
    echo json_encode(['error' => 'Faixa inválida. Ex: ini=10&fim=20.', 'received' => ['ini' => $ini, 'fim' => $fim]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (mb_strlen($just) < 15) {
    http_response_code(400);
    echo json_encode(['error' => 'Justificativa deve ter no mínimo 15 caracteres.', 'receivedLen' => mb_strlen($just)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$baseDir = dirname(__DIR__, 3); // public/v1/nfe -> raiz do projeto
$scriptWeb = $baseDir . '/public/inutilizar.php';

if (!is_file($scriptWeb)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Script fiscal de inutilização não encontrado',
        'expected' => $scriptWeb,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ========= Execução: include + captura stdout =========
$exitCode = 0;

$oldGet  = $_GET;
$oldPost = $_POST;

// Alimenta GET como o fiscal espera
$_GET['ano']   = $ano;
$_GET['serie'] = (string)$serie;
$_GET['ini']   = (string)$ini;
$_GET['fim']   = (string)$fim;
$_GET['just']  = $just;

ob_start();
try {
    require $scriptWeb;
} catch (Throwable $e) {
    echo "\n[WRAPPER_EXCEPTION] " . $e->getMessage();
    $exitCode = 1;
}
$output = (string)ob_get_clean();

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

$idInut    = $pickFirst('/^\s*idInut\s*=\s*([^\r\n]+)\s*$/im', $output);
$xmlGerado = $pickFirst('/^\s*XML gerado\s*:\s*(.+)$/im', $output);

// seu fiscal imprime exatamente:
$retPath = $pickFirst('/^\s*Retorno SEFAZ salvo\s*:\s*(.+)$/im', $output);
$reqPath = $pickFirst('/^\s*Request salvo\s*:\s*(.+)$/im', $output);

// opcional (se aparecer no raw)
$cStat   = $pickFirst('/cStat\s*=\s*(\d+)/i', $output) ?: $pickFirst('/cStat\s*:\s*(\d+)/i', $output);
$xMotivo = $pickFirst('/xMotivo\s*=\s*([^\r\n]+)/i', $output) ?: $pickFirst('/xMotivo\s*:\s*([^\r\n]+)/i', $output);

// status (mesma ideia do cancelar_padrao)
$status = 'DESCONHECIDO';
if ($has($output, 'Inutilização OK')) {
    $status = 'OK';
} elseif ($has($output, 'Erro:') || $has($output, 'ERRO') || $exitCode !== 0) {
    $status = 'ERRO';
}

$ok = ($status !== 'ERRO');

// Paths no padrão do emitir_padrao (sempre presentes) + extras
$paths = [
    'xmlGerado'   => $xmlGerado, // procInut
    'xmlAssinado' => null,
    'retEnvio'    => $retPath,   // retorno SEFAZ salvo
    'nfeProc'     => null,
    'relatorio'   => null,

    // extras do evento
    'request'     => $reqPath,
];

http_response_code(200);
echo json_encode([
    'item' => [
        'ok' => $ok,
        'status' => $status,
        'cStat' => $cStat,
        'xMotivo' => $xMotivo,
        'chave' => null,
        'idInut' => $idInut,
        'paths' => $paths,
        'raw' => [
            'exitCode' => $exitCode,
            'output' => $output,
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
