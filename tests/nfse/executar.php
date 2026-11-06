<?php
declare(strict_types=1);

/**
 * Testes da NFS-e.
 *
 *   php tests/nfse/executar.php
 *
 * Testes de integracao com PostgreSQL (fila + worker + monitor) rodam apenas
 * se a variavel NFSE_TESTE_PG estiver definida, apontando para um banco
 * DESCARTAVEL (as tabelas nfse_* sao apagadas e recriadas):
 *
 *   NFSE_TESTE_PG="pgsql:host=127.0.0.1;dbname=nfephp_teste;user=nfephp_app;password=..." \
 *     php tests/nfse/executar.php
 *
 * NFSE_TESTE_EXIGIR_PG=1 faz o teste FALHAR se a integracao for pulada
 * (usado no CI, .github/workflows/testes.yml).
 *
 * Nenhum teste fala com o Sefin ou com Salvador: as respostas sao simuladas
 * pelo TransporteFalso (tests/nfse/Apoio.php).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Apoio.php';
require __DIR__ . '/casos_nfe.php';
require __DIR__ . '/casos_clientes.php';
require __DIR__ . '/casos_portal.php';
require __DIR__ . '/casos_sistema.php';

use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoLocal;
use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoS3;
use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Infra\AssinadorXml;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\TransporteGravador;
use Xande\NfeIntegracao\Nfse\Infra\ValidadorXsd;
use Xande\NfeIntegracao\Nfse\Infra\Xml;
use Xande\NfeIntegracao\Nfse\Nacional\EventoXmlBuilder;
use Xande\NfeIntegracao\Nfse\Nacional\ProvedorNacional;
use Xande\NfeIntegracao\Nfse\Nacional\SefinNacionalCliente;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;
use Xande\NfeIntegracao\Nfse\Salvador\ProvedorSalvador;
use Xande\NfeIntegracao\Nfse\Salvador\SalvadorSoapCliente;
use Xande\NfeIntegracao\Nfse\Servico\ProcessadorJobs;
use Xande\NfeIntegracao\Nfse\Servico\RecebimentoLote;
use Xande\NfeIntegracao\Nfse\Servico\Solicitacoes;
use Xande\NfeIntegracao\Nfse\Servico\ValidadorPayload;

$raiz = dirname(__DIR__, 2);
$cfgGeral = Apoio::config();
// Provedores e montadores recebem a config de UM emitente.
$cfg = NfseConfig::paraEmitente($cfgGeral, '41986662000160');
$chave = str_repeat('1', 50);

// ---------------------------------------------------------------------------
// Respostas simuladas
// ---------------------------------------------------------------------------

function nfseNacionalXml(string $chave, string $numero = '123'): string
{
    return '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $chave . '">'
        . "<nNFSe>{$numero}</nNFSe><dhProc>2026-11-04T10:00:00-03:00</dhProc></infNFSe></NFSe>";
}

function respostaSefinAutorizada(string $chave): string
{
    return json_encode([
        'tipoAmbiente' => 2,
        'chaveAcesso' => $chave,
        'nfseXmlGZipB64' => SefinNacionalCliente::compactar(nfseNacionalXml($chave)),
        'alertas' => [],
    ]);
}

function soapSalvador(string $operacao, string $xmlAbrasf): string
{
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
        . "<{$operacao}Response xmlns=\"http://tempuri.org/\"><{$operacao}Result>"
        . htmlspecialchars($xmlAbrasf, ENT_XML1)
        . "</{$operacao}Result></{$operacao}Response></s:Body></s:Envelope>";
}

const NS_ABRASF = 'http://www.abrasf.org.br/ABRASF/arquivos/nfse.xsd';

function compNfseSalvador(): string
{
    return '<ConsultarLoteRpsResposta xmlns="' . NS_ABRASF . '"><ListaNfse><CompNfse><Nfse><InfNfse>'
        . '<Numero>987</Numero><CodigoVerificacao>ABCD-1234</CodigoVerificacao>'
        . '<DataEmissao>2026-11-04T10:05:00</DataEmissao></InfNfse></Nfse></CompNfse></ListaNfse></ConsultarLoteRpsResposta>';
}

function provedorNacional(array $cfg, TransporteFalso $http): ProvedorNacional
{
    return new ProvedorNacional(
        $cfg,
        new SefinNacionalCliente($http, 'https://sefin.teste', 'https://adn.teste'),
        new AssinadorXml(Apoio::certificado(), 'sha256')
    );
}

function provedorSalvador(array $cfg, TransporteFalso $http): ProvedorSalvador
{
    return new ProvedorSalvador($cfg, new SalvadorSoapCliente($http, 'https://salvador.teste'), new AssinadorXml(Apoio::certificado(), 'sha1'));
}

/** Confere a assinatura: digest do no + SignatureValue com a chave publica. */
function assinaturaValida(string $xml, string $noAssinado, string $atributo): bool
{
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $no = $dom->getElementsByTagName($noAssinado)->item(0);

    // Pega a <Signature> cuja Reference aponta para este no (o lote de
    // Salvador tem duas assinaturas: a do RPS e a do lote).
    $sig = null;
    foreach ($dom->getElementsByTagName('Signature') as $candidata) {
        if ($candidata->getElementsByTagName('Reference')->item(0)->getAttribute('URI') === '#' . $no->getAttribute($atributo)) {
            $sig = $candidata;
        }
    }
    if ($sig === null) {
        return false;
    }
    $signedInfo = $sig->getElementsByTagName('SignedInfo')->item(0);
    $digestEsperado = $sig->getElementsByTagName('DigestValue')->item(0)->textContent;
    $algoritmo = str_contains($sig->getElementsByTagName('SignatureMethod')->item(0)->getAttribute('Algorithm'), 'sha256') ? 'sha256' : 'sha1';

    $digest = base64_encode(hash($algoritmo, $no->C14N(false, false), true));
    $chavePublica = openssl_pkey_get_public((string)Apoio::certificado()->publicKey);
    $ok = openssl_verify(
        $signedInfo->C14N(false, false),
        base64_decode($sig->getElementsByTagName('SignatureValue')->item(0)->textContent),
        $chavePublica,
        $algoritmo === 'sha256' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1
    );
    return $digest === $digestEsperado && $ok === 1 && $no->getAttribute($atributo) !== '';
}

