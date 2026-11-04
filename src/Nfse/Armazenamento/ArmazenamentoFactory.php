<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Armazenamento;

use RuntimeException;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;

/**
 * Cria o armazenamento configurado em config/nfse.php ('armazenamento').
 * Retorna null quando o tipo e 'nenhum' (so banco).
 */
final class ArmazenamentoFactory
{
    private function __construct()
    {
    }

    public static function criar(array $cfg, ?TransporteHttp $http = null): ?ArmazenamentoArquivos
    {
        $a = $cfg['armazenamento'] ?? ['tipo' => 'nenhum'];

        return match ($a['tipo'] ?? 'nenhum') {
            'nenhum' => null,
            'local' => new ArmazenamentoLocal((string)$a['local']['diretorio']),
            's3' => new ArmazenamentoS3(
                $http ?? new CurlTransporte(null, 60),
                (string)$a['s3']['endpoint'],
                (string)$a['s3']['regiao'],
                (string)$a['s3']['bucket'],
                (string)$a['s3']['chave_acesso'],
                (string)$a['s3']['chave_secreta'],
                (string)($a['s3']['prefixo'] ?? ''),
                (bool)($a['s3']['path_style'] ?? false)
            ),
            default => throw new RuntimeException("Tipo de armazenamento desconhecido: {$a['tipo']}"),
        };
    }

    public static function ativo(array $cfg): bool
    {
        return ($cfg['armazenamento']['tipo'] ?? 'nenhum') !== 'nenhum';
    }
}
