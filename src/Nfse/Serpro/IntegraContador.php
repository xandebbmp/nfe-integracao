<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Serpro;

use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;

/**
 * Cliente da API Integra Contador (SERPRO / Receita Federal), servico PGDASD
 * (declaracao mensal do Simples Nacional e guia DAS).
 *
 * Dois modos (Sistema > Configuracoes > "Gerar DAS pela Receita"):
 *
 *   TESTE     ambiente de demonstracao do SERPRO: gratuito, sem contrato.
 *             NAO fala com a Receita de verdade: so responde para os dados de
 *             exemplo dele (CNPJ 00000000000100, mes 01/2018). Por isso, no
 *             teste, o CNPJ e o mes pedidos sao trocados pelos de exemplo.
 *   PRODUCAO  Receita de verdade, cobrado por chamada. Precisa do contrato:
 *             Consumer Key/Secret + certificado e-CNPJ de quem contratou
 *             (cadastrados pelo painel, ver AcessoSerpro) e a procuracao
 *             eletronica de cada cliente para esse CNPJ no e-CAC.
 *
 * Login da producao (OAuth2 + certificado, "mTLS"):
 *   POST https://autenticacao.sapi.serpro.gov.br/authenticate
 *   Authorization: Basic base64(key:secret) / role-type: TERCEIROS
 *   corpo grant_type=client_credentials -> { access_token, jwt_token, expires_in }
 *
 * Toda chamada usa o mesmo envelope:
 *   POST {url}/Declarar (ou /Emitir)
 *   { contratante, autorPedidoDados, contribuinte,
 *     pedidoDados: { idSistema, idServico, versaoSistema, dados (JSON em texto) } }
 * e a resposta traz { status, mensagens[], dados (JSON em texto) }.
 */
final class IntegraContador
{
    public const URL_TESTE = 'https://gateway.apiserpro.serpro.gov.br/integra-contador-trial/v1';
    public const URL_PRODUCAO = 'https://gateway.apiserpro.serpro.gov.br/integra-contador/v1';
    public const URL_LOGIN = 'https://autenticacao.sapi.serpro.gov.br/authenticate';
    /** Token publico do ambiente de demonstracao do SERPRO (o mesmo para todos). */
    public const TOKEN_TESTE = '06aef429-a981-3ec5-a1f8-71d38d86481e';
    /** Dados de exemplo do SERPRO (cenarios PGDASD). Outros valores dao "Dados invalidos". */
    public const CONTRATANTE_TESTE = '00000000000100';
    public const CONTRIBUINTE_TESTE = '00000000000100';
    public const PERIODO_TESTE = '201801';
    /** Pedido de exemplo do cenario "Entregar Declaracao" (PGDASD) do SERPRO, sem mudar nada. */
    public const DADOS_DECLARACAO_TESTE = [
        'cnpjCompleto' => '00000000000100', 'pa' => 202101, 'indicadorTransmissao' => true, 'indicadorComparacao' => true,
        'declaracao' => [
            'tipoDeclaracao' => 1, 'receitaPaCompetenciaInterno' => 10000.00, 'receitaPaCompetenciaExterno' => 0.00,
            'receitaPaCaixaInterno' => null, 'receitaPaCaixaExterno' => null, 'valorFixoIcms' => 100.00, 'valorFixoIss' => null,
            'receitasBrutasAnteriores' => [
                ['pa' => 202001, 'valorInterno' => 100.00, 'valorExterno' => 200.00], ['pa' => 202002, 'valorInterno' => 300.00, 'valorExterno' => 0.00],
                ['pa' => 202003, 'valorInterno' => 0.00, 'valorExterno' => 0.00], ['pa' => 202004, 'valorInterno' => 0.00, 'valorExterno' => 0.00],
                ['pa' => 202005, 'valorInterno' => 0.00, 'valorExterno' => 0.00], ['pa' => 202006, 'valorInterno' => 0.00, 'valorExterno' => 0.00],
                ['pa' => 202007, 'valorInterno' => 0.00, 'valorExterno' => 0.00], ['pa' => 202008, 'valorInterno' => 0.00, 'valorExterno' => 0.00],
                ['pa' => 202009, 'valorInterno' => 0.00, 'valorExterno' => 0.00], ['pa' => 202010, 'valorInterno' => 0.00, 'valorExterno' => 0.00],
                ['pa' => 202011, 'valorInterno' => 0.00, 'valorExterno' => 0.00], ['pa' => 202012, 'valorInterno' => 0.00, 'valorExterno' => 0.00],
            ],
            'folhasSalario' => [
                ['pa' => 202001, 'valor' => 2000.00], ['pa' => 202002, 'valor' => 2000.00], ['pa' => 202003, 'valor' => 2000.00],
                ['pa' => 202004, 'valor' => 2000.00], ['pa' => 202005, 'valor' => 0.00], ['pa' => 202006, 'valor' => 0.00],
                ['pa' => 202007, 'valor' => 0.00], ['pa' => 202008, 'valor' => 0.00], ['pa' => 202009, 'valor' => 0.00],
                ['pa' => 202010, 'valor' => 0.00], ['pa' => 202011, 'valor' => 0.00], ['pa' => 202012, 'valor' => 0.00],
            ],
            'naoOptante' => null,
            'estabelecimentos' => [[
                'cnpjCompleto' => '0000000000100',
                'atividades' => [
                    ['idAtividade' => 1, 'valorAtividade' => 4000.00, 'receitasAtividade' => [[
                        'valor' => 4000.00, 'codigoOutroMunicipio' => null, 'outraUf' => null,
                        'isencoes' => [['codTributo' => 1007, 'valor' => 100.00, 'identificador' => 1]],
                        'reducoes' => [['codTributo' => 1007, 'valor' => 1500.00, 'percentualReducao' => 50.00, 'identificador' => 1]],
                        'qualificacoesTributarias' => [], 'exigibilidadesSuspensas' => null]]],
                    ['idAtividade' => 10, 'valorAtividade' => 6000.00, 'receitasAtividade' => [[
                        'valor' => 6000.00, 'codigoOutroMunicipio' => 9701, 'outraUf' => 'DF', 'isencoes' => null,
                        'reducoes' => null, 'qualificacoesTributarias' => null, 'exigibilidadesSuspensas' => null]]],
                ],
            ]],
        ],
        'valoresParaComparacao' => [
            ['codigoTributo' => 1001, 'valor' => 23.20], ['codigoTributo' => 1002, 'valor' => 18.20], ['codigoTributo' => 1004, 'valor' => 66.53],
            ['codigoTributo' => 1005, 'valor' => 14.43], ['codigoTributo' => 1006, 'valor' => 222.64], ['codigoTributo' => 1007, 'valor' => 100.00],
            ['codigoTributo' => 1010, 'valor' => 120.60],
        ],
    ];

