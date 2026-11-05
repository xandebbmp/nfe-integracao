<?php
declare(strict_types=1);

/**
 * Inicio comum das rotas do emissor simples (/v1/emissor/*.php).
 * Nao e uma rota: as outras fazem require deste arquivo.
 *
 * Cria $api (Api) e $emissor (EmissorSimples). Use $exigirUsuario() para
 * pegar o usuario logado (cabecalho X-EMISSOR-SESSAO).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Infra\ConsultaCnpj;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Servico\EmissorSimples;

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_inicio.php') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var list<string> $metodos definido pela rota antes do require */
$api = Api::iniciar($metodos);
$emissor = new EmissorSimples($api->pdo(), $api->cfg, new ConsultaCnpj(new CurlTransporte(null, 8)));
$exigirUsuario = static fn(): array => $api->exigirSessaoEmissor($emissor);
$responderResultado = static function (array $r) use ($api): never {
    if (!$r['ok']) {
        $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
    }
    unset($r['ok'], $r['http']);
    $api->responder(200, $r);
};
