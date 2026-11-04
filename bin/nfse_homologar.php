<?php
declare(strict_types=1);

/**
 * Roteiro automatico de HOMOLOGACAO da NFS-e.
 *
 * Fala direto com o fisco (sem fila, sem banco) e executa, em ordem:
 *
 *   NACIONAL: emitir -> consultar DPS -> consultar NFS-e -> PDF (DANFSe) -> cancelar -> conferir cancelamento
 *   SALVADOR: emitir lote -> aguardar processamento -> consultar por RPS -> PDF (espelho)
 *             (Salvador nao cancela por webservice)
 *
 * Tudo o que vai e volta do fisco fica gravado em
 *   storage/logs/homologacao/<data>-<padrao>/
 * (requisicoes, respostas, XMLs ja descompactados, PDF e resumo.txt).
 * Envie essa pasta para quem for ajustar a integracao.
 *
 * Uso:
 *   php bin/nfse_homologar.php --padrao=NACIONAL
 *   php bin/nfse_homologar.php --padrao=SALVADOR --cnpj=41986662000160
 *
 * Opcoes:
 *   --padrao=NACIONAL|SALVADOR  obrigatorio
 *   --cnpj=...                  emitente (obrigatorio se houver mais de um configurado)
 *   --payload=arquivo.json      nota de teste (padrao: json/nfse/homologacao_<padrao>.json)
 *   --serie=N                   serie da DPS/RPS (padrao: a da configuracao)
 *   --numero=N                  numero da DPS/RPS (padrao: gerado pela data/hora, nunca repete)
 *   --so-validar                so monta, assina e valida o XML no XSD (nao chama o fisco)
 *   --sem-cancelar              nao cancela a nota no final (Nacional)
 *
 * Seguranca: so roda com tpAmb = 2 (homologacao). Em producao, recusa.
 */

use Xande\NfeIntegracao\Nfse\Contrato\ProvedorNfse;
use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Infra\AssinadorXml;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteGravador;
use Xande\NfeIntegracao\Nfse\Infra\ValidadorXsd;
use Xande\NfeIntegracao\Nfse\Nacional\DpsXmlBuilder;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Salvador\RpsXmlBuilder;
use Xande\NfeIntegracao\Nfse\Servico\ValidadorPayload;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

// ===========================================================================
// 1. Parametros e verificacoes iniciais
// ===========================================================================

$opcoes = getopt('', ['padrao:', 'cnpj:', 'payload:', 'serie:', 'numero:', 'so-validar', 'sem-cancelar', 'ajuda']);
if (isset($opcoes['ajuda']) || empty($opcoes['padrao'])) {
    fwrite(STDOUT, "Uso: php bin/nfse_homologar.php --padrao=NACIONAL|SALVADOR [--cnpj=...] [--payload=...] [--so-validar] [--sem-cancelar]\n");
    exit(isset($opcoes['ajuda']) ? 0 : 2);
}

$padrao = strtoupper((string)$opcoes['padrao']);
if (!in_array($padrao, Padrao::TODOS, true)) {
    parar("Padrao invalido: {$padrao}. Use NACIONAL ou SALVADOR.");
}

$cfgGeral = NfseConfig::carregar();
if ((int)$cfgGeral['tpAmb'] !== 2) {
    parar('tpAmb configurado e 1 (PRODUCAO). Este script so roda em HOMOLOGACAO (tpAmb = 2).');
}

$cnpjs = array_map('strval', array_keys($cfgGeral['emitentes']));
$cnpj = isset($opcoes['cnpj']) ? NfseConfig::normalizarCnpj($opcoes['cnpj']) : (count($cnpjs) === 1 ? $cnpjs[0] : '');
if ($cnpj === '' || !in_array($cnpj, $cnpjs, true)) {
    parar('Informe --cnpj com um destes emitentes configurados: ' . implode(', ', $cnpjs));
}
$cfg = NfseConfig::paraEmitente($cfgGeral, $cnpj);
$emitente = $cfg['emitente'];

if (trim((string)$emitente['inscricao_municipal']) === '') {
    aviso('Emitente sem inscricao municipal configurada. Salvador exige; no Nacional pode ser rejeitado se o municipio exigir.');
}

