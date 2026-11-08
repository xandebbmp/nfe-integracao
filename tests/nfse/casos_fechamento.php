<?php
declare(strict_types=1);

/**
 * Testes do Fechamento do mes (painel admin): receita por empresa para o
 * contador fazer a declaracao do Simples. Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\FechamentoMes;

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
}
