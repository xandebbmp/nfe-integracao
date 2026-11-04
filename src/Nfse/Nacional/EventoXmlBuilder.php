<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nacional;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use RuntimeException;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Infra\Xml;

/**
 * Monta o pedido de registro de evento (pedRegEvento) do Padrao Nacional.
 *
 * Eventos suportados pelo emitente (prestador):
 *   101101 - Cancelamento de NFS-e
 *   101103 - Solicitacao de analise fiscal para cancelamento (fora do prazo)
 *
 * O Id segue o XSD (TSIdPedRegEvt): "PRE" + chave(50) + codigo do evento(6).
 */
final class EventoXmlBuilder
{
    public const EVENTOS_DO_EMITENTE = [
        TipoEvento::CANCELAMENTO,
        TipoEvento::SOLICITACAO_ANALISE_CANCELAMENTO,
    ];

    public function __construct(private array $cfg)
    {
    }

    /**
     * @return string XML ainda NAO assinado
     */
    public function montar(string $chaveNfse, array $evento, ?DateTimeImmutable $agora = null): string
    {
        $tipo = (string)$evento['tipo_evento'];
        if (!in_array($tipo, self::EVENTOS_DO_EMITENTE, true)) {
            throw new RuntimeException("Evento {$tipo} nao e suportado para envio pelo emitente.");
        }

        $fuso = new DateTimeZone('America/Bahia');
        $agora = ($agora ?? new DateTimeImmutable('now', $fuso))->setTimezone($fuso)->modify('-1 minute');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $raiz = $dom->createElementNS(DpsXmlBuilder::NAMESPACE, 'pedRegEvento');
        $raiz->setAttribute('versao', (string)$this->cfg['nacional']['versao_leiaute']);
        $dom->appendChild($raiz);

        $inf = Xml::filho($dom, $raiz, 'infPedReg');
        $inf->setAttribute('Id', 'PRE' . $chaveNfse . $tipo);

        Xml::filho($dom, $inf, 'tpAmb', (string)$this->cfg['tpAmb']);
        Xml::filho($dom, $inf, 'verAplic', DpsXmlBuilder::VERSAO_APLICATIVO);
        Xml::filho($dom, $inf, 'dhEvento', $agora->format('Y-m-d\TH:i:sP'));
        Xml::filho($dom, $inf, 'CNPJAutor', (string)$this->cfg['emitente']['cnpj']);
        Xml::filho($dom, $inf, 'chNFSe', $chaveNfse);

        $grupo = Xml::filho($dom, $inf, 'e' . $tipo);
        Xml::filho($dom, $grupo, 'xDesc', TipoEvento::DESCRICOES[$tipo]);
        Xml::filho($dom, $grupo, 'cMotivo', (string)$evento['motivo_codigo']);
        Xml::filho($dom, $grupo, 'xMotivo', (string)$evento['motivo_texto']);

        return $dom->saveXML($dom->documentElement);
    }
}