// ===========================================================================
echo "\n[1] Validacao de entrada (ValidadorPayload)\n";
// ===========================================================================

$doc = Apoio::documentoNacional()['payload'];
Apoio::igual([], ValidadorPayload::validar($doc, 'NACIONAL'), 'documento nacional completo e valido');
Apoio::igual([], ValidadorPayload::validar($doc, 'SALVADOR'), 'mesmo documento e valido para Salvador');

$semCodigo = $doc;
unset($semCodigo['servico']['codigo_tributacao_nacional']);
Apoio::verificar(count(ValidadorPayload::validar($semCodigo, 'NACIONAL')) === 1, 'Nacional exige codigo_tributacao_nacional');

$semTomador = $doc;
unset($semTomador['tomador']);
Apoio::igual([], ValidadorPayload::validar($semTomador, 'NACIONAL'), 'tomador e opcional no Nacional');
Apoio::verificar(ValidadorPayload::validar($semTomador, 'SALVADOR') !== [], 'tomador e obrigatorio em Salvador');

$alfanumerico = $doc;
$alfanumerico['tomador']['cpf_cnpj'] = '12ABC34501DE35';
Apoio::igual([], ValidadorPayload::validar($alfanumerico, 'NACIONAL'), 'CNPJ alfanumerico aceito na validacao do Nacional');
Apoio::verificar(ValidadorPayload::validar($alfanumerico, 'SALVADOR') !== [], 'CNPJ alfanumerico recusado em Salvador (XSD so numerico)');


// ===========================================================================
echo "\n[2] Padrao Nacional - XML e assinatura\n";
// ===========================================================================

$http = new TransporteFalso();
$http->responder(201, respostaSefinAutorizada($chave));
$retorno = provedorNacional($cfg, $http)->emitir(Apoio::documentoNacional());

Apoio::igual(StatusNfse::AUTORIZADO, $retorno->status, 'emissao com HTTP 201 vira AUTORIZADO');
Apoio::igual($chave, $retorno->dados['chave_acesso'] ?? null, 'chave de acesso gravada');
Apoio::igual('123', $retorno->dados['numero_nfse'] ?? null, 'numero da NFS-e extraido do XML');
Apoio::verificar(str_contains((string)$retorno->dados['xml_autorizado'], '<nNFSe>123</nNFSe>'), 'XML autorizado descompactado (gzip+base64)');

$enviado = json_decode((string)$http->requisicoes[0]['corpo'], true);
$dps = SefinNacionalCliente::descompactar($enviado['dpsXmlGZipB64'] ?? null);
Apoio::igual('https://sefin.teste/nfse', $http->requisicoes[0]['url'], 'POST em {sefin}/nfse');
Apoio::igual([], ValidadorXsd::validar((string)$dps, "{$raiz}/schemas/nfse/nacional/1.01/DPS_v1.01.xsd"), 'DPS enviada passa no XSD oficial 1.01');
Apoio::verificar(assinaturaValida((string)$dps, 'infDPS', 'Id'), 'assinatura da DPS confere (digest + RSA-SHA256)');
Apoio::igual('DPS292740824198666200016000001000000000000015', Xml::valor((string)$dps, 'DPS') !== null ? (new DOMXPath(Xml::carregar($dps)))->evaluate('string(//*[local-name()="infDPS"]/@Id)') : null, 'Id da DPS = DPS+municipio+tipo+CNPJ+serie+numero');

