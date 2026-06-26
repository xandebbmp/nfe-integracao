<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/src/Support/HttpSecurity.php';
$cfgSecurity = nfe_require_api_token();

/**
 * POST /v1/nfe/dfe_padrao
 * (Também aceita GET para facilitar testes)
 *
 * Entrada:
 *  - POST JSON: {"ultNSU":0,"numNSU":0,"chave":"44digitos","fonte":"AN"}
 *  - GET/form-data com os mesmos campos
 *
 * Prioridade:
 *  1) chave preenchida
 *  2) numNSU > 0
 *  3) ultNSU
 *
 * Observação: este wrapper salva somente o XML bruto do retorno.
 * Não descompacta docZip e não processa documentos retornados.
 */

$alreadyOutput = false;

register_shutdown_function(static function () use (&$alreadyOutput, $cfgSecurity): void {
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
                'ultNSU' => null,
                'maxNSU' => null,
                'modo' => null,
                'chave' => null,
                'numNSU' => null,
                'fonte' => null,
                'message' => 'Falha fatal ao consultar Distribuição DF-e.',
                'paths' => [],
                'raw' => nfe_maybe_raw([
                    'exitCode' => 1,
                    'output' => ($err['message'] ?? 'fatal') . ' in ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'),
                ], $cfgSecurity),
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

function dfe_only_digits(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}

function dfe_non_negative_int($value, string $field, ?string &$error): int {
    if ($value === null || $value === '') {
        return 0;
    }

    if (is_int($value)) {
        if ($value >= 0) {
            return $value;
        }
        $error = "{$field} deve ser um inteiro não negativo.";
        return 0;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }
    }

    $error = "{$field} deve ser um inteiro não negativo.";
    return 0;
}

