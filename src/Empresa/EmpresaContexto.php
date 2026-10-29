<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Empresa;

use RuntimeException;
use Xande\NfeIntegracao\Database\ConnectionFactory;

/**
 * Sobrepoe a config base com os dados da empresa selecionada na requisicao.
 *
 * A empresa vem do header X-NFE-EMPRESA (HTTP) ou de NFE_EMPRESA_CNPJ (CLI).
 * Sem empresa selecionada, a config base (config/nfe.php) continua valendo.
 */
final class EmpresaContexto
{
    public static function aplicar(array $cfg, string $cnpj): array
    {
        if (!ConnectionFactory::configurado()) {
            throw new RuntimeException('Multiempresa exige o banco fiscal configurado.');
        }

        $repo = new EmpresaRepository(ConnectionFactory::make(), CertificadoCofre::fromConfig($cfg));

        $empresa = $repo->buscarPorCnpj($cnpj);
        if ($empresa === null || !$empresa['ativo']) {
            throw new RuntimeException("Empresa {$cnpj} nao cadastrada ou inativa.");
        }

        $cert = $repo->certificadoAtivo((int)$empresa['id']);
        if ($cert === null) {
            throw new RuntimeException("Empresa {$cnpj} sem certificado ativo.");
        }
        if (strtotime($cert['valido_ate']) < time()) {
            throw new RuntimeException("Certificado da empresa {$cnpj} vencido em {$cert['valido_ate']}.");
        }

        // Cada empresa tem sua propria arvore de XML/PDF/logs.
        $porEmpresa = static fn(string $key, string $default): string =>
            rtrim((string)($cfg[$key] ?? $default), '/\\') . DIRECTORY_SEPARATOR . $cnpj;

        return array_replace($cfg, [
            'empresaId' => (int)$empresa['id'],
            'cnpj' => $empresa['cnpj'],
            'razao' => $empresa['razao_social'],
            'ie' => (string)($empresa['ie'] ?? ''),
            'siglaUF' => $empresa['uf'],
            'cMun' => (int)$empresa['c_mun'],
            'crt' => (int)$empresa['crt'],
            'tpAmb' => (int)$empresa['tp_amb'],
            'certPfxContent' => $cert['pfx'],
            'certPassword' => $cert['senha'],
            'pathXml' => $porEmpresa('pathXml', dirname(__DIR__, 2) . '/storage/xml'),
            'pathPdf' => $porEmpresa('pathPdf', dirname(__DIR__, 2) . '/storage/pdf'),
            'pathLogs' => $porEmpresa('pathLogs', dirname(__DIR__, 2) . '/storage/logs'),
        ]);
    }
}