$arquivoPayload = $opcoes['payload'] ?? "{$raiz}/json/nfse/homologacao_" . strtolower($padrao) . '.json';
$payload = json_decode((string)@file_get_contents($arquivoPayload), true);
if (!is_array($payload)) {
    parar("Nao consegui ler o JSON da nota de teste: {$arquivoPayload}");
}
$payload = array_filter($payload, static fn($chave) => !str_starts_with((string)$chave, '_'), ARRAY_FILTER_USE_KEY);
$payload['referencia'] = ($payload['referencia'] ?? 'HOMOLOGACAO') . '-' . date('YmdHis');

$erros = ValidadorPayload::validar($payload, $padrao);
if ($erros !== []) {
    foreach ($erros as $e) {
        fwrite(STDERR, " - {$e['campo']}: {$e['mensagem']} -> {$e['correcao']}\n");
    }
    parar("A nota de teste tem erros ({$arquivoPayload}).");
}

titulo("Homologacao NFS-e {$padrao}");
linha('Emitente', "{$emitente['razao_social']} ({$cnpj})");
linha('Inscricao municipal', (string)$emitente['inscricao_municipal']);
linha('Nota de teste', $arquivoPayload);

try {
    $certificado = CertificadoCliente::doArquivo(
        (string)$emitente['certificado']['arquivo'],
        (string)$emitente['certificado']['senha']
    );
} catch (Throwable $e) {
    parar('Certificado: ' . $e->getMessage());
}
linha('Certificado', $certificado->certificado()->getCompanyName() . ' - valido ate ' . $certificado->certificado()->getValidTo()->format('d/m/Y'));
$cnpjCertificado = NfseConfig::normalizarCnpj($certificado->certificado()->getCnpj() ?? '');
if ($cnpjCertificado === '') {
    aviso('Nao foi possivel ler o CNPJ do certificado. Confira se e o e-CNPJ do emitente.');
} elseif (substr($cnpjCertificado, 0, 8) !== substr($cnpj, 0, 8)) {
    aviso("O certificado e do CNPJ {$cnpjCertificado}, nao do emitente {$cnpj}. O fisco deve recusar.");
}

$pasta = "{$raiz}/storage/logs/homologacao/" . date('Ymd-His') . '-' . strtolower($padrao);
@mkdir($pasta, 0775, true);
linha('Gravando em', $pasta);

$documento = [
    'id' => 'homologacao-' . date('YmdHis'),
    'padrao' => $padrao,
    'emitente_cnpj' => $cnpj,
    'consumidor' => 'HOMOLOGACAO',
    'referencia' => $payload['referencia'],
    'tp_amb' => 2,
    'status' => StatusNfse::RECEBIDO,
    'serie' => (string)($opcoes['serie'] ?? ($padrao === Padrao::NACIONAL ? $cfg['nacional']['serie_dps'] : $cfg['salvador']['serie_rps'])),
    // Numero pela data/hora: cada execucao usa um numero novo e nao conflita com a anterior.
    'numero' => (int)($opcoes['numero'] ?? date('ymdHis')),
    'payload' => $payload,
    'id_dps' => null,
    'xml_enviado' => null,
    'protocolo_lote' => null,
    'chave_acesso' => null,
    'numero_nfse' => null,
    'codigo_verificacao' => null,
    'data_autorizacao' => null,
    'xml_autorizado' => null,
    'mensagens' => [],
];
linha('DPS/RPS', "serie {$documento['serie']} / numero {$documento['numero']}");

$resultados = [];

// ===========================================================================
// 2. So validar (sem chamar o fisco)
// ===========================================================================

if (isset($opcoes['so-validar'])) {
    titulo('Validacao offline (montar, assinar e validar no XSD)');
    $assinador = new AssinadorXml(
        $certificado->certificado(),
        (string)$cfg[$padrao === Padrao::NACIONAL ? 'nacional' : 'salvador']['algoritmo_assinatura']
    );

    if ($padrao === Padrao::NACIONAL) {
        $montado = (new DpsXmlBuilder($cfg))->montar($documento);
        $dom = new DOMDocument();
        $dom->loadXML($montado['xml']);
        $assinador->assinarNo($dom, $dom->getElementsByTagName('infDPS')->item(0), $dom->documentElement, 'Id');
        $xsd = "{$raiz}/schemas/nfse/nacional/{$cfg['nacional']['versao_leiaute']}/DPS_v{$cfg['nacional']['versao_leiaute']}.xsd";
    } else {
        $montado = (new RpsXmlBuilder($cfg))->montarEnvioLote($documento);
        $dom = $montado['dom'];
        $assinador->assinarNo($dom, $montado['infRps'], $montado['rps'], 'id');
        $assinador->assinarNo($dom, $montado['loteRps'], $dom->documentElement, 'id');
        $xsd = "{$raiz}/schemas/nfse/salvador/1.00/nfse.xsd";
    }

    $xml = $dom->saveXML($dom->documentElement);
    file_put_contents("{$pasta}/envio-assinado.xml", $xml);
    $errosXsd = ValidadorXsd::validar($xml, $xsd);
    registrar('Montar, assinar e validar no XSD', $errosXsd === [], $errosXsd === [] ? 'XML valido: envio-assinado.xml' : implode(' | ', $errosXsd));
    finalizar();
}

