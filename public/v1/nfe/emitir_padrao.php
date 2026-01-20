<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * POST /v1/nfe/emitir_padrao
 *
 * Saída:
 *  - status padronizado + cStat/xMotivo + raw
 *  - AUTORIZADO: cStat 100/150 (gera DANFE se existir nfeProc)
 *  - DENEGADO:  cStat 110/301/302 (ok=false, NÃO pode usar, NÃO reenviar, NÃO gera PDF)
 *  - REJEITADO: outros cStat (ok=false, pode corrigir e reenviar)
 *  - ERRO:      falha técnica (exitCode != 0 / fatal / exception)
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ===================== Helpers =====================

function jsonOut(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function safeFileName(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $s) ?? $s;
    return $s === '' ? 'file' : $s;
}

function runCmd(string $cmd): array {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [(int)$code, implode("\n", $out)];
}

function pickLast(string $pattern, string $text): ?string {
    if (preg_match_all($pattern, $text, $m)) {
        $vals = $m[1] ?? [];
        if (!empty($vals)) {
            return trim((string)$vals[count($vals) - 1]);
        }
    }
    return null;
}

function pickLastPair(string $pattern, string $text): array {
    if (preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
        $last = $all[count($all) - 1];
        return [trim((string)($last[1] ?? '')), trim((string)($last[2] ?? ''))];
    }
    return [null, null];
}

function hasText(string $text, string $needle): bool {
    return stripos($text, $needle) !== false;
}

/**
 * Gera DANFE PDF a partir de um nfeProc (XML autorizado).
 * Retorna o caminho do PDF se gerou (ou já existia), senão null.
 */
function gerarDanfePdfFromProc(string $nfeProcPath, string $pdfDir, string $chave): ?string {
    if (!is_file($nfeProcPath) || filesize($nfeProcPath) < 100) {
        return null;
    }

    ensureDir($pdfDir);

    $chaveSafe = safeFileName($chave);
    $pdfPath = rtrim($pdfDir, "/\\") . DIRECTORY_SEPARATOR . "DANFE-{$chaveSafe}.pdf";

    // Cache: se já existe e é > 1KB, não regenera
    if (is_file($pdfPath) && filesize($pdfPath) > 1024) {
        return $pdfPath;
    }

    $xml = @file_get_contents($nfeProcPath);
    if ($xml === false || trim($xml) === '') {
        return null;
    }

    if (!class_exists(\NFePHP\DA\NFe\Danfe::class)) {
        return null;
    }

    $danfe = new \NFePHP\DA\NFe\Danfe($xml);

    if (method_exists($danfe, 'debugMode')) {
        $danfe->debugMode(false);
    }
    if (method_exists($danfe, 'setPaper')) {
        $danfe->setPaper('A4', 'P');
    }

    $pdfBinary = $danfe->render();
    if (!is_string($pdfBinary) || strlen($pdfBinary) < 1000) {
        return null;
    }

    $ok = @file_put_contents($pdfPath, $pdfBinary);
    if ($ok === false) {
        return null;
    }

    return $pdfPath;
}

// ===================== Bootstrap =====================

$baseDir = dirname(__DIR__, 3);
$autoload = $baseDir . '/vendor/autoload.php';
$configFile = $baseDir . '/config/nfe.php';

if (!is_file($autoload)) {
    jsonOut(500, ['error' => 'vendor/autoload.php não encontrado', 'expected' => $autoload]);
}
if (!is_file($configFile)) {
    jsonOut(500, ['error' => 'config/nfe.php não encontrado', 'expected' => $configFile]);
}

require $autoload;

$cfg = require $configFile;

// CLI CAIXA-PRETA homologado
$cliLote = $baseDir . '/public/lote_emitir.php';
if (!is_file($cliLote)) {
    jsonOut(500, [
        'error' => 'Script CLI de lote não encontrado',
        'expected' => $cliLote,
    ]);
}

// ===================== Entrada =====================

