<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nacional;

use DateTimeImmutable;
use DOMDocument;
use Xande\NfeIntegracao\Nfse\Contrato\ProvedorNfse;
use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Infra\AssinadorXml;
use Xande\NfeIntegracao\Nfse\Infra\RespostaHttp;
use Xande\NfeIntegracao\Nfse\Infra\ValidadorXsd;
use Xande\NfeIntegracao\Nfse\Infra\Xml;
use Xande\NfeIntegracao\Nfse\Pdf\DanfsePdf;

/**
 * NFS-e Padrao Nacional (Sefin Nacional).
 *
 * Caracteristicas do padrao:
 *   - Emissao SINCRONA: enviamos a DPS e ja recebemos a NFS-e (ou rejeicao).
 *   - Um documento por chamada (nao existe lote no Sefin). O "lote" da nossa
 *     API e apenas um agrupamento logico; cada DPS vira um job na fila.
 *   - Recuperacao segura: se a chamada cair no meio (timeout), na proxima
 *     tentativa primeiro perguntamos ao Sefin "essa DPS ja virou nota?"
 *     (GET /dps/{id}). Assim nunca emitimos em duplicidade.
 */
final class ProvedorNacional implements ProvedorNfse
{
    private string $dirXsd;

    public function __construct(
        private array $cfg,
        private SefinNacionalCliente $cliente,
        private AssinadorXml $assinador
    ) {
        $versao = (string)$cfg['nacional']['versao_leiaute'];
        $this->dirXsd = dirname(__DIR__, 3) . "/schemas/nfse/nacional/{$versao}";
    }

    public function padrao(): string
    {
        return Padrao::NACIONAL;
    }

    // =================================================================
    // Emissao
    // =================================================================

    public function emitir(array $documento): RetornoProvedor
    {
        // 1) Ja tentamos antes? Entao primeiro verifica se a DPS ja virou nota.
        if (!empty($documento['id_dps']) && !empty($documento['xml_enviado'])) {
            $existente = $this->buscarPorDps((string)$documento['id_dps']);
            if ($existente !== null) {
                return $existente;
            }
        }

        // 2) Monta, assina e valida a DPS.
        $montado = (new DpsXmlBuilder($this->cfg))->montar($documento);
        $xmlAssinado = $this->assinar($montado['xml'], 'DPS', 'infDPS');
        $dadosEnvio = ['id_dps' => $montado['id_dps'], 'xml_enviado' => $xmlAssinado];

        $errosXsd = ValidadorXsd::validar($xmlAssinado, $this->dirXsd . '/DPS_v' . $this->cfg['nacional']['versao_leiaute'] . '.xsd');
        if ($errosXsd !== []) {
            return RetornoProvedor::rejeitado(self::mensagensXsd($errosXsd), $dadosEnvio);
        }

        // 3) Envia ao Sefin.
        $resposta = $this->cliente->emitir($xmlAssinado);

        if ($resposta->falhaTemporaria() || in_array($resposta->status, [401, 403], true)) {
            return RetornoProvedor::falhaTemporaria(self::descreverFalha($resposta), $dadosEnvio);
        }

        $json = $resposta->json() ?? [];
        $chave = (string)self::campo($json, 'chaveAcesso');

        if ($resposta->sucesso() && $chave !== '') {
            $xmlNfse = SefinNacionalCliente::descompactar(self::campo($json, 'nfseXmlGZipB64'));
            return RetornoProvedor::autorizado(
                $dadosEnvio + $this->dadosDaNfse($chave, $xmlNfse),
                self::mensagens($json, 'alertas')
            );
        }

        return RetornoProvedor::rejeitado(self::mensagensDeErro($json, $resposta), $dadosEnvio);
    }

    public function consultarProcessamento(array $documento): RetornoProvedor
    {
        if (empty($documento['id_dps'])) {
            return RetornoProvedor::situacao(RetornoProvedor::NAO_ENCONTRADO, [], [[
                'codigo' => 'NAO_ENVIADO',
                'mensagem' => 'Documento ainda nao foi enviado ao Sefin Nacional.',
            ]]);
        }

        return $this->buscarPorDps((string)$documento['id_dps'])
            ?? RetornoProvedor::situacao(RetornoProvedor::NAO_ENCONTRADO, [], [[
                'codigo' => 'DPS_NAO_LOCALIZADA',
                'mensagem' => 'A DPS nao foi localizada no Sefin Nacional. E seguro reenviar o documento.',
            ]]);
    }

