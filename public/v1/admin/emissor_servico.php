<?php
declare(strict_types=1);

/**
 * Painel admin - servicos da empresa (o que deixa a emissao em 3 campos).
 *
 * POST /v1/admin/emissor_servico.php
 *   { "acao": "SALVAR", "cnpj": "...", "id": null (novo) | 3,
 *     "nome": "Consultoria", "codigo_tributacao_nacional": "170101", "codigo_nbs": "",
 *     "codigo_tributacao_municipal": "", "aliquota_iss": "5", "iss_retido": false,
 *     "descricao_padrao": "...", "padrao": true }
 *   { "acao": "REMOVER", "cnpj": "...", "id": 3 }
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoEmissor;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

$r = (new AdministracaoEmissor($api->pdo()))->servico($api->corpoJson(), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}
$http = $r['http'];
unset($r['ok'], $r['http']);
$api->responder($http, $r);
