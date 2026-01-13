<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xande\NfeIntegracao\NfeService;
use Xande\NfeIntegracao\Support\Io;

$cfg = require __DIR__ . '/../config/nfe.php';
$svc = new NfeService($cfg);

// ==== PARAMS ====
$tipo   = strtolower(trim((string)($_GET['tipo'] ?? ''))); // canc | cce | inut
$chave  = Io::onlyDigits((string)($_GET['chave'] ?? ''));  // 44 dígitos
$seq    = (int)($_GET['seq'] ?? 1);                        // CC-e seq
$idInut = trim((string)($_GET['idInut'] ?? ''));           // inutilização

$tiposPermitidos = ['canc', 'cce', 'inut'];
if (!in_array($tipo, $tiposPermitidos, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Tipo inválido. Use: canc, cce ou inut.\n";
    exit;
}

// ==== BASE DIRS (Opção A) ====
$xmlBase = rtrim($cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . $tipo;
$pdfBase = rtrim($cfg['pathPdf'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . $tipo;

try {
    Io::ensureDir($xmlBase);
    Io::ensureDir($pdfBase);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro criando diretórios: " . $e->getMessage() . "\n";
    exit;
}

// ==== RESOLVE XML FILE ====
$xmlFile = '';
$pdfName = '';

if ($tipo === 'canc') {
    if (!preg_match('/^\d{44}$/', $chave)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Chave inválida (precisa ter 44 dígitos).\n";
        exit;
    }
    $xmlFile = $xmlBase . DIRECTORY_SEPARATOR . "canc-{$chave}-procEvento.xml";
    $pdfName = "canc-{$chave}.pdf";
}

if ($tipo === 'cce') {
    if (!preg_match('/^\d{44}$/', $chave)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Chave inválida (precisa ter 44 dígitos).\n";
        exit;
    }
    if ($seq < 1 || $seq > 999) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Seq inválida (1..999).\n";
        exit;
    }
    $seqStr  = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    $xmlFile = $xmlBase . DIRECTORY_SEPARATOR . "cce-{$chave}-{$seqStr}-procEvento.xml";
    $pdfName = "cce-{$chave}-{$seqStr}.pdf";
}

if ($tipo === 'inut') {
    if ($idInut === '' || preg_match('/[^a-z0-9._-]/i', $idInut)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "idInut inválido (use somente letras/números/._-).\n";
        exit;
    }

    // padrão novo (Opção A)
    $xmlFile = $xmlBase . DIRECTORY_SEPARATOR . "inut-{$idInut}-procInut.xml";
    $pdfName = "inut-{$idInut}.pdf";

    // compatibilidade: se você tinha salvado antes direto em storage/xml/eventos (sem /inut)
    if (!is_file($xmlFile)) {
        $legacy = rtrim($cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . "inut-{$idInut}-procInut.xml";
        if (is_file($legacy)) {
            $xmlFile = $legacy;
        }
    }
}

// ==== LÊ XML ====
if (!is_file($xmlFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "XML não encontrado: {$xmlFile}\n";
    exit;
}

$xml = file_get_contents($xmlFile);
if ($xml === false || trim($xml) === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falha ao ler XML.\n";
    exit;
}

// ==== GERA (PDF ou fallback XML) ====
try {
    if ($tipo === 'inut') {
        // Tenta gerar PDF se a classe existir
        if (class_exists(\NFePHP\DA\NFe\DaInut::class)) {
            $da = new \NFePHP\DA\NFe\DaInut($xml);
            if (method_exists($da, 'debugMode')) $da->debugMode(false);
            $pdf = $da->render();
        } elseif (class_exists(\NFePHP\DA\NFe\Dainut::class)) {
            $da = new \NFePHP\DA\NFe\Dainut($xml);
            if (method_exists($da, 'debugMode')) $da->debugMode(false);
            $pdf = $da->render();
        } else {
            // fallback: mostra o XML (não quebra teu fluxo)
            header('Content-Type: application/xml; charset=utf-8');
            echo $xml;
            exit;
        }

        $pdfFile = $pdfBase . DIRECTORY_SEPARATOR . $pdfName;
        @file_put_contents($pdfFile, $pdf);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $pdfName . '"');
        echo $pdf;
        exit;
    }

    // canc / cce -> Daevento (2º param array)
    if (!class_exists(\NFePHP\DA\NFe\Daevento::class)) {
        throw new RuntimeException("Classe Daevento não encontrada. Verifique o sped-da instalado.");
    }

    $config = [
        'tipo'     => $tipo,
        'tpEvento' => ($tipo === 'canc') ? '110111' : '110110',
    ];

    $da  = new \NFePHP\DA\NFe\Daevento($xml, $config);
    if (method_exists($da, 'debugMode')) $da->debugMode(false);
    $pdf = $da->render();

    $pdfFile = $pdfBase . DIRECTORY_SEPARATOR . $pdfName;
    @file_put_contents($pdfFile, $pdf);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $pdfName . '"');
    echo $pdf;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro: " . $e->getMessage() . "\n";
}
