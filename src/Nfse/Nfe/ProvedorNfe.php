<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nfe;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use NFePHP\Common\Exception\ValidatorException;
use NFePHP\DA\NFe\Danfe;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;
use RuntimeException;
use Throwable;
use Xande\NfeIntegracao\Nfse\Contrato\ProvedorNfse;
use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;

/**
 * NF-e modelo 55 (produtos), autorizada pela SEFAZ do estado do emitente.
 *
 * Usa a mesma biblioteca e as mesmas chamadas dos scripts homologados
 * (public/assinar_enviar_sincrono.php, cancelar.php, cce.php):
 *   - envio SINCRONO (indSinc = 1), uma NF-e por vez;
 *   - cancelamento e CC-e por evento;
 *   - nota autorizada = XML assinado + protocolo (Complements::toAuthorize).
 *
 * Protecao contra nota duplicada: o XML assinado e guardado ANTES do envio.
 * Se a chamada cair no meio, a proxima tentativa primeiro CONSULTA A CHAVE
 * na SEFAZ. So reenvia (o MESMO XML, mesma chave) se a nota nao existir.
 *
 * Codigos da SEFAZ usados aqui:
 *   Lote:     103 recebido (assincrono) | 104 processado | 105 em processamento
 *             108/109 servico paralisado -> nova tentativa depois
 *   Nota:     100/150 autorizada | 110/205/301/302/303 denegada
 *             204 duplicidade (ja autorizada) -> busca pela consulta
 *             217 nao consta na base -> pode enviar
 *             101/151/155 cancelada
 *   Evento:   128 lote processado | 135/136/155 registrado | 573 duplicidade
 */
final class ProvedorNfe implements ProvedorNfse
{
    private const AUTORIZADA = ['100', '150'];
    private const DENEGADA = ['110', '205', '301', '302', '303'];
    private const CANCELADA = ['101', '151', '155'];
    private const SERVICO_PARALISADO = ['108', '109'];
    private const EVENTO_REGISTRADO = ['135', '136', '155', '573'];

    public function __construct(private array $cfg, private Tools $tools)
    {
    }

    public function padrao(): string
    {
        return Padrao::NFE;
    }

    // =================================================================
    // Emissao
    // =================================================================

    public function emitir(array $documento): RetornoProvedor
    {
        // 1) Ja enviamos antes (caiu no meio)? Pergunta a SEFAZ primeiro.
        if (!empty($documento['xml_enviado']) && !empty($documento['chave_acesso'])) {
            $existente = $this->consultarChave($documento);
            if ($existente !== null) {
                return $existente;
            }
            // Nao consta na SEFAZ: reenvia o MESMO XML (mesma chave).
            return $this->enviar((string)$documento['xml_enviado'], [
                'chave_acesso' => $documento['chave_acesso'],
                'xml_enviado' => $documento['xml_enviado'],
                'numero_nfse' => (string)$documento['numero'],
            ]);
        }

        // 2) Monta e assina (a assinatura tambem valida o XSD oficial).
        try {
            $montado = $this->montarEAssinar($documento);
        } catch (NfeXmlException $e) {
            return RetornoProvedor::rejeitado(self::mensagensMontagem($e));
        } catch (ValidatorException $e) {
            return RetornoProvedor::rejeitado(self::mensagensXsd($e->getMessage()));
        } catch (Throwable $e) {
            return RetornoProvedor::falhaTemporaria('Falha ao assinar a NF-e: ' . $e->getMessage());
        }

        // Guardado ANTES de enviar: se cair no meio, sabemos a chave.
        return $this->enviar($montado['xml'], [
            'chave_acesso' => $montado['chave'],
            'xml_enviado' => $montado['xml'],
            'numero_nfse' => (string)$documento['numero'],
        ]);
    }

    /**
     * Monta o XML a partir do JSON e assina. A assinatura valida o XSD
     * oficial (lanca ValidatorException se algo estiver fora do leiaute).
     * Tambem usado pelo bin/nfse_homologar.php --so-validar.
     *
     * @return array{xml: string, chave: string} XML assinado
     */
    public function montarEAssinar(array $documento): array
    {
        $montado = NfeXmlBuilder::montar($this->payloadParaEnvio($documento), $this->cfgMontador());
        return ['xml' => $this->tools->signNFe($montado['xml']), 'chave' => $montado['chave']];
    }

