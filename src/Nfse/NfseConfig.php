<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse;

use RuntimeException;

/**
 * Junta config/nfe.php (ambiente e empresa da NF-e) com config/nfse.php.
 *
 * Multiempresa:
 *   - cfg['emitentes'] e um mapa CNPJ => dados do emitente (inscricao
 *     municipal, regime, certificado...). Se config/nfse.php nao trouxer
 *     emitentes, usamos a empresa e o certificado do config/nfe.php.
 *   - Cada consumidor pode ter 'cnpjs' => [...] com os CNPJs que pode usar.
 *
 * Para montar a config de UM emitente (o que os provedores recebem), use
 * NfseConfig::paraEmitente($cfg, $cnpj): ela coloca os dados dele em
 * cfg['emitente'].
 */
final class NfseConfig
{
    /** Valores padrao de cada emitente (podem ser sobrescritos). */
    private const EMITENTE_PADRAO = [
        'cnpj' => '',
        'razao_social' => '',
        'inscricao_municipal' => '',
        'codigo_municipio' => '2927408',
        'telefone' => '',
        'email' => '',
        'opcao_simples_nacional' => 1,
        'regime_apuracao_simples' => null,
        'regime_especial_tributacao' => 0,
        'incentivador_cultural' => false,
        'regime_especial_abrasf' => null,
        // NF-e: UF do emitente (SEFAZ que autoriza) e inscricao estadual.
        'uf' => '',
        'ie' => '',
        'certificado' => ['arquivo' => '', 'senha' => ''],
    ];

    private function __construct()
    {
    }

    public static function carregar(?string $diretorioConfig = null): array
    {
        $dir = $diretorioConfig ?? dirname(__DIR__, 2) . '/config';

        $nfe = is_file($dir . '/nfe.php') ? require $dir . '/nfe.php' : [];
        $nfse = is_file($dir . '/nfse.php') ? require $dir . '/nfse.php' : [];

        if (!is_array($nfe) || !is_array($nfse)) {
            throw new RuntimeException('Arquivos de configuracao fiscal invalidos.');
        }

        return self::montar($nfe, $nfse);
    }

    /**
     * Separado de carregar() para poder ser usado nos testes.
     */
    public static function montar(array $nfe, array $nfse): array
    {
        $cfg = $nfse;

        $cfg['tpAmb'] = (int)($nfse['tpAmb'] ?? 0) ?: (int)($nfe['tpAmb'] ?? 2);
        if (!in_array($cfg['tpAmb'], [1, 2], true)) {
            throw new RuntimeException('tpAmb invalido na configuracao (use 1 ou 2).');
        }

        $cfg['emitentes'] = self::montarEmitentes($nfe, $nfse);

        // NF-e (modelo 55): parametros tecnicos vem do config/nfe.php.
        $cfg['nfe'] = array_replace([
            'serie' => 1,
            'schemes' => (string)($nfe['schemes'] ?? 'PL_010_V1.30'),
            'versao' => (string)($nfe['versao'] ?? '4.00'),
            'autxml_ba_cnpj' => (string)($nfe['autxml_ba_cnpj'] ?? '13937073000156'),
            'timeout_segundos' => 60,
        ], (array)($nfse['nfe'] ?? []));
        unset($cfg['emitente']); // use paraEmitente()

        // Consumidor padrao: quem ja usa o apiToken da NF-e continua funcionando.
        $consumidores = $nfse['consumidores'] ?? [];
        $tokenNfe = trim((string)($nfe['apiToken'] ?? ''));
        if ($consumidores === [] && $tokenNfe !== '') {
            $consumidores['PADRAO'] = ['token' => $tokenNfe];
        }
        $cfg['consumidores'] = $consumidores;

        return $cfg;
    }

    /**
     * Config pronta para um emitente: igual a $cfg, com cfg['emitente'] preenchido.
     */
    public static function paraEmitente(array $cfg, string $cnpj): array
    {
        $cnpj = self::normalizarCnpj($cnpj);
        if (!isset($cfg['emitentes'][$cnpj])) {
            throw new RuntimeException("Emitente {$cnpj} nao esta configurado em config/nfse.php (emitentes).");
        }
        $cfg['emitente'] = $cfg['emitentes'][$cnpj];
        return $cfg;
    }

    /**
     * CNPJs que um consumidor pode usar para emitir.
     *
     * Regra: se o consumidor tiver 'cnpjs', vale a lista. Se nao tiver, so e
     * liberado quando existe UM unico emitente configurado (com varias
     * empresas, a lista passa a ser obrigatoria, por seguranca).
     *
     * @return list<string>
     */
    public static function cnpjsPermitidos(array $cfg, string $consumidor): array
    {
        // strval: o PHP transforma chave numerica ('41986662000160') em int.
        $todos = array_map('strval', array_keys($cfg['emitentes']));
        $lista = $cfg['consumidores'][$consumidor]['cnpjs'] ?? null;

        if ($consumidor === 'LOCAL') {
            return $todos; // desenvolvimento local sem tokens
        }
        if (is_array($lista) && $lista !== []) {
            return array_values(array_intersect($todos, array_map([self::class, 'normalizarCnpj'], $lista)));
        }
        return count($todos) === 1 ? $todos : [];
    }

    public static function normalizarCnpj(mixed $cnpj): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$cnpj) ?? '');
    }

    /**
     * @return array<string, array> CNPJ => emitente
     */
    private static function montarEmitentes(array $nfe, array $nfse): array
    {
        $lista = $nfse['emitentes'] ?? [];

        // Sem lista: um unico emitente, com empresa e certificado da NF-e.
        if ($lista === []) {
            $unico = $nfse['emitente'] ?? [];
            $unico['cnpj'] = ($unico['cnpj'] ?? '') !== '' ? $unico['cnpj'] : ($nfe['cnpj'] ?? '');
            $unico['razao_social'] = ($unico['razao_social'] ?? '') !== '' ? $unico['razao_social'] : ($nfe['razao'] ?? '');
            $unico['uf'] = ($unico['uf'] ?? '') !== '' ? $unico['uf'] : ($nfe['siglaUF'] ?? '');
            $unico['ie'] = ($unico['ie'] ?? '') !== '' ? $unico['ie'] : ($nfe['ie'] ?? '');
            $unico['certificado'] = $unico['certificado'] ?? [
                'arquivo' => (string)($nfe['certPfxPath'] ?? ''),
                'senha' => (string)($nfe['certPassword'] ?? ''),
            ];
            $lista = [$unico['cnpj'] => $unico];
        }

        $emitentes = [];
        foreach ($lista as $chave => $dados) {
            $dados = array_replace(self::EMITENTE_PADRAO, (array)$dados);
            $cnpj = self::normalizarCnpj($dados['cnpj'] !== '' ? $dados['cnpj'] : $chave);
            if (!preg_match('/^[A-Z0-9]{14}$/', $cnpj)) {
                throw new RuntimeException("CNPJ de emitente invalido na configuracao: {$chave}");
            }
            $dados['cnpj'] = $cnpj;
            $dados['uf'] = strtoupper((string)($dados['uf'] !== '' ? $dados['uf'] : ($nfe['siglaUF'] ?? '')));
            $emitentes[$cnpj] = $dados;
        }

        return $emitentes;
    }
}
