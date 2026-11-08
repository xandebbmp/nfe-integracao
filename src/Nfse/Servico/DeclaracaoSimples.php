<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\DeclaracaoSimplesRepositorio;
use Xande\NfeIntegracao\Nfse\Serpro\AcessoSerpro;
use Xande\NfeIntegracao\Nfse\Serpro\IntegraContador;

/**
 * Declaracao do Simples (PGDAS-D) e guia DAS pelo sistema
 * (Fechamento do mes > "Declarar e gerar DAS").
 *
 * Passo a passo (igual ao site do Simples, so que sem sair do painel):
 *   1. preparar()   a receita do mes vem das notas do sistema, separada em
 *                   servicos sem e com retencao de ISS e mercadorias. A
 *                   atividade e a folha de salarios vem da ultima declaracao.
 *   2. calcular()   manda ao SERPRO SEM transmitir: volta quanto e cada
 *                   imposto. Nada e gravado na Receita.
 *   3. transmitir() o contador conferiu e confirmou: transmite a declaracao
 *                   (OFICIAL), guarda o recibo, gera o DAS e anexa a guia
 *                   para o cliente (portal + e-mail, como o "Anexar guia").
 *
 * Modo TESTE (Sistema > Configuracoes): tudo vai ao ambiente de
 * demonstracao do SERPRO, que responde com dados de exemplo. A guia de
 * exemplo NAO e anexada para o cliente.
 */
final class DeclaracaoSimples
{
    /**
     * Servicos (NFS-e), "exceto para o exterior", ISS devido ao municipio da
     * empresa. Codigos da tabela de atividades do PGDASD (dados de dominio do
     * SERPRO): [nome, sem retencao de ISS, com retencao de ISS].
     */
    public const SERVICOS = [
        'ANEXO_III' => ['Anexo III, sem fator "r" (ex.: serralheria, manutenção, instalação)', 14, 15],
        'FATOR_R' => ['Sujeito ao fator "r" (Anexo III ou V, conforme a folha)', 11, 12],
        'ANEXO_IV' => ['Anexo IV (ex.: limpeza, vigilância, advocacia)', 17, 18],
        'CONSTRUCAO_III' => ['Construção civil (subitens 7.02 e 7.05), Anexo III', 20, 21],
        'CONSTRUCAO_IV' => ['Construção civil (subitens 7.02 e 7.05), Anexo IV', 23, 24],
    ];

    /** Mercadorias (NF-e), exceto para o exterior: id da atividade => nome. */
    public const MERCADORIAS = [
        1 => 'Revenda, sem substituição tributária',
        2 => 'Revenda, com substituição tributária',
        4 => 'Venda de produção própria (industrializada), sem substituição tributária',
        5 => 'Venda de produção própria (industrializada), com substituição tributária',
    ];

    /** Codigos dos tributos na resposta da Receita. */
    public const TRIBUTOS = [1001 => 'IRPJ', 1002 => 'CSLL', 1004 => 'COFINS', 1005 => 'PIS', 1006 => 'INSS (CPP)',
        1007 => 'ICMS', 1008 => 'IPI', 1010 => 'ISS'];

    private ClienteRepositorio $clientes;
    private DeclaracaoSimplesRepositorio $declaracoes;

    /** @param IntegraContador|null $clienteFixo so nos testes (SERPRO falso) */
    public function __construct(private PDO $pdo, private array $cfg, private ?IntegraContador $clienteFixo = null)
    {
        $this->clientes = new ClienteRepositorio($pdo);
        $this->declaracoes = new DeclaracaoSimplesRepositorio($pdo);
    }

    public function modo(): string
    {
        return (string)($this->cfg['serpro']['modo'] ?? '');
    }

