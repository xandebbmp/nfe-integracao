<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NFePHP\NFe\Complements;
use Xande\NfeIntegracao\NfeService;
use Xande\NfeIntegracao\Support\Io;

header('Content-Type: text/plain; charset=utf-8');

$cfg = require __DIR__ . '/../config/nfe.php';
$svc = new NfeService($cfg);

// ========================
// Parâmetros
// ========================
$chave = Io::onlyDigits((string)($_GET['chave'] ?? ''));
$texto = trim((string)($_GET['texto'] ?? ''));
$seq   = (int)($_GET['seq'] ?? 1);

if (!preg_match('/^\d{44}$/', $chave)) {
    http_response_code(400);
    exit("Chave inválida (precisa ter 44 dígitos).\n");
}
if (mb_strlen($texto) < 15) {
    http_response_code(400);
    exit("Texto de correção deve ter ao menos 15 caracteres.\n");
}
if ($seq < 1 || $seq > 999) {
    http_response_code(400);
    exit("Seq inválida (1..999).\n");
}

$seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);

// ========================
// Diretórios (OPÇÃO A)
// ========================
$xmlDirCce = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . 'cce';
$pdfDirCce = rtrim((string)$cfg['pathPdf'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . 'cce';
$retDir    = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'retornos';

Io::ensureDir($xmlDirCce);
Io::ensureDir($pdfDirCce);
Io::ensureDir($retDir);

$procEventoFile = $xmlDirCce . DIRECTORY_SEPARATOR . "cce-{$chave}-{$seqStr}-procEvento.xml";
$pdfFile        = $pdfDirCce . DIRECTORY_SEPARATOR . "cce-{$chave}-{$seqStr}.pdf";

// Se já existe, não reenvia
if (is_file($procEventoFile) && filesize($procEventoFile) > 50) {
    echo "CC-e já existe.\n";
    echo "Arquivo XML: {$procEventoFile}\n";
    echo "PDF (cache): {$pdfFile}\n";
    echo "Abrir PDF via evento.php: http://localhost/nfe-integracao/public/evento.php?tipo=cce&chave={$chave}&seq={$seq}\n";
    exit;
}

try {
    $tools = $svc->tools();

    // ========================
    // Envia CC-e
    // ========================
    $response = $tools->sefazCCe($chave, $texto, $seq);

    $ts = Io::ts();

    // salva retorno bruto em retornos/
    $retPath = Io::save($retDir, "ret-cce-{$chave}-{$seqStr}-{$ts}.xml", (string)$response);

    // ========================
    // Parse robusto (ignora namespace)
    // ========================
    $xml = @simplexml_load_string((string)$response);
    if ($xml === false) {
        throw new RuntimeException("Retorno inválido da SEFAZ (não é XML). Retorno salvo em: {$retPath}");
    }

    // cStat do lote
    $cStatNode = $xml->xpath('//*[local-name()="cStat"][1]');
    $cStatLote = isset($cStatNode[0]) ? (string)$cStatNode[0] : '';

    if ($cStatLote !== '128') {
        $xMot = $xml->xpath('//*[local-name()="xMotivo"][1]');
        $xMotivo = isset($xMot[0]) ? (string)$xMot[0] : '';
        throw new RuntimeException("CC-e não processada. cStat={$cStatLote} xMotivo={$xMotivo}");
    }

    // cStat do evento
    $cStatEvtNode = $xml->xpath('//*[local-name()="retEvento"]//*[local-name()="infEvento"]//*[local-name()="cStat"][1]');
    if (!isset($cStatEvtNode[0])) {
        throw new RuntimeException("Retorno sem cStat do evento. Retorno salvo em: {$retPath}");
    }
    $cStatEvt = (string)$cStatEvtNode[0];

    // Sucesso aceito:
    // 135 = evento registrado e vinculado a NF-e
    // 136 = evento registrado, mas não vinculado
    // 573 = duplicidade (já existe)
    $sucesso = ['135', '136', '573'];
    if (!in_array($cStatEvt, $sucesso, true)) {
        $xMotEvt = $xml->xpath('//*[local-name()="retEvento"]//*[local-name()="infEvento"]//*[local-name()="xMotivo"][1]');
        $xMotivoEvt = isset($xMotEvt[0]) ? (string)$xMotEvt[0] : '';
        throw new RuntimeException("Evento rejeitado. cStat={$cStatEvt} xMotivo={$xMotivoEvt}");
    }

    // ========================
    // Monta procEvento (Complements)
    // ========================
    $req = $tools->lastRequest ?? '';
    if (trim((string)$req) === '') {
        throw new RuntimeException("tools->lastRequest veio vazio. Não dá pra montar procEvento. Retorno salvo em: {$retPath}");
    }

    $reqPath = Io::save($retDir, "req-cce-{$chave}-{$seqStr}-{$ts}.xml", (string)$req);

    $procEventoXml = Complements::toAuthorize((string)$req, (string)$response);

    if (trim((string)$procEventoXml) === '' || mb_strlen((string)$procEventoXml) < 50) {
        Io::save($retDir, "proc-cce-{$chave}-{$seqStr}-gerado-{$ts}.xml", (string)$procEventoXml);
        throw new RuntimeException("procEvento gerado vazio/pequeno. Debug salvo em retornos/.");
    }

    // salva procEvento
    if (file_put_contents($procEventoFile, (string)$procEventoXml) === false) {
        $err = error_get_last();
        throw new RuntimeException("Falha ao salvar procEvento em: {$procEventoFile}. Erro: " . ($err['message'] ?? 'desconhecido'));
    }

    // ========================
    // Gera PDF e salva (Daevento exige config array)
    // ========================
    $config = [
        'tipo'     => 'cce',
        'tpEvento' => '110110',
    ];

    $svc->gerarPdfEvento((string)$procEventoXml, $config, $pdfFile);

    echo "CC-e OK.\n";
    echo "cStatEvento={$cStatEvt}\n";
    echo "XML gerado: {$procEventoFile}\n";
    echo "PDF gerado: {$pdfFile}\n";
    echo "Request salvo: {$reqPath}\n";
    echo "Retorno salvo: {$retPath}\n";
    echo "Abrir PDF via evento.php: http://localhost/nfe-integracao/public/evento.php?tipo=cce&chave={$chave}&seq={$seq}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro: " . $e->getMessage() . "\n";
}
