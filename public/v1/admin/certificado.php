<?php
declare(strict_types=1);

/**
 * Painel admin - envia o certificado A1 de uma empresa.
 *
 * POST /v1/admin/certificado.php   (multipart/form-data)
 *      cnpj=...  senha=...  arquivo=@empresa.pfx
 *
 * A API confere a senha, se o certificado e da empresa (mesma raiz de CNPJ)
 * e a validade; depois guarda CRIPTOGRAFADO. O arquivo nao fica em disco.
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$api->exigirAdmin();

$arquivo = $_FILES['arquivo'] ?? null;
if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$arquivo['tmp_name'])) {
    $api->erro(422, 'SEM_ARQUIVO', 'Envie o arquivo .pfx no campo "arquivo".');
}
if ((int)$arquivo['size'] > 100_000) {
    $api->erro(422, 'ARQUIVO_GRANDE', 'Arquivo grande demais para um certificado A1.');
}

$conteudo = (string)file_get_contents((string)$arquivo['tmp_name']);
@unlink((string)$arquivo['tmp_name']);

$r = (new AdministracaoClientes($api->pdo(), $api->cfg))
    ->enviarCertificado((string)($_POST['cnpj'] ?? ''), $conteudo, (string)($_POST['senha'] ?? ''), Api::ip());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem']);
}
$api->responder(200, ['mensagem' => $r['mensagem'], 'cliente' => $r['cliente']]);