    /** Tela de conferencia: receita do mes, sugestoes e o que ja foi declarado. */
    public function preparar(string $cnpj, string $competencia): array
    {
        $erro = $this->conferirBasico($cnpj, $competencia);
        if ($erro !== null) {
            return $erro;
        }
        $empresa = $this->clientes->buscar($cnpj);
        $receita = $this->clientes->receitaSimples($cnpj, $competencia, (int)($this->cfg['tpAmb'] ?? 2));
        $ultima = $this->declaracoes->ultima($cnpj);
        $doMes = $this->declaracoes->doMes($cnpj, $competencia);
        $anterior = $ultima['entrada'] ?? [];

        // Folha de salarios dos 12 meses anteriores: o que foi informado antes (ou zero).
        $folhas = [];
        foreach (self::mesesAnteriores($competencia) as $pa) {
            $folhas[$pa] = (float)($anterior['folhas'][$pa] ?? 0);
        }
        $declaradoDeVerdade = array_filter($doMes, static fn($d) => !$d['teste']);

        return [
            'ok' => true,
            'modo' => $this->modo(),
            'empresa' => ['cnpj' => $cnpj, 'razao_social' => $empresa['razao_social'] ?? '', 'municipio' => $empresa['codigo_municipio'] ?? ''],
            'competencia' => $competencia,
            'receita' => $receita,
            'sugestao' => [
                'tipo_declaracao' => $declaradoDeVerdade !== [] ? 2 : 1,
                'servico' => (string)($anterior['servico'] ?? 'ANEXO_III'),
                'mercadoria' => (int)($anterior['mercadoria'] ?? 1),
                'folhas' => $folhas,
            ],
            'declaracoes_do_mes' => $doMes,
            'servicos' => array_map(static fn($s) => $s[0], self::SERVICOS),
            'mercadorias' => self::MERCADORIAS,
        ];
    }

    /** Passo 2: so calcula (nada vai para a Receita). */
    public function calcular(array $entrada): array
    {
        [$declaracao, $erro] = $this->montar($entrada);
        if ($erro !== null) {
            return $erro;
        }
        $cliente = $this->cliente();
        if (!$cliente instanceof IntegraContador) {
            return $cliente;
        }
        $r = $cliente->declarar($entrada['cnpj'], $entrada['competencia'], $declaracao, false);
        if (!$r['ok']) {
            return self::falha(502, 'SERPRO_ERRO', $r['mensagem']);
        }
        return ['ok' => true, 'teste' => $r['teste'], 'tributos' => self::nomes($r['tributos']),
            'valor_devido' => $r['valor_devido'], 'avisos' => $r['avisos'], 'declaracao' => $declaracao];
    }

    /**
     * Passo 3: TRANSMITE (oficial), guarda o recibo e gera/anexa o DAS.
     *
     * @param list<array{codigo: int, valor: float}> $conferidos valores que o contador viu no passo 2
     */
    public function transmitir(array $entrada, array $conferidos, bool $avisar, string $ip): array
    {
        if (!$this->declaracoes->existe()) {
            return self::falha(503, 'BANCO_DESATUALIZADO', 'Falta atualizar o banco (script 0017). Abra Sistema > Atualizar banco.');
        }
        [$declaracao, $erro] = $this->montar($entrada);
        if ($erro !== null) {
            return $erro;
        }
        $cliente = $this->cliente();
        if (!$cliente instanceof IntegraContador) {
            return $cliente;
        }
        $cnpj = $entrada['cnpj'];
        $competencia = $entrada['competencia'];

        $d = $cliente->declarar($cnpj, $competencia, $declaracao, true, $conferidos);
        $this->clientes->registrarLog($cnpj, $cliente->teste() ? 'SERPRO_DECLARACAO_TESTE' : 'SERPRO_DECLARACAO',
            ['competencia' => $competencia, 'ok' => $d['ok'], 'numero' => $d['numero_declaracao'] ?? null, 'mensagem' => $d['mensagem'] ?? null], $ip);
        if (!$d['ok']) {
            return self::falha(502, 'SERPRO_ERRO', $d['mensagem'] . ' Nada foi transmitido.');
        }
        $id = $this->declaracoes->salvar($cnpj, $competencia, $d['teste'], (int)$entrada['tipo_declaracao'], self::limpar($entrada),
            $d['tributos'], $d['valor_devido'], $d['numero_declaracao'], $d['transmitida_em'], $d['recibo_pdf'], $d['declaracao_pdf'],
            ClienteRepositorio::$autor);

        // Com a declaracao transmitida, pede o DAS do mes.
        $das = $cliente->gerarDas($cnpj, $competencia);
        $guia = null;
        $avisados = 0;
        if ($das['ok'] && !$das['teste']) {
            $anexo = (new GuiasDas($this->pdo, $this->cfg))->anexar([
                'cnpj' => $cnpj, 'competencia' => $competencia, 'valor' => $das['valor'], 'vencimento' => $das['vencimento'],
                'linha_digitavel' => '', 'avisar' => $avisar,
            ], $das['pdf'], "das-{$competencia}.pdf", $ip);
            $guia = $anexo['ok'] ? $anexo['guia'] : null;
            $avisados = (int)($anexo['avisados'] ?? 0);
            if (!$anexo['ok']) {
                $das = ['ok' => false, 'mensagem' => 'A guia veio, mas não foi anexada: ' . $anexo['mensagem']];
            }
        }
        $this->clientes->registrarLog($cnpj, $cliente->teste() ? 'SERPRO_DAS_TESTE' : 'SERPRO_DAS',
            ['competencia' => $competencia, 'ok' => $das['ok'], 'mensagem' => $das['mensagem'] ?? null], $ip);

        return [
            'ok' => true,
            'teste' => $d['teste'],
            'declaracao' => [
                'id' => $id, 'numero' => $d['numero_declaracao'], 'transmitida_em' => $d['transmitida_em'],
                'tributos' => self::nomes($d['tributos']), 'valor_devido' => $d['valor_devido'], 'avisos' => $d['avisos'],
                'tem_recibo' => $d['recibo_pdf'] !== null, 'tem_declaracao' => $d['declaracao_pdf'] !== null, 'tem_multa' => $d['multa_pdf'] !== null,
            ],
            'das' => $das['ok']
                ? ['ok' => true, 'valor' => $das['valor'], 'vencimento' => $das['vencimento'], 'numero_documento' => $das['numero_documento'],
                    'pdf_base64' => base64_encode($das['pdf']), 'anexada' => $guia !== null, 'avisados' => $avisados]
                : ['ok' => false, 'mensagem' => $das['mensagem']],
        ];
    }

