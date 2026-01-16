<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/**
 * POST /v1/nfe/status_padrao
 * (Também aceita GET para facilitar testes)
 *
 * Entrada (opcional):
 *  - POST JSON: {"uf":"BA"}
 *  - GET: ?uf=BA
 *
 * Retorno:
 *  - item (ok/status/cStat/xMotivo/uf/paths/raw)
 */

$alreadyOutput = false;

register_shutdown_function(static function () use (&$alreadyOutput): void {
    if ($alreadyOutput) return;

    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'item' => [
                'ok' => false,
                'status' => 'ERRO',
                'cStat' => null,
                'xMotivo' => 'Fatal error',
                'uf' => null,
                'paths' => [
                    'retEnvio' => null,
                ],
                'raw' => [
                    'exitCode' => 1,
                    'output' => ($err['message'] ?? 'fatal') . ' in ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $alreadyOutput = true;
    }
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $alreadyOutput = true;
    exit;
}

$ufIn = '';

if ($method === 'GET') {
    $ufIn = (string)($_GET['uf'] ?? '');
} else {
    $rawBody = file_get_contents('php://input') ?: '';
    $rawBodyTrim = trim($rawBody);
    if ($rawBodyTrim !== '') {
        $decoded = json_decode($rawBodyTrim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $ufIn = (string)($decoded['uf'] ?? '');
        }
    }
    if ($ufIn === '') $ufIn = (string)($_POST['uf'] ?? '');
}

$ufIn = strtoupper(trim($ufIn));

$baseDir = dirname(__DIR__, 3); // public/v1/nfe -> raiz do projeto
$autoload = $baseDir . '/vendor/autoload.php';
$configFile = $baseDir . '/config/nfe.php';

if (!is_file($autoload)) {
    http_response_code(500);
    echo json_encode([
        'item' => [
            'ok' => false,
            'status' => 'ERRO',
            'cStat' => null,
            'xMotivo' => 'vendor/autoload.php não encontrado',
            'uf' => null,
            'paths' => ['retEnvio' => null],
            'raw' => [
                'exitCode' => 1,
                'output' => 'expected: ' . $autoload,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $alreadyOutput = true;
    exit;
}

if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode([
        'item' => [
            'ok' => false,
            'status' => 'ERRO',
            'cStat' => null,
            'xMotivo' => 'config/nfe.php não encontrado',
            'uf' => null,
            'paths' => ['retEnvio' => null],
            'raw' => [
                'exitCode' => 1,
                'output' => 'expected: ' . $configFile,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $alreadyOutput = true;
    exit;
}

require $autoload;

use Xande\NfeIntegracao\NfeService;
use Xande\NfeIntegracao\Support\Io;

$cfg = require $configFile;
$svc = new NfeService($cfg);

$exitCode = 0;
$output = '';
$retPath = null;

$pickFirst = static function (string $pattern, string $text): ?string {
    if (preg_match($pattern, $text, $m)) return trim((string)$m[1]);
    return null;
};

try {
    $tools = $svc->tools();

    // UF: input -> tenta achar no config -> default BA
    $uf = $ufIn;
    if ($uf === '') {
        foreach (['siglaUF', 'uf', 'UF', 'cUF'] as $k) {
            if (!empty($cfg[$k])) {
                $v = strtoupper(trim((string)$cfg[$k]));
                if (preg_match('/^\d+$/', $v)) {
                    // mapeamento mínimo (se vier cUF=29)
                    $v = ($v === '29') ? 'BA' : $v;
                }
                $uf = $v;
                break;
            }
        }
    }
    if ($uf === '') $uf = 'BA';

    $tpAmb = (int)($cfg['tpAmb'] ?? 2);

    // pasta retornos
    $retDir = rtrim((string)($cfg['pathXml'] ?? ($baseDir . '/storage/xml')), "/\\") . DIRECTORY_SEPARATOR . 'retornos';
    Io::ensureDir($retDir);

    // consulta status (assinaturas variam)
    try {
        $response = $tools->sefazStatus($uf, $tpAmb);
    } catch (Throwable $e1) {
        try {
            $response = $tools->sefazStatus($uf);
        } catch (Throwable $e2) {
            $response = $tools->sefazStatus();
        }
    }

    $output = (string)$response;

    // salva retorno
    $ts = Io::ts();
    $retPath = Io::save($retDir, "ret-status-{$uf}-{$ts}.xml", $output);

    // parse (derivado)
    $cStat   = $pickFirst('/<cStat>\s*([^<]+)\s*<\/cStat>/i', $output) ?: $pickFirst('/cStat\s*=\s*(\d+)/i', $output);
    $xMotivo = $pickFirst('/<xMotivo>\s*([^<]+)\s*<\/xMotivo>/i', $output);

    $status = 'DESCONHECIDO';
    if ($cStat !== null && $cStat !== '') {
        $status = 'OK';
    }

    http_response_code(200);
    echo json_encode([
        'item' => [
            'ok' => ($status !== 'ERRO'),
            'status' => $status,
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'uf' => $uf,
            'paths' => [
                'retEnvio' => $retPath,
            ],
            'raw' => [
                'exitCode' => 0,
                'output' => $output,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $alreadyOutput = true;
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'item' => [
            'ok' => false,
            'status' => 'ERRO',
            'cStat' => null,
            'xMotivo' => $e->getMessage(),
            'uf' => ($ufIn !== '' ? $ufIn : null),
            'paths' => [
                'retEnvio' => $retPath,
            ],
            'raw' => [
                'exitCode' => 1,
                'output' => $output !== '' ? $output : ('Erro: ' . $e->getMessage()),
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $alreadyOutput = true;
    exit;
}
