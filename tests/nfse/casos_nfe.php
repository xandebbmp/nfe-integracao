<?php
declare(strict_types=1);

/**
 * Testes da NF-e (modelo 55) na estrutura de fila/worker/monitor.
 * Incluido por tests/nfse/executar.php.
 *
 * A SEFAZ e simulada pelo SoapNfeFalso (tests/nfse/Apoio.php).
 */

use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Nfe\NfeXmlBuilder;
use Xande\NfeIntegracao\Nfse\Nfe\ProvedorNfe;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\DocumentoApresentador;
use Xande\NfeIntegracao\Nfse\Servico\ProcessadorJobs;
use Xande\NfeIntegracao\Nfse\Servico\RecebimentoLote;
use Xande\NfeIntegracao\Nfse\Servico\Solicitacoes;
use Xande\NfeIntegracao\Nfse\Servico\ValidadorPayload;

/** JSON de homologacao do projeto, ajustado para o emitente dos testes. */
function payloadNfe(array $sobrescrever = []): array
{
    $p = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/json/nota_homologacao_emitir_padrao.json'), true);
    $p['emit']['CNPJ'] = '41986662000160';
    $p['referencia'] = 'PEDIDO-NFE-1';
    return array_replace_recursive($p, $sobrescrever);
}

function provedorNfe(array $cfgGeral, SoapNfeFalso $soap): ProvedorNfe
{
    /** @var ProvedorNfe $p */
    $p = (new ProvedorFactory($cfgGeral, new CertificadoCliente(Apoio::certificado()), null, $soap))->para('NFE', '41986662000160');
    return $p;
}

function documentoNfe(array $sobrescrever = []): array
{
    return array_replace([
        'id' => '00000000-0000-0000-0000-0000000000ff', 'padrao' => 'NFE', 'status' => 'PROCESSANDO',
        'serie' => '1', 'numero' => 77, 'payload' => payloadNfe(),
        'xml_enviado' => null, 'chave_acesso' => null, 'protocolo_lote' => null, 'protocolo_autorizacao' => null,
    ], $sobrescrever);
}

