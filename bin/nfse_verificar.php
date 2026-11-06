<?php
declare(strict_types=1);

/**
 * Verificador da instalacao.
 *
 * Confere, item por item, se o servidor esta pronto para emitir NFS-e e
 * diz COMO RESOLVER cada problema encontrado. Nao altera nada.
 *
 * As verificacoes principais sao as mesmas do botao "Diagnostico" do
 * painel admin (src/Nfse/Servico/Diagnostico.php). Aqui ficam so as que
 * precisam rodar antes do sistema carregar (PHP, vendor, arquivos de
 * configuracao) e as dos servicos do servidor (systemctl).
 *
 * Uso (na pasta do sistema, como o mesmo usuario do site):
 *   sudo -u www-data php bin/nfse_verificar.php
 *   sudo -u www-data php bin/nfse_verificar.php --rede    # tambem testa acesso ao fisco
 *
 * Resultado:
 *   [OK]      tudo certo
 *   [ATENCAO] funciona, mas vale corrigir
 *   [ERRO]    precisa corrigir antes de usar
 * Sai com codigo 1 se houver algum ERRO.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
$testarRede = in_array('--rede', $argv, true);
$total = ['OK' => 0, 'ATENCAO' => 0, 'ERRO' => 0];

/**
 * Registra o resultado de uma verificacao.
 *
 * @param string $nivel OK | ATENCAO | ERRO
 */
function item(string $nivel, string $texto, string $comoResolver = ''): void
{
    global $total;
    $total[$nivel]++;
    $rotulo = ['OK' => '[OK]     ', 'ATENCAO' => '[ATENCAO]', 'ERRO' => '[ERRO]   '][$nivel];
    echo "  {$rotulo} {$texto}\n";
    if ($comoResolver !== '' && $nivel !== 'OK') {
        echo "            -> Como resolver: {$comoResolver}\n";
    }
}

function secao(string $titulo): void
{
    echo "\n== {$titulo} ==\n";
}

$usuario = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
echo "Verificacao da instalacao - " . date('d/m/Y H:i:s') . "\n";
echo "Pasta: {$raiz}  |  Usuario: {$usuario}\n";

// ===========================================================================
secao('Antes de tudo: PHP, bibliotecas e arquivos de configuracao');
// ===========================================================================

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    item('ERRO', 'PHP ' . PHP_VERSION . ' e antigo (minimo 8.1).', 'instale o PHP 8.3: sudo bash deploy/instalar_pacotes.sh');
    resumoEFim();
}
if (!extension_loaded('pcntl')) {
    item('ATENCAO', 'Extensao pcntl ausente: o worker nao consegue parar de forma segura.', 'use o PHP de linha de comando do Ubuntu (php8.3-cli), que ja traz pcntl.');
}
$autoload = "{$raiz}/vendor/autoload.php";
if (!is_file($autoload)) {
    item('ERRO', 'Bibliotecas (pasta vendor) nao instaladas.', "na pasta {$raiz} rode: composer install --no-dev --optimize-autoloader");
    resumoEFim();
}
require $autoload;
item('OK', 'Bibliotecas instaladas (vendor/)');

$arquivos = [
    'config/nfe.php' => [true, 'faz parte do sistema: baixe de novo o codigo (git pull).'],
    'config/database.local.php' => [true, 'copie config/database.local.php.example para config/database.local.php e preencha (manual, passo 5).'],
    'config/nfse.local.php' => [false, 'copie config/nfse.local.php.example para config/nfse.local.php e preencha (manual, passo 7).'],
];
foreach ($arquivos as $arquivo => [$obrigatorio, $dica]) {
    $caminho = "{$raiz}/{$arquivo}";
    if (!is_file($caminho)) {
        item($obrigatorio ? 'ERRO' : 'ATENCAO', "{$arquivo} nao existe.", $dica);
        continue;
    }
    if (!is_readable($caminho)) {
        item('ERRO', "{$arquivo} existe, mas este usuario ({$usuario}) nao consegue ler.", "sudo chown root:www-data {$caminho} && sudo chmod 640 {$caminho}");
        continue;
    }
    if (str_contains($arquivo, '.local.') && ((fileperms($caminho) & 0777) & 0004)) {
        item('ATENCAO', "{$arquivo} pode ser lido por qualquer usuario do servidor (contem senhas).", "sudo chown root:www-data {$caminho} && sudo chmod 640 {$caminho}");
    } else {
        item('OK', $arquivo);
    }
}

