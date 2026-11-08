<?php
declare(strict_types=1);

/**
 * Painel admin - acesso de PRODUCAO ao SERPRO (Integra Contador).
 * Regras em src/Nfse/Serpro/AcessoSerpro.php. Nada volta para a tela.
 *
 * GET  /v1/admin/serpro_acesso.php               situacao (chaves e certificado cadastrados?)
 * POST (JSON) {"acao": "chaves", "key": "...", "secret": "..."}
 * POST (JSON) {"acao": "testar_login"}            confere chaves + certificado no SERPRO
 * POST (JSON) {"acao": "apagar"}
 * POST (multipart) senha=..., arquivo=@escritorio.pfx   certificado e-CNPJ de quem contratou
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Serpro\AcessoSerpro;
use Xande\NfeIntegracao\Nfse\Serpro\IntegraContador;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['GET', 'POST']);
$api->exigirAdmin();
$acesso = new AcessoSerpro($api->pdo(), (string)($api->cfg['chave_certificados'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $api->responder(200, $acesso->situacao() + ['modo' => (string)($api->cfg['serpro']['modo'] ?? '')]);
}

if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $corpo = $api->corpoJson();
    $r = match ($corpo['acao'] ?? '') {
        'chaves' => $acesso->salvarChaves((string)($corpo['key'] ?? ''), (string)($corpo['secret'] ?? ''), Api::ip()),
        'apagar' => $acesso->apagar(Api::ip()),
        'testar_login' => (static function () use ($acesso): array {
            try {
                return (new IntegraContador('PRODUCAO', $acesso->credenciais()))->testarLogin();
            } catch (Throwable $e) {
                return ['ok' => false, 'http' => 409, 'codigo' => 'SERPRO_SEM_ACESSO', 'mensagem' => $e->getMessage()];
            }
        })(),
        default => $api->erro(422, 'ACAO_INVALIDA', 'Ação desconhecida.'),
    };
    if (!$r['ok']) {
        $api->erro($r['http'] ?? 502, $r['codigo'] ?? 'SERPRO_ERRO', $r['mensagem']);
    }
    $api->responder(200, ['mensagem' => $r['mensagem']] + $acesso->situacao());
}

$arquivo = $_FILES['arquivo'] ?? null;
if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$arquivo['tmp_name'])) {
    $api->erro(422, 'SEM_ARQUIVO', 'Escolha o arquivo .pfx do certificado do escritório.');
}
$conteudo = (string)file_get_contents((string)$arquivo['tmp_name']);
@unlink((string)$arquivo['tmp_name']);
$r = $acesso->salvarCertificado($conteudo, (string)($_POST['senha'] ?? ''), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, ['mensagem' => $r['mensagem']] + $acesso->situacao());
