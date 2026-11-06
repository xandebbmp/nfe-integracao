<?php
declare(strict_types=1);

/**
 * Portal do cliente - tela "Minha empresa" (so leitura).
 *
 * GET /v1/portal/empresa.php
 *   { cadastro, fiscal, certificado, plano }
 *
 * Para mudar algum dado, o suporte altera no painel admin.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Servico\PortalEmpresa;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$usuario = PortalSessao::exigir($api);

$ficha = (new PortalEmpresa($api->pdo(), $api->cfg))->ficha($usuario['cnpj']);
if ($ficha === null) {
    $api->erro(404, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não encontrada.');
}
$api->responder(200, $ficha);
