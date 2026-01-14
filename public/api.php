<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

function jsonResponse(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function run(string $cmd): array {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function readJsonBody(): mixed {
    $raw = file_get_contents('php://input') ?: '';
    $raw = trim($raw);
    if ($raw === '') return null;

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(400, ['status'=>'ERRO', 'msg'=>'JSON inválido', 'detalhe'=>json_last_error_msg()]);
    }
    return $data;
}

function writeTempJson(array $nota, string $tmpDir): string {
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    $file = $tmpDir . '/nota-' . uniqid('', true) . '.json';
    $ok = file_put_contents($file, json_encode($nota, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($ok === false) throw new RuntimeException("Falha ao criar temp json: $file");
    return $file;
}

/**
 * Tenta extrair do stdout do assinar_enviar_sincrono.php:
 * - cStat do lote
 * - cStat do protocolo
 * - caminho do XML assinado
 * - caminho do retorno salvo
 * - caminho do nfeProc salvo
 */
function parseEnvioOutput(string $out): array {
    $r = [
        'cStatLote' => null,
        'xMotivoLote' => null,
        'cStatProt' => null,
        'xMotivoProt' => null,
        'xmlAssinado' => null,
        'retornoSalvo' => null,
        'nfeProc' => null,
        'raw' => $out,
    ];

    if (preg_match('/Retorno Lote:\s*cStat=([0-9]+)\s*xMotivo=(.+)\s*$/mi', $out, $m)) {
        $r['cStatLote'] = $m[1];
        $r['xMotivoLote'] = trim($m[2]);
    }
    if (preg_match('/Protocolo:\s*cStat=([0-9]+)\s*xMotivo=(.+)\s*$/mi', $out, $m)) {
        $r['cStatProt'] = $m[1];
        $r['xMotivoProt'] = trim($m[2]);
    }
    if (preg_match('/^XML Assinado:\s*(.+)\s*$/mi', $out, $m)) {
        $r['xmlAssinado'] = trim($m[1], " \t\n\r\0\x0B\"'");
    }
    if (preg_match('/^Retorno salvo:\s*(.+)\s*$/mi', $out, $m)) {
        $r['retornoSalvo'] = trim($m[1], " \t\n\r\0\x0B\"'");
    }
    if (preg_match('/^nfeProc salvo:\s*(.+)\s*$/mi', $out, $m)) {
        $r['nfeProc'] = trim($m[1], " \t\n\r\0\x0B\"'");
    }

    return $r;
}

function emitirUmaNota(array $nota, string $baseDir): array {
    $emitScript = escapeshellarg($baseDir . '/public/emitir_json_pl010.php');
    $sendScript = escapeshellarg($baseDir . '/public/assinar_enviar_sincrono.php');

    $tmpDir = $baseDir . '/storage/tmp/api';
    $tmpJson = writeTempJson($nota, $tmpDir);

    // Ajuda o integrador a “casar” resposta com request
    $idIntegracao = $nota['idIntegracao'] ?? null;

    try {
        // 1) gerar XML
        $tmpArg = escapeshellarg($tmpJson);
        [$c1, $out1] = run("php {$emitScript} {$tmpArg}");
        if ($c1 !== 0) {
            return [
                'status' => 'ERRO_GERAR_XML',
                'idIntegracao' => $idIntegracao,
                'detalhe' => $out1,
            ];
        }

        $xmlPath = trim($out1, " \t\n\r\0\x0B\"'");
        if ($xmlPath === '' || !is_file($xmlPath)) {
            return [
                'status' => 'ERRO_XML_PATH',
                'idIntegracao' => $idIntegracao,
                'detalhe' => $out1,
            ];
        }

        // 2) assinar + enviar
        $xmlArg = escapeshellarg($xmlPath);
        [$c2, $out2] = run("php {$sendScript} {$xmlArg}");

        $parsed = parseEnvioOutput($out2);

        if ($c2 !== 0) {
            return [
                'status' => 'ERRO_ENVIO',
                'idIntegracao' => $idIntegracao,
                'xmlGerado' => $xmlPath,
                'cStatLote' => $parsed['cStatLote'],
                'cStatProt' => $parsed['cStatProt'],
                'xMotivoProt' => $parsed['xMotivoProt'],
                'nfeProc' => $parsed['nfeProc'],
                'retornoSalvo' => $parsed['retornoSalvo'],
                'detalhe' => $parsed['raw'],
            ];
        }

        // “OK” aqui significa: script terminou com exit(0).
        // Você ainda pode conferir cStatProt=100/110/301/302 se quiser ser mais rígido.
        return [
            'status' => 'OK',
            'idIntegracao' => $idIntegracao,
            'xmlGerado' => $xmlPath,
            'xmlAssinado' => $parsed['xmlAssinado'],
            'nfeProc' => $parsed['nfeProc'],
            'retornoSalvo' => $parsed['retornoSalvo'],
            'cStatLote' => $parsed['cStatLote'],
            'xMotivoLote' => $parsed['xMotivoLote'],
            'cStatProt' => $parsed['cStatProt'],
            'xMotivoProt' => $parsed['xMotivoProt'],
        ];

    } finally {
        @unlink($tmpJson);
    }
}

// =======================
// Router mínimo
// =======================
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$baseDir = dirname(__DIR__);

if ($method === 'GET' && $path === '/ping') {
    jsonResponse(200, ['status'=>'OK', 'msg'=>'pong']);
}

/**
 * ROTA ÚNICA: aceita:
 *  - objeto (nota única)
 *  - array (lote)
 *  - {"notas":[...]} (wrapper)
 */
if ($method === 'POST' && $path === '/nfe/emitir') {
    $body = readJsonBody();

    if ($body === null) {
        jsonResponse(400, ['status'=>'ERRO', 'msg'=>'Body vazio. Envie JSON.']);
    }

    // normaliza para array de notas
    if (is_array($body) && array_key_exists('notas', $body)) {
        $notas = $body['notas'];
    } else {
        $notas = $body;
    }

    // Se veio nota única (objeto), transforma em lote de 1
    $isLista = is_array($notas) && array_keys($notas) === range(0, count($notas) - 1);
    if (!$isLista) {
        if (!is_array($notas)) {
            jsonResponse(400, ['status'=>'ERRO', 'msg'=>'Envie um objeto de nota ou um array de notas.']);
        }
        $notas = [$notas];
    }

    $resultados = [];
    $ok = 0;
    $erros = 0;

    foreach ($notas as $i => $nota) {
        if (!is_array($nota)) {
            $resultados[] = ['idx'=>$i, 'status'=>'ERRO_NOTA_INVALIDA'];
            $erros++;
            continue;
        }
        $res = emitirUmaNota($nota, $baseDir);
        $res['idx'] = $i;
        $resultados[] = $res;

        if (($res['status'] ?? '') === 'OK') $ok++; else $erros++;
    }

    // Código HTTP: 200 se teve pelo menos 1 OK, senão 422
    $http = $ok > 0 ? 200 : 422;

    jsonResponse($http, [
        'status' => $erros === 0 ? 'OK' : ($ok === 0 ? 'ERRO' : 'PARCIAL'),
        'total' => count($resultados),
        'sucesso' => $ok,
        'falha' => $erros,
        'resultados' => $resultados,
    ]);
}

jsonResponse(404, ['status'=>'ERRO', 'msg'=>'Rota não encontrada.']);
