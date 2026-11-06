<?php
declare(strict_types=1);

/**
 * Portal do cliente - servicos salvos da empresa.
 *
 * GET    /v1/portal/servicos.php            lista
 * POST   /v1/portal/servicos.php            cria (sem id) ou altera (com id)
 *          { "id"?, "nome", "descricao", "codigo_tributacao_nacional", "item_lista_servico",
 *            "codigo_tributacao_municipal"?, "codigo_cnae"?, "aliquota_iss", "valor_padrao"? }
 * DELETE /v1/portal/servicos.php?id=N       esconde o servico
 *
 * POST e DELETE exigem o cabecalho X-Portal: 1.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalServicos;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST', 'DELETE']);
$usuario = PortalSessao::exigir($api);
$servicos = new PortalServicos($api->pdo());

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') {
    $api->responder(200, ['servicos' => $servicos->listar($usuario['cnpj'])]);
}

if ($metodo === 'DELETE') {
    $r = $servicos->remover($usuario['cnpj'], (int)($_GET['id'] ?? 0));
} else {
    $r = $servicos->salvar($usuario['cnpj'], $api->corpoJson());
}
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}
$api->responder(200, $r);
