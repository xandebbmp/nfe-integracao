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
$chave     = Io::onlyDigits((string)($_GET['chave'] ?? ''));
$protocolo = Io::onlyDigits((string)($_GET['protocolo'] ?? '')); // nProt
$just      = trim((string)($_GET['just'] ?? 'Cancelamento por solicitação do cliente.'));

if (!preg_match('/^\d{44}$/', $chave)) {
    http_response_code(400);
    exit("Chave inválida (precisa ter 44 dígitos).\n");
}
if ($protocolo === '' || strlen($protocolo) < 10) {
    http_response_code(400);
    exit("Protocolo (nProt) inválido.\n");
}
if (mb_strlen($just) < 15) {
    http_response_code(400);
    exit("Justificativa deve ter ao menos 15 caracteres.\n");
}

// ========================
// Diretórios (OPÇÃO A)
// ========================
$xmlDirCanc = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . 'canc';
$pdfDirCanc = rtrim((string)$cfg['pathPdf'], "/\\") . DIRECTORY_SEPARATOR . 'eventos' . DIRECTORY_SEPARATOR . 'canc';
$retDir     = rtrim((string)$cfg['pathXml'], "/\\") . DIRECTORY_SEPARATOR . 'retornos';

Io::ensureDir($xmlDirCanc);
Io::ensureDir($pdfDirCanc);
Io::ensureDir($retDir);

$procEventoFile = $xmlDirCanc . DIRECTORY_SEPARATOR . "canc-{$chave}-procEvento.xml";
$pdfFile        = $pdfDirCanc . DIRECTORY_SEPARATOR . "canc-{$chave}.pdf";

// Se já existe, não reenvia
if (is_file($procEventoFile) && filesize($procEventoFile) > 50) {
    echo "Cancelamento já existe.\n";
    echo "Arquivo XML: {$procEventoFile}\n";
    echo "PDF (cache): {$pdfFile}\n";
    echo "Abrir PDF via evento.php: http://localhost/nfe-integracao/public/evento.php?tipo=canc&chave={$chave}\n";
    exit;
}

try {
    $tools = $svc->tools();

    // ========================
    // Envia cancelamento
    // sefazCancela(chave, justificativa, protocolo)
    // ========================
    $response = $tools->sefazCancela($chave, $just, $protocolo);

    // salva retorno bruto em retornos/
    $ts = Io::ts();
    $retPath = Io::save($retDir, "ret-canc-{$chave}-{$ts}.xml", (string)$response);

    // ========================
    // Parse robusto (ignora namespace)
    // ========================
    $xml = @simplexml_load_string((string)$response);
    if ($xml === false) {
        throw new RuntimeException("Retorno inválido da SEFAZ (não é XML). Retorno salvo em: {$retPath}");
    }

    // cStat do lote (primeiro cStat do XML)
    $cStatNode = $xml->xpath('//*[local-name()="cStat"][1]');
    $cStatLote = isset($cStatNode[0]) ? (string)$cStatNode[0] : '';

    if ($cStatLote !== '128') {
        $xMot = $xml->xpath('//*[local-name()="xMotivo"][1]');
        $xMotivo = isset($xMot[0]) ? (string)$xMot[0] : '';
        throw new RuntimeException("Cancelamento não processado. cStat={$cStatLote} xMotivo={$xMotivo}");
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

    // salva request também
    $reqPath = Io::save($retDir, "req-canc-{$chave}-{$ts}.xml", (string)$req);

    $procEventoXml = Complements::toAuthorize((string)$req, (string)$response);

    if (trim((string)$procEventoXml) === '' || mb_strlen((string)$procEventoXml) < 50) {
        Io::save($retDir, "proc-canc-{$chave}-gerado-{$ts}.xml", (string)$procEventoXml);
        throw new RuntimeException("procEvento gerado vazio/pequeno. Debug salvo em retornos/.");
    }

    // salva procEvento no padrão Opção A
    if (file_put_contents($procEventoFile, (string)$procEventoXml) === false) {
        $err = error_get_last();
        throw new RuntimeException("Falha ao salvar procEvento em: {$procEventoFile}. Erro: " . ($err['message'] ?? 'desconhecido'));
    }

    // ========================
    // Gera PDF e salva (Daevento exige config array)
    // ========================
    $config = [
        'tipo'     => 'canc',
        'tpEvento' => '110111',
    ];

    $svc->gerarPdfEvento((string)$procEventoXml, $config, $pdfFile);

    echo "Cancelamento OK.\n";
    echo "cStatEvento={$cStatEvt}\n";
    echo "XML gerado: {$procEventoFile}\n";
    echo "PDF gerado: {$pdfFile}\n";
    echo "Request salvo: {$reqPath}\n";
    echo "Retorno salvo: {$retPath}\n";
    echo "Abrir PDF via evento.php: http://localhost/nfe-integracao/public/evento.php?tipo=canc&chave={$chave}\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro: " . $e->getMessage() . "\n";
}