// ===========================================================================
// 3. Roteiro com o fisco
// ===========================================================================

$gravador = new TransporteGravador(
    new CurlTransporte($certificado, (int)$cfg[$padrao === Padrao::NACIONAL ? 'nacional' : 'salvador']['timeout_segundos']),
    $pasta
);
$provedor = (new ProvedorFactory($cfgGeral, $certificado, $gravador))->para($padrao, $cnpj);

if ($padrao === Padrao::NACIONAL) {
    roteiroNacional($provedor, $documento, isset($opcoes['sem-cancelar']));
} else {
    roteiroSalvador($provedor, $documento, $cfg);
}
finalizar();

// ===========================================================================
// Roteiros
// ===========================================================================

function roteiroNacional(ProvedorNfse $provedor, array &$documento, bool $semCancelar): void
{
    titulo('1. Emitir (POST /nfse)');
    $r = aplicar($documento, $provedor->emitir($documento));
    if (!registrar('Emitir', $r->status === StatusNfse::AUTORIZADO, descrever($r, $documento))) {
        return;
    }

    titulo('2. Consultar DPS (GET /dps/{id}) - usado na protecao contra duplicidade');
    $r = $provedor->consultarProcessamento($documento);
    registrar('Consultar DPS', $r->status === StatusNfse::AUTORIZADO && ($r->dados['chave_acesso'] ?? null) === $documento['chave_acesso'], descrever($r));

    titulo('3. Consultar NFS-e e eventos (GET /nfse/{chave})');
    $r = $provedor->consultar($documento);
    registrar('Consultar NFS-e', $r->status === StatusNfse::AUTORIZADO, descrever($r));

    titulo('4. PDF (DANFSe do ADN)');
    salvarPdf($provedor, $documento);

    if ($semCancelar) {
        registrar('Cancelar', true, 'pulado (--sem-cancelar)');
        return;
    }

    titulo('5. Cancelar (evento 101101)');
    $r = $provedor->registrarEvento($documento, [
        'id' => 'cancelamento-homologacao',
        'tipo_evento' => TipoEvento::CANCELAMENTO,
        'motivo_codigo' => '1',
        'motivo_texto' => 'Cancelamento de nota emitida em teste de homologacao',
    ]);
    if (!registrar('Cancelar', $r->status === StatusEvento::REGISTRADO, descrever($r))) {
        return;
    }

    titulo('6. Conferir cancelamento na consulta');
    for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
        $r = $provedor->consultar($documento);
        if ($r->status === StatusNfse::CANCELADO) {
            break;
        }
        fwrite(STDOUT, "   ainda {$r->status}, nova consulta em 10s...\n");
        sleep(10);
    }
    registrar('Conferir cancelamento', $r->status === StatusNfse::CANCELADO, descrever($r));
}

function roteiroSalvador(ProvedorNfse $provedor, array &$documento, array $cfg): void
{
    titulo('1. Emitir (EnviarLoteRPS)');
    $r = aplicar($documento, $provedor->emitir($documento));
    $ok = in_array($r->status, [StatusNfse::PROCESSANDO, StatusNfse::AUTORIZADO], true);
    if (!registrar('Enviar lote', $ok, descrever($r, $documento))) {
        return;
    }

    if ($r->status === StatusNfse::PROCESSANDO) {
        titulo('2. Aguardar processamento (ConsultarSituacaoLoteRPS / ConsultarLoteRPS)');
        $espera = min(15, (int)$cfg['salvador']['consulta_lote_intervalo']);
        for ($tentativa = 1; $tentativa <= 20; $tentativa++) {
            sleep($tentativa === 1 ? (int)$cfg['salvador']['consulta_lote_primeira_em'] : $espera);
            $r = aplicar($documento, $provedor->consultarProcessamento($documento));
            fwrite(STDOUT, "   tentativa {$tentativa}: {$r->status}" . ($r->resumo() ? ' - ' . $r->resumo() : '') . "\n");
            if ($r->status !== StatusNfse::PROCESSANDO && !$r->falhaTemporaria) {
                break;
            }
        }
        if (!registrar('Aguardar processamento', $r->status === StatusNfse::AUTORIZADO, descrever($r, $documento))) {
            return;
        }
    }

    titulo('3. Consultar NFS-e pelo RPS (ConsultarNfseRPS)');
    $r = $provedor->consultar($documento);
    registrar('Consultar por RPS', $r->status === StatusNfse::AUTORIZADO, descrever($r));

    titulo('4. PDF (espelho gerado pela API)');
    salvarPdf($provedor, $documento);

    registrar('Cancelar', true, 'nao se aplica: Salvador nao cancela por webservice (testar o registro manual pela API)');
}