try {
    $cfg = \Xande\NfeIntegracao\Nfse\NfseConfig::carregar();
    item('OK', 'Configuracao da NFS-e carregada');
} catch (Throwable $e) {
    item('ERRO', 'Configuracao invalida: ' . $e->getMessage(), 'revise config/nfse.local.php (compare com config/nfse.local.php.example).');
    resumoEFim();
}
if (strlen((string)$cfg['admin_token']) < 24) {
    item('ATENCAO', 'Painel admin desligado (admin_token vazio ou curto).', "coloque 'admin_token' em config/nfse.local.php (gere com: openssl rand -hex 32)");
} else {
    item('OK', 'Painel admin ligado (/admin/)');
}

$pdo = null;
try {
    $pdo = \Xande\NfeIntegracao\Database\ConnectionFactory::make();
    $cfg = \Xande\NfeIntegracao\Nfse\NfseConfig::comClientes($cfg, $pdo);
} catch (Throwable $e) {
    $causa = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
    item('ERRO', 'Nao conectou no banco: ' . trim($causa),
        'confira host, porta, nome do banco, usuario e senha em config/database.local.php e se o PostgreSQL esta ligado (sudo systemctl status postgresql).');
}

// ===========================================================================
// O resto e o mesmo diagnostico do botao "Diagnostico" do painel
// (src/Nfse/Servico/Diagnostico.php).
// ===========================================================================

$diagnostico = (new \Xande\NfeIntegracao\Nfse\Servico\Diagnostico($cfg, $pdo, $raiz))->executar($testarRede);
foreach ($diagnostico['grupos'] as $grupo) {
    secao($grupo['titulo']);
    foreach ($grupo['itens'] as $i) {
        item($i['nivel'], $i['texto'], $i['resolver']);
    }
}
if (!$testarRede) {
    secao('Internet: acesso ao fisco (pulado: use --rede)');
}

// ===========================================================================
secao('Servicos do servidor');
// ===========================================================================

$systemctl = trim((string)@shell_exec('command -v systemctl 2>/dev/null'));
if ($systemctl === '') {
    item('ATENCAO', 'Nao foi possivel consultar os servicos (systemctl indisponivel).', 'confira manualmente: sudo systemctl status nginx php8.3-fpm nfse-worker@1');
} else {
    foreach (['nginx', 'php8.3-fpm', 'postgresql', 'nfse-worker@1'] as $servico) {
        $estado = trim((string)@shell_exec('systemctl is-active ' . escapeshellarg($servico) . ' 2>/dev/null'));
        if ($estado === 'active') {
            item('OK', "Servico {$servico} rodando");
        } else {
            item($servico === 'nfse-worker@1' ? 'ERRO' : 'ATENCAO', "Servico {$servico}: " . ($estado ?: 'nao encontrado'),
                "sudo systemctl enable --now {$servico}" . ($servico === 'nfse-worker@1' ? ' nfse-worker@2' : ''));
        }
    }
}

resumoEFim();

function resumoEFim(): never
{
    global $total;
    echo "\n== Resumo ==\n";
    echo "  OK: {$total['OK']}   ATENCAO: {$total['ATENCAO']}   ERRO: {$total['ERRO']}\n";
    echo $total['ERRO'] === 0
        ? "  Pronto para uso" . ($total['ATENCAO'] > 0 ? ' (veja os itens de ATENCAO).' : '.') . "\n"
        : "  Corrija os itens com [ERRO] e rode a verificacao de novo.\n";
    exit($total['ERRO'] === 0 ? 0 : 1);
}