// ===========================================================================
function testesNfeUnidade(array $cfgGeral, string $raiz): void
{
    echo "\n[6] NF-e - XML identico ao script homologado\n";

    $base = json_decode((string)file_get_contents("{$raiz}/json/nota_homologacao_emitir_padrao.json"), true);
    $base['ide']['cNF'] = '43219876'; // fixa o codigo aleatorio para comparar
    $cfgNfe = require "{$raiz}/config/nfe.php";

    $comRtc = $base;
    $comRtc['reformaTributaria'] = ['itens' => [[
        'nItem' => 1,
        'IBSCBS' => ['CST' => '000', 'cClassTrib' => '000001', 'gIBSCBS' => [
            'vBC' => '100.00', 'gIBSUF' => ['pIBSUF' => '0.10', 'vIBSUF' => '0.10'],
            'gIBSMun' => ['pIBSMun' => '0.00', 'vIBSMun' => '0.00'], 'gCBS' => ['pCBS' => '0.90', 'vCBS' => '0.90'],
        ]],
    ]]];

    foreach (['JSON de homologacao' => $base, 'JSON com Reforma Tributaria (IBS/CBS)' => $comRtc] as $nome => $dados) {
        $arquivo = tempnam(sys_get_temp_dir(), 'nfe_eq_');
        file_put_contents($arquivo, json_encode($dados));
        $saida = [];
        exec('php ' . escapeshellarg("{$raiz}/public/emitir_json_pl010.php") . ' ' . escapeshellarg($arquivo) . ' 2>&1', $saida, $codigo);
        $legado = $codigo === 0 ? (string)@file_get_contents(trim(end($saida))) : 'ERRO: ' . implode(' ', $saida);
        if ($codigo === 0) {
            @unlink(trim(end($saida)));
        }
        try {
            $novo = NfeXmlBuilder::montar($dados, $cfgNfe)['xml'];
        } catch (Throwable $e) {
            $novo = 'ERRO: ' . $e->getMessage();
        }
        @unlink($arquivo);
        Apoio::verificar($novo === $legado && $novo !== '', "{$nome}: XML do montador novo identico ao do script homologado" . (str_starts_with($novo, 'ERRO') ? ' (mesmo erro)' : ''));
    }

    echo "\n[7] NF-e - validacao de entrada\n";
    Apoio::igual([], ValidadorPayload::validar(payloadNfe(), 'NFE'), 'JSON de homologacao e valido');
    $semNcm = payloadNfe();
    unset($semNcm['itens'][0]['prod']['NCM']);
    $semNcm['itens'][0]['prod']['CFOP'] = '5.102';
    $erros = ValidadorPayload::validar($semNcm, 'NFE');
    Apoio::igual(['itens[0].prod.NCM', 'itens[0].prod.CFOP'], array_column($erros, 'campo'), 'NCM ausente e CFOP com ponto apontados, com sugestao de correcao');
    Apoio::verificar(ValidadorPayload::validar(payloadNfe(['ide' => ['mod' => 65]]), 'NFE') !== [], 'NFC-e (modelo 65) recusada nesta rota');

    echo "\n[8] NF-e - retornos da SEFAZ (provedor)\n";

    // Autorizada
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEnvio('100'));
    $r = provedorNfe($cfgGeral, $soap)->emitir(documentoNfe());
    Apoio::igual(StatusNfse::AUTORIZADO, $r->status, 'cStat 100 -> AUTORIZADO');
    Apoio::igual(['129261000000042', '77'], [$r->dados['protocolo_autorizacao'] ?? null, $r->dados['numero_nfse'] ?? null], 'protocolo e numero gravados (numero definido pela API)');
    Apoio::verificar(str_contains((string)($r->dados['xml_autorizado'] ?? ''), '<nfeProc'), 'XML autorizado (nfeProc) montado com o protocolo');
    Apoio::verificar(str_contains($soap->requisicoes[0]['requisicao'], '<tpAmb>2</tpAmb>') && str_contains($soap->requisicoes[0]['requisicao'], '<nNF>77</nNF>'), 'API forca ambiente (tpAmb) e numero (nNF) no XML');
    Apoio::igual('nfeAutorizacaoLote', $soap->requisicoes[0]['operacao'], 'envio pelo servico NFeAutorizacao (sincrono)');
    $notaAutorizada = documentoNfe($r->dados + ['status' => StatusNfse::AUTORIZADO]);

    // Rejeitada e denegada
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEnvio('539', 'Rejeicao: Duplicidade de NF-e com diferenca na Chave de Acesso'));
    $r = provedorNfe($cfgGeral, $soap)->emitir(documentoNfe());
    Apoio::igual([StatusNfse::REJEITADO, '539'], [$r->status, $r->mensagens[0]['codigo'] ?? null], 'cStat 539 -> REJEITADO com o codigo da SEFAZ');

    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEnvio('301', 'Uso Denegado: Irregularidade fiscal do emitente'));
    $r = provedorNfe($cfgGeral, $soap)->emitir(documentoNfe());
    Apoio::igual(StatusNfse::DENEGADO, $r->status, 'cStat 301 -> DENEGADO');

    // Erro de montagem (nao chega na SEFAZ)
    $soap = new SoapNfeFalso();
    $semItens = documentoNfe(['payload' => payloadNfe(['itens' => []])]);
    $semItens['payload']['itens'] = [];
    $r = provedorNfe($cfgGeral, $soap)->emitir($semItens);
    Apoio::verificar($r->status === StatusNfse::REJEITADO && $soap->requisicoes === [], 'JSON incompleto -> REJEITADO sem chamar a SEFAZ');

    // Queda de rede -> nova tentativa consulta a chave e NAO reenvia
    $soap = new SoapNfeFalso();
    $soap->responder(new RuntimeException('Operation timed out'));
    $r = provedorNfe($cfgGeral, $soap)->emitir(documentoNfe());
    Apoio::verificar($r->falhaTemporaria && !empty($r->dados['chave_acesso']) && !empty($r->dados['xml_enviado']), 'timeout -> falha temporaria, guardando chave e XML enviado');
    $tentativa = documentoNfe($r->dados);
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoConsulta($tentativa['xml_enviado'], '100', 'Autorizado o uso da NF-e'));
    $r2 = provedorNfe($cfgGeral, $soap)->emitir($tentativa);
    Apoio::igual([StatusNfse::AUTORIZADO, ['nfeConsultaNF']], [$r2->status, array_column($soap->requisicoes, 'operacao')], 'retentativa: consulta a chave, acha autorizada e NAO reenvia');

    // Retentativa quando a nota NAO chegou: reenvia o MESMO XML
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoConsulta($tentativa['xml_enviado'], '217', 'Rejeicao: NF-e nao consta na base de dados da SEFAZ'));
    $soap->responder(SoapNfeFalso::retornoEnvio('100'));
    $r3 = provedorNfe($cfgGeral, $soap)->emitir($tentativa);
    preg_match('/Id="NFe(\d{44})"/', $soap->requisicoes[1]['requisicao'] ?? '', $c);
    Apoio::igual([StatusNfse::AUTORIZADO, $tentativa['chave_acesso']], [$r3->status, $c[1] ?? null], 'nao constava (217): reenvia o mesmo XML, mesma chave');

    // Lote assincrono (103) -> consulta do recibo
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEnvio('100', '', '103'));
    $r = provedorNfe($cfgGeral, $soap)->emitir(documentoNfe());
    Apoio::igual([StatusNfse::PROCESSANDO, '291000000000001'], [$r->status, $r->dados['protocolo_lote'] ?? null], 'SEFAZ respondeu 103: PROCESSANDO com o recibo');
    $soap = new SoapNfeFalso();
    $xmlEnviado = (string)$r->dados['xml_enviado'];
    $soap->responder(static function () use ($xmlEnviado): string {
        $envio = SoapNfeFalso::retornoEnvio('100');
        return str_replace(['retEnviNFe', '<cStat>104</cStat>'], ['retConsReciNFe', '<nRec>291000000000001</nRec><cStat>104</cStat>'], $envio($xmlEnviado));
    });
    $r = provedorNfe($cfgGeral, $soap)->consultarProcessamento(documentoNfe($r->dados));
    Apoio::igual([StatusNfse::AUTORIZADO, 'nfeRetAutorizacaoLote'], [$r->status, $soap->requisicoes[0]['operacao'] ?? null], 'consulta do recibo -> AUTORIZADO');

    // SEFAZ paralisada
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEnvio('100', '', '108'));
    $r = provedorNfe($cfgGeral, $soap)->emitir(documentoNfe());
    Apoio::verificar($r->falhaTemporaria, 'cStat 108 (servico paralisado) -> nova tentativa depois');

    // Eventos
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEvento('135'));
    $r = provedorNfe($cfgGeral, $soap)->registrarEvento($notaAutorizada, ['tipo_evento' => '110110', 'motivo_texto' => 'Correcao do endereco de entrega do cliente', 'sequencia' => 2]);
    Apoio::igual(StatusEvento::REGISTRADO, $r->status, 'Carta de Correcao registrada (135)');
    Apoio::verificar(str_contains($soap->requisicoes[0]['requisicao'], '<nSeqEvento>2</nSeqEvento>') && str_contains((string)($r->dados['xml_retorno'] ?? ''), 'procEventoNFe'), 'CC-e enviada com a sequencia certa e procEvento montado');

    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEvento('135'));
    $r = provedorNfe($cfgGeral, $soap)->registrarEvento($notaAutorizada, ['tipo_evento' => '110111', 'motivo_texto' => 'Cancelamento por erro de digitacao']);
    Apoio::verificar($r->status === StatusEvento::REGISTRADO && str_contains($soap->requisicoes[0]['requisicao'], '<nProt>129261000000042</nProt>'), 'cancelamento registrado usando o protocolo de autorizacao');

    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoEvento('501', 'Rejeicao: Prazo de cancelamento superior ao previsto na Legislacao'));
    $r = provedorNfe($cfgGeral, $soap)->registrarEvento($notaAutorizada, ['tipo_evento' => '110111', 'motivo_texto' => 'Cancelamento por erro de digitacao']);
    Apoio::igual([StatusEvento::REJEITADO, '501'], [$r->status, $r->mensagens[0]['codigo'] ?? null], 'cancelamento fora do prazo -> REJEITADO com o motivo');

    // Consulta de nota cancelada e DANFE
    $soap = new SoapNfeFalso();
    $soap->responder(SoapNfeFalso::retornoConsulta((string)$notaAutorizada['xml_enviado'], '101', 'Cancelamento de NF-e homologado'));
    Apoio::igual(StatusNfse::CANCELADO, provedorNfe($cfgGeral, $soap)->consultar($notaAutorizada)->status, 'consulta cStat 101 -> CANCELADO');

    $pdf = provedorNfe($cfgGeral, new SoapNfeFalso())->gerarPdf($notaAutorizada);
    Apoio::verificar(str_starts_with($pdf, '%PDF'), 'DANFE gerado a partir do nfeProc');
}

