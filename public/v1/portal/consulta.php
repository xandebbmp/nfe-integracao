<?php
declare(strict_types=1);

/**
 * Portal do cliente - preencher o tomador sozinho.
 *
 * GET /v1/portal/consulta.php?cnpj=00000000000000   dados da Receita (BrasilAPI)
 * GET /v1/portal/consulta.php?cep=00000000          endereco + codigo IBGE (ViaCEP)
 *
 * Antes, procura nos tomadores ja salvos da empresa (mais rapido e com o
 * e-mail que a empresa ja usou).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\ConsultaCadastro;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);
$consulta = new ConsultaCadastro($api->pdo(), $api->cfg);

if (isset($_GET['cep'])) {
    $r = $consulta->cep((string)$_GET['cep']);
} else {
    $documento = preg_replace('/\D/', '', (string)($_GET['cnpj'] ?? $_GET['cpf'] ?? '')) ?? '';
    $salvo = (new PortalRepositorio($api->pdo()))->tomador($usuario['cnpj'], $documento);
    if ($salvo !== null) {
        $api->responder(200, ['ok' => true, 'tipo' => 'SALVO', 'origem' => 'Seus tomadores'] + $salvo);
    }
    if (strlen($documento) !== 14) {
        $api->erro(404, 'NAO_ENCONTRADO', 'Tomador ainda não cadastrado. Preencha o nome.');
    }
    $r = $consulta->cnpj($documento) + ['origem' => 'Receita Federal'];
}

if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, $r);
