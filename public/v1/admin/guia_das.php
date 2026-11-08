<?php
declare(strict_types=1);

/**
 * Painel admin - guia DAS do Simples (tela Fechamento do mes).
 * Regras em Servico/GuiasDas.php.
 *
 * POST /v1/admin/guia_das.php   (multipart/form-data) anexa ou troca a guia do mes
 *      cnpj, competencia=AAAA-MM, valor, vencimento=AAAA-MM-DD,
 *      linha_digitavel (opcional), avisar=1 (e-mail para o cliente), arquivo=@das.pdf
 * POST /v1/admin/guia_das.php   (JSON) {"acao": "remover", "id": 12}
 * POST /v1/admin/guia_das.php   (JSON) {"acao": "pagar", "id": 12, "data": "2026-12-18"}  (data vazia = hoje)
 * POST /v1/admin/guia_das.php   (JSON) {"acao": "desfazer_pagamento", "id": 12}
 * GET  /v1/admin/guia_das.php?id=12   abre o PDF
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\GuiasDas;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$guias = new GuiasDas($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    $guia = $guias->buscar($id);
    $pdf = $guia !== null ? $guias->pdf($id) : null;
    if ($pdf === null) {
        $api->erro(404, 'NAO_ENCONTRADA', 'Guia não encontrada.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="das-' . $guia['cnpj'] . '-' . $guia['competencia'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// Remover (JSON)
if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $corpo = $api->corpoJson();
    $id = (int)($corpo['id'] ?? 0);
    $r = match ($corpo['acao'] ?? '') {
        'remover' => $guias->remover($id, Api::ip()),
        'pagar' => $guias->definirPagamento($id, (string)($corpo['data'] ?? ''), (string)ClienteRepositorio::$autor, Api::ip()),
        'desfazer_pagamento' => $guias->definirPagamento($id, null, (string)ClienteRepositorio::$autor, Api::ip()),
        default => $api->erro(422, 'ACAO_INVALIDA', 'Ação desconhecida.'),
    };
    if (!$r['ok']) {
        $api->erro($r['http'], $r['codigo'], $r['mensagem']);
    }
    $api->responder(200, ['mensagem' => 'Feito.', 'guia' => $r['guia'] ?? null]);
}

// Anexar (multipart)
$arquivo = $_FILES['arquivo'] ?? null;
if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$arquivo['tmp_name'])) {
    $api->erro(422, 'SEM_ARQUIVO', 'Escolha o PDF da guia.');
}
if ((int)$arquivo['size'] > GuiasDas::TAMANHO_MAXIMO) {
    $api->erro(422, 'ARQUIVO_GRANDE', 'PDF grande demais (máximo 2 MB).');
}
$conteudo = (string)file_get_contents((string)$arquivo['tmp_name']);
@unlink((string)$arquivo['tmp_name']);

$r = $guias->anexar($_POST + ['avisar' => ($_POST['avisar'] ?? '') === '1'], $conteudo, basename((string)$arquivo['name']), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], array_intersect_key($r, ['erros' => 1]));
}
$api->responder(200, ['guia' => $r['guia'], 'trocou' => $r['trocou'], 'avisados' => $r['avisados']]);