    private TransporteHttp $http;
    private ?array $tokens = null;

    /**
     * @param string     $modo         'TESTE' ou 'PRODUCAO'
     * @param array|null $credenciais  so na PRODUCAO: AcessoSerpro::credenciais()
     *                                 {key, secret, certificado: CertificadoCliente, cnpj}
     */
    public function __construct(
        private string $modo = 'TESTE',
        private ?array $credenciais = null,
        ?TransporteHttp $http = null
    ) {
        $certificado = $credenciais['certificado'] ?? null;
        $this->http = $http ?? new CurlTransporte($certificado instanceof CertificadoCliente ? $certificado : null, 60);
    }

    public function teste(): bool
    {
        return $this->modo !== 'PRODUCAO';
    }

    /**
     * Declaracao do mes (PGDASD / TRANSDECLARACAO11).
     *
     *   $transmitir = false  so CALCULA: a Receita devolve os valores devidos
     *                        e nada e gravado (use para o contador conferir).
     *   $transmitir = true   TRANSMITE: e DECLARACAO OFICIAL. Mande junto os
     *                        valores que o contador conferiu ($conferidos): se
     *                        a Receita calcular diferente (1 centavo que seja),
     *                        ela recusa e nada e gravado.
     *
     * No TESTE o SERPRO so aceita o pedido de exemplo dele: o que vai e
     * DADOS_DECLARACAO_TESTE (a resposta tambem e de exemplo).
     *
     * @param array $declaracao objeto "declaracao" (DeclaracaoSimples::montar())
     * @param list<array{codigo: int, valor: float}> $conferidos
     * @return array ok=true: tributos [{codigo, valor}], valor_devido, numero_declaracao, transmitida_em,
     *               recibo_pdf, declaracao_pdf, multa_pdf, avisos, teste
     *               ok=false: mensagem
     */
    public function declarar(string $cnpj, string $competencia, array $declaracao, bool $transmitir, array $conferidos = []): array
    {
        if ($this->teste()) {
            $contribuinte = self::CONTRIBUINTE_TESTE;
            $dados = self::DADOS_DECLARACAO_TESTE;
        } else {
            $contribuinte = $cnpj;
            $dados = [
                'cnpjCompleto' => $cnpj,
                'pa' => (int)str_replace('-', '', $competencia),
                'indicadorTransmissao' => $transmitir,
                'indicadorComparacao' => $transmitir,
                'declaracao' => $declaracao,
            ];
            if ($transmitir) {
                $dados['valoresParaComparacao'] = array_map(
                    static fn(array $v) => ['codigoTributo' => (int)$v['codigo'], 'valor' => round((float)$v['valor'], 2)], $conferidos);
            }
        }
        $r = $this->chamar('/Declarar', $contribuinte, 'PGDASD', 'TRANSDECLARACAO11', $dados);
        if (!$r['ok']) {
            return $r;
        }
        $d = $r['dados'];
        $tributos = [];
        foreach (($d['valoresDevidos'] ?? []) as $v) {
            $tributos[] = ['codigo' => (int)($v['codigoTributo'] ?? 0), 'valor' => round((float)($v['valor'] ?? 0), 2)];
        }
        // Avisos da Receita (ex.: entregue fora do prazo, com multa).
        $avisos = [];
        foreach ($r['mensagens'] as $m) {
            if (!str_contains((string)($m['codigo'] ?? ''), 'Sucesso')) {
                $avisos[] = trim((string)($m['texto'] ?? ''));
            }
        }
        return [
            'ok' => true,
            'teste' => $this->teste(),
            'tributos' => $tributos,
            'valor_devido' => round(array_sum(array_column($tributos, 'valor')), 2),
            'numero_declaracao' => (string)($d['idDeclaracao'] ?? ''),
            'transmitida_em' => (string)($d['dataHoraTransmissao'] ?? ''),
            'recibo_pdf' => self::pdf($d['recibo'] ?? null),
            'declaracao_pdf' => self::pdf($d['declaracao'] ?? null),
            'multa_pdf' => self::pdf($d['darf'] ?? null),
            'avisos' => array_values(array_filter($avisos)),
        ];
    }

