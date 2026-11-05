<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use Xande\NfeIntegracao\Nfse\Dominio\Padrao;

/**
 * Nome sugerido para os XMLs baixados (API e monitor).
 *
 * NF-e segue o costume do mercado (o mesmo dos ERPs e do portal da SEFAZ):
 *   nota:   <chave>-procNFe.xml
 *   evento: <chave>-<tipo>-<sequencia>-procEventoNFe.xml
 *           ex.: ...-110111-01-procEventoNFe.xml (cancelamento)
 *                ...-110110-02-procEventoNFe.xml (2a Carta de Correcao)
 *
 * NFS-e:
 *   nota:   nfse-<chave ou numero>.xml
 *   evento: nfse-<chave ou numero>-evento-<tipo>.xml
 */
final class NomeArquivoXml
{
    private function __construct()
    {
    }

    public static function documento(array $documento): string
    {
        if ($documento['padrao'] === Padrao::NFE && !empty($documento['chave_acesso'])) {
            return self::limpar($documento['chave_acesso'] . '-procNFe.xml');
        }
        $prefixo = $documento['padrao'] === Padrao::NFE ? 'nfe' : 'nfse';
        return self::limpar($prefixo . '-' . self::identificador($documento) . '.xml');
    }

    public static function evento(array $documento, array $evento): string
    {
        $tipo = (string)$evento['tipo_evento'];
        if ($documento['padrao'] === Padrao::NFE && !empty($documento['chave_acesso'])) {
            $sequencia = str_pad((string)max(1, (int)($evento['sequencia'] ?? 1)), 2, '0', STR_PAD_LEFT);
            return self::limpar("{$documento['chave_acesso']}-{$tipo}-{$sequencia}-procEventoNFe.xml");
        }
        $prefixo = $documento['padrao'] === Padrao::NFE ? 'nfe' : 'nfse';
        return self::limpar($prefixo . '-' . self::identificador($documento) . "-evento-{$tipo}.xml");
    }

    private static function identificador(array $documento): string
    {
        foreach (['chave_acesso', 'numero_nfse', 'id'] as $campo) {
            if (!empty($documento[$campo])) {
                return (string)$documento[$campo];
            }
        }
        return 'documento';
    }

    /** So letras, numeros, ponto, hifen e sublinhado (seguro em cabecalho HTTP). */
    private static function limpar(string $nome): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $nome) ?? 'documento.xml';
    }
}