$rawBody = file_get_contents('php://input') ?: '';
$rawBodyTrim = trim($rawBody);

if ($rawBodyTrim === '') {
    jsonOut(400, ['error' => 'Body vazio (JSON esperado)']);
}

$decoded = json_decode($rawBodyTrim, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    jsonOut(400, [
        'error' => 'JSON inválido',
        'jsonError' => json_last_error_msg(),
    ]);
}

// Aceita objeto único ou array de objetos
$notas = [];
if (is_array($decoded)) {
    $isList = array_keys($decoded) === range(0, count($decoded) - 1);
    $notas = $isList ? $decoded : [$decoded];
} else {
    jsonOut(400, ['error' => 'JSON deve ser objeto ou array de objetos']);
}

if (count($notas) < 1) {
    jsonOut(400, ['error' => 'Nenhuma nota informada']);
}

// ===================== Processamento =====================

$summary = [
    'total' => count($notas),
    'autorizados' => 0,
    'denegados' => 0,
    'rejeitados' => 0,
    'falhas' => 0,
    'erros' => 0,
];

$itens = [];

// pasta temporária por request
$tmpRoot = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR
    . 'nfe_emitir_' . str_replace('.', '', uniqid('', true));
ensureDir($tmpRoot);

$pdfBase = (string)($cfg['pathPdf'] ?? ($baseDir . '/storage/pdf'));
$danfeDir = rtrim($pdfBase, "/\\") . DIRECTORY_SEPARATOR . 'danfe';
ensureDir($danfeDir);