$eventoXml = (new EventoXmlBuilder($cfg))->montar($chave, ['tipo_evento' => '101101', 'motivo_codigo' => '1', 'motivo_texto' => 'Erro na emissao da nota fiscal']);
$domEvento = new DOMDocument();
$domEvento->loadXML($eventoXml);
(new AssinadorXml(Apoio::certificado(), 'sha256'))->assinarNo($domEvento, $domEvento->getElementsByTagName('infPedReg')->item(0), $domEvento->documentElement, 'Id');
Apoio::igual([], ValidadorXsd::validar($domEvento->saveXML(), "{$raiz}/schemas/nfse/nacional/1.01/pedRegEvento_v1.01.xsd"), 'pedido de cancelamento passa no XSD oficial');

// ===========================================================================
echo "\n[3] Padrao Nacional - rejeicao, falha e recuperacao\n";
// ===========================================================================

$http = new TransporteFalso();
$http->responder(400, json_encode(['erros' => [['Codigo' => 'E0312', 'Descricao' => 'Codigo de tributacao invalido', 'Complemento' => 'cTribNac']]]));
$retorno = provedorNacional($cfg, $http)->emitir(Apoio::documentoNacional());
Apoio::igual(StatusNfse::REJEITADO, $retorno->status, 'HTTP 400 com erros vira REJEITADO');
Apoio::igual('E0312', $retorno->mensagens[0]['codigo'] ?? null, 'codigo do erro do Sefin preservado (Codigo/Descricao maiusculos)');

$http = new TransporteFalso();
$http->responder(0, '');
$retorno = provedorNacional($cfg, $http)->emitir(Apoio::documentoNacional());
Apoio::verificar($retorno->falhaTemporaria, 'timeout vira falha temporaria (sera re-tentado)');
Apoio::verificar(!empty($retorno->dados['id_dps']) && !empty($retorno->dados['xml_enviado']), 'falha guarda id_dps/xml_enviado para a proxima tentativa');

// Segunda tentativa: a DPS ja tinha virado nota no Sefin -> nao reenvia.
$http = new TransporteFalso();
$http->responder(200, json_encode(['chaveAcesso' => $chave]));
$http->responder(200, json_encode(['chaveAcesso' => $chave, 'nfseXmlGZipB64' => SefinNacionalCliente::compactar(nfseNacionalXml($chave))]));
$retorno = provedorNacional($cfg, $http)->emitir(Apoio::documentoNacional($retorno->dados));
Apoio::igual(StatusNfse::AUTORIZADO, $retorno->status, 'retentativa encontra a DPS ja autorizada');
Apoio::igual(['GET', 'GET'], array_column($http->requisicoes, 'metodo'), 'retentativa NAO faz novo POST (sem duplicidade)');
Apoio::verificar(str_ends_with($http->requisicoes[0]['url'], '/dps/DPS292740824198666200016000001000000000000015'), 'consulta GET /dps/{id}');

// Consulta de NFS-e cancelada.
$http = new TransporteFalso();
$http->responder(200, json_encode(['chaveAcesso' => $chave, 'nfseXmlGZipB64' => SefinNacionalCliente::compactar(nfseNacionalXml($chave))]));
$http->responder(200, json_encode(['eventos' => [['chaveAcesso' => $chave, 'tipoEvento' => '101101']]]));
$retorno = provedorNacional($cfg, $http)->consultar(Apoio::documentoNacional(['chave_acesso' => $chave]));
Apoio::igual(StatusNfse::CANCELADO, $retorno->status, 'consulta com evento 101101 vira CANCELADO');

// ===========================================================================
echo "\n[4] Salvador - lote assincrono\n";
// ===========================================================================

$docSalvador = Apoio::documentoNacional(['padrao' => 'SALVADOR']);

$http = new TransporteFalso();
$http->responder(200, soapSalvador('EnviarLoteRPS', '<EnviarLoteRpsResposta xmlns="' . NS_ABRASF . '"><NumeroLote>15</NumeroLote><DataRecebimento>2026-11-04T10:00:00</DataRecebimento><Protocolo>PROT-777</Protocolo></EnviarLoteRpsResposta>'));
$retorno = provedorSalvador($cfg, $http)->emitir($docSalvador);
Apoio::igual(StatusNfse::PROCESSANDO, $retorno->status, 'envio do lote retorna PROCESSANDO');
Apoio::igual('PROT-777', $retorno->dados['protocolo_lote'] ?? null, 'protocolo do lote guardado');
Apoio::igual('"http://tempuri.org/IEnvioLoteRPS/EnviarLoteRPS"', $http->requisicoes[0]['cabecalhos']['SOAPAction'] ?? null, 'SOAPAction correto');
Apoio::verificar(str_contains((string)$http->requisicoes[0]['corpo'], '<![CDATA[<EnviarLoteRpsEnvio'), 'XML ABRASF enviado dentro de CDATA');
$loteEnviado = (string)$retorno->dados['xml_enviado'];
Apoio::igual([], ValidadorXsd::validar($loteEnviado, "{$raiz}/schemas/nfse/salvador/1.00/nfse.xsd"), 'lote passa no XSD de Salvador');
Apoio::verificar(assinaturaValida($loteEnviado, 'InfRps', 'id'), 'assinatura do RPS confere');
Apoio::verificar(assinaturaValida($loteEnviado, 'LoteRps', 'id'), 'assinatura do lote confere');
Apoio::igual('0.0500', Xml::valor($loteEnviado, 'Aliquota'), 'aliquota 5% enviada como fracao 0.0500');