    /**
     * Gera o DAS de uma declaracao JA TRANSMITIDA (PGDASD / GERARDAS12).
     *
     * @param string $competencia AAAA-MM (mes da receita)
     * @return array ok=true: numero_documento, valor, vencimento (AAAA-MM-DD), pdf (conteudo), teste
     *               ok=false: mensagem
     */
    public function gerarDas(string $cnpj, string $competencia): array
    {
        $contribuinte = $this->teste() ? self::CONTRIBUINTE_TESTE : $cnpj;
        $pa = $this->teste() ? self::PERIODO_TESTE : str_replace('-', '', $competencia);
        $r = $this->chamar('/Emitir', $contribuinte, 'PGDASD', 'GERARDAS12', ['periodoApuracao' => $pa]);
        if (!$r['ok']) {
            return $r;
        }

        $das = $r['dados'][0] ?? null;
        $det = is_array($das) ? ($das['detalhamentoDas'] ?? null) : null;
        if (!is_array($det) || empty($das['pdf'])) {
            return self::falha('A Receita respondeu sem a guia. ' . $r['texto_mensagens']);
        }
        $pdf = self::pdf($das['pdf']);
        if ($pdf === null) {
            return self::falha('A guia veio num formato inesperado (não é PDF).');
        }

        return [
            'ok' => true,
            'teste' => $this->teste(),
            'cnpj_pedido' => $cnpj,
            'competencia_pedida' => $competencia,
            'numero_documento' => (string)($det['numeroDocumento'] ?? ''),
            'valor' => round((float)($det['valores']['total'] ?? 0), 2),
            'vencimento' => self::data((string)($det['dataVencimento'] ?? '')),
            'pdf' => $pdf,
            'mensagens' => $r['mensagens'],
        ];
    }

    /** Antigo nome (modo teste). */
    public function gerarDasTeste(string $cnpj, string $competencia): array
    {
        return $this->gerarDas($cnpj, $competencia);
    }

    /**
     * So o login da producao: confere chaves e certificado sem gastar chamada
     * de servico.
     */
    public function testarLogin(): array
    {
        if ($this->teste()) {
            return ['ok' => true, 'mensagem' => 'Modo teste: não precisa de login.'];
        }
        $t = $this->login();
        return $t['ok'] ? ['ok' => true, 'mensagem' => 'Login no SERPRO funcionou. As chaves e o certificado estão certos.'] : $t;
    }