for ($i = 0; $i < count($notas); $i++) {
    $n = $i + 1;
    $notaObj = $notas[$i];

    $loteDir = $tmpRoot . DIRECTORY_SEPARATOR . "lote_{$n}";
    ensureDir($loteDir);

    $jsonPath = $loteDir . DIRECTORY_SEPARATOR . 'nota.json';
    $jsonOk = @file_put_contents(
        $jsonPath,
        json_encode($notaObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT)
    );

    if ($jsonOk === false) {
        $summary['falhas']++;
        $summary['erros']++;
        $itens[] = [
            'nota' => $n,
            'ok' => false,
            'status' => 'ERRO',
            'cStat' => null,
            'xMotivo' => 'Falha ao salvar JSON temporário',
            'chave' => null,
            'bloqueio' => [
                'tipo' => 'erro_tecnico',
                'mensagem' => 'Falha interna ao preparar a emissão.',
            ],
            'paths' => [
                'xmlGerado' => null,
                'xmlAssinado' => null,
                'retEnvio' => null,
                'nfeProc' => null,
                'relatorio' => null,
                'pdf' => null,
            ],
            'raw' => [
                'exitCode' => 1,
                'output' => "Falha ao salvar: {$jsonPath}",
            ],
        ];
        continue;
    }

    $cmd = sprintf('php %s %s', escapeshellarg($cliLote), escapeshellarg($loteDir));
    [$exitCode, $output] = runCmd($cmd);

    // ===== Parse derivado =====
    [$cStatA, $xMotA] = pickLastPair('/cStat\s*=\s*(\d+)\s*xMotivo\s*=\s*([^\r\n]+)/i', $output);
    [$cStatB, $xMotB] = pickLastPair('/cStat\s*:\s*(\d+)\s*xMotivo\s*:\s*([^\r\n]+)/i', $output);

    $cStat = $cStatA ?: $cStatB;
    $xMotivo = $xMotA ?: $xMotB;

    $chave = pickLast('/\b(\d{44})\b/', $output);

    $xmlGerado   = pickLast('/^XML gerado:\s*(.+)$/im', $output);
    $xmlAssinado = pickLast('/^XML Assinado:\s*(.+)$/im', $output);
    $nfeProc     = pickLast('/^nfeProc salvo:\s*(.+)$/im', $output);
    $relatorio   = pickLast('/^Relatório salvo:\s*(.+)$/im', $output);

    $retEnvio = null;
    if (preg_match_all('/^Retorno salvo:\s*(.+)$/im', $output, $mRet)) {
        $cands = $mRet[1] ?? [];
        for ($k = count($cands) - 1; $k >= 0; $k--) {
            $cand = trim((string)$cands[$k]);
            if ($cand !== '' && stripos($cand, 'ret_envio') !== false) {
                $retEnvio = $cand;
                break;
            }
        }
        if ($retEnvio === null && !empty($cands)) {
            $retEnvio = trim((string)$cands[count($cands) - 1]);
        }
    }

    // ===== Classificação fiscal (CLARA pro usuário) =====
    $status = 'DESCONHECIDO';
    $ok = true;
    $bloqueio = null;

    // ERRO técnico
    if ($exitCode !== 0 || hasText($output, 'Fatal error') || hasText($output, 'FATAL') || hasText($output, 'Exception')) {
        $ok = false;
        $status = 'ERRO';
        $bloqueio = [
            'tipo' => 'erro_tecnico',
            'mensagem' => 'Falha técnica no processamento. Verifique o retorno bruto (raw.output).',
        ];
    } else {
        $c = (string)($cStat ?? '');

        $isAut = in_array($c, ['100', '150'], true);
        $isDen = in_array($c, ['110', '301', '302'], true);

        if ($isAut) {
            $status = 'AUTORIZADO';
            $ok = true;
        } elseif ($isDen) {
            $status = 'DENEGADO';
            $ok = false;
            $bloqueio = [
                'tipo' => 'denegada',
                'mensagem' => 'NF-e DENEGADA: não pode ser utilizada e não pode ser corrigida/reemitida com a mesma chave.',
            ];
        } elseif ($c !== '') {
            $status = 'REJEITADO';
            $ok = false;
            $bloqueio = [
                'tipo' => 'rejeicao',
                'mensagem' => 'NF-e REJEITADA: corrija os dados e reenvie a nota.',
            ];
        } else {
            // Sem cStat: decide por texto
            if (hasText($output, 'ERRO')) {
                $status = 'ERRO';
                $ok = false;
                $bloqueio = [
                    'tipo' => 'erro_tecnico',
                    'mensagem' => 'Falha técnica no processamento. Verifique o retorno bruto (raw.output).',
                ];
            } else {
                $status = 'OK';
                $ok = true;
            }
        }
    }

    // ===== DANFE PDF (somente se AUTORIZADO) =====
    $pdfPath = null;
    if ($status === 'AUTORIZADO'
        && is_string($nfeProc) && $nfeProc !== ''
        && is_string($chave) && $chave !== '') {
        $pdfPath = gerarDanfePdfFromProc($nfeProc, $danfeDir, $chave);
    }

    // ===== Contadores =====
    $c = (string)($cStat ?? '');
    if ($status === 'AUTORIZADO') {
        $summary['autorizados']++;
    } elseif ($status === 'DENEGADO') {
        $summary['denegados']++;
        $summary['falhas']++;
    } elseif ($status === 'REJEITADO') {
        $summary['rejeitados']++;
        $summary['falhas']++;
    } elseif ($status === 'ERRO') {
        $summary['falhas']++;
        $summary['erros']++;
    }

    $itens[] = [
        'nota' => $n,
        'ok' => $ok,
        'status' => $status,
        'cStat' => $cStat,
        'xMotivo' => $xMotivo,
        'chave' => $chave,
        'bloqueio' => $bloqueio, // <<< AQUI fica explícito para o usuário
        'paths' => [
            'xmlGerado' => $xmlGerado,
            'xmlAssinado' => $xmlAssinado,
            'retEnvio' => $retEnvio,
            'nfeProc' => $nfeProc,
            'relatorio' => $relatorio,
            'pdf' => $pdfPath,
        ],
        'raw' => [
            'exitCode' => $exitCode,
            'output' => $output,
        ],
    ];
}

// ===================== Saída =====================

jsonOut(200, [
    'summary' => $summary,
    'itens' => $itens,
]);
