<?php
declare(strict_types=1);

/**
 * Portal do cliente - emitir a NFS-e do formulario.
 *
 * POST /v1/portal/emitir.php   (cabecalho X-Portal: 1)
 *   {
 *     "chave_envio": "id gerado pelo navegador ao abrir o formulario",
 *     "tomador": { "cpf_cnpj", "nome", "email", "cep", "logradouro", "numero", "bairro", "codigo_municipio", "uf" },
 *     "servico": { "id": 3 }  ou  { "codigo_tributacao_nacional", "item_lista_servico", "aliquota_iss", "salvar": true, "nome" },
 *     "descricao": "Consultoria referente a {mes}",
 *     "competencia": "2026-11",
 *     "valores": { "valor_servicos": 1500, "iss_retido": false, "desconto_incondicionado": 0, "valor_ir": 0, ... }
 *   }
 *
 * Espera ate 15 s pelo resultado da prefeitura. Depois disso a nota segue
 * na fila e o status aparece na tela inicial.
 *
 * 200/202 { nota: {...} }
 * 422 DADOS_INVALIDOS   { erros: [ {campo, mensagem, correcao} ] } nada foi gravado
 * 422 EMISSAO_BLOQUEADA / LIMITE_MENSAL / ...  empresa nao pode emitir agora
 */

use Xande\NfeIntegracao\Nfse\Api\Api;
use Xande\NfeIntegracao\Nfse\Api\PortalSessao;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\PortalEmissao;
use Xande\NfeIntegracao\Nfse\Servico\PortalInicio;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$api = Api::iniciar(['POST']);
$usuario = PortalSessao::exigir($api);

$r = (new PortalEmissao($api->pdo(), $api->cfg))->emitir($usuario, $api->corpoJson());
if (!$r['ok']) {
    $api->erro($r['http'], $r['codigo'], $r['mensagem'], isset($r['erros']) ? ['erros' => $r['erros']] : []);
}

$documentos = new DocumentoRepositorio($api->pdo());
$api->aguardar(15, static fn(): bool => DocumentoApresentador::documento($documentos->buscar($r['id']))['finalizado']);
$nota = DocumentoApresentador::documento($documentos->buscar($r['id']));
$nota['links'] = PortalInicio::links($nota['id'], $nota['links']['pdf'] !== null);

$api->responder($nota['finalizado'] ? 200 : 202, [
    'situacao_recebimento' => $r['situacao_recebimento'],
    'nota' => $nota,
]);