function dfe_json_error(int $code, array $item): void {
    http_response_code($code);
    echo json_encode(['item' => $item], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$input = [];

if ($method === 'GET') {
    $input = $_GET;
} else {
    $rawBody = file_get_contents('php://input') ?: '';
    $rawBodyTrim = trim($rawBody);

    if ($rawBodyTrim !== '') {
        $decoded = json_decode($rawBodyTrim, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $input = $decoded;
        }
    }

    $input = array_replace($_POST, $input);
}

$numberError = null;
$ultNSU = dfe_non_negative_int($input['ultNSU'] ?? 0, 'ultNSU', $numberError);
$numNSU = dfe_non_negative_int($input['numNSU'] ?? 0, 'numNSU', $numberError);
$chave = dfe_only_digits((string)($input['chave'] ?? ''));
$fonte = strtoupper(trim((string)($input['fonte'] ?? 'AN')));
if ($fonte === '') {
    $fonte = 'AN';
}

if ($numberError !== null) {
    $alreadyOutput = true;
    dfe_json_error(400, [
        'ok' => false,
        'status' => 'ERRO',
        'cStat' => null,
        'xMotivo' => $numberError,
        'ultNSU' => null,
        'maxNSU' => null,
        'modo' => null,
        'chave' => $chave !== '' ? $chave : null,
        'numNSU' => null,
        'fonte' => $fonte,
        'message' => 'Entrada inválida para Distribuição DF-e.',
        'paths' => [],
        'raw' => null,
    ]);
}

if ($chave !== '' && !preg_match('/^\d{44}$/', $chave)) {
    $alreadyOutput = true;
    dfe_json_error(400, [
        'ok' => false,
        'status' => 'ERRO',
        'cStat' => null,
        'xMotivo' => 'Chave inválida (precisa ter 44 dígitos).',
        'ultNSU' => null,
        'maxNSU' => null,
        'modo' => null,
        'chave' => $chave,
        'numNSU' => $numNSU,
        'fonte' => $fonte,
        'message' => 'Entrada inválida para Distribuição DF-e.',
        'paths' => [],
        'raw' => null,
    ]);
}

if (!in_array($fonte, ['AN', 'RS'], true)) {
    $alreadyOutput = true;
    dfe_json_error(400, [
        'ok' => false,
        'status' => 'ERRO',
        'cStat' => null,
        'xMotivo' => 'Fonte inválida (aceita apenas AN ou RS).',
        'ultNSU' => null,
        'maxNSU' => null,
        'modo' => null,
        'chave' => $chave !== '' ? $chave : null,
        'numNSU' => $numNSU,
        'fonte' => $fonte,
        'message' => 'Entrada inválida para Distribuição DF-e.',
        'paths' => [],
        'raw' => null,
    ]);
}

$modo = 'ultNSU';
if ($chave !== '') {
    $modo = 'chave';
    $numNSU = 0;
    $ultNSU = 0;
} elseif ($numNSU > 0) {
    $modo = 'numNSU';
    $ultNSU = 0;
}

$baseDir = dirname(__DIR__, 3);
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
            'ultNSU' => null,
            'maxNSU' => null,
            'modo' => $modo,
            'chave' => $chave !== '' ? $chave : null,
            'numNSU' => $numNSU,
            'fonte' => $fonte,
            'message' => 'Falha ao preparar consulta Distribuição DF-e.',
            'paths' => [],
            'raw' => nfe_maybe_raw([
                'exitCode' => 1,
                'output' => 'expected: ' . $autoload,
            ], $cfgSecurity),
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
            'ultNSU' => null,
            'maxNSU' => null,
            'modo' => $modo,
            'chave' => $chave !== '' ? $chave : null,
            'numNSU' => $numNSU,
            'fonte' => $fonte,
            'message' => 'Falha ao preparar consulta Distribuição DF-e.',
            'paths' => [],
            'raw' => nfe_maybe_raw([
                'exitCode' => 1,
                'output' => 'expected: ' . $configFile,
            ], $cfgSecurity),
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
$debugRaw = nfe_debug_raw_enabled($cfg);

$output = '';
$retPath = null;

$pickFirst = static function (string $pattern, string $text): ?string {
    if (preg_match($pattern, $text, $m)) return trim((string)$m[1]);
    return null;
};

try {
    $tools = $svc->tools();
    $response = $tools->sefazDistDFe($ultNSU, $numNSU, $chave !== '' ? $chave : null, $fonte);
    $output = (string)$response;

    $retDir = rtrim((string)($cfg['pathXml'] ?? ($baseDir . '/storage/xml')), "/\\") . DIRECTORY_SEPARATOR . 'retornos';
    Io::ensureDir($retDir);

    $ts = Io::ts();
    if ($modo === 'chave') {
        $filename = "ret-dfe-chave-{$chave}-{$ts}.xml";
    } elseif ($modo === 'numNSU') {
        $filename = "ret-dfe-nsu-{$numNSU}-{$ts}.xml";
    } else {
        $filename = "ret-dfe-ultnsu-{$ultNSU}-{$ts}.xml";
    }
    $retPath = Io::save($retDir, $filename, $output);

    $cStat = $pickFirst('/<cStat>\s*([^<]+)\s*<\/cStat>/i', $output);
    $xMotivo = $pickFirst('/<xMotivo>\s*([^<]+)\s*<\/xMotivo>/i', $output);
    $ultNSURet = $pickFirst('/<ultNSU>\s*([^<]+)\s*<\/ultNSU>/i', $output);
    $maxNSU = $pickFirst('/<maxNSU>\s*([^<]+)\s*<\/maxNSU>/i', $output);

    $status = 'DESCONHECIDO';
    if ($cStat !== null && $cStat !== '') {
        $status = ((string)$cStat === '656') ? 'REJEITADO' : 'OK';
    }
    $ok = ($status === 'OK');

    http_response_code(200);
    echo json_encode([
        'item' => [
            'ok' => $ok,
            'status' => $status,
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'ultNSU' => $ultNSURet,
            'maxNSU' => $maxNSU,
            'modo' => $modo,
            'chave' => $chave !== '' ? $chave : null,
            'numNSU' => $numNSU,
            'fonte' => $fonte,
            'message' => 'Distribuição DF-e consultada.',
            'paths' => $debugRaw ? [
                'retEnvio' => $retPath,
            ] : [],
            'raw' => nfe_maybe_raw([
                'exitCode' => 0,
                'output' => $output,
            ], $cfg),
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
            'xMotivo' => 'Falha ao consultar Distribuição DF-e.',
            'ultNSU' => null,
            'maxNSU' => null,
            'modo' => $modo,
            'chave' => $chave !== '' ? $chave : null,
            'numNSU' => $numNSU,
            'fonte' => $fonte,
            'message' => 'Falha ao consultar Distribuição DF-e.',
            'paths' => $debugRaw ? [
                'retEnvio' => $retPath,
            ] : [],
            'raw' => nfe_maybe_raw([
                'exitCode' => 1,
                'output' => $output !== '' ? $output : ('Erro: ' . $e->getMessage()),
            ], $cfg),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $alreadyOutput = true;
    exit;
}
