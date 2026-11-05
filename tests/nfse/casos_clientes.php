<?php
declare(strict_types=1);

/**
 * Testes do controle por empresa cliente (script 0005, painel admin):
 * cadastro, certificado, token proprio, bloqueios e uso para cobranca.
 * Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;
use Xande\NfeIntegracao\Nfse\Servico\ControleEmissao;
use Xande\NfeIntegracao\Nfse\Servico\RecebimentoLote;

/** situacao_recebimento e codigo do 1o erro de cada documento do lote. */
function resumoLote(array $r): array
{
    return array_map(static fn(array $d): array => [$d['situacao_recebimento'], $d['erros'][0]['codigo'] ?? null], $r['documentos'] ?? []);
}

function testesClientesIntegracao(PDO $pdo, array $cfgGeral): void
{
    echo "\n[10] Empresas clientes: token proprio, bloqueios, franquia e uso para cobranca\n";

    $cfgGeral['chave_certificados'] = base64_encode(random_bytes(32));
    $cfgGeral['emitente_implicito'] = true;
    $admin = new AdministracaoClientes($pdo, $cfgGeral);
    $cnpj = '41986662000160';

    // ---- Cadastro ----
    $r = $admin->salvar(['cnpj' => '41.986.662/0001-60', 'razao_social' => 'Cliente Teste Ltda', 'uf' => 'ba', 'ie' => '31.638.351',
        'inscricao_municipal' => '123.456', 'plano' => 'Basico'], '10.0.0.1');
    Apoio::igual([true, 201], [$r['ok'], $r['http']], 'admin: cadastra empresa nova (CNPJ com pontuacao)');
    Apoio::igual(['BA', '31638351', ['NFE', 'NACIONAL', 'SALVADOR']], [$r['cliente']['uf'], $r['cliente']['ie'], $r['cliente']['padroes_permitidos']],
        'admin: UF/IE normalizados e todos os tipos liberados por padrao');
    Apoio::igual('LIBERADA', $r['cliente']['situacao'], 'admin: empresa nova fica LIBERADA');

    $r = $admin->salvar(['cnpj' => $cnpj, 'limite_mensal' => -3, 'liberado_ate' => '31/12/2026', 'padroes_permitidos' => ['NFCE']], '10.0.0.1');
    Apoio::igual([false, 422], [$r['ok'], $r['http']], 'admin: limite, data e tipo invalidos sao recusados');
    Apoio::igual(3, count($r['erros'] ?? []), 'admin: cada erro de preenchimento e explicado');

    // ---- Certificado ----
    $pfx = Apoio::certificado()->writePfx('senha-pfx');
    Apoio::igual('CERTIFICADO_INVALIDO', $admin->enviarCertificado($cnpj, $pfx, 'errada', 'ip')['codigo'] ?? null, 'certificado: senha errada e recusada');
    $admin->salvar(['cnpj' => '11222333000181', 'razao_social' => 'Outra Empresa'], 'ip');
    Apoio::igual('CERTIFICADO_DE_OUTRA_EMPRESA', $admin->enviarCertificado('11222333000181', $pfx, 'senha-pfx', 'ip')['codigo'] ?? null,
        'certificado: e-CNPJ de outra empresa e recusado');
    $r = $admin->enviarCertificado($cnpj, $pfx, 'senha-pfx', 'ip');
    Apoio::verificar($r['ok'] && $r['cliente']['tem_certificado'], 'certificado: aceito e guardado criptografado');
    $bruto = $pdo->query("SELECT encode(certificado_cifrado, 'hex') FROM fiscal_clientes WHERE cnpj = '{$cnpj}'")->fetchColumn();
    Apoio::verificar(!str_contains((string)$bruto, bin2hex(substr($pfx, 0, 32))), 'certificado: banco nao guarda o .pfx em claro');

    // ---- Token ----
    $t = $admin->gerarToken($cnpj, 'ip');
    Apoio::verificar((bool)preg_match('/^[0-9a-f]{64}$/', (string)($t['token'] ?? '')), 'token: gerado com 64 caracteres aleatorios');
    $hashBanco = $pdo->query("SELECT token_hash FROM fiscal_clientes WHERE cnpj = '{$cnpj}'")->fetchColumn();
    Apoio::igual(hash('sha256', $t['token']), $hashBanco, 'token: banco guarda so o hash');

    $cfg = NfseConfig::comClientes($cfgGeral, $pdo);
    Apoio::igual([$cnpj], NfseConfig::cnpjsPermitidos($cfg, $cnpj), 'config: o token da empresa so emite pelo proprio CNPJ');
    Apoio::igual(hash('sha256', $t['token']), $cfg['consumidores'][$cnpj]['token_hash'], 'config: empresa autentica pelo hash do token');
    $cert = CertificadoCliente::doEmitente($cfg['emitentes'][$cnpj], $cfgGeral['chave_certificados'])->certificado();
    Apoio::igual($cnpj, $cert->getCnpj(), 'config: certificado do painel abre para assinar (em memoria)');
    Apoio::verificar(isset($cfg['emitentes']['11222333000181']), 'config: empresa sem certificado continua cadastrada (consulta/cancelamento)');

    // ---- Emissao com o token da empresa ----
    $recebimento = new RecebimentoLote($pdo, $cfg);
    $nfe = static fn(string $ref): array => payloadNfe(['referencia' => $ref]);
    $nacional = static fn(string $ref): array => ['padrao' => 'NACIONAL'] + array_replace(Apoio::documentoNacional()['payload'], ['referencia' => $ref]);

    Apoio::igual([['NOVO', null]], resumoLote($recebimento->receber($cnpj, ['padrao' => 'NFE', 'documentos' => [$nfe('CLI-1')]])),
        'emissao: empresa liberada emite com o proprio token');

    $admin->salvar(['cnpj' => $cnpj, 'padroes_permitidos' => ['NFE']], 'ip');
    Apoio::igual([['RECUSADO', 'TIPO_NAO_CONTRATADO']], resumoLote($recebimento->receber($cnpj, ['documentos' => [$nacional('CLI-NAC-1')]])),
        'tipo: NFS-e recusada quando so NF-e foi contratada');

    // Franquia: limite = consumo atual + 1 -> no lote de 2, so o 1o entra.
    // (Consumo = autorizadas no mes + em andamento, inclusive as dos outros testes.)
    $consumo = (new ControleEmissao($pdo, (int)$cfgGeral['tpAmb']))->situacao($admin->detalhe($cnpj))['consumo_mes'];
    $admin->salvar(['cnpj' => $cnpj, 'limite_mensal' => $consumo + 1], 'ip');
    Apoio::igual([['NOVO', null], ['RECUSADO', 'LIMITE_MENSAL']],
        resumoLote($recebimento->receber($cnpj, ['padrao' => 'NFE', 'documentos' => [$nfe('CLI-2'), $nfe('CLI-3')]])),
        'franquia: conta as em andamento e as aceitas no mesmo lote');
    Apoio::igual([['JA_EXISTENTE', null]], resumoLote($recebimento->receber($cnpj, ['padrao' => 'NFE', 'documentos' => [$nfe('CLI-1')]])),
        'franquia: reenvio de nota ja existente nao consome nem e barrado');
    $controle = new ControleEmissao($pdo, (int)$cfgGeral['tpAmb']);
    Apoio::igual('LIMITE_ATINGIDO', $controle->situacao($admin->detalhe($cnpj))['situacao'], 'franquia: painel mostra LIMITE_ATINGIDO');

    // Bloqueio manual e vencimento.
    $admin->salvar(['cnpj' => $cnpj, 'limite_mensal' => '', 'emissao_liberada' => false, 'motivo_bloqueio' => 'Mensalidade em atraso'], 'ip');
    $b = $controle->bloqueioDoCliente($cnpj);
    Apoio::igual('EMISSAO_BLOQUEADA', $b['codigo'] ?? null, 'bloqueio manual: lote inteiro recusado (403 na rota)');
    Apoio::verificar(str_contains((string)($b['mensagem'] ?? ''), 'Mensalidade em atraso'), 'bloqueio manual: mensagem traz o motivo');
    Apoio::igual([['RECUSADO', 'EMISSAO_BLOQUEADA']], resumoLote($recebimento->receber($cnpj, ['padrao' => 'NFE', 'documentos' => [$nfe('CLI-4')]])),
        'bloqueio manual: documento tambem e barrado no recebimento');

    $ontem = (new DateTimeImmutable('yesterday', new DateTimeZone('America/Bahia')))->format('Y-m-d');
    $admin->salvar(['cnpj' => $cnpj, 'emissao_liberada' => true, 'liberado_ate' => $ontem], 'ip');
    Apoio::igual('EMISSAO_VENCIDA', $controle->bloqueioDoCliente($cnpj)['codigo'] ?? null, 'vencimento: passou de "liberado ate" -> bloqueia sozinho');
    $admin->salvar(['cnpj' => $cnpj, 'liberado_ate' => (new DateTimeImmutable('today', new DateTimeZone('America/Bahia')))->format('Y-m-d')], 'ip');
    Apoio::verificar($controle->bloqueioDoCliente($cnpj) === null, 'vencimento: o proprio dia de "liberado ate" ainda vale');

    // ---- Uso para cobranca (gatilho no banco) ----
    // Outros testes ja autorizaram notas deste CNPJ no mes: mede a diferenca.
    $linhaDoCnpj = static fn() => array_values(array_filter($admin->relatorioUso(AdministracaoClientes::mesAtual()), static fn($l) => $l['cnpj'] === $cnpj))[0];
    $antes = $linhaDoCnpj();
    $antesPainel = $admin->detalhe($cnpj)['autorizadas_mes']['nfe'];
    $id = $pdo->query("SELECT id FROM nfse_documentos WHERE emitente_cnpj = '{$cnpj}' AND referencia = 'CLI-1' AND consumidor = '{$cnpj}'")->fetchColumn();
    $pdo->exec("UPDATE nfse_documentos SET status = 'AUTORIZADO', data_autorizacao = now() WHERE id = '{$id}'");
    $pdo->exec("UPDATE nfse_documentos SET status = 'CANCELADO' WHERE id = '{$id}'");
    $pdo->exec("UPDATE nfse_documentos SET status = 'AUTORIZADO' WHERE id = '{$id}'");
    Apoio::igual(1, (int)$pdo->query("SELECT count(*) FROM fiscal_uso WHERE documento_id = '{$id}'")->fetchColumn(),
        'uso: nota autorizada conta UMA vez (mesmo cancelada / consultada de novo)');
    $id2 = $pdo->query("SELECT id FROM nfse_documentos WHERE consumidor = '{$cnpj}' AND referencia = 'CLI-2'")->fetchColumn();
    $pdo->exec("UPDATE nfse_documentos SET status = 'REJEITADO' WHERE id = '{$id2}'");
    $depois = $linhaDoCnpj();
    Apoio::igual([1, 0, 1], [$depois['nfe'] - $antes['nfe'], $depois['nfse_nacional'] - $antes['nfse_nacional'], $depois['total'] - $antes['total']],
        'uso: relatorio do mes conta so autorizadas, por tipo (rejeitada nao conta)');
    $detalhe = $admin->detalhe($cnpj);
    Apoio::igual(1, $detalhe['autorizadas_mes']['nfe'] - $antesPainel, 'painel: autorizadas do mes por tipo');
    Apoio::verificar(($detalhe['status_mes']['REJEITADO'] ?? 0) >= 1, 'painel: documentos do mes por status (rejeitados aparecem, mas nao cobram)');
    Apoio::verificar(count($detalhe['historico']) >= 5 && $detalhe['historico'][0]['ip'] === 'ip', 'historico: alteracoes registradas com IP');

    // ---- Numeracao inicial ----
    $r = $admin->definirNumeracao(['cnpj' => $cnpj, 'padrao' => 'NFE', 'serie' => '001', 'ultimo_numero' => 1520], 'ip');
    Apoio::igual([true, true, 1521], [$r['ok'], $r['aplicado'] ?? null, $r['proximo_numero'] ?? null], 'numeracao: informa o ultimo usado (1520) -> proxima 1521');
    $serie1 = array_values(array_filter($r['cliente']['numeracao'], static fn($n) => $n['padrao'] === 'NFE' && $n['serie'] === '1'))[0] ?? [];
    Apoio::igual(1520, $serie1['ultimo_numero'] ?? null, 'numeracao: serie "001" e a mesma serie "1" da NF-e');

    $nota = $recebimento->receber($cnpj, ['padrao' => 'NFE', 'documentos' => [payloadNfe(['referencia' => 'CLI-NUM-1', 'ide' => ['serie' => 1]])]]);
    $numero = $pdo->query("SELECT numero FROM nfse_documentos WHERE id = '{$nota['documentos'][0]['id']}'")->fetchColumn();
    Apoio::igual(1521, (int)$numero, 'numeracao: a proxima NF-e emitida sai com o numero 1521');

    $r = $admin->definirNumeracao(['cnpj' => $cnpj, 'padrao' => 'NFE', 'serie' => '1', 'ultimo_numero' => 100], 'ip');
    Apoio::igual([true, false, 1522], [$r['ok'], $r['aplicado'] ?? null, $r['proximo_numero'] ?? null], 'numeracao: numero menor NAO volta para tras (evita nota repetida)');
    Apoio::verificar(str_contains((string)($r['mensagem'] ?? ''), 'Mantido'), 'numeracao: avisa que manteve o numero maior');

    $r = $admin->definirNumeracao(['cnpj' => $cnpj, 'padrao' => 'NACIONAL', 'ultimo_numero' => 77], 'ip');
    Apoio::igual([true, (string)$cfgGeral['nacional']['serie_dps'], 78],
        [$r['ok'], array_values(array_filter($r['cliente']['numeracao'], static fn($n) => $n['padrao'] === 'NACIONAL'))[0]['serie'] ?? null, $r['proximo_numero'] ?? null],
        'numeracao: sem serie, usa a serie padrao do tipo (DPS)');

    Apoio::igual('DADOS_INVALIDOS', $admin->definirNumeracao(['cnpj' => $cnpj, 'padrao' => 'NFE', 'serie' => 'AB', 'ultimo_numero' => 1], 'ip')['codigo'] ?? null,
        'numeracao: serie de NF-e com letras e recusada');
    Apoio::igual('DADOS_INVALIDOS', $admin->definirNumeracao(['cnpj' => $cnpj, 'padrao' => 'NFE', 'ultimo_numero' => -5], 'ip')['codigo'] ?? null,
        'numeracao: numero negativo e recusado');
    Apoio::igual('NAO_ENCONTRADO', $admin->definirNumeracao(['cnpj' => '99999999000191', 'padrao' => 'NFE', 'ultimo_numero' => 1], 'ip')['codigo'] ?? null,
        'numeracao: empresa nao cadastrada e recusada');
    $ultimoLog = $admin->detalhe($cnpj)['historico'][0];
    Apoio::igual(['NUMERACAO', 77], [$ultimoLog['acao'], $ultimoLog['detalhes']['informado'] ?? null], 'numeracao: ajuste registrado no historico');
}
