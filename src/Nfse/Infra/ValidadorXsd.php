<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use DOMDocument;

/**
 * Valida um XML contra o XSD oficial ANTES de enviar ao fisco.
 *
 * Erros de schema viram rejeicao local (mais rapida e com mensagem clara),
 * sem gastar chamada no webservice.
 *
 * Os XSDs ficam em schemas/nfse/<padrao>/<versao>/.
 */
final class ValidadorXsd
{
    /**
     * @return list<string> lista de erros (vazia = XML valido)
     */
    public static function validar(string $xml, string $arquivoXsd): array
    {
        if (!is_file($arquivoXsd)) {
            return ["XSD nao encontrado: " . basename($arquivoXsd)];
        }

        $usoAnterior = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument();
        $erros = [];

        if (!$dom->loadXML($xml)) {
            $erros[] = 'XML mal formado.';
        } elseif (!$dom->schemaValidate($arquivoXsd)) {
            foreach (libxml_get_errors() as $erro) {
                $erros[] = trim($erro->message) . " (linha {$erro->line})";
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($usoAnterior);

        return $erros;
    }
}
