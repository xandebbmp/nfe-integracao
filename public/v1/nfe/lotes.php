<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 3) . '/src/Support/HttpSecurity.php';
nfe_require_api_token();

/**
 * Emissão assíncrona de NF-e (fila + worker). Exige o header X-NFE-EMPRESA.
 *
 * POST /v1/nfe/lotes
 *   Body: [nota, nota, ...] ou {"notas": [...]}; cada nota no formato de json/lote/001.json
 *   com "idIntegracao" obrigatório (id único da nota no sistema consumidor).
 *   Sem ide.nNF, o número é reservado na hora e devolvido na resposta.
 *   Resposta 202: {"loteId": 1, "itens": [{"jobId", "idIntegracao", "serie", "nNF", "status", "acao", ...}]}
 *
 * GET /v1/nfe/lotes?id=<loteId>
 *   Situação de cada nota do lote (status do job + status do documento na SEFAZ).
 */

const NFE_LOTE_MAX_NOTAS = 500;

function lotesOut(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Fila\FilaStore;
use Xande\NfeIntegracao\Support\ChaveAcesso;

if (nfe_empresa_solicitada() === '') {
    lotesOut(400, ['error' => 'Informe a empresa no header X-NFE-EMPRESA.']);
}

$cfg = nfe_config();
$cnpj = ChaveAcesso::normalizarCnpj((string)$cfg['cnpj']);
$tpAmb = (int)$cfg['tpAmb'];
$fila = new FilaStore(ConnectionFactory::make());

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $loteId = (int)($_GET['id'] ?? 0);
    if ($loteId <= 0) {
        lotesOut(400, ['error' => 'Informe ?id=<loteId>.']);
    }

    $lote = $fila->buscarLote($cnpj, $loteId);
    if ($lote === null) {
        lotesOut(404, ['error' => 'Lote não encontrado para esta empresa.']);
    }

    $resumo = ['total' => count($lote['itens'])];
    foreach ($lote['itens'] as $item) {
        $chave = $item['status'] === 'CONCLUIDO' ? (string)$item['statusDocumento'] : (string)$item['status'];
        $resumo[$chave] = ($resumo[$chave] ?? 0) + 1;
    }

    lotesOut(200, [
        'loteId' => (int)$lote['id'],
        'criadoEm' => $lote['criado_em'],
        'resumo' => $resumo,
        'itens' => $lote['itens'],
    ]);
}

if ($method !== 'POST') {
    lotesOut(405, ['error' => 'Método não permitido']);
}

$body = json_decode(trim((string)file_get_contents('php://input')), true);
if (!is_array($body)) {
    lotesOut(400, ['error' => 'JSON inválido: envie um array de notas ou {"notas": [...]}.']);
}

$notas = array_key_exists('notas', $body) ? $body['notas'] : $body;
if (!is_array($notas) || !array_is_list($notas)) {
    $notas = [$notas];
}
if (count($notas) === 0) {
    lotesOut(400, ['error' => 'Nenhuma nota informada.']);
}
if (count($notas) > NFE_LOTE_MAX_NOTAS) {
    lotesOut(413, ['error' => 'Máximo de ' . NFE_LOTE_MAX_NOTAS . ' notas por lote.']);
}

// Validação estrutural de todas as notas antes de enfileirar qualquer uma (lote é tudo ou nada).
$erros = [];
$ids = [];
foreach ($notas as $i => $nota) {
    $e = [];
    if (!is_array($nota)) {
        $erros[] = ['indice' => $i, 'erros' => ['Nota deve ser um objeto JSON.']];
        continue;
    }

    $id = $nota['idIntegracao'] ?? null;
    if (!is_string($id) || trim($id) === '' || mb_strlen($id) > 100) {
        $e[] = 'idIntegracao obrigatório (texto de até 100 caracteres).';
    } elseif (isset($ids[$id])) {
        $e[] = "idIntegracao repetido no lote (índice {$ids[$id]}).";
    } else {
        $ids[$id] = $i;
    }

    foreach (['ide', 'emit', 'dest', 'itens'] as $bloco) {
        if (empty($nota[$bloco]) || !is_array($nota[$bloco])) {
            $e[] = "Bloco {$bloco} ausente.";
        }
    }
    if (empty($nota['totais']['ICMSTot'])) {
        $e[] = 'Bloco totais.ICMSTot ausente.';
    }

    $cnpjEmit = ChaveAcesso::normalizarCnpj((string)($nota['emit']['CNPJ'] ?? ''));
    if ($cnpjEmit !== $cnpj) {
        $e[] = "emit.CNPJ ({$cnpjEmit}) diferente da empresa do header ({$cnpj}).";
    }
    if (isset($nota['ide']['tpAmb']) && (int)$nota['ide']['tpAmb'] !== $tpAmb) {
        $e[] = "ide.tpAmb={$nota['ide']['tpAmb']} diverge do ambiente da empresa (tpAmb={$tpAmb}).";
    }

    if ($e) {
        $erros[] = ['indice' => $i, 'idIntegracao' => is_string($id) ? $id : null, 'erros' => $e];
    }
}

if ($erros) {
    lotesOut(400, ['error' => 'Lote recusado: corrija as notas abaixo (nenhuma foi enfileirada).', 'notas' => $erros]);
}

try {
    $resultado = $fila->enfileirarNfe($cnpj, $tpAmb, $notas);
} catch (Throwable $e) {
    lotesOut(500, [
        'error' => 'Falha ao enfileirar o lote.',
        'message' => nfe_debug_raw_enabled($cfg) ? $e->getMessage() : null,
    ]);
}

lotesOut(202, $resultado);
