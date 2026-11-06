<?php
declare(strict_types=1);

/**
 * Painel admin - botao "Diagnostico" (tela Sistema).
 *
 * GET /v1/admin/diagnostico.php            confere tudo (inclui internet, ate ~10 s)
 * GET /v1/admin/diagnostico.php?rede=0     sem testar a internet
 *
 * Nao altera nada e nao envia nota. Mesmas regras do bin/nfse_verificar.php
 * (ver src/Nfse/Servico/Diagnostico.php).
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\Diagnostico;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET']);
$api->exigirAdmin();

$diagnostico = new Diagnostico($api->cfg, $api->pdo(), dirname(__DIR__, 3));
$api->responder(200, $diagnostico->executar(($_GET['rede'] ?? '1') !== '0'));