    /** Tokens de acesso: fixos no teste; na producao, login com certificado (guardado durante este pedido). */
    private function login(): array
    {
        if ($this->teste()) {
            return ['ok' => true, 'access' => self::TOKEN_TESTE, 'jwt' => self::TOKEN_TESTE];
        }
        if ($this->tokens !== null) {
            return $this->tokens;
        }
        $c = $this->credenciais ?? [];
        if (($c['key'] ?? '') === '' || ($c['secret'] ?? '') === '' || !($c['certificado'] ?? null) instanceof CertificadoCliente) {
            return self::falha('Falta cadastrar o acesso ao SERPRO (chaves e certificado do escritório) em Sistema > Integração com a Receita.');
        }
        $resposta = $this->http->enviar('POST', self::URL_LOGIN, [
            'Authorization' => 'Basic ' . base64_encode($c['key'] . ':' . $c['secret']),
            'role-type' => 'TERCEIROS',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');
        if ($resposta->status === 0) {
            return self::falha('Sem resposta do login do SERPRO (' . ($resposta->erroRede ?? 'rede') . '). Confira a internet e tente de novo.');
        }
        $json = json_decode($resposta->corpo, true);
        if (!$resposta->sucesso() || empty($json['access_token']) || empty($json['jwt_token'])) {
            $motivo = $resposta->status === 401 ? 'confira a Consumer Key, o Consumer Secret e se o certificado é o de quem contratou'
                : (string)($json['error_description'] ?? $json['message'] ?? 'sem detalhes');
            return self::falha("O SERPRO recusou o login (HTTP {$resposta->status}): {$motivo}.");
        }
        return $this->tokens = ['ok' => true, 'access' => (string)$json['access_token'], 'jwt' => (string)$json['jwt_token']];
    }

    /**
     * Monta o envelope, envia e confere a resposta.
     *
     * @return array ok=true: dados (JSON ja decodificado), mensagens, texto_mensagens | ok=false: mensagem
     */
    private function chamar(string $rota, string $contribuinte, string $sistema, string $servico, array $dados): array
    {
        $tokens = $this->login();
        if (!$tokens['ok']) {
            return $tokens;
        }
        // Contratante e autor: o escritorio que contratou (no teste, o de exemplo).
        $contratante = $this->teste() ? self::CONTRATANTE_TESTE : (string)$this->credenciais['cnpj'];
        $corpo = [
            'contratante' => ['numero' => $contratante, 'tipo' => self::tipo($contratante)],
            'autorPedidoDados' => ['numero' => $contratante, 'tipo' => self::tipo($contratante)],
            'contribuinte' => ['numero' => $contribuinte, 'tipo' => self::tipo($contribuinte)],
            'pedidoDados' => [
                'idSistema' => $sistema,
                'idServico' => $servico,
                'versaoSistema' => '1.0',
                'dados' => json_encode($dados, JSON_PRESERVE_ZERO_FRACTION), // a API pede o "dados" como TEXTO com JSON dentro
            ],
        ];
        $resposta = $this->http->enviar('POST', ($this->teste() ? self::URL_TESTE : self::URL_PRODUCAO) . $rota, [
            'Authorization' => 'Bearer ' . $tokens['access'],
            'jwt_token' => $tokens['jwt'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], (string)json_encode($corpo));

        if ($resposta->status === 0) {
            return self::falha('Sem resposta do SERPRO (' . ($resposta->erroRede ?? 'rede') . '). Confira a internet e tente de novo.');
        }
        $json = json_decode($resposta->corpo, true);
        $mensagens = is_array($json['mensagens'] ?? null) ? $json['mensagens'] : [];
        $texto = implode(' ', array_map(static fn($m) => trim(($m['codigo'] ?? '') . ' ' . ($m['texto'] ?? '')), $mensagens));
        if (!$resposta->sucesso() || !is_array($json)) {
            return self::falha("O SERPRO recusou o pedido (HTTP {$resposta->status}). " . ($texto !== '' ? $texto : 'Sem detalhes.'));
        }
        $conteudo = $json['dados'] ?? '';
        $decodificado = is_string($conteudo) ? json_decode($conteudo, true) : $conteudo;
        return ['ok' => true, 'dados' => is_array($decodificado) ? $decodificado : [], 'mensagens' => $mensagens, 'texto_mensagens' => $texto];
    }

    /** PDF em base64 -> conteudo (null se nao for PDF). */
    private static function pdf(mixed $base64): ?string
    {
        if (!is_string($base64) || $base64 === '') {
            return null;
        }
        $pdf = base64_decode($base64, true);
        return $pdf !== false && str_starts_with($pdf, '%PDF-') ? $pdf : null;
    }

    /** Tipo do documento no envelope: 1 = CPF (11 digitos), 2 = CNPJ. */
    private static function tipo(string $documento): int
    {
        return strlen($documento) === 11 ? 1 : 2;
    }

    /** "20210222" ou "2021-02-22" -> "2021-02-22" (vazio se nao reconhecer). */
    private static function data(string $texto): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $texto, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $texto) ? substr($texto, 0, 10) : '';
    }

    private static function falha(string $mensagem): array
    {
        return ['ok' => false, 'mensagem' => trim($mensagem)];
    }
}
