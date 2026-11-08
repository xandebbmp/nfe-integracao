<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Serpro;

use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;

/**
 * Cliente da API Integra Contador (SERPRO / Receita Federal).
 *
 * Hoje so o modo TESTE: o ambiente de demonstracao do SERPRO, gratuito e
 * sem contrato. Ele NAO fala com a Receita de verdade: responde sempre com
 * dados de exemplo para CNPJs de teste. Serve para provar que o caminho
 * funciona antes de contratar a producao.
 *
 * Producao (proxima etapa, depende do contrato com o SERPRO): exige
 * consumer key/secret, certificado e-CNPJ do escritorio (mTLS) e procuracao
 * eletronica de cada cliente. NAO esta implementada aqui.
 *
 * Toda chamada usa o mesmo envelope:
 *   POST {url}/Emitir   (ou /Declarar, /Consultar)
 *   { contratante, autorPedidoDados, contribuinte,
 *     pedidoDados: { idSistema, idServico, versaoSistema, dados (JSON em texto) } }
 * e a resposta traz { status, mensagens[], dados (JSON em texto) }.
 */
final class IntegraContador
{
    public const URL_TESTE = 'https://gateway.apiserpro.serpro.gov.br/integra-contador-trial/v1';
    /** Token publico do ambiente de demonstracao do SERPRO (o mesmo para todos). */
    public const TOKEN_TESTE = '06aef429-a981-3ec5-a1f8-71d38d86481e';
    /** No modo teste o SERPRO so responde para os dados de exemplo dele. */
    public const CONTRATANTE_TESTE = '00000000000191';
    public const CONTRIBUINTE_TESTE = '00000000000100';
    public const PERIODO_TESTE = '202101';

    private TransporteHttp $http;

    public function __construct(?TransporteHttp $http = null)
    {
        $this->http = $http ?? new CurlTransporte(null, 60);
    }

    /**
     * Gera o DAS de uma declaracao do Simples JA TRANSMITIDA (PGDASD / GERARDAS12).
     *
     * No modo teste o CNPJ e o mes sao trocados pelos de exemplo do SERPRO.
     *
     * @param string $cnpj        CNPJ do cliente (contribuinte)
     * @param string $competencia AAAA-MM (mes da receita)
     * @return array ok=true: numero_documento, valor, vencimento (AAAA-MM-DD), pdf (conteudo), teste
     *               ok=false: mensagem
     */
    public function gerarDasTeste(string $cnpj, string $competencia): array
    {
        $dados = ['periodoApuracao' => self::PERIODO_TESTE];
        $r = $this->chamar('/Emitir', self::CONTRIBUINTE_TESTE, 'PGDASD', 'GERARDAS12', $dados);
        if (!$r['ok']) {
            return $r;
        }

        $das = $r['dados'][0] ?? null;
        $det = is_array($das) ? ($das['detalhamentoDas'] ?? null) : null;
        if (!is_array($det) || empty($das['pdf'])) {
            return self::falha('A Receita respondeu sem a guia. ' . $r['texto_mensagens']);
        }
        $pdf = base64_decode((string)$das['pdf'], true);
        if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
            return self::falha('A guia veio num formato inesperado (não é PDF).');
        }

        return [
            'ok' => true,
            'teste' => true,
            'cnpj_pedido' => $cnpj,
            'competencia_pedida' => $competencia,
            'numero_documento' => (string)($det['numeroDocumento'] ?? ''),
            'valor' => round((float)($det['valores']['total'] ?? 0), 2),
            'vencimento' => self::data((string)($det['dataVencimento'] ?? '')),
            'pdf' => $pdf,
            'mensagens' => $r['mensagens'],
        ];
    }

    /**
     * Monta o envelope, envia e confere a resposta.
     *
     * @return array ok=true: dados (JSON ja decodificado), mensagens, texto_mensagens | ok=false: mensagem
     */
    private function chamar(string $rota, string $contribuinte, string $sistema, string $servico, array $dados): array
    {
        $corpo = [
            'contratante' => ['numero' => self::CONTRATANTE_TESTE, 'tipo' => 2],
            'autorPedidoDados' => ['numero' => self::CONTRATANTE_TESTE, 'tipo' => 2],
            'contribuinte' => ['numero' => $contribuinte, 'tipo' => strlen($contribuinte) === 11 ? 1 : 2],
            'pedidoDados' => [
                'idSistema' => $sistema,
                'idServico' => $servico,
                'versaoSistema' => '1.0',
                'dados' => json_encode($dados), // a API pede o "dados" como TEXTO com JSON dentro
            ],
        ];
        $resposta = $this->http->enviar('POST', self::URL_TESTE . $rota, [
            'Authorization' => 'Bearer ' . self::TOKEN_TESTE,
            'jwt_token' => self::TOKEN_TESTE,
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