    public function consultarProcessamento(array $documento): RetornoProvedor
    {
        // Lote assincrono (SEFAZ respondeu 103): consulta pelo recibo.
        if (!empty($documento['protocolo_lote'])) {
            try {
                $resposta = $this->tools->sefazConsultaRecibo((string)$documento['protocolo_lote']);
            } catch (Throwable $e) {
                return RetornoProvedor::falhaTemporaria('Falha ao consultar o recibo do lote: ' . $e->getMessage());
            }
            return $this->interpretarLote($resposta, (string)$documento['xml_enviado'], []);
        }

        if (empty($documento['chave_acesso'])) {
            return RetornoProvedor::situacao(RetornoProvedor::NAO_ENCONTRADO, [], [[
                'codigo' => 'NAO_ENVIADO',
                'mensagem' => 'NF-e ainda nao foi enviada a SEFAZ.',
            ]]);
        }

        return $this->consultarChave($documento) ?? self::naoLocalizada();
    }

    // =================================================================
    // Consulta
    // =================================================================

    public function consultar(array $documento): RetornoProvedor
    {
        return $this->consultarProcessamento(['protocolo_lote' => null] + $documento);
    }

    // =================================================================
    // Eventos
    // =================================================================

    public function registrarEvento(array $documento, array $evento): RetornoProvedor
    {
        $chave = (string)($documento['chave_acesso'] ?? '');
        $tipo = (string)$evento['tipo_evento'];
        $texto = (string)$evento['motivo_texto'];

        if (!in_array($tipo, [TipoEvento::NFE_CANCELAMENTO, TipoEvento::NFE_CARTA_CORRECAO], true)) {
            return RetornoProvedor::eventoRejeitado([[
                'codigo' => 'NAO_SUPORTADO_PELO_PADRAO',
                'mensagem' => "Evento {$tipo} nao suportado na NF-e.",
            ]]);
        }

        try {
            $resposta = $tipo === TipoEvento::NFE_CANCELAMENTO
                ? $this->tools->sefazCancela($chave, $texto, (string)($documento['protocolo_autorizacao'] ?? ''))
                : $this->tools->sefazCCe($chave, $texto, (int)($evento['sequencia'] ?? 1));
        } catch (ValidatorException $e) {
            return RetornoProvedor::eventoRejeitado(self::mensagensXsd($e->getMessage()));
        } catch (Throwable $e) {
            return RetornoProvedor::falhaTemporaria('Falha ao enviar o evento a SEFAZ: ' . $e->getMessage());
        }

        $requisicao = (string)$this->tools->lastRequest;
        $dados = ['xml_enviado' => $requisicao];

        $xpath = self::xpath($resposta);
        $cStatLote = self::valor($xpath, "//*[local-name()='retEnvEvento']/*[local-name()='cStat']");
        if ($cStatLote !== '128') {
            if (in_array($cStatLote, self::SERVICO_PARALISADO, true)) {
                return RetornoProvedor::falhaTemporaria("SEFAZ indisponivel ({$cStatLote}).", $dados);
            }
            return RetornoProvedor::eventoRejeitado([self::mensagem($xpath, "//*[local-name()='retEnvEvento']")], $dados);
        }

        $base = "//*[local-name()='retEvento']//*[local-name()='infEvento']";
        $cStat = self::valor($xpath, "{$base}/*[local-name()='cStat']");
        if (!in_array($cStat, self::EVENTO_REGISTRADO, true)) {
            return RetornoProvedor::eventoRejeitado([self::mensagem($xpath, $base)], $dados);
        }

        try {
            $dados['xml_retorno'] = Complements::toAuthorize($requisicao, $resposta);
        } catch (Throwable) {
            // 573 (duplicidade) as vezes nao traz o evento completo: guarda o retorno bruto.
            $dados['xml_retorno'] = $resposta;
        }

        return RetornoProvedor::eventoRegistrado($dados, [self::mensagem($xpath, $base)]);
    }

    // =================================================================
    // PDF (DANFE)
    // =================================================================

    public function gerarPdf(array $documento): string
    {
        if (($documento['status'] ?? '') === StatusNfse::DENEGADO) {
            throw new RuntimeException('NF-e denegada nao tem DANFE valido.');
        }
        $nfeProc = (string)($documento['xml_autorizado'] ?? '');
        if ($nfeProc === '') {
            throw new RuntimeException('NF-e sem XML autorizado: DANFE indisponivel.');
        }

        $danfe = new Danfe($nfeProc);
        $danfe->debugMode(false);
        $pdf = $danfe->render();
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Falha ao gerar o DANFE.');
        }
        return $pdf;
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    /**
     * O JSON vem no formato de sempre. A API sobrescreve apenas o que ela
     * controla: numero, serie, ambiente e (se faltar) data de emissao.
     */
    private function payloadParaEnvio(array $documento): array
    {
        $payload = $documento['payload'];
        $payload['ide']['nNF'] = (int)$documento['numero'];
        $payload['ide']['serie'] = (int)$documento['serie'];
        $payload['ide']['tpAmb'] = (int)$this->cfg['tpAmb'];

        if (empty($payload['ide']['dhEmi'])) {
            $fuso = new DateTimeZone('America/Bahia');
            $payload['ide']['dhEmi'] = (new DateTimeImmutable('now', $fuso))->modify('-1 minute')->format('Y-m-d\TH:i:sP');
        }

        return $payload;
    }

