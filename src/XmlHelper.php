<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao;

final class XmlHelper
{
    public static function firstText(string $xml, string $localName): string
    {
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return '';

        $nodes = $sx->xpath('//*[local-name()="' . $localName . '"]');
        if (!$nodes || !isset($nodes[0])) return '';
        return trim((string)$nodes[0]);
    }

    public static function extract(string $xml, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) $out[$f] = self::firstText($xml, $f);
        return $out;
    }
}
