<?php
declare(strict_types=1);

/**
 * Painel admin - relatorio de COBRANCA: notas autorizadas no mes, por
 * empresa e por tipo (NF-e, NFS-e Nacional, NFS-e Salvador).
 *
 * GET /v1/admin/uso.php?mes=2026-11              JSON
 * GET /v1/admin/uso.php?mes=2026-11&formato=csv  planilha (abre no Excel)
 *
 * Conta cada nota autorizada UMA vez, mesmo que cancelada depois.
 * Rejeitadas, denegadas e erros nao contam.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$api->exigirAdmin();

$mes = (string)($_GET['mes'] ?? AdministracaoClientes::mesAtual());
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $api->erro(422, 'MES_INVALIDO', 'Informe o mes como AAAA-MM.');
}

$linhas = (new AdministracaoClientes($api->pdo(), $api->cfg))->relatorioUso($mes);
$ambiente = (int)$api->cfg['tpAmb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO';

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"uso-{$mes}-{$ambiente}.csv\"");
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF"); // BOM: Excel abre com acentos certos
    fputcsv($saida, ['CNPJ', 'Razao social', 'Plano', 'Limite mensal', 'NF-e', 'NFS-e Nacional', 'NFS-e Salvador', 'Total'], ';');
    foreach ($linhas as $l) {
        fputcsv($saida, [$l['cnpj'], $l['razao_social'], $l['plano'], $l['limite_mensal'], $l['nfe'], $l['nfse_nacional'], $l['nfse_salvador'], $l['total']], ';');
    }
    exit;
}

$api->responder(200, ['mes' => $mes, 'ambiente' => $ambiente, 'total' => array_sum(array_column($linhas, 'total')), 'empresas' => $linhas]);