    private function cfgMontador(): array
    {
        return [
            'tpAmb' => (int)$this->cfg['tpAmb'],
            'autxml_ba_cnpj' => (string)$this->cfg['nfe']['autxml_ba_cnpj'],
        ];
    }

    /**
     * Envia (sincrono) e interpreta. $dados sao gravados mesmo em caso de
     * falha, para a proxima tentativa saber a chave e o XML enviado.
     */
    private function enviar(string $xmlAssinado, array $dados): RetornoProvedor
    {
        $idLote = str_pad((string)random_int(1, 999999999999999), 15, '0', STR_PAD_LEFT);
        try {
            $resposta = $this->tools->sefazEnviaLote([$xmlAssinado], $idLote, 1);
        } catch (ValidatorException $e) {
            return RetornoProvedor::rejeitado(self::mensagensXsd($e->getMessage()), $dados);
        } catch (Throwable $e) {
            return RetornoProvedor::falhaTemporaria('Sem resposta da SEFAZ: ' . $e->getMessage(), $dados);
        }

        return $this->interpretarLote($resposta, $xmlAssinado, $dados);
    }

    /** Le retEnviNFe (envio) ou retConsReciNFe (consulta de recibo). */
    private function interpretarLote(string $resposta, string $xmlAssinado, array $dados): RetornoProvedor
    {
        $xpath = self::xpath($resposta);
        $raiz = "//*[local-name()='retEnviNFe' or local-name()='retConsReciNFe']";
        $cStatLote = self::valor($xpath, "{$raiz}/*[local-name()='cStat']");

        if ($cStatLote === '103' || $cStatLote === '105') {
            // Lote recebido / em processamento: consulta pelo recibo depois.
            $recibo = self::valor($xpath, "//*[local-name()='nRec']");
            return RetornoProvedor::processando($dados + ($recibo !== '' ? ['protocolo_lote' => $recibo] : []), 5);
        }
        if (in_array($cStatLote, self::SERVICO_PARALISADO, true) || $cStatLote === '') {
            return RetornoProvedor::falhaTemporaria(
                $cStatLote === '' ? 'Resposta da SEFAZ sem cStat.' : "SEFAZ indisponivel ({$cStatLote}).",
                $dados
            );
        }
        if ($cStatLote !== '104') {
            return RetornoProvedor::rejeitado([self::mensagem($xpath, $raiz)], $dados);
        }

        $prot = "//*[local-name()='protNFe']//*[local-name()='infProt']";
        $cStat = self::valor($xpath, "{$prot}/*[local-name()='cStat']");
        $mensagens = [self::mensagem($xpath, $prot)];

        if (in_array($cStat, self::AUTORIZADA, true) || in_array($cStat, self::DENEGADA, true)) {
            $dados += $this->dadosDoProtocolo($xpath, $prot, $xmlAssinado, $resposta);
            return in_array($cStat, self::AUTORIZADA, true)
                ? RetornoProvedor::autorizado($dados, $mensagens)
                : RetornoProvedor::situacao(StatusNfse::DENEGADO, $dados, $mensagens);
        }

        if ($cStat === '204') {
            // Duplicidade: a nota JA foi autorizada (envio anterior). Busca o protocolo.
            return $this->consultarChave(['xml_enviado' => $xmlAssinado] + $dados)
                ?? RetornoProvedor::falhaTemporaria('SEFAZ informou duplicidade, mas a consulta nao achou a NF-e.', $dados);
        }

        return RetornoProvedor::rejeitado($mensagens, $dados);
    }

