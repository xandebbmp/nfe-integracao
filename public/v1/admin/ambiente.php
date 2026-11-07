<?php
declare(strict_types=1);

/**
 * Painel admin - "Ambiente de emissao" (tela Sistema).
 *
 * GET  /v1/admin/ambiente.php
 *   { atual: 1|2, arquivo: 1|2, no_painel: bool, producao_modelo_novo: bool }   1 = producao, 2 = homologacao
 * POST /v1/admin/ambiente.php  { "tpAmb": 1, "confirmacao": "PRODUÇÃO" }
 *   troca para o sistema todo (NF-e e NFS-e; API, portal e worker).
 *   Producao exige a palavra PRODUCAO. Fica no historico com quem trocou.
 * POST /v1/admin/ambiente.php  { "producao_modelo_novo": true|false }
 *   a Receita passou a producao da NFS-e Nacional para o modelo novo (finNFSe)?
 *
 * So o PAINEL ADMIN (pessoas do painel ou chave de emergencia) chega aqui:
 * usuarios do portal das empresas nunca passam pelo exigirAdmin().
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Servico\ConfiguracoesPainel;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$config = new ConfiguracoesPainel($api->pdo(), NfseConfig::carregar());
if (!$config->instalado()) {
    $api->erro(503, 'CONFIGURACOES_NAO_INSTALADAS', 'Falta atualizar o banco: clique em "Atualizar banco".');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, $config->ambiente());
}

$corpo = $api->corpoJson();
$r = array_key_exists('producao_modelo_novo', $corpo)
    ? $config->trocarModeloProducao((bool)$corpo['producao_modelo_novo'], Api::ip())
    : $config->trocarAmbiente((int)($corpo['tpAmb'] ?? 0), (string)($corpo['confirmacao'] ?? ''), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
unset($r['ok']);
$api->responder(200, $r);