    // =================================================================
    // Consulta
    // =================================================================

    public function consultar(array $documento): RetornoProvedor
    {
        $chave = (string)($documento['chave_acesso'] ?? '');
        if ($chave === '') {
            // Sem chave ainda: a consulta vira "a DPS ja foi processada?".
            return $this->consultarProcessamento($documento);
        }

        $resposta = $this->cliente->consultarNfse($chave);
        if ($resposta->falhaTemporaria()) {
            return RetornoProvedor::falhaTemporaria(self::descreverFalha($resposta));
        }
        if (!$resposta->sucesso()) {
            return RetornoProvedor::situacao(RetornoProvedor::NAO_ENCONTRADO, [], self::mensagensDeErro($resposta->json() ?? [], $resposta));
        }

        $xmlNfse = SefinNacionalCliente::descompactar(self::campo($resposta->json() ?? [], 'nfseXmlGZipB64'));
        $dados = $this->dadosDaNfse($chave, $xmlNfse);

        // A NFS-e em si nao muda; cancelamento/substituicao aparecem como eventos.
        $eventos = $this->cliente->listarEventos($chave);
        if ($eventos->falhaTemporaria()) {
            return RetornoProvedor::falhaTemporaria(self::descreverFalha($eventos));
        }

        $status = StatusNfse::AUTORIZADO;
        foreach ((array)self::campo($eventos->json() ?? [], 'eventos') as $evento) {
            $tipo = (string)self::campo((array)$evento, 'tipoEvento');
            if ($tipo === TipoEvento::CANCELAMENTO_POR_SUBSTITUICAO) {
                $status = StatusNfse::SUBSTITUIDO;
            } elseif (in_array($tipo, [TipoEvento::CANCELAMENTO, '105104', '305101'], true)) {
                // 101101 cancelamento, 105104 cancelamento deferido, 305101 cancelamento de oficio
                $status = StatusNfse::CANCELADO;
            }
        }

        return RetornoProvedor::situacao($status, $dados);
    }

    // =================================================================
    // Eventos
    // =================================================================

    public function registrarEvento(array $documento, array $evento): RetornoProvedor
    {
        $chave = (string)($documento['chave_acesso'] ?? '');
        if ($chave === '') {
            return RetornoProvedor::eventoRejeitado([[
                'codigo' => 'SEM_CHAVE',
                'mensagem' => 'Documento sem chave de acesso: so e possivel registrar evento em NFS-e autorizada.',
            ]]);
        }

        $xml = (new EventoXmlBuilder($this->cfg))->montar($chave, $evento);
        $xmlAssinado = $this->assinar($xml, 'pedRegEvento', 'infPedReg');

        $errosXsd = ValidadorXsd::validar($xmlAssinado, $this->dirXsd . '/pedRegEvento_v' . $this->cfg['nacional']['versao_leiaute'] . '.xsd');
        if ($errosXsd !== []) {
            return RetornoProvedor::eventoRejeitado(self::mensagensXsd($errosXsd), ['xml_enviado' => $xmlAssinado]);
        }

        $resposta = $this->cliente->registrarEvento($chave, $xmlAssinado);
        if ($resposta->falhaTemporaria() || in_array($resposta->status, [401, 403], true)) {
            return RetornoProvedor::falhaTemporaria(self::descreverFalha($resposta), ['xml_enviado' => $xmlAssinado]);
        }

        $json = $resposta->json() ?? [];
        $xmlRetorno = SefinNacionalCliente::descompactar(self::campo($json, 'eventoXmlGZipB64'));

        if ($resposta->sucesso() && $xmlRetorno !== null) {
            return RetornoProvedor::eventoRegistrado(
                ['xml_enviado' => $xmlAssinado, 'xml_retorno' => $xmlRetorno],
                self::mensagens($json, 'alertas')
            );
        }

        return RetornoProvedor::eventoRejeitado(self::mensagensDeErro($json, $resposta), ['xml_enviado' => $xmlAssinado]);
    }

    // =================================================================
    // PDF
    // =================================================================

