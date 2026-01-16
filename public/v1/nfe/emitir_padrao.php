<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Body vazio']);
    exit;
}

$payload = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

// Normaliza para array de notas (objeto único OU array)
$notas = array_is_list($payload) ? $payload : [$payload];

$baseDir   = dirname(__DIR__, 3); // public/v1/nfe -> raiz do projeto
$scriptCli = $baseDir . '/public/lote_emitir.php';

if (!is_file($scriptCli)) {
    http_response_code(500);
    echo json_encode(['error' => 'Script fiscal não encontrado']);
    exit;
}

// Pasta temporária do request
$tmpBase = sys_get_temp_dir() . '/nfe_emitir_' . uniqid('', true);
if (!@mkdir($tmpBase, 0775, true) && !is_dir($tmpBase)) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao criar diretório temporário']);
    exit;
}

// helpers de parse (não fiscal; só texto)
$pickFirst = function (string $pattern, string $text): ?string {
    if (preg_match($pattern, $text, $m)) {
        return trim((string)$m[1]);
    }
    return null;
};

$pickLastPair = function (string $pattern, string $text): array {
    if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
        $last = $all[count($all) - 1];
        return [trim((string)($last[1] ?? '')), trim((string)($last[2] ?? ''))];
    }
    return [null, null];
};

$has = function (string $text, string $needle): bool {
    return stripos($text, $needle) !== false;
};

$normalizeItem = function (int $nota, int $exitCode, string $output) use ($pickFirst, $pickLastPair, $has): array {
    $xmlGerado   = $pickFirst('/XML gerado:\s*(.+)/i', $output);
    $xmlAssinado = $pickFirst('/XML Assinado:\s*(.+)/i', $output);
    $retEnvio    = $pickFirst('/Retorno salvo:\s*(.+)/i', $output);
    $nfeProc     = $pickFirst('/nfeProc salvo:\s*(.+)/i', $output);
    $relatorio   = $pickFirst('/Relat[oó]rio salvo:\s*(.+)/i', $output);

    // cStat/xMotivo: preferir Protocolo; fallback Retorno Lote
    [$c1, $m1] = $pickLastPair('/Protocolo:\s*cStat=(\d+)\s*xMotivo=([^\r\n]+)/i', $output);
    [$c2, $m2] = $pickLastPair('/Retorno Lote:\s*cStat=(\d+)\s*xMotivo=([^\r\n]+)/i', $output);

    $cStat   = $c1 ?: $c2;
    $xMotivo = $m1 ?: $m2;

    // chave: tenta extrair do nome do XML NFe-<44>
    $chave = $pickFirst('/NFe-(\d{44})/i', $output);

    // classificação para integração (não muda fiscal; só ajuda o consumidor)
    $temFalhas = $has($output, 'FALHAS:') && !$has($output, 'FALHAS: 0');
    $autorizado = $has($output, 'cStat=100') || ($cStat === '100');
    $temErro = $has($output, 'ERRO') || ($exitCode !== 0);

    $status = 'DESCONHECIDO';
    if ($autorizado) $status = 'AUTORIZADO';
    elseif ($temErro && $temFalhas) $status = 'PROCESSADO_COM_FALHA';
    elseif ($temErro) $status = 'ERRO';

    $okOperacional = ($exitCode === 0) && !$temFalhas && ($status !== 'ERRO');

    return [
        'nota' => $nota,
        'ok' => $okOperacional,
        'status' => $status,
        'cStat' => $cStat,
        'xMotivo' => $xMotivo,
        'chave' => $chave,
        'paths' => [
            'xmlGerado' => $xmlGerado,
            'xmlAssinado' => $xmlAssinado,
            'retEnvio' => $retEnvio,
            'nfeProc' => $nfeProc,
            'relatorio' => $relatorio,
        ],
        // bruto SEMPRE preservado
        'raw' => [
            'exitCode' => $exitCode,
            'output' => $output,
        ],
    ];
};

$itens = [];

foreach ($notas as $idx => $notaJson) {
    $nota = $idx + 1;

    $loteDir = $tmpBase . '/lote_' . $nota;
    @mkdir($loteDir, 0775, true);

    $jsonFile = $loteDir . '/nota.json';
    file_put_contents(
        $jsonFile,
        json_encode($notaJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    $cmd = sprintf('php %s %s', escapeshellarg($scriptCli), escapeshellarg($loteDir));

    $outLines = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $outLines, $exitCode);

    $output = implode("\n", $outLines);

    $itens[] = $normalizeItem($nota, $exitCode, $output);
}

$summary = [
    'total' => count($itens),
    'autorizados' => 0,
    'falhas' => 0,
    'erros' => 0,
];

foreach ($itens as $i) {
    if (($i['status'] ?? '') === 'AUTORIZADO') $summary['autorizados']++;
    if (($i['status'] ?? '') === 'PROCESSADO_COM_FALHA') $summary['falhas']++;
    if (($i['status'] ?? '') === 'ERRO') $summary['erros']++;
}

http_response_code(200);
echo json_encode([
    'summary' => $summary,
    'itens' => $itens,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
