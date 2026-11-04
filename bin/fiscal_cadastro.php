<?php
declare(strict_types=1);

/**
 * Ferramentas do cadastro de empresas e sistemas (telas do Scriptcase).
 *
 * Uso:
 *   php bin/fiscal_cadastro.php --gerar-chave
 *       Gera a chave que criptografa os certificados. Coloque o resultado em
 *       config/nfse.local.php: 'cadastro' => ['chave' => '...'].
 *
 *   php bin/fiscal_cadastro.php --gerar-token
 *       Gera um token aleatorio para cadastrar um sistema ou painel.
 *
 *   php bin/fiscal_cadastro.php --processar
 *       Confere agora os certificados enviados (o worker ja faz sozinho).
 *
 *   php bin/fiscal_cadastro.php --listar
 *       Mostra o que a API esta enxergando: empresas, certificados e sistemas.
 */

use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Servico\ProcessadorCertificados;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$opcoes = getopt('', ['gerar-chave', 'gerar-token', 'processar', 'listar', 'ajuda']);

if (isset($opcoes['gerar-chave'])) {
    echo CofreCertificado::gerarChave() . "\n";
    exit(0);
}
if (isset($opcoes['gerar-token'])) {
    echo bin2hex(random_bytes(32)) . "\n";
    exit(0);
}
if (!isset($opcoes['processar']) && !isset($opcoes['listar'])) {
    echo "Uso: php bin/fiscal_cadastro.php --gerar-chave | --gerar-token | --processar | --listar\n";
    exit(isset($opcoes['ajuda']) ? 0 : 2);
}

$pdo = ConnectionFactory::make();
$cfgArquivos = NfseConfig::carregar();

if (isset($opcoes['processar'])) {
    $total = (new ProcessadorCertificados($pdo, (string)$cfgArquivos['cadastro']['chave']))->processarPendentes();
    echo "{$total} certificado(s) conferido(s).\n";
}

if (isset($opcoes['listar'])) {
    $cfg = NfseConfig::comCadastro($cfgArquivos, $pdo);

    echo "\nEmpresas que a API pode usar:\n";
    foreach ($cfg['emitentes'] as $cnpj => $e) {
        $origem = !empty($e['certificado']['cifrado']) ? 'certificado do cadastro'
            : (($e['certificado']['arquivo'] ?? '') !== '' ? 'certificado em arquivo' : 'SEM certificado');
        printf("  %s  %-40s UF %-2s  %s\n", $cnpj, mb_substr((string)$e['razao_social'], 0, 40), $e['uf'] ?: '--', $origem);
    }

    if ((bool)$pdo->query("SELECT to_regclass('public.fiscal_empresas') IS NOT NULL")->fetchColumn()) {
        echo "\nSituacao dos certificados no cadastro:\n";
        foreach ($pdo->query('SELECT cnpj, ativo, certificado_situacao, certificado_mensagem FROM fiscal_empresas ORDER BY cnpj') as $l) {
            printf("  %s  %-15s %s%s\n", $l['cnpj'], $l['certificado_situacao'], (string)$l['certificado_mensagem'], $l['ativo'] ? '' : ' (INATIVA)');
        }
    }

    echo "\nSistemas:\n";
    foreach ($cfg['consumidores'] as $nome => $c) {
        $cnpjs = NfseConfig::cnpjsPermitidos($cfg, (string)$nome);
        printf("  %-20s pode emitir por: %s\n", $nome, $cnpjs === [] ? 'NENHUMA empresa' : implode(', ', $cnpjs));
    }
    echo '  Acessos ao painel cadastrados: ' . count($cfg['paineis']) . ($cfg['monitor_token'] !== '' ? ' (+ monitor_token do arquivo)' : '') . "\n";
}