$docProcessando = $docSalvador + [];
$docProcessando['protocolo_lote'] = 'PROT-777';

$http = new TransporteFalso();
$http->responder(200, soapSalvador('ConsultarSituacaoLoteRPS', '<ConsultarSituacaoLoteRpsResposta xmlns="' . NS_ABRASF . '"><NumeroLote>15</NumeroLote><Situacao>2</Situacao></ConsultarSituacaoLoteRpsResposta>'));
$retorno = provedorSalvador($cfg, $http)->consultarProcessamento($docProcessando);
Apoio::igual(StatusNfse::PROCESSANDO, $retorno->status, 'situacao 2 (nao processado) continua PROCESSANDO');
Apoio::igual(30, $retorno->consultarNovamenteEmSegundos, 'proxima consulta agendada pelo intervalo configurado');

$http = new TransporteFalso();
$http->responder(200, soapSalvador('ConsultarSituacaoLoteRPS', '<ConsultarSituacaoLoteRpsResposta xmlns="' . NS_ABRASF . '"><NumeroLote>15</NumeroLote><Situacao>4</Situacao></ConsultarSituacaoLoteRpsResposta>'));
$http->responder(200, soapSalvador('ConsultarLoteRPS', compNfseSalvador()));
$retorno = provedorSalvador($cfg, $http)->consultarProcessamento($docProcessando);
Apoio::igual(StatusNfse::AUTORIZADO, $retorno->status, 'situacao 4 + CompNfse vira AUTORIZADO');
Apoio::igual('987', $retorno->dados['numero_nfse'] ?? null, 'numero da NFS-e de Salvador');
Apoio::igual('ABCD-1234', $retorno->dados['codigo_verificacao'] ?? null, 'codigo de verificacao');

$http = new TransporteFalso();
$http->responder(200, soapSalvador('ConsultarSituacaoLoteRPS', '<ConsultarSituacaoLoteRpsResposta xmlns="' . NS_ABRASF . '"><NumeroLote>15</NumeroLote><Situacao>3</Situacao></ConsultarSituacaoLoteRpsResposta>'));
$http->responder(200, soapSalvador('ConsultarLoteRPS', '<ConsultarLoteRpsResposta xmlns="' . NS_ABRASF . '"><ListaMensagemRetorno><MensagemRetorno><Codigo>E45</Codigo><Mensagem>Item da lista invalido</Mensagem><Correcao>Verifique o item</Correcao></MensagemRetorno></ListaMensagemRetorno></ConsultarLoteRpsResposta>'));
$retorno = provedorSalvador($cfg, $http)->consultarProcessamento($docProcessando);
Apoio::igual(StatusNfse::REJEITADO, $retorno->status, 'situacao 3 vira REJEITADO');
Apoio::igual(['codigo' => 'E45', 'mensagem' => 'Item da lista invalido', 'correcao' => 'Verifique o item'], $retorno->mensagens[0] ?? null, 'mensagem de Salvador traduzida');

$retorno = provedorSalvador($cfg, new TransporteFalso())->registrarEvento($docSalvador, ['tipo_evento' => '101101']);
Apoio::igual(StatusEvento::REJEITADO, $retorno->status, 'cancelamento em Salvador e recusado sem chamar a prefeitura');

$pdf = provedorSalvador($cfg, new TransporteFalso())->gerarPdf($docSalvador + ['numero_nfse' => '987', 'codigo_verificacao' => 'ABCD-1234', 'data_autorizacao' => '2026-11-04']);
Apoio::verificar(str_starts_with($pdf, '%PDF'), 'espelho PDF de Salvador gerado');

// ===========================================================================
echo "\n[4b] Armazenamento externo (S3 / pasta)\n";
// ===========================================================================