    /**
     * Confere a tela e monta o objeto "declaracao" do PGDASD.
     *
     * Entrada: cnpj, competencia (AAAA-MM), tipo_declaracao (1/2),
     *   servico (chave de SERVICOS), servicos_sem_retencao, servicos_com_retencao,
     *   mercadoria (id de MERCADORIAS), mercadorias, folhas {AAAAMM: valor}
     *
     * @return array{0: ?array, 1: ?array} [declaracao, erro]
     */
    public function montar(array &$entrada): array
    {
        $entrada['cnpj'] = NfseConfig::normalizarCnpj((string)($entrada['cnpj'] ?? ''));
        $entrada['competencia'] = (string)($entrada['competencia'] ?? '');
        $basico = $this->conferirBasico($entrada['cnpj'], $entrada['competencia']);
        if ($basico !== null) {
            return [null, $basico];
        }
        $erros = [];
        $tipo = (int)($entrada['tipo_declaracao'] ?? 1);
        if (!in_array($tipo, [1, 2], true)) {
            $erros[] = 'Escolha se a declaração é original ou retificadora.';
        }
        $semRet = self::numero($entrada['servicos_sem_retencao'] ?? 0);
        $comRet = self::numero($entrada['servicos_com_retencao'] ?? 0);
        $merc = self::numero($entrada['mercadorias'] ?? 0);
        if ($semRet === null || $comRet === null || $merc === null) {
            $erros[] = 'Os valores de receita precisam ser números (zero ou mais).';
        }
        $servico = self::SERVICOS[(string)($entrada['servico'] ?? '')] ?? null;
        if (($semRet > 0 || $comRet > 0) && $servico === null) {
            $erros[] = 'Escolha a atividade dos serviços.';
        }
        $mercadoria = (int)($entrada['mercadoria'] ?? 0);
        if ($merc > 0 && !isset(self::MERCADORIAS[$mercadoria])) {
            $erros[] = 'Escolha a atividade das mercadorias.';
        }
        $folhas = [];
        foreach (self::mesesAnteriores($entrada['competencia']) as $pa) {
            $valor = self::numero($entrada['folhas'][$pa] ?? 0);
            if ($valor === null) {
                $erros[] = 'A folha de salários de ' . substr($pa, 4, 2) . '/' . substr($pa, 0, 4) . ' precisa ser um número.';
                continue;
            }
            $folhas[$pa] = $valor;
        }
        if ($erros !== []) {
            return [null, self::falha(422, 'DADOS_INVALIDOS', implode(' ', $erros), ['erros' => $erros])];
        }
        $entrada['tipo_declaracao'] = $tipo;
        $entrada['folhas'] = $folhas;

        $atividades = [];
        $servicos = round($semRet + $comRet, 2);
        if ($semRet > 0) {
            $atividades[] = self::atividade($servico[1], $semRet);
        }
        if ($comRet > 0) {
            $atividades[] = self::atividade($servico[2], $comRet);
        }
        if ($merc > 0) {
            $atividades[] = self::atividade($mercadoria, $merc);
        }

        $estabelecimento = ['cnpjCompleto' => $entrada['cnpj']];
        if ($atividades !== []) {
            $estabelecimento['atividades'] = $atividades; // sem receita: a lista nao vai
        }
        $declaracao = [
            'tipoDeclaracao' => $tipo,
            'receitaPaCompetenciaInterno' => round($servicos + $merc, 2),
            'receitaPaCompetenciaExterno' => 0,
            'folhasSalario' => array_map(static fn($pa, $v) => ['pa' => (int)$pa, 'valor' => $v], array_keys($folhas), $folhas),
            'estabelecimentos' => [$estabelecimento],
        ];
        return [$declaracao, null];
    }