// ===========================================================================
function testesNfeIntegracao(PDO $pdo, array $cfgGeral, ProcessadorJobs $worker, SoapNfeFalso $soap): void
{
    echo "\n[9] NF-e - fluxo completo com PostgreSQL\n";

    $recebimento = new RecebimentoLote($pdo, $cfgGeral);
    $documentos = new DocumentoRepositorio($pdo);
    $pdo->exec("DELETE FROM nfse_jobs WHERE status = 'PENDENTE'");

    $lote = $recebimento->receber('TESTE', ['padrao' => 'NFE', 'documentos' => [
        payloadNfe(),
        payloadNfe(['referencia' => 'PEDIDO-NFE-SERIE-2', 'ide' => ['serie' => 2]]),
        payloadNfe(['referencia' => 'PEDIDO-NFE-OUTRA-EMPRESA', 'emit' => ['CNPJ' => '07047183000140']]),
        payloadNfe(['referencia' => 'PEDIDO-NFE-CONFLITO', 'emitente_cnpj' => '11222333000181']),
    ]]);
    Apoio::igual(['NOVO', 'NOVO', 'RECUSADO', 'RECUSADO'], array_column($lote['documentos'], 'situacao_recebimento'), 'emit.CNPJ nao liberado e emitente_cnpj divergente sao recusados');
    $nota = $documentos->buscar($lote['documentos'][0]['id']);
    $serie2 = $documentos->buscar($lote['documentos'][1]['id']);
    Apoio::igual([['1', 1], ['2', 1]], [[$nota['serie'], $nota['numero']], [$serie2['serie'], $serie2['numero']]], 'numeracao separada por serie (serie 1 n1, serie 2 n1)');

    $soap->responder(SoapNfeFalso::retornoEnvio('100'));
    $soap->responder(SoapNfeFalso::retornoEnvio('301', 'Uso Denegado: Irregularidade fiscal do emitente'));
    while ($worker->processarProximo()) {
    }
    $nota = $documentos->buscar($nota['id']);
    $serie2 = $documentos->buscar($serie2['id']);
    Apoio::igual([StatusNfse::AUTORIZADO, '129261000000042'], [$nota['status'], $nota['protocolo_autorizacao']], 'worker: NF-e AUTORIZADA com protocolo gravado');
    Apoio::igual(StatusNfse::DENEGADO, $serie2['status'], 'worker: NF-e DENEGADA');

    $repetida = $recebimento->receber('TESTE', ['padrao' => 'NFE', 'documentos' => [payloadNfe(['referencia' => 'PEDIDO-NFE-SERIE-2', 'ide' => ['serie' => 2]])]]);
    Apoio::igual('JA_EXISTENTE', $repetida['documentos'][0]['situacao_recebimento'] ?? null, 'denegada NAO pode ser reenviada (numero consumido)');

    $solicitacoes = new Solicitacoes($pdo, $cfgGeral);
    $eventos = new EventoRepositorio($pdo);
    $r1 = $solicitacoes->registrarEvento($nota, '110110', '', 'Correcao do endereco de entrega do cliente');
    $soap->responder(SoapNfeFalso::retornoEvento('135'));
    while ($worker->processarProximo()) {
    }
    $r2 = $solicitacoes->registrarEvento($documentos->buscar($nota['id']), '110110', '', 'Segunda correcao: complemento do endereco');
    $soap->responder(SoapNfeFalso::retornoEvento('135'));
    while ($worker->processarProximo()) {
    }
    Apoio::igual([1, 2], [(int)$eventos->buscar($r1['evento']['id'])['sequencia'], (int)$eventos->buscar($r2['evento']['id'])['sequencia']], 'Cartas de Correcao numeradas 1 e 2');

    $curto = $solicitacoes->registrarEvento($documentos->buscar($nota['id']), '110111', '', 'curto');
    Apoio::igual('MOTIVO_INVALIDO', $curto['codigo'] ?? null, 'justificativa do cancelamento com menos de 15 caracteres e recusada');

    $solicitacoes->registrarEvento($documentos->buscar($nota['id']), '110111', '', 'Cancelamento por erro de digitacao');
    $soap->responder(SoapNfeFalso::retornoEvento('135'));
    while ($worker->processarProximo()) {
    }
    Apoio::igual(StatusNfse::CANCELADO, $documentos->buscar($nota['id'])['status'], 'cancelamento registrado -> NF-e CANCELADA');

    // Download dos XMLs (nota e eventos) com nome no padrao do mercado.
    $notaFinal = $documentos->buscar($nota['id']);
    $saida = DocumentoApresentador::documento($notaFinal, false, $eventos->listarDoDocumento($nota['id']));
    $chave = $notaFinal['chave_acesso'];
    Apoio::igual("{$chave}-procNFe.xml", $saida['links']['xml_nome'], 'XML da NF-e: nome <chave>-procNFe.xml');
    $nomes = array_map(static fn(array $e): ?string => $e['links']['xml_nome'], $saida['eventos']);
    Apoio::verificar(in_array("{$chave}-110110-01-procEventoNFe.xml", $nomes, true) && in_array("{$chave}-110110-02-procEventoNFe.xml", $nomes, true),
        'XML das CC-e: nome com tipo e sequencia (01, 02)');
    Apoio::verificar(in_array("{$chave}-110111-01-procEventoNFe.xml", $nomes, true), 'XML do cancelamento: <chave>-110111-01-procEventoNFe.xml');
    $registrados = array_filter($saida['eventos'], static fn(array $e): bool => $e['status'] === StatusEvento::REGISTRADO);
    Apoio::verificar($registrados !== [] && array_filter($registrados, static fn(array $e): bool => $e['links']['xml'] === null) === [],
        'todo evento registrado tem link para baixar o XML');
    $rejeitados = array_filter($saida['eventos'], static fn(array $e): bool => $e['status'] !== StatusEvento::REGISTRADO);
    Apoio::verificar(array_filter($rejeitados, static fn(array $e): bool => $e['links']['xml'] !== null) === [],
        'evento nao registrado (rejeitado/em andamento) nao oferece XML');
    $cce = $eventos->buscar($r1['evento']['id']);
    Apoio::verificar(str_contains((string)$cce['xml_retorno'], 'procEventoNFe'), 'XML guardado da CC-e e o procEventoNFe (evento + retorno da SEFAZ)');
    Apoio::igual('Carta de Correção Eletrônica (CC-e)', DocumentoApresentador::evento($cce, $notaFinal)['descricao'], 'evento mostra a descricao do tipo');
}