    /**
     * DANFSe v2.0 gerado a partir do XML autorizado (NT 008/2026).
     * A API do ADN que entregava o PDF foi desligada em 03/08/2026.
     */
    public function gerarPdf(array $documento): string
    {
        return (new DanfsePdf())->gerar((string)($documento['xml_autorizado'] ?? ''), (string)($documento['status'] ?? ''));
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    /**
     * Pergunta ao Sefin se a DPS ja gerou NFS-e.
     * Retorna null quando a DPS nao existe (HTTP 404).
     */
    private function buscarPorDps(string $idDps): ?RetornoProvedor
    {
        $resposta = $this->cliente->consultarDps($idDps);
        if ($resposta->status === 404) {
            return null;
        }
        if ($resposta->falhaTemporaria() || !$resposta->sucesso()) {
            return RetornoProvedor::falhaTemporaria('Falha ao verificar DPS ja enviada: ' . self::descreverFalha($resposta));
        }

        $chave = (string)self::campo($resposta->json() ?? [], 'chaveAcesso');
        if ($chave === '') {
            return null;
        }

        $nfse = $this->cliente->consultarNfse($chave);
        if (!$nfse->sucesso()) {
            return RetornoProvedor::falhaTemporaria('DPS localizada, mas falhou ao baixar a NFS-e: ' . self::descreverFalha($nfse));
        }

        $xmlNfse = SefinNacionalCliente::descompactar(self::campo($nfse->json() ?? [], 'nfseXmlGZipB64'));
        return RetornoProvedor::autorizado($this->dadosDaNfse($chave, $xmlNfse));
    }

    /**
     * Extrai do XML da NFS-e os campos que guardamos em colunas.
     */
    private function dadosDaNfse(string $chave, ?string $xmlNfse): array
    {
        $dados = ['chave_acesso' => $chave, 'xml_autorizado' => $xmlNfse];
        if ($xmlNfse !== null) {
            $dados['numero_nfse'] = Xml::valor($xmlNfse, 'nNFSe');
            $dhProc = Xml::valor($xmlNfse, 'dhProc');
            $dados['data_autorizacao'] = $dhProc !== null ? (new DateTimeImmutable($dhProc))->format(DATE_ATOM) : null;
        }
        return $dados;
    }

    private function assinar(string $xml, string $raiz, string $noAssinado): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $this->assinador->assinarNo(
            $dom,
            $dom->getElementsByTagName($noAssinado)->item(0),
            $dom->getElementsByTagName($raiz)->item(0),
            'Id'
        );

        return '<?xml version="1.0" encoding="UTF-8"?>' . $dom->saveXML($dom->documentElement);
    }

    /**
     * O Sefin as vezes devolve chaves com inicial maiuscula (Codigo/Descricao)
     * e as vezes minuscula. Esta funcao le ignorando maiusculas/minusculas.
     */
    private static function campo(array $json, string $nome): mixed
    {
        foreach ($json as $chave => $valor) {
            if (strcasecmp((string)$chave, $nome) === 0) {
                return $valor;
            }
        }
        return null;
    }

    private static function mensagens(array $json, string $campo): array
    {
        $lista = self::campo($json, $campo);
        if (!is_array($lista)) {
            return [];
        }
        // Pode vir um objeto unico em vez de lista.
        if (self::campo($lista, 'codigo') !== null || self::campo($lista, 'descricao') !== null) {
            $lista = [$lista];
        }

        $mensagens = [];
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $mensagens[] = array_filter([
                'codigo' => (string)self::campo($item, 'codigo'),
                'mensagem' => (string)self::campo($item, 'descricao'),
                'correcao' => (string)self::campo($item, 'complemento'),
            ], static fn($v) => $v !== '');
        }
        return $mensagens;
    }

    private static function mensagensDeErro(array $json, RespostaHttp $resposta): array
    {
        $mensagens = array_merge(self::mensagens($json, 'erros'), self::mensagens($json, 'erro'));
        if ($mensagens === []) {
            $mensagens[] = [
                'codigo' => 'HTTP_' . $resposta->status,
                'mensagem' => 'Resposta inesperada do Sefin Nacional: ' . mb_substr(trim($resposta->corpo), 0, 300),
            ];
        }
        return $mensagens;
    }

    private static function mensagensXsd(array $erros): array
    {
        return array_map(
            static fn(string $erro) => ['codigo' => 'XSD', 'mensagem' => $erro],
            $erros
        );
    }

    private static function descreverFalha(RespostaHttp $resposta): string
    {
        if ($resposta->status === 0) {
            return 'Sem resposta do servidor: ' . ($resposta->erroRede ?? 'erro de rede');
        }
        if (in_array($resposta->status, [401, 403], true)) {
            return "HTTP {$resposta->status}: acesso negado. Verifique o certificado digital e o credenciamento do CNPJ.";
        }
        return "HTTP {$resposta->status}: " . mb_substr(trim($resposta->corpo), 0, 300);
    }
}