    /** Os 12 meses antes da competencia, em AAAAMM (o mais antigo primeiro). */
    public static function mesesAnteriores(string $competencia): array
    {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m', $competencia);
        if ($inicio === false) {
            return [];
        }
        $meses = [];
        for ($i = 12; $i >= 1; $i--) {
            $meses[] = $inicio->modify("-{$i} months")->format('Ym');
        }
        return $meses;
    }

    private static function atividade(int $id, float $valor): array
    {
        return ['idAtividade' => $id, 'valorAtividade' => $valor, 'receitasAtividade' => [['valor' => $valor]]];
    }

    private function conferirBasico(string $cnpj, string $competencia): ?array
    {
        if (!in_array($this->modo(), ['TESTE', 'PRODUCAO'], true)) {
            return self::falha(409, 'SERPRO_DESLIGADO', 'A integração com a Receita está desligada. Ligue em Sistema > Configurações > Integração com a Receita.');
        }
        if (strlen($cnpj) !== 14 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia)) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Informe a empresa e o mês (AAAA-MM).');
        }
        if ($this->modo() === 'PRODUCAO' && $competencia >= date('Y-m')) {
            return self::falha(422, 'MES_ABERTO', 'Só dá para declarar um mês que já terminou.');
        }
        $empresa = $this->clientes->buscar($cnpj);
        if ($empresa === null) {
            return self::falha(404, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não encontrada no cadastro.');
        }
        if ((int)($empresa['opcao_simples_nacional'] ?? 0) !== 3) {
            return self::falha(422, 'NAO_E_ME_EPP', 'Só empresas ME/EPP do Simples fazem esta declaração (o MEI tem o DAS-MEI).');
        }
        return null;
    }

    /** Cliente do SERPRO no modo escolhido (na producao, com as chaves do painel). */
    private function cliente(): IntegraContador|array
    {
        if ($this->clienteFixo !== null) {
            return $this->clienteFixo;
        }
        if ($this->modo() !== 'PRODUCAO') {
            return new IntegraContador('TESTE');
        }
        try {
            $credenciais = (new AcessoSerpro($this->pdo, (string)($this->cfg['chave_certificados'] ?? '')))->credenciais();
        } catch (Throwable $e) {
            return self::falha(409, 'SERPRO_SEM_ACESSO', $e->getMessage());
        }
        return new IntegraContador('PRODUCAO', $credenciais);
    }

    /** Guarda so o que a tela mandou (sem campos estranhos). */
    private static function limpar(array $e): array
    {
        return array_intersect_key($e, array_flip(['tipo_declaracao', 'servico', 'servicos_sem_retencao', 'servicos_com_retencao',
            'mercadoria', 'mercadorias', 'folhas']));
    }

    /** [{codigo, valor}] -> [{codigo, nome, valor}] */
    private static function nomes(array $tributos): array
    {
        return array_map(static fn($t) => $t + ['nome' => self::TRIBUTOS[$t['codigo']] ?? (string)$t['codigo']], $tributos);
    }

    /** "1.234,56", "1234.56" ou numero -> float (null se nao for numero ou negativo). */
    private static function numero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return $valor >= 0 ? round((float)$valor, 2) : null;
        }
        $texto = trim((string)$valor);
        if ($texto === '') {
            return 0.0;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }
        return is_numeric($texto) && (float)$texto >= 0 ? round((float)$texto, 2) : null;
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }
}
