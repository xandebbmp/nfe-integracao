<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

function dieMsg(string $msg, int $code = 1): void {
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function run(string $cmd): array {
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return [$code, implode("\n", $output)];
}

function normalizePath(string $path): string {
    return trim($path, " \t\n\r\0\x0B\"'");
}

function extractChaveFromXmlPath(string $xmlPath): string {
    // Espera: .../NFe-<CHAVE44>.xml
    $base = basename($xmlPath);
    if (preg_match('/^NFe-(\d{44})\.xml$/', $base, $m)) {
        return $m[1];
    }
    return '';
}

// ======================
// Args
// ======================
$dir = $argv[1] ?? '';
$dir = normalizePath($dir);

if ($dir === '') {
    dieMsg("Uso: php public/lote_emitir.php <pasta_com_jsons>\nEx: php public/lote_emitir.php json/lote");
}

$baseDir = dirname(__DIR__);
$absDir = $dir;

// se não for absoluto, considera relativo ao projeto
$isAbsWindows = preg_match('/^[A-Za-z]:\\\\/', $absDir) === 1;
$isAbsUnix = str_starts_with($absDir, '/');
if (!$isAbsWindows && !$isAbsUnix) {
    $absDir = $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
}

if (!is_dir($absDir)) {
    dieMsg("Pasta não encontrada: {$absDir}");
}

$files = glob($absDir . DIRECTORY_SEPARATOR . '*.json');
if (!$files || count($files) === 0) {
    dieMsg("Nenhum .json encontrado em: {$absDir}");
}

sort($files);

// scripts que você JÁ TEM
$emitScript = escapeshellarg($baseDir . '/public/emitir_json_pl010.php');
$sendScript = escapeshellarg($baseDir . '/public/assinar_enviar_sincrono.php');

// caminhos padrão do seu projeto
$dirAutorizados = $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'autorizados';

echo "=== LOTE NF-e (sequencial) ===\n";
echo "Pasta: {$absDir}\n";
echo "Arquivos: " . count($files) . "\n\n";

$ok = 0;
$fail = 0;
$skipped = 0;

$report = [];

foreach ($files as $file) {
    $fileArg = escapeshellarg($file);

    echo ">>> JSON: " . basename($file) . "\n";

    // 1) gera XML (seu emitir_json_pl010.php imprime o caminho do XML gerado)
    [$c1, $out1] = run("php {$emitScript} {$fileArg}");
    if ($c1 !== 0) {
        $fail++;
        echo "ERRO ao gerar XML.\n{$out1}\n\n";
        $report[] = [
            'json' => basename($file),
            'status' => 'ERRO_GERAR_XML',
            'detalhe' => $out1
        ];
        continue;
    }

    $xmlPath = normalizePath($out1);
    if ($xmlPath === '' || !is_file($xmlPath)) {
        $fail++;
        echo "ERRO: script não retornou um caminho válido de XML.\n{$out1}\n\n";
        $report[] = [
            'json' => basename($file),
            'status' => 'ERRO_XML_PATH',
            'detalhe' => $out1
        ];
        continue;
    }

    echo "XML gerado: {$xmlPath}\n";

    // 1.1) EXTRAI CHAVE DO NOME DO XML
    $chave = extractChaveFromXmlPath($xmlPath);
    if ($chave === '') {
        $fail++;
        echo "ERRO: não consegui extrair CHAVE do arquivo XML: " . basename($xmlPath) . "\n\n";
        $report[] = [
            'json' => basename($file),
            'status' => 'ERRO_EXTRair_CHAVE',
            'xml' => $xmlPath,
            'detalhe' => 'Nome esperado: NFe-<CHAVE44>.xml'
        ];
        continue;
    }

    // 1.2) PULA SE JÁ EXISTE procNFe EM autorizados/
    $procPath = $dirAutorizados . DIRECTORY_SEPARATOR . "NFe-{$chave}-procNFe.xml";
    if (is_file($procPath) && filesize($procPath) > 200) {
        $skipped++;
        echo "PULADO ✅ (já autorizada)\n";
        echo "procNFe existente: {$procPath}\n\n";
        $report[] = [
            'json' => basename($file),
            'status' => 'PULADO_JA_AUTORIZADA',
            'xml' => $xmlPath,
            'proc' => $procPath,
        ];
        continue;
    }

    // 2) assina + envia + salva nfeProc (seu script atual já faz tudo)
    $xmlArg = escapeshellarg($xmlPath);
    [$c2, $out2] = run("php {$sendScript} {$xmlArg}");

    if ($c2 !== 0) {
    // Se o script de envio falhar mas tiver Protocolo cStat=100/150, considera AUTORIZADA
        $cStatProt = '';
        if (preg_match('/Protocolo:\s*cStat=(\d+)/', $out2, $m)) {
            $cStatProt = $m[1];
        }

        if (in_array($cStatProt, ['100', '150'], true)) {
            $ok++;
            echo "OK ✅ (autorizada apesar do exitCode)\n{$out2}\n\n";
            $report[] = [
                'json' => basename($file),
                'status' => 'OK_AUTORIZADA_EXITCODE',
                'xml' => $xmlPath,
                'detalhe' => $out2
            ];
            continue;
        }

        $fail++;
        echo "ERRO no envio.\n{$out2}\n\n";
        $report[] = [
            'json' => basename($file),
            'status' => 'ERRO_ENVIO',
            'xml' => $xmlPath,
            'detalhe' => $out2
        ];
        continue;
    }

    $ok++;
    echo "OK ✅\n{$out2}\n\n";

    $report[] = [
        'json' => basename($file),
        'status' => 'OK',
        'xml' => $xmlPath,
        'detalhe' => $out2
    ];
}

echo "=== RESULTADO ===\n";
echo "OK: {$ok}\n";
echo "PULADOS: {$skipped}\n";
echo "FALHAS: {$fail}\n";

// salva um report simples em storage/xml/retornos
$reportDir = $baseDir . '/storage/xml/retornos';
@mkdir($reportDir, 0775, true);

$reportFile = $reportDir . '/ret-lote-' . date('Ymd_His') . '.txt';

$txt = "LOTE " . date('Y-m-d H:i:s') . PHP_EOL;
$txt .= "Pasta: {$absDir}" . PHP_EOL;
$txt .= "OK={$ok} SKIP={$skipped} FAIL={$fail}" . PHP_EOL . PHP_EOL;

foreach ($report as $r) {
    $txt .= "- {$r['json']} :: {$r['status']}" . PHP_EOL;
    if (!empty($r['xml']))  $txt .= "  XML: {$r['xml']}" . PHP_EOL;
    if (!empty($r['proc'])) $txt .= "  PROC: {$r['proc']}" . PHP_EOL;
    $txt .= PHP_EOL;
}

file_put_contents($reportFile, $txt);
echo "Relatório salvo: {$reportFile}\n";