// ===========================================================================
// Auxiliares
// ===========================================================================

/** Copia para o documento os dados que o fisco devolveu (chave, numero, XML...). */
function aplicar(array &$documento, RetornoProvedor $r): RetornoProvedor
{
    $documento = array_replace($documento, $r->dados);
    $documento['status'] = $r->status;
    return $r;
}

function salvarPdf(ProvedorNfse $provedor, array $documento): void
{
    global $pasta;
    try {
        $pdf = $provedor->gerarPdf($documento);
        file_put_contents("{$pasta}/nfse.pdf", $pdf);
        registrar('PDF', true, 'salvo em nfse.pdf (' . strlen($pdf) . ' bytes)');
    } catch (Throwable $e) {
        registrar('PDF', false, $e->getMessage());
    }
}

function descrever(RetornoProvedor $r, ?array $documento = null): string
{
    $partes = ["status {$r->status}" . ($r->falhaTemporaria ? ' (falha temporaria)' : '')];
    foreach (['numero_nfse' => 'NFS-e', 'chave_acesso' => 'chave', 'protocolo_lote' => 'protocolo', 'codigo_verificacao' => 'verificacao'] as $campo => $nome) {
        $valor = $r->dados[$campo] ?? ($documento[$campo] ?? null);
        if ($valor) {
            $partes[] = "{$nome} {$valor}";
        }
    }
    foreach ($r->mensagens as $m) {
        $partes[] = trim(($m['codigo'] ?? '') . ' ' . ($m['mensagem'] ?? '') . (isset($m['correcao']) ? " (correcao: {$m['correcao']})" : ''));
    }
    return implode(' | ', $partes);
}

function registrar(string $etapa, bool $ok, string $detalhe): bool
{
    global $resultados;
    $resultados[] = [$etapa, $ok, $detalhe];
    fwrite(STDOUT, ($ok ? '   OK    ' : '   FALHOU ') . "{$etapa}: {$detalhe}\n");
    return $ok;
}

function finalizar(): never
{
    global $resultados, $pasta, $documento, $padrao;

    $resumo = "Homologacao NFS-e {$padrao} - " . date('d/m/Y H:i:s') . "\n"
        . "Emitente: {$documento['emitente_cnpj']} | DPS/RPS {$documento['serie']}/{$documento['numero']}\n\n";
    foreach ($resultados as [$etapa, $ok, $detalhe]) {
        $resumo .= ($ok ? '[OK]     ' : '[FALHOU] ') . "{$etapa}: {$detalhe}\n";
    }
    file_put_contents("{$pasta}/resumo.txt", $resumo);
    foreach (['xml_enviado' => 'envio.xml', 'xml_autorizado' => 'nfse.xml'] as $campo => $arquivo) {
        if (!empty($documento[$campo])) {
            file_put_contents("{$pasta}/{$arquivo}", $documento[$campo]);
        }
    }

    $falhas = count(array_filter($resultados, static fn($r) => !$r[1]));
    titulo($falhas === 0 ? 'RESULTADO: tudo OK' : "RESULTADO: {$falhas} etapa(s) com falha");
    fwrite(STDOUT, "Arquivos gravados em: {$pasta}\n");
    exit($falhas === 0 && $resultados !== [] ? 0 : 1);
}

function titulo(string $texto): void
{
    fwrite(STDOUT, "\n== {$texto} ==\n");
}

function linha(string $nome, string $valor): void
{
    fwrite(STDOUT, str_pad($nome . ':', 22) . $valor . "\n");
}

function aviso(string $texto): void
{
    fwrite(STDOUT, "   ATENCAO: {$texto}\n");
}

function parar(string $mensagem): never
{
    fwrite(STDERR, "ERRO: {$mensagem}\n");
    exit(2);
}
