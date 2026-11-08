<?php
declare(strict_types=1);

/**
 * Painel admin - gerar o DAS pela Receita (API Integra Contador do SERPRO).
 *
 * POST /v1/admin/serpro_das.php   {"cnpj": "...", "competencia": "AAAA-MM"}
 *
 * Por enquanto so no modo TESTE (Sistema > Configuracoes > Integração com a
 * Receita): o SERPRO responde com uma guia DE EXEMPLO, que so e mostrada na
 * tela e NAO vira guia da empresa. Detalhes em src/Nfse/Serpro/IntegraContador.php.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Serpro\IntegraContador;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

if (($api->cfg['serpro']['modo'] ?? '') !== 'TESTE') {
    $api->erro(409, 'SERPRO_DESLIGADO', 'A integração com a Receita está desligada. Ligue em Sistema > Configurações > Integração com a Receita.');
}

$corpo = $api->corpoJson();
$cnpj = preg_replace('/\D/', '', (string)($corpo['cnpj'] ?? ''));
$competencia = (string)($corpo['competencia'] ?? '');
if (strlen($cnpj) !== 14 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia)) {
    $api->erro(422, 'DADOS_INVALIDOS', 'Informe o CNPJ e o mês (AAAA-MM).');
}

$r = (new IntegraContador())->gerarDasTeste($cnpj, $competencia);
(new ClienteRepositorio($api->pdo()))->registrarLog($cnpj, 'SERPRO_DAS_TESTE',
    ['competencia' => $competencia, 'ok' => $r['ok'], 'mensagem' => $r['mensagem'] ?? null], Api::ip());
if (!$r['ok']) {
    $api->erro(502, 'SERPRO_ERRO', $r['mensagem']);
}

$api->responder(200, [
    'teste' => true,
    'numero_documento' => $r['numero_documento'],
    'valor' => $r['valor'],
    'vencimento' => $r['vencimento'],
    'pdf_base64' => base64_encode($r['pdf']),
    'mensagens' => $r['mensagens'],
]);