    /**
     * Consulta a situacao pela chave. Retorna null quando a NF-e NAO consta
     * na SEFAZ (cStat 217): ai e seguro enviar.
     */
    private function consultarChave(array $documento): ?RetornoProvedor
    {
        try {
            $resposta = $this->tools->sefazConsultaChave((string)$documento['chave_acesso']);
        } catch (Throwable $e) {
            return RetornoProvedor::falhaTemporaria('Falha ao consultar a NF-e na SEFAZ: ' . $e->getMessage());
        }

        $xpath = self::xpath($resposta);
        $raiz = "//*[local-name()='retConsSitNFe']";
        $cStat = self::valor($xpath, "{$raiz}/*[local-name()='cStat']");
        $mensagens = [self::mensagem($xpath, $raiz)];

        if ($cStat === '217') {
            return null;
        }
        if (in_array($cStat, self::SERVICO_PARALISADO, true)) {
            return RetornoProvedor::falhaTemporaria("SEFAZ indisponivel ({$cStat}).");
        }

        $prot = "//*[local-name()='protNFe']//*[local-name()='infProt']";
        $dados = ['chave_acesso' => $documento['chave_acesso']];
        if (!empty($documento['xml_enviado']) && $xpath->query($prot)->length > 0) {
            $dados += $this->dadosDoProtocolo($xpath, $prot, (string)$documento['xml_enviado'], $resposta);
        }

        if (in_array($cStat, self::AUTORIZADA, true)) {
            return RetornoProvedor::autorizado($dados, $mensagens);
        }
        if (in_array($cStat, self::DENEGADA, true)) {
            return RetornoProvedor::situacao(StatusNfse::DENEGADO, $dados, $mensagens);
        }
        if (in_array($cStat, self::CANCELADA, true)) {
            return RetornoProvedor::situacao(StatusNfse::CANCELADO, $dados, $mensagens);
        }

        // Outro codigo (ex.: chave invalida): nao arrisca reenviar.
        return RetornoProvedor::falhaTemporaria('Consulta da NF-e retornou ' . ($mensagens[0]['codigo'] ?? '?') . ': ' . ($mensagens[0]['mensagem'] ?? ''));
    }

    private function dadosDoProtocolo(DOMXPath $xpath, string $prot, string $xmlAssinado, string $resposta): array
    {
        $dados = [
            'protocolo_autorizacao' => self::valor($xpath, "{$prot}/*[local-name()='nProt']") ?: null,
        ];
        $recebimento = self::valor($xpath, "{$prot}/*[local-name()='dhRecbto']");
        if ($recebimento !== '') {
            $dados['data_autorizacao'] = (new DateTimeImmutable($recebimento))->format(DATE_ATOM);
        }
        try {
            // nfeProc = XML assinado + protocolo (confere a assinatura).
            $dados['xml_autorizado'] = Complements::toAuthorize($xmlAssinado, $resposta);
        } catch (Throwable $e) {
            $dados['xml_autorizado'] = null;
        }
        $numero = self::valorXml($xmlAssinado, 'nNF');
        if ($numero !== '') {
            $dados['numero_nfse'] = $numero;
        }
        return $dados;
    }

    private static function naoLocalizada(): RetornoProvedor
    {
        return RetornoProvedor::situacao(RetornoProvedor::NAO_ENCONTRADO, [], [[
            'codigo' => '217',
            'mensagem' => 'A NF-e nao consta na base da SEFAZ. E seguro reenviar o documento.',
        ]]);
    }

    private static function mensagensMontagem(NfeXmlException $e): array
    {
        $erros = $e->errosMake !== [] ? $e->errosMake : [$e->getMessage()];
        return array_map(static fn($erro) => [
            'codigo' => 'MONTAGEM',
            'mensagem' => (string)$erro,
            'correcao' => 'Confira no JSON da nota o campo citado (formato e obrigatoriedade seguem o leiaute da NF-e).',
        ], $erros);
    }

    private static function mensagensXsd(string $texto): array
    {
        $linhas = array_values(array_filter(array_map('trim', preg_split('/\R/', $texto) ?: [])));
        return array_map(static fn($linha) => [
            'codigo' => 'XSD',
            'mensagem' => $linha,
            'correcao' => 'Campo fora do formato do leiaute oficial da NF-e: confira tamanho, casas decimais e obrigatoriedade no JSON.',
        ], $linhas ?: [$texto]);
    }

    private static function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        $uso = libxml_use_internal_errors(true);
        $dom->loadXML($xml !== '' ? $xml : '<vazio/>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($uso);
        return new DOMXPath($dom);
    }

    private static function valor(DOMXPath $xpath, string $consulta): string
    {
        $nos = $xpath->query($consulta);
        return ($nos !== false && $nos->length > 0) ? trim((string)$nos->item(0)->textContent) : '';
    }

    private static function valorXml(string $xml, string $tag): string
    {
        return self::valor(self::xpath($xml), "//*[local-name()='{$tag}']");
    }

    /** {codigo, mensagem} a partir de cStat/xMotivo filhos do no informado. */
    private static function mensagem(DOMXPath $xpath, string $no): array
    {
        return [
            'codigo' => self::valor($xpath, "{$no}/*[local-name()='cStat']"),
            'mensagem' => self::valor($xpath, "{$no}/*[local-name()='xMotivo']"),
        ];
    }
}
