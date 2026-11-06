<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\AjustesPainel;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;
use RuntimeException;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;

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
 *
 * Empresas clientes (painel /admin/, script 0005): comClientes() acrescenta
 * cada cliente como emitente E como consumidor (token proprio, so pode emitir
 * pelo proprio CNPJ). Para o mesmo CNPJ, o cadastro do banco vale mais que o
 * arquivo.
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
        // true = emitente unico tirado do config/nfe.php (nao listado de proposito).
        $cfg['emitente_implicito'] = ($nfse['emitentes'] ?? []) === [];
        $cfg['admin_token'] = (string)($nfse['admin_token'] ?? '');
        $cfg['chave_certificados'] = (string)($nfse['chave_certificados'] ?? '') ?: (string)getenv('NFSE_CHAVE_CERTIFICADOS');

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
     * Acrescenta o que foi cadastrado no painel admin:
     *   - configuracoes alteradas na tela Sistema (sistema_ajustes, script 0009),
     *     que substituem o valor do arquivo (lista em Dominio/AjustesPainel.php);
     *   - empresas clientes (script 0005).
     * Sem os scripts aplicados, devolve a config como veio.
     */
    public static function comClientes(array $cfg, PDO $pdo): array
    {
        $cfg = AjustesPainel::aplicar($cfg, (new SistemaRepositorio($pdo))->ajustes());

        $repositorio = new ClienteRepositorio($pdo);
        if (!$repositorio->existe()) {
            return $cfg;
        }
        $clientes = $repositorio->paraConfiguracao();
        if ($clientes !== [] && !empty($cfg['emitente_implicito'])) {
            // Com clientes cadastrados, o emitente "automatico" do config/nfe.php sai.
            $cfg['emitentes'] = [];
        }

        foreach ($clientes as $c) {
            $cnpj = (string)$c['cnpj'];
            $base = $cfg['emitentes'][$cnpj] ?? self::EMITENTE_PADRAO;
            $emitente = array_replace($base, [
                'cnpj' => $cnpj,
                'razao_social' => (string)$c['razao_social'],
                'uf' => strtoupper((string)($c['uf'] ?? '')),
                'ie' => (string)($c['ie'] ?? ''),
                'inscricao_municipal' => (string)($c['inscricao_municipal'] ?? ''),
                'codigo_municipio' => (string)$c['codigo_municipio'],
                'telefone' => (string)($c['telefone'] ?? ''),
                'email' => (string)($c['email'] ?? ''),
                'opcao_simples_nacional' => (int)$c['opcao_simples_nacional'],
                'regime_apuracao_simples' => $c['regime_apuracao_simples'] ?? null,
                'regime_especial_tributacao' => (int)$c['regime_especial_tributacao'],
                'incentivador_cultural' => (bool)$c['incentivador_cultural'],
                'regime_especial_abrasf' => $c['regime_especial_abrasf'] ?? null,
            ]);
            if ($c['certificado_cifrado'] !== null) {
                $emitente['certificado'] = ['cifrado' => $c['certificado_cifrado']];
            }
            $cfg['emitentes'][$cnpj] = $emitente;

            // O proprio cliente e o "consumidor": token dele, so o CNPJ dele.
            if (!empty($c['token_hash'])) {
                $cfg['consumidores'][$cnpj] = [
                    'token_hash' => (string)$c['token_hash'],
                    'cnpjs' => [$cnpj],
                    'webhook_url' => (string)($c['webhook_url'] ?? ''),
                    'webhook_segredo' => (string)($c['webhook_segredo'] ?? ''),
                    'cliente' => true,
                ];
            }
        }

        return $cfg;
    }

    /**
     * Config pronta para um emitente: igual a $cfg, com cfg['emitente'] preenchido.
     */
    public static function paraEmitente(array $cfg, string $cnpj): array
    {
        $cnpj = self::normalizarCnpj($cnpj);
        if (!isset($cfg['emitentes'][$cnpj])) {
            throw new RuntimeException("Emitente {$cnpj} nao cadastrado (painel admin ou 'emitentes' em config/nfse.local.php).");
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
