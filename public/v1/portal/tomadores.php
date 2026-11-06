<?php
declare(strict_types=1);

/**
 * Portal do cliente - tomadores salvos (preenchidos sozinhos na emissao).
 *
 * GET    /v1/portal/tomadores.php?busca=texto         lista (mais usados primeiro)
 * POST   /v1/portal/tomadores.php                     cria ou corrige (chave: cpf_cnpj)
 *          { "cpf_cnpj", "nome", "email"?, "telefone"?, "cep"?, "logradouro"?, "numero"?,
 *            "complemento"?, "bairro"?, "codigo_municipio"?, "cidade"?, "uf"? }
 * DELETE /v1/portal/tomadores.php?cpf_cnpj=N          tira da lista (as notas ficam)
 *
 * POST e DELETE exigem o cabecalho X-Portal: 1.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalTomadores;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST', 'DELETE']);
$usuario = PortalSessao::exigir($api);
$tomadores = new PortalTomadores($api->pdo());

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'GET') {
    $api->responder(200, ['tomadores' => $tomadores->listar($usuario['cnpj'], (string)($_GET['busca'] ?? ''))]);
}

$r = $metodo === 'DELETE'
    ? $tomadores->remover($usuario['cnpj'], (string)($_GET['cpf_cnpj'] ?? ''))
    : $tomadores->salvar($usuario['cnpj'], $api->corpoJson());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}
$api->responder(200, $r);