// Exemplo oficial da documentacao AWS (Signature Version 4, GET /test.txt).
$autorizacao = ArmazenamentoS3::autorizacao('GET', '/test.txt', '', [
    'host' => 'examplebucket.s3.amazonaws.com',
    'range' => 'bytes=0-9',
    'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'x-amz-date' => '20130524T000000Z',
], 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', '20130524T000000Z');
Apoio::verificar(str_ends_with($autorizacao, 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41'), 'assinatura S3 (SigV4) igual ao exemplo oficial da AWS');

$http = new TransporteFalso();
$http->responder(200, '');
(new ArmazenamentoS3($http, 'https://s3.sa-east-1.amazonaws.com', 'sa-east-1', 'meu-bucket', 'AK', 'SK', 'nfse'))->salvar('homologacao/x/nfse.xml', '<a/>');
Apoio::igual('PUT https://meu-bucket.s3.sa-east-1.amazonaws.com/nfse/homologacao/x/nfse.xml', $http->requisicoes[0]['metodo'] . ' ' . $http->requisicoes[0]['url'], 'S3: PUT no bucket com prefixo');

$http = new TransporteFalso();
$http->responder(403, '<Error>AccessDenied</Error>');
try {
    (new ArmazenamentoS3($http, 'http://minio:9000', 'us-east-1', 'b', 'AK', 'SK', '', true))->salvar('a.xml', '<a/>');
    Apoio::verificar(false, 'S3: erro HTTP gera excecao');
} catch (RuntimeException) {
    Apoio::verificar(str_starts_with($http->requisicoes[0]['url'], 'http://minio:9000/b/a.xml'), 'S3 path-style (MinIO) e erro HTTP gera excecao');
}

$pastaArquivos = sys_get_temp_dir() . '/nfse_teste_arquivos_' . getmypid();

// Gravador de chamadas (usado pelo bin/nfse_homologar.php)
$pastaGravacao = sys_get_temp_dir() . '/nfse_teste_gravacao_' . getmypid();
$falso = new TransporteFalso();
$falso->responder(201, respostaSefinAutorizada($chave));
$falso->responder(200, '%PDF-1.4 teste', 'application/pdf');
$gravador = new TransporteGravador($falso, $pastaGravacao);
$gravador->enviar('POST', 'https://sefin.teste/SefinNacional/nfse', ['Content-Type' => 'application/json'],
    json_encode(['dpsXmlGZipB64' => SefinNacionalCliente::compactar('<DPS>teste</DPS>')]));
$gravador->enviar('GET', 'https://adn.teste/danfse/' . $chave);
Apoio::igual('<DPS>teste</DPS>', @file_get_contents("{$pastaGravacao}/01-POST-sefinnacional-nfse.envio.xml"), 'gravador salva o XML enviado ja descompactado');
Apoio::verificar(str_contains((string)@file_get_contents("{$pastaGravacao}/01-POST-sefinnacional-nfse.retorno.xml"), '<nNFSe>123</nNFSe>'), 'gravador salva o XML de retorno descompactado');
Apoio::verificar(is_file("{$pastaGravacao}/02-GET-danfse.pdf"), 'gravador salva o PDF (sem a chave no nome do arquivo)');
exec('rm -rf ' . escapeshellarg($pastaGravacao));

testesNfeUnidade($cfgGeral, $raiz);

// ===========================================================================
echo "\n[5] Integracao com PostgreSQL (fila, worker, monitor, idempotencia)\n";
// ===========================================================================

testesPortalUnidade();
testesSistemaUnidade($cfgGeral);

$dsn = getenv('NFSE_TESTE_PG');
if (!$dsn) {
    echo "  (pulado: defina NFSE_TESTE_PG para rodar)\n";
    // No CI a integracao e obrigatoria: pular seria um falso "verde".
    Apoio::verificar(!getenv('NFSE_TESTE_EXIGIR_PG'), 'NFSE_TESTE_EXIGIR_PG ativo, mas NFSE_TESTE_PG nao foi definido');
} else {
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('DROP TABLE IF EXISTS sistema_emails, portal_redefinicoes, portal_recorrencias, sistema_ajustes, sistema_scripts, sistema_workers, sistema_controle, portal_consultas, portal_tomadores, portal_servicos, portal_sessoes, portal_usuarios, fiscal_uso, fiscal_admin_log, fiscal_clientes, nfse_webhooks_saida, nfse_monitor, nfse_jobs, nfse_eventos, nfse_numeracao, nfse_documentos, nfse_lotes CASCADE');
    // Aplica TODOS os scripts do banco, na ordem (0001, 0002, 0003...),
    // igual ao deploy/aplicar_banco.sh.
    $scripts = glob("{$raiz}/database/scripts/*.sql");
    sort($scripts);
    foreach ($scripts as $script) {
        $pdo->exec((string)file_get_contents($script));
    }

    $cfgGeral['consumidores']['TESTE']['webhook_url'] = 'https://consumidor.teste/webhook';
    $http = new TransporteFalso();
    $soapNfe = new SoapNfeFalso();
    $fabrica = new ProvedorFactory($cfgGeral, new CertificadoCliente(Apoio::certificado()), $http, $soapNfe);
    $worker = new ProcessadorJobs($pdo, $cfgGeral, $fabrica, 'teste', new ArmazenamentoLocal($pastaArquivos));
    $recebimento = new RecebimentoLote($pdo, $cfgGeral);
    $documentos = new DocumentoRepositorio($pdo);

    $payload = Apoio::documentoNacional()['payload'];
    $lote = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [
        $payload,
        array_replace($payload, ['referencia' => 'PEDIDO-2']),
    ]]);
    Apoio::verificar($lote['ok'], 'lote com 2 documentos aceito');
    [$doc1, $doc2] = array_map(fn($d) => $documentos->buscar($d['id']), $lote['documentos']);
    Apoio::igual([1, 2], [$doc1['numero'], $doc2['numero']], 'numeracao DPS sequencial reservada (1, 2)');

    // Lote misto: 1 valido, 1 invalido (gravado como REJEITADO), 1 recusado (padrao errado).
    $misto = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [
        array_replace($payload, ['referencia' => 'X']),
        ['referencia' => 'Y', 'servico' => ['descricao' => 'Teste']],
        array_replace($payload, ['referencia' => 'Z', 'padrao' => 'RECIFE']),
    ]]);
    Apoio::igual(['NOVO', 'INVALIDO', 'RECUSADO'], array_column($misto['documentos'], 'situacao_recebimento'), 'lote misto: valido segue, invalido e gravado, recusado nao e gravado');
    $lotesAntes = (int)$pdo->query('SELECT count(*) FROM nfse_lotes')->fetchColumn();
    $soRecusados = $recebimento->receber('TESTE', ['padrao' => 'RECIFE', 'documentos' => [$payload]]);
    Apoio::igual([null, $lotesAntes], [$soRecusados['lote_id'], (int)$pdo->query('SELECT count(*) FROM nfse_lotes')->fetchColumn()], 'lote so com recusados nao deixa lote vazio gravado');
    $docInvalido = $documentos->buscar($misto['documentos'][1]['id']);
    Apoio::igual([StatusNfse::REJEITADO, null], [$docInvalido['status'], $docInvalido['numero']], 'invalido fica REJEITADO e nao consome numero de DPS');
    Apoio::verificar(($docInvalido['mensagens'][0]['correcao'] ?? '') !== '' && ($docInvalido['mensagens'][0]['campo'] ?? '') !== '', 'mensagem traz campo e sugestao de correcao');
    Apoio::igual('REJEITADO', (new MonitorRepositorio($pdo))->historico($docInvalido['id'])[0]['status'] ?? null, 'invalido aparece no monitor como REJEITADO');
    $pdo->exec("DELETE FROM nfse_jobs WHERE documento_id = '{$misto['documentos'][0]['id']}'");

    $corrigido = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [array_replace($payload, ['referencia' => 'Y'])]]);
    $docCorrigido = $documentos->buscar($corrigido['documentos'][0]['id']);
    Apoio::igual(['REENVIADO', StatusNfse::RECEBIDO, $docInvalido['id']], [$corrigido['documentos'][0]['situacao_recebimento'], $docCorrigido['status'], $docCorrigido['id']], 'reenvio corrigido reaproveita o documento');
    Apoio::verificar($docCorrigido['numero'] !== null, 'reenvio corrigido recebe numero de DPS');
    $pdo->exec("DELETE FROM nfse_jobs WHERE documento_id = '{$docCorrigido['id']}'");

    // Doc 1 autoriza; doc 2 e rejeitado.
    $http->responder(201, respostaSefinAutorizada($chave));
    $http->responder(400, json_encode(['erros' => [['codigo' => 'E0001', 'descricao' => 'Teste de rejeicao']]]));
    while ($worker->processarProximo()) {
    }
    $doc1 = $documentos->buscar($doc1['id']);
    $doc2 = $documentos->buscar($doc2['id']);
    Apoio::igual(StatusNfse::AUTORIZADO, $doc1['status'], 'worker: documento 1 AUTORIZADO');
    Apoio::igual(StatusNfse::REJEITADO, $doc2['status'], 'worker: documento 2 REJEITADO');

    $historico = array_column((new MonitorRepositorio($pdo))->historico($doc1['id']), 'status');
    Apoio::igual(['RECEBIDO', 'PROCESSANDO', 'AUTORIZADO'], $historico, 'monitor registrou a linha do tempo do documento');
    $webhooks = (int)$pdo->query("SELECT count(*) FROM nfse_webhooks_saida")->fetchColumn();
    Apoio::igual(2, $webhooks, 'um webhook por documento finalizado');

    // Idempotencia
    $repetido = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [$payload]]);
    Apoio::igual('JA_EXISTENTE', $repetido['documentos'][0]['situacao_recebimento'], 'mesma referencia autorizada nao gera nova nota');
    Apoio::igual(0, (int)$pdo->query("SELECT count(*) FROM nfse_jobs WHERE status = 'PENDENTE'")->fetchColumn(), 'nenhum job novo para documento ja autorizado');

    $reenvio = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [array_replace($payload, ['referencia' => 'PEDIDO-2'])]]);
    Apoio::igual('REENVIADO', $reenvio['documentos'][0]['situacao_recebimento'], 'referencia rejeitada pode ser reenviada');
    $http->responder(201, respostaSefinAutorizada(str_repeat('2', 50)));
    while ($worker->processarProximo()) {
    }
    $doc2 = $documentos->buscar($doc2['id']);
    Apoio::igual([StatusNfse::AUTORIZADO, 2], [$doc2['status'], $doc2['numero']], 'reenvio autorizado reaproveitando o numero 2');

    // Falha temporaria -> reagendamento
    $loteFalha = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [array_replace($payload, ['referencia' => 'PEDIDO-3'])]]);
    $http->responder(503, 'Servico indisponivel');
    $worker->processarProximo();
    $job = $pdo->query("SELECT * FROM nfse_jobs WHERE tipo = 'EMITIR' ORDER BY id DESC LIMIT 1")->fetch();
    Apoio::igual(['PENDENTE', 1], [$job['status'], (int)$job['tentativas']], 'HTTP 503 reagenda o job (tentativa 1)');
    Apoio::igual(StatusNfse::PROCESSANDO, $documentos->buscar($loteFalha['documentos'][0]['id'])['status'], 'documento segue PROCESSANDO durante as tentativas');

    // Cancelamento (evento)
    $solicitacoes = new Solicitacoes($pdo, $cfgGeral);
    $r = $solicitacoes->registrarEvento($doc1, '101101', '1', 'Curto');
    Apoio::igual('MOTIVO_INVALIDO', $r['codigo'] ?? null, 'motivo com menos de 15 caracteres e recusado');
    $r = $solicitacoes->registrarEvento($doc1, '101101', '1', 'Erro na emissao da nota fiscal');
    Apoio::igual(202, $r['http'], 'cancelamento enfileirado');
    $r2 = $solicitacoes->registrarEvento($doc1, '101101', '1', 'Erro na emissao da nota fiscal');
    Apoio::igual($r['evento']['id'], $r2['evento']['id'], 'pedido repetido devolve o mesmo evento');

    // Tira da frente o job do PEDIDO-3 (em espera) para o worker pegar so o evento.
    $pdo->exec("UPDATE nfse_jobs SET executar_apos = CURRENT_TIMESTAMP + interval '1 hour' WHERE status = 'PENDENTE' AND tipo = 'EMITIR'");
    $http->responder(201, json_encode(['eventoXmlGZipB64' => SefinNacionalCliente::compactar('<evento/>')]));
    while ($worker->processarProximo()) {
    }
    Apoio::igual(StatusEvento::REGISTRADO, (new EventoRepositorio($pdo))->buscar($r['evento']['id'])['status'], 'evento REGISTRADO');
    Apoio::igual(StatusNfse::CANCELADO, $documentos->buscar($doc1['id'])['status'], 'documento CANCELADO apos o evento');

    // Salvador: cancelamento recusado na entrada
    $loteSsa = $recebimento->receber('TESTE', ['padrao' => 'SALVADOR', 'documentos' => [$payload]]);
    $docSsa = $documentos->buscar($loteSsa['documentos'][0]['id']);
    $documentos->atualizar($docSsa['id'], ['status' => StatusNfse::AUTORIZADO]);
    $r = $solicitacoes->registrarEvento($documentos->buscar($docSsa['id']), '101101', '1', 'Erro na emissao da nota fiscal');
    Apoio::igual([422, 'NAO_SUPORTADO_PELO_PADRAO'], [$r['http'], $r['codigo']], 'Salvador: cancelamento recusado com orientacao');

    // Salvador: fluxo assincrono completo pelo worker
    $loteSsa2 = $recebimento->receber('TESTE', ['padrao' => 'SALVADOR', 'documentos' => [array_replace($payload, ['referencia' => 'SSA-2'])]]);
    $idSsa = $loteSsa2['documentos'][0]['id'];
    $pdo->exec("DELETE FROM nfse_jobs WHERE status = 'PENDENTE' AND documento_id <> '{$idSsa}'");
    $http->responder(200, soapSalvador('EnviarLoteRPS', '<EnviarLoteRpsResposta xmlns="' . NS_ABRASF . '"><NumeroLote>2</NumeroLote><DataRecebimento>2026-11-04T10:00:00</DataRecebimento><Protocolo>P2</Protocolo></EnviarLoteRpsResposta>'));
    $worker->processarProximo();
    Apoio::igual(['PROCESSANDO', 'P2'], [$documentos->buscar($idSsa)['status'], $documentos->buscar($idSsa)['protocolo_lote']], 'Salvador: PROCESSANDO com protocolo');
    $pdo->exec("UPDATE nfse_jobs SET executar_apos = CURRENT_TIMESTAMP WHERE status = 'PENDENTE'");
    $http->responder(200, soapSalvador('ConsultarSituacaoLoteRPS', '<ConsultarSituacaoLoteRpsResposta xmlns="' . NS_ABRASF . '"><NumeroLote>2</NumeroLote><Situacao>4</Situacao></ConsultarSituacaoLoteRpsResposta>'));
    $http->responder(200, soapSalvador('ConsultarLoteRPS', compNfseSalvador()));
    while ($worker->processarProximo()) {
    }
    Apoio::igual([StatusNfse::AUTORIZADO, '987'], [$documentos->buscar($idSsa)['status'], $documentos->buscar($idSsa)['numero_nfse']], 'Salvador: AUTORIZADO apos consulta do lote');

    // Arquivamento: as notas autorizadas/canceladas foram copiadas para a pasta.
    while ($worker->processarProximo()) {
    }
    $arquivados = glob($pastaArquivos . '/homologacao/41986662000160/*/*/*/nfse.xml') ?: [];
    Apoio::verificar(count($arquivados) >= 3, 'XMLs autorizados copiados para o armazenamento (' . count($arquivados) . ' notas)');
    $semEnvio = array_filter($arquivados, static fn(string $nfse) => !is_file(dirname($nfse) . '/envio.xml'));
    Apoio::igual([], array_values($semEnvio), 'XML de envio arquivado junto de cada NFS-e');
    Apoio::verificar($documentos->buscar($idSsa)['arquivado_em'] !== null, 'documento marcado com arquivado_em');

    // Cancelamento externo (Salvador)
    $r = $solicitacoes->registrarCancelamentoExterno($documentos->buscar($idSsa), 'TESTE', '', '1', 'Cancelado no portal da prefeitura', null);
    Apoio::igual('PROCESSO_INVALIDO', $r['codigo'] ?? null, 'cancelamento externo exige numero do processo');
    $r = $solicitacoes->registrarCancelamentoExterno($documentos->buscar($idSsa), 'TESTE', '2026/0001', '1', 'Cancelado no portal da prefeitura', 'Pedido deferido');
    Apoio::igual([201, StatusNfse::CANCELADO], [$r['http'], $documentos->buscar($idSsa)['status']], 'cancelamento externo registrado e nota CANCELADA');
    Apoio::igual(['EXTERNO', '2026/0001', 'REGISTRADO'], [$r['evento']['origem'], $r['evento']['numero_processo'], $r['evento']['status']], 'evento externo com origem e processo');
    $r = $solicitacoes->registrarCancelamentoExterno($documentos->buscar($doc2['id']), 'TESTE', '2026/0002', '1', 'Cancelado no portal da prefeitura', null);
    Apoio::igual('NAO_SUPORTADO_PELO_PADRAO', $r['codigo'] ?? null, 'Nacional nao aceita cancelamento externo (usa evento.php)');

    // Substituicao (Nacional): ao autorizar a substituta, a antiga e consultada.
    $pdo->exec("DELETE FROM nfse_jobs WHERE status = 'PENDENTE'");
    $loteSub = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [array_replace($payload, [
        'referencia' => 'SUBSTITUTA-1',
        'substituicao' => ['chave_substituida' => str_repeat('2', 50), 'codigo_motivo' => '01', 'motivo' => 'Correcao de valores'],
    ])]]);
    $http->responder(201, respostaSefinAutorizada(str_repeat('3', 50)));
    $worker->processarProximo();
    $consultaAgendada = $pdo->query("SELECT count(*) FROM nfse_jobs WHERE tipo = 'CONSULTAR' AND documento_id = '{$doc2['id']}' AND status = 'PENDENTE'")->fetchColumn();
    Apoio::igual(1, (int)$consultaAgendada, 'substituta autorizada agenda consulta da nota substituida');
    $pdo->exec("UPDATE nfse_jobs SET executar_apos = CURRENT_TIMESTAMP WHERE tipo = 'CONSULTAR'");
    $pdo->exec("DELETE FROM nfse_jobs WHERE tipo = 'ARQUIVAR' AND status = 'PENDENTE'");
    $http->responder(200, json_encode(['chaveAcesso' => str_repeat('2', 50), 'nfseXmlGZipB64' => SefinNacionalCliente::compactar(nfseNacionalXml(str_repeat('2', 50)))]));
    $http->responder(200, json_encode(['eventos' => [['tipoEvento' => '105102']]]));
    while ($worker->processarProximo()) {
    }
    Apoio::igual(StatusNfse::SUBSTITUIDO, $documentos->buscar($doc2['id'])['status'], 'nota antiga passa a SUBSTITUIDO');

    exec('rm -rf ' . escapeshellarg($pastaArquivos));

    testesNfeIntegracao($pdo, $cfgGeral, $worker, $soapNfe);
    testesClientesIntegracao($pdo, $cfgGeral);
    testesPortalIntegracao($pdo, $cfgGeral);
    testesSistemaIntegracao($pdo, $cfgGeral);
}

// ===========================================================================
$total = Apoio::$ok + count(Apoio::$falhas);
echo "\n" . Apoio::$ok . "/{$total} verificacoes ok.\n";
if (Apoio::$falhas !== []) {
    echo "FALHAS:\n - " . implode("\n - ", Apoio::$falhas) . "\n";
    exit(1);
}
