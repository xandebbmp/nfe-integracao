<?php
declare(strict_types=1);

/**
 * Testes do Fechamento do mes (painel admin): receita por empresa para o
 * contador fazer a declaracao do Simples. Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\FechamentoMes;
use Xande\NfeIntegracao\Nfse\Servico\GuiasDas;

function testesFechamentoUnidade(): void
{
    echo "\n[15a] Fechamento do mes: estimativa do DAS\n";

    $r = ['nfse_qtd' => 2, 'nfse_valor' => 1500.0, 'nfe_qtd' => 1, 'nfe_valor' => 500.0, 'canceladas' => 1, 'rbt12' => 9000.0];
    $l = FechamentoMes::linha('11222333000181', 'Empresa', 3, '2.00', $r);
    Apoio::igual([3, 2000.0, 40.0, 'ME/EPP'], [$l['notas'], $l['receita_mes'], $l['das_estimado'], $l['simples']],
        'ME/EPP: receita = NFS-e + NF-e; DAS estimado = receita x percentual');
    Apoio::igual(null, FechamentoMes::linha('11222333000181', 'Empresa', 3, null, $r)['das_estimado'], 'sem percentual cadastrado: nao estima');
    Apoio::igual(null, FechamentoMes::linha('11222333000181', 'Empresa', 2, '2.00', $r)['das_estimado'], 'MEI: nao estima (DAS-MEI e valor fixo)');
    Apoio::igual(null, FechamentoMes::linha('11222333000181', 'Empresa', 1, '2.00', $r)['das_estimado'], 'nao optante: sem DAS');
    $vazia = FechamentoMes::linha('11222333000181', 'Empresa', 3, '2.00', []);
    Apoio::igual([0, 0.0, 0.0], [$vazia['notas'], $vazia['receita_mes'], $vazia['das_estimado']], 'empresa sem notas no mes: tudo zero');
}

function testesFechamentoIntegracao(PDO $pdo, array $cfgGeral): void
{
    echo "\n[15] Fechamento do mes: receita por empresa\n";

    $tpAmb = (int)$cfgGeral['tpAmb'];
    $cnpj = '33444555000101';
    $repo = new ClienteRepositorio($pdo);
    $repo->criar($cnpj, 'Fechamento Teste Ltda');
    $repo->atualizar($cnpj, ['opcao_simples_nacional' => 3, 'percentual_tributos_simples' => '6.00']);

    $lote = $pdo->query("INSERT INTO nfse_lotes (consumidor, quantidade_documentos) VALUES ('TESTE', 9) RETURNING id")->fetchColumn();
    $numero = 0;
    $nota = static function (string $padrao, string $status, array $payload, string $autorizada) use ($pdo, $lote, $cnpj, $tpAmb, &$numero): void {
        $numero++;
        $stmt = $pdo->prepare("INSERT INTO nfse_documentos (lote_id, consumidor, padrao, tp_amb, emitente_cnpj, referencia, status, payload, serie, numero, data_autorizacao)
                               VALUES (:lote, 'TESTE', :padrao, :amb, :cnpj, :ref, :status, :payload, '1', :numero, :autorizada)");
        $stmt->execute([':lote' => $lote, ':padrao' => $padrao, ':amb' => $tpAmb, ':cnpj' => $cnpj, ':ref' => "FECH-{$numero}",
            ':status' => $status, ':payload' => json_encode($payload), ':numero' => $numero, ':autorizada' => $autorizada]);
    };
    $servico = static fn(float $valor, string $competencia): array => ['competencia' => $competencia, 'valores' => ['valor_servicos' => $valor]];
    $produto = static fn(float $valor, array $ide = []): array => ['ide' => $ide + ['tpNF' => 1, 'finNFe' => 1], 'totais' => ['ICMSTot' => ['vNF' => $valor]]];

    $mes = (new DateTimeImmutable('first day of this month', new DateTimeZone('America/Bahia')))->format('Y-m');
    $agora = (new DateTimeImmutable('now'))->format('c');
    $mesPassado = (new DateTimeImmutable($mes . '-01'))->modify('-1 month');

    $nota('NACIONAL', 'AUTORIZADO', $servico(1000.00, "{$mes}-05"), $agora);
    $nota('SALVADOR', 'AUTORIZADO', $servico(250.50, "{$mes}-10"), $agora);
    $nota('NACIONAL', 'CANCELADO', $servico(999.00, "{$mes}-12"), $agora);                  // cancelada: fora da receita
    $nota('NACIONAL', 'AUTORIZADO', $servico(300.00, $mesPassado->format('Y-m-20')), $agora); // emitida agora, servico do mes passado
    $nota('NACIONAL', 'REJEITADO', $servico(5000.00, "{$mes}-01"), $agora);                 // rejeitada: nao conta
    $nota('NFE', 'AUTORIZADO', $produto(749.50), $agora);
    $nota('NFE', 'AUTORIZADO', $produto(80.00, ['tpNF' => 0]), $agora);                     // NF-e de entrada
    $nota('NFE', 'AUTORIZADO', $produto(60.00, ['finNFe' => 4]), $agora);                    // devolucao
    $nota('NACIONAL', 'AUTORIZADO', $servico(700.00, $mesPassado->modify('-11 months')->format('Y-m-01')), $agora); // 12 meses antes: entra no RBT12

    $linha = array_values(array_filter((new FechamentoMes($pdo, $tpAmb))->montar($mes)['empresas'], static fn($l) => $l['cnpj'] === $cnpj))[0] ?? [];
    Apoio::igual([2, 1250.5], [$linha['nfse']['quantidade'] ?? null, $linha['nfse']['valor'] ?? null],
        'NFS-e: soma as autorizadas da competencia (cancelada, rejeitada e de outro mes ficam fora)');
    Apoio::igual([1, 749.5], [$linha['nfe']['quantidade'] ?? null, $linha['nfe']['valor'] ?? null], 'NF-e: soma o vNF das vendas (entrada e devolucao ficam fora)');
    Apoio::igual([2000.0, 1, 120.0], [$linha['receita_mes'] ?? null, $linha['canceladas'] ?? null, $linha['das_estimado'] ?? null],
        'receita do mes, canceladas a parte e DAS estimado (6%)');
    Apoio::igual(1000.0, $linha['receita_12_meses'] ?? null, 'receita dos 12 meses anteriores (competencia do mes passado e de 12 meses atras)');

    $passado = array_values(array_filter((new FechamentoMes($pdo, $tpAmb))->montar($mesPassado->format('Y-m'))['empresas'], static fn($l) => $l['cnpj'] === $cnpj))[0] ?? [];
    Apoio::igual(300.0, $passado['receita_mes'] ?? null, 'mes passado: a nota emitida agora com competencia do mes passado entra la');

    testesGuiasDas($pdo, $cfgGeral, $cnpj, $mes);
}

function testesGuiasDas(PDO $pdo, array $cfgGeral, string $cnpj, string $mes): void
{
    echo "\n[16] Guia DAS: contador anexa, cliente ve no portal\n";

    $guias = new GuiasDas($pdo, $cfgGeral);
    Apoio::verificar($guias->instalado(), 'script 0015: tabela fiscal_guias_das existe');

    // Pessoas da empresa no portal: 2 ativas e 1 desativada (nao recebe e-mail).
    $pdo->exec("INSERT INTO portal_usuarios (cnpj, nome, email, senha_hash, ativo) VALUES
        ('{$cnpj}', 'Maria Souza', 'maria@fechamento.com.br', 'x', TRUE),
        ('{$cnpj}', 'Joao Lima', 'joao@fechamento.com.br', 'x', TRUE),
        ('{$cnpj}', 'Antigo', 'antigo@fechamento.com.br', 'x', FALSE)");

    $pdf = "%PDF-1.4\n% guia de teste\n%%EOF";
    $dados = ['cnpj' => $cnpj, 'competencia' => $mes, 'valor' => '1.234,56', 'vencimento' => '2030-01-20',
        'linha_digitavel' => '85810000012 3 45670328192 6 61107000000 0 00000000000 0', 'avisar' => true];

    $r = $guias->anexar(['valor' => '0', 'vencimento' => '2030-02-30', 'linha_digitavel' => '123'] + $dados, 'nao e pdf', 'x.txt', 'ip');
    Apoio::igual('DADOS_INVALIDOS', $r['codigo'] ?? null, 'anexar: dados errados recusados');
    Apoio::igual(4, count($r['erros'] ?? []), 'anexar: explica valor, vencimento, codigo de barras e arquivo');
    Apoio::igual('EMPRESA_NAO_ENCONTRADA', $guias->anexar(['cnpj' => '99888777000155'] + $dados, $pdf, 'das.pdf', 'ip')['codigo'] ?? null,
        'anexar: empresa fora do cadastro recusada');

    $antesEmails = (int)$pdo->query('SELECT count(*) FROM sistema_emails')->fetchColumn();
    $r = $guias->anexar($dados, $pdf, 'das.pdf', 'ip');
    Apoio::verificar($r['ok'] && !$r['trocou'], 'anexar: guia gravada');
    Apoio::igual([1234.56, '2030-01-20', '858100000123456703281926611070000000000000000000'],
        [$r['guia']['valor'], $r['guia']['vencimento'], $r['guia']['linha_digitavel']], 'anexar: valor com virgula, vencimento e codigo so com numeros');
    Apoio::igual(2, $r['avisados'], 'anexar: avisa por e-mail so as pessoas ATIVAS da empresa');
    $email = $pdo->query("SELECT assunto, texto FROM sistema_emails ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    Apoio::verificar($antesEmails + 2 === (int)$pdo->query('SELECT count(*) FROM sistema_emails')->fetchColumn()
        && str_contains($email['assunto'], 'DAS') && str_contains($email['texto'], 'R$ 1.234,56') && str_contains($email['texto'], '20/01/2030'),
        'anexar: e-mail com valor e vencimento');
    Apoio::igual($pdf, $guias->pdf($r['guia']['id']), 'pdf: o arquivo volta igual');

    $r2 = $guias->anexar(['valor' => '1300', 'avisar' => false] + $dados, $pdf, 'das-retificado.pdf', 'ip');
    Apoio::verificar($r2['ok'] && $r2['trocou'] && $r2['guia']['id'] === $r['guia']['id'] && $r2['avisados'] === 0,
        'anexar de novo no mesmo mes: TROCA a guia (sem aviso quando desmarcado)');
    Apoio::igual(1, (int)$pdo->query("SELECT count(*) FROM fiscal_guias_das WHERE cnpj = '{$cnpj}'")->fetchColumn(), 'uma guia por empresa e mes');

    $linha = array_values(array_filter((new FechamentoMes($pdo, (int)$cfgGeral['tpAmb']))->montar($mes)['empresas'], static fn($l) => $l['cnpj'] === $cnpj))[0];
    Apoio::igual(1300.0, $linha['guia']['valor'] ?? null, 'fechamento do mes: mostra a guia anexada');

    $lista = $guias->daEmpresa($cnpj, '2030-01-20');
    Apoio::igual([1, 'VENCE_HOJE'], [count($lista), $lista[0]['situacao'] ?? null], 'portal: lista a guia da empresa, com a situacao');
    Apoio::igual(['A_VENCER', 'VENCIDA'], [$guias->daEmpresa($cnpj, '2030-01-19')[0]['situacao'], $guias->daEmpresa($cnpj, '2030-01-21')[0]['situacao']],
        'portal: a vencer / vencida pela data');
    Apoio::igual([], $guias->daEmpresa('41986662000160'), 'portal: outra empresa nao ve a guia');

    $acoes = $pdo->query("SELECT acao FROM fiscal_admin_log WHERE cnpj = '{$cnpj}' AND acao LIKE 'GUIA_DAS%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    Apoio::igual(['GUIA_DAS_ANEXADA', 'GUIA_DAS_TROCADA'], $acoes, 'historico: anexada e trocada');
    // ---- Marcar como paga (script 0016) ----
    $id = $r['guia']['id'];
    Apoio::verificar($guias->pagamentoInstalado(), 'script 0016: da para marcar como paga');
    Apoio::igual('NAO_ENCONTRADA', $guias->definirPagamento($id, '', 'Portal: X', 'ip', '41986662000160')['codigo'] ?? null,
        'pagar: portal de outra empresa nao mexe na guia');
    Apoio::igual('DATA_INVALIDA', $guias->definirPagamento($id, '2999-01-01', 'Ana', 'ip')['codigo'] ?? null, 'pagar: data no futuro recusada');
    $p = $guias->definirPagamento($id, '2026-01-15', 'Portal: Maria Souza <maria@fechamento.com.br>', 'ip', $cnpj);
    Apoio::igual(['2026-01-15', 'Portal: Maria Souza <maria@fechamento.com.br>'], [$p['guia']['pago_em'] ?? null, $p['guia']['pago_marcado_por'] ?? null],
        'pagar: cliente marca pelo portal (guarda data e quem marcou)');
    Apoio::igual('PAGA', $guias->daEmpresa($cnpj, '2031-01-01')[0]['situacao'], 'portal: paga nao aparece como vencida');
    $hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Bahia')))->format('Y-m-d');
    Apoio::igual($hoje, $guias->definirPagamento($id, '', 'Ana', 'ip')['guia']['pago_em'] ?? null, 'pagar: data vazia = hoje');
    $d = $guias->definirPagamento($id, null, 'Ana', 'ip');
    Apoio::igual([null, null, 'VENCIDA'], [$d['guia']['pago_em'], $d['guia']['pago_marcado_por'], $guias->daEmpresa($cnpj, '2031-01-01')[0]['situacao']],
        'desfazer: volta a nao paga');
    $guias->definirPagamento($id, '', 'Ana', 'ip');
    $guias->anexar(['avisar' => false] + $dados, $pdf, 'das-nova.pdf', 'ip');
    Apoio::igual(null, $guias->buscar($id)['pago_em'], 'trocar a guia: a nova volta a "nao paga"');
    Apoio::igual(3, (int)$pdo->query("SELECT count(*) FROM fiscal_admin_log WHERE cnpj = '{$cnpj}' AND acao = 'GUIA_DAS_PAGA'")->fetchColumn(),
        'historico: cada "pago" registrado');

    Apoio::verificar($guias->remover($r['guia']['id'], 'ip')['ok'] && $guias->daEmpresa($cnpj) === [], 'remover: some do portal');
    Apoio::igual('NAO_ENCONTRADA', $guias->remover($r['guia']['id'], 'ip')['codigo'] ?? null, 'remover: guia inexistente');
}
