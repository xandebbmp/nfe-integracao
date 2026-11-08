<?php
declare(strict_types=1);

/**
 * Painel admin - declaracao do Simples e DAS pela Receita (API Integra
 * Contador do SERPRO). Regras em src/Nfse/Servico/DeclaracaoSimples.php.
 *
 * GET  /v1/admin/serpro_das.php?cnpj=...&competencia=AAAA-MM
 *      tela de conferencia: receita do mes (das notas), atividade e folha sugeridas
 * POST {"acao": "calcular",   ...entrada}   so calcula (nada vai para a Receita)
 * POST {"acao": "transmitir", ...entrada, "conferidos": [{codigo, valor}], "avisar": true}
 *      TRANSMITE a declaracao (oficial), gera o DAS e anexa a guia para o cliente
 * POST {"acao": "testar", "cnpj": "...", "competencia": "AAAA-MM"}
 *      so no modo TESTE: pede uma guia de exemplo (nao e salva)
 * GET  /v1/admin/serpro_das.php?id=12&pdf=recibo|declaracao   abre o PDF guardado
 *
 * Ligar: Sistema > Configuracoes > Integração com a Receita (Teste ou Produção).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\DeclaracaoSimplesRepositorio;
use Xande\NfeIntegracao\Nfse\Serpro\IntegraContador;
use Xande\NfeIntegracao\Nfse\Servico\DeclaracaoSimples;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$servico = new DeclaracaoSimples($api->pdo(), $api->cfg);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['pdf'])) {
        $arquivo = (new DeclaracaoSimplesRepositorio($api->pdo()))->pdf((int)($_GET['id'] ?? 0), (string)$_GET['pdf']);
        if ($arquivo === null) {
            $api->erro(404, 'NAO_ENCONTRADO', 'PDF não encontrado.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . ($_GET['pdf'] === 'recibo' ? 'recibo' : 'declaracao')
            . "-{$arquivo['cnpj']}-{$arquivo['competencia']}.pdf\"");
        echo $arquivo['pdf'];
        exit;
    }
    $r = $servico->preparar(NfseConfig::normalizarCnpj((string)($_GET['cnpj'] ?? '')), (string)($_GET['competencia'] ?? ''));
    if (!$r['ok']) {
        $api->erro($r['http'], $r['codigo'], $r['mensagem']);
    }
    $api->responder(200, $r);
}

$corpo = $api->corpoJson();
$acao = (string)($corpo['acao'] ?? '');

if ($acao === 'testar') {
    // Botao antigo "Testar Receita": guia de EXEMPLO do ambiente de teste (nao e salva).
    if ($servico->modo() !== 'TESTE') {
        $api->erro(409, 'SO_NO_TESTE', 'O "Testar Receita" só funciona no modo Teste.');
    }
    $cnpj = NfseConfig::normalizarCnpj((string)($corpo['cnpj'] ?? ''));
    $competencia = (string)($corpo['competencia'] ?? '');
    $r = (new IntegraContador('TESTE'))->gerarDas($cnpj, $competencia);
    (new ClienteRepositorio($api->pdo()))->registrarLog($cnpj, 'SERPRO_DAS_TESTE',
        ['competencia' => $competencia, 'ok' => $r['ok'], 'mensagem' => $r['mensagem'] ?? null], Api::ip());
    if (!$r['ok']) {
        $api->erro(502, 'SERPRO_ERRO', $r['mensagem']);
    }
    $api->responder(200, ['teste' => true, 'numero_documento' => $r['numero_documento'], 'valor' => $r['valor'],
        'vencimento' => $r['vencimento'], 'pdf_base64' => base64_encode($r['pdf']), 'mensagens' => $r['mensagens']]);
}

$r = match ($acao) {
    'calcular' => $servico->calcular($corpo),
    'transmitir' => $servico->transmitir($corpo, is_array($corpo['conferidos'] ?? null) ? $corpo['conferidos'] : [],
        !empty($corpo['avisar']), Api::ip()),
    default => $api->erro(422, 'ACAO_INVALIDA', 'Ação desconhecida.'),
};
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], array_intersect_key($r, ['erros' => 1]));
}
unset($r['ok']);
$api->responder(200, $r);
