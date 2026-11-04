<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Pequenos utilitarios de XML usados pelos montadores e leitores.
 *
 * Usamos DOMDocument (e nao concatenacao de strings) para que caracteres
 * especiais como & < > sejam escapados automaticamente.
 */
final class Xml
{
    private function __construct()
    {
    }

    /**
     * Cria <nome>valor</nome> dentro de $pai, no mesmo namespace do pai.
     */
    public static function filho(DOMDocument $dom, DOMElement $pai, string $nome, ?string $valor = null): DOMElement
    {
        $no = $pai->namespaceURI !== null
            ? $dom->createElementNS($pai->namespaceURI, $nome)
            : $dom->createElement($nome);

        if ($valor !== null) {
            $no->appendChild($dom->createTextNode(self::limparTexto($valor)));
        }

        $pai->appendChild($no);
        return $no;
    }

    /**
     * Igual a filho(), mas nao cria nada quando o valor e vazio.
     */
    public static function filhoOpcional(DOMDocument $dom, DOMElement $pai, string $nome, mixed $valor): void
    {
        if ($valor === null || trim((string)$valor) === '') {
            return;
        }
        self::filho($dom, $pai, $nome, (string)$valor);
    }

    /** 1234.5 -> "1234.50" */
    public static function decimal(mixed $valor, int $casas = 2): string
    {
        return number_format((float)$valor, $casas, '.', '');
    }

    public static function somenteDigitos(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $digitos = preg_replace('/\D+/', '', (string)$valor) ?? '';
        return $digitos === '' ? null : $digitos;
    }

    /**
     * Remove quebras de linha, tabs e espacos duplicados. Prefeituras
     * costumam rejeitar esses caracteres.
     */
    public static function limparTexto(string $texto): string
    {
        $texto = preg_replace('/[\r\n\t]+/', ' ', $texto) ?? $texto;
        $texto = preg_replace('/ {2,}/', ' ', $texto) ?? $texto;
        return trim($texto);
    }

    /**
     * Le o texto do primeiro elemento com esse nome local, ignorando namespace.
     * Ex.: Xml::valor($xml, 'nNFSe')
     */
    public static function valor(string $xml, string $nomeLocal): ?string
    {
        $dom = self::carregar($xml);
        if ($dom === null) {
            return null;
        }
        $xpath = new DOMXPath($dom);
        $nos = $xpath->query("//*[local-name()='{$nomeLocal}']");
        if ($nos === false || $nos->length === 0) {
            return null;
        }
        return trim((string)$nos->item(0)->textContent);
    }

    public static function carregar(string $xml): ?DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }
        $usoAnterior = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($usoAnterior);
        return $ok ? $dom : null;
    }
}
