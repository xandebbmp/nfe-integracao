<?php
declare(strict_types=1);

/**
 * Painel admin - FECHAMENTO DO MES: receita de cada empresa com as notas
 * emitidas pelo sistema, para o contador fazer a declaracao do Simples
 * (PGDAS-D) e gerar a guia DAS. Regras em Servico/FechamentoMes.php.
 *
 * GET /v1/admin/fechamento.php?mes=2026-11              JSON
 * GET /v1/admin/fechamento.php?mes=2026-11&formato=csv  planilha (abre no Excel)
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;
use Xande\NfeIntegracao\Nfse\Servico\FechamentoMes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$api->exigirAdmin();

$mes = (string)($_GET['mes'] ?? AdministracaoClientes::mesAtual());
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $api->erro(422, 'MES_INVALIDO', 'Informe o mes como AAAA-MM.');
}

$fechamento = (new FechamentoMes($api->pdo(), (int)$api->cfg['tpAmb']))->montar($mes);
$ambiente = (int)$api->cfg['tpAmb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO';

if (($_GET['formato'] ?? '') === 'csv') {
    // Numero com virgula decimal: o Excel em portugues entende como numero.
    $n = static fn($v): string => $v === null ? '' : number_format((float)$v, 2, ',', '');
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"fechamento-{$mes}-{$ambiente}.csv\"");
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF"); // BOM: Excel abre com acentos certos
    fputcsv($saida, ['CNPJ', 'Razao social', 'Simples', 'NFS-e (qtd)', 'NFS-e (valor)', 'NF-e (qtd)', 'NF-e (valor)',
        'Canceladas', 'Receita do mes', 'Receita 12 meses anteriores', '% Simples', 'DAS estimado', 'Guia DAS (valor)', 'Guia DAS (vencimento)'], ';');
    foreach ($fechamento['empresas'] as $l) {
        fputcsv($saida, [$l['cnpj'], $l['razao_social'], $l['simples'] ?? '', $l['nfse']['quantidade'], $n($l['nfse']['valor']),
            $l['nfe']['quantidade'], $n($l['nfe']['valor']), $l['canceladas'], $n($l['receita_mes']),
            $n($l['receita_12_meses']), $n($l['percentual_simples']), $n($l['das_estimado']),
            $l['guia'] !== null ? $n($l['guia']['valor']) : '', $l['guia']['vencimento'] ?? ''], ';');
    }
    exit;
}

$api->responder(200, $fechamento + ['ambiente' => $ambiente, 'serpro_modo' => (string)($api->cfg['serpro']['modo'] ?? '')]);
