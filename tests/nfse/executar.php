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
 * Nenhum teste fala com o Sefin ou com Salvador: as respostas sao simuladas
 * pelo TransporteFalso (tests/nfse/Apoio.php).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Apoio.php';

use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Infra\AssinadorXml;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\ValidadorXsd;
use Xande\NfeIntegracao\Nfse\Infra\Xml;
use Xande\NfeIntegracao\Nfse\Nacional\EventoXmlBuilder;
use Xande\NfeIntegracao\Nfse\Nacional\ProvedorNacional;
use Xande\NfeIntegracao\Nfse\Nacional\SefinNacionalCliente;
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
$cfg = Apoio::config();
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

Apoio::verificar(ValidadorPayload::validar($doc, 'RECIFE') !== [], 'padrao desconhecido e recusado');

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
echo "\n[5] Integracao com PostgreSQL (fila, worker, monitor, idempotencia)\n";
// ===========================================================================

$dsn = getenv('NFSE_TESTE_PG');
if (!$dsn) {
    echo "  (pulado: defina NFSE_TESTE_PG para rodar)\n";
} else {
    $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('DROP TABLE IF EXISTS nfse_webhooks_saida, nfse_monitor, nfse_jobs, nfse_eventos, nfse_numeracao, nfse_documentos, nfse_lotes CASCADE');
    $pdo->exec((string)file_get_contents("{$raiz}/database/scripts/0002_nfse_criar_base.sql"));

    $cfg['consumidores']['TESTE']['webhook_url'] = 'https://consumidor.teste/webhook';
    $http = new TransporteFalso();
    $fabrica = new ProvedorFactory($cfg, new CertificadoCliente(Apoio::certificado()), $http);
    $worker = new ProcessadorJobs($pdo, $cfg, $fabrica, 'teste');
    $recebimento = new RecebimentoLote($pdo, $cfg);
    $documentos = new DocumentoRepositorio($pdo);

    $payload = Apoio::documentoNacional()['payload'];
    $lote = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [
        $payload,
        array_replace($payload, ['referencia' => 'PEDIDO-2']),
    ]]);
    Apoio::verificar($lote['ok'], 'lote com 2 documentos aceito');
    [$doc1, $doc2] = array_map(fn($d) => $documentos->buscar($d['id']), $lote['documentos']);
    Apoio::igual([1, 2], [$doc1['numero'], $doc2['numero']], 'numeracao DPS sequencial reservada (1, 2)');

    $invalido = $recebimento->receber('TESTE', ['padrao' => 'NACIONAL', 'documentos' => [array_replace($payload, ['referencia' => 'X']), ['referencia' => 'Y']]]);
    Apoio::verificar(!$invalido['ok'] && count($invalido['erros']) === 1, 'lote com 1 invalido e recusado inteiro (nada gravado)');

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
    $solicitacoes = new Solicitacoes($pdo, $cfg);
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
}

// ===========================================================================
$total = Apoio::$ok + count(Apoio::$falhas);
echo "\n" . Apoio::$ok . "/{$total} verificacoes ok.\n";
if (Apoio::$falhas !== []) {
    echo "FALHAS:\n - " . implode("\n - ", Apoio::$falhas) . "\n";
    exit(1);
}
