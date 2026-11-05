<?php
declare(strict_types=1);

/**
 * Verificador da instalacao.
 *
 * Confere, item por item, se o servidor esta pronto para emitir NFS-e e
 * diz COMO RESOLVER cada problema encontrado. Nao altera nada.
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
secao('1. PHP');
// ===========================================================================

if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
    item('OK', 'PHP ' . PHP_VERSION);
} else {
    item('ERRO', 'PHP ' . PHP_VERSION . ' e antigo (minimo 8.1).', 'instale o PHP 8.3: sudo bash deploy/instalar_pacotes.sh');
}

$extensoes = [
    'curl' => 'php8.3-curl', 'dom' => 'php8.3-xml', 'openssl' => 'php8.3-cli', 'pdo_pgsql' => 'php8.3-pgsql',
    'soap' => 'php8.3-soap', 'mbstring' => 'php8.3-mbstring', 'zlib' => 'php8.3-cli', 'gd' => 'php8.3-gd',
    'bcmath' => 'php8.3-bcmath', 'xml' => 'php8.3-xml',
];
$faltando = [];
foreach ($extensoes as $ext => $pacote) {
    if (!extension_loaded($ext)) {
        $faltando[$ext] = $pacote;
    }
}
if ($faltando === []) {
    item('OK', 'Extensoes PHP necessarias instaladas');
} else {
    item('ERRO', 'Extensoes PHP faltando: ' . implode(', ', array_keys($faltando)),
        'sudo apt install ' . implode(' ', array_unique($faltando)) . ' && sudo systemctl restart php8.3-fpm');
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

// ===========================================================================
secao('2. Arquivos de configuracao');
// ===========================================================================

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
    $permissao = fileperms($caminho) & 0777;
    if (str_contains($arquivo, '.local.') && ($permissao & 0004)) {
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

if ((int)$cfg['tpAmb'] === 1) {
    item('ATENCAO', 'Ambiente: PRODUCAO (tpAmb = 1). As notas emitidas tem valor fiscal.', 'confirme que a homologacao foi concluida antes (docs/nfse-homologacao.md).');
} else {
    item('OK', 'Ambiente: HOMOLOGACAO (tpAmb = 2) - notas sem valor fiscal');
}

// ===========================================================================
secao('3. Banco de dados');
// ===========================================================================

$pdo = null;
try {
    $pdo = \Xande\NfeIntegracao\Database\ConnectionFactory::make();
    item('OK', 'Conexao com o PostgreSQL');
} catch (Throwable $e) {
    $causa = $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
    item('ERRO', 'Nao conectou no banco: ' . trim($causa),
        'confira host, porta, nome do banco, usuario e senha em config/database.local.php e se o PostgreSQL esta ligado (sudo systemctl status postgresql).');
}

if ($pdo !== null) {
    $existe = static function (string $sql) use ($pdo): bool {
        return (bool)$pdo->query($sql)->fetchColumn();
    };
    $checagens = [
        '0002 (tabelas da NFS-e)' => "SELECT to_regclass('public.nfse_documentos') IS NOT NULL",
        '0003 (multiempresa / arquivamento)' => "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'nfse_documentos' AND column_name = 'arquivado_em')",
        '0004 (NF-e na fila / monitor)' => "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'nfse_documentos' AND column_name = 'protocolo_autorizacao')",
        '0005 (empresas clientes / uso para cobranca)' => "SELECT to_regclass('public.fiscal_clientes') IS NOT NULL",
    ];
    foreach ($checagens as $script => $sql) {
        if ($existe($sql)) {
            item('OK', "Script {$script} aplicado");
        } else {
            item('ERRO', "Script {$script} NAO aplicado.", 'na pasta do sistema rode: bash deploy/aplicar_banco.sh');
        }
    }

    // Worker: ha trabalho parado ha muito tempo?
    if ($existe("SELECT to_regclass('public.nfse_jobs') IS NOT NULL")) {
        $parados = (int)$pdo->query("SELECT count(*) FROM nfse_jobs WHERE status = 'PENDENTE' AND executar_apos < now() - interval '5 minutes'")->fetchColumn();
        if ($parados > 0) {
            item('ERRO', "{$parados} tarefa(s) esperando ha mais de 5 minutos: o worker parece parado.",
                'sudo systemctl status nfse-worker@1  (para ligar: sudo systemctl enable --now nfse-worker@1 nfse-worker@2)');
        } else {
            item('OK', 'Fila sem tarefas atrasadas');
        }
    }
}

// Empresas clientes (painel admin): entram nas verificacoes abaixo.
if ($pdo !== null) {
    try {
        $cfg = \Xande\NfeIntegracao\Nfse\NfseConfig::comClientes($cfg, $pdo);
    } catch (Throwable $e) {
        item('ERRO', 'Empresas do painel admin nao puderam ser lidas: ' . $e->getMessage(), 'rode: bash deploy/aplicar_banco.sh');
    }
}
if (strlen((string)$cfg['admin_token']) < 24) {
    item('ATENCAO', 'Painel admin desligado (admin_token vazio ou curto).', "para usar /admin/: coloque 'admin_token' em config/nfse.local.php (gere com: openssl rand -hex 32)");
} else {
    item('OK', 'Painel admin ligado (/admin/)');
}
try {
    new \Xande\NfeIntegracao\Nfse\Infra\CofreCertificado((string)$cfg['chave_certificados']);
    item('OK', 'Chave dos certificados configurada');
} catch (Throwable) {
    item('ATENCAO', 'Chave dos certificados ausente ou invalida: o painel admin nao aceita certificados.',
        "coloque 'chave_certificados' em config/nfse.local.php (gere com: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;')");
}

// ===========================================================================
secao('4. Empresas (emitentes) e certificados');
// ===========================================================================

foreach ($cfg['emitentes'] as $cnpj => $emitente) {
    $cnpj = (string)$cnpj;
    $nome = trim("{$emitente['razao_social']} ({$cnpj})");

    if (trim((string)$emitente['inscricao_municipal']) === '') {
        item('ATENCAO', "{$nome}: inscricao municipal vazia (obrigatoria em Salvador).", 'preencha inscricao_municipal em config/nfse.local.php.');
    }

    $arquivo = (string)($emitente['certificado']['arquivo'] ?? '');
    $doBanco = !empty($emitente['certificado']['cifrado']);
    if (!$doBanco && str_starts_with(realpath($arquivo) ?: $arquivo, realpath("{$raiz}/public") ?: "{$raiz}/public")) {
        item('ERRO', "{$nome}: o certificado esta dentro da pasta public (visivel na internet!).", 'mova o .pfx para storage/certs/ e ajuste o caminho.');
    }

    try {
        $certificado = \Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente::doEmitente($emitente, (string)$cfg['chave_certificados'])->certificado();
        $dias = (int)floor(($certificado->getValidTo()->getTimestamp() - time()) / 86400);
        $cnpjCertificado = \Xande\NfeIntegracao\Nfse\NfseConfig::normalizarCnpj($certificado->getCnpj() ?? '');

        if ($cnpjCertificado !== '' && substr($cnpjCertificado, 0, 8) !== substr($cnpj, 0, 8)) {
            item('ERRO', "{$nome}: o certificado e do CNPJ {$cnpjCertificado}, de outra empresa.", 'use o e-CNPJ (A1) desta empresa.');
        } elseif ($dias < 30) {
            item('ATENCAO', "{$nome}: certificado vence em {$dias} dia(s) (" . $certificado->getValidTo()->format('d/m/Y') . ').', 'providencie a renovacao e troque o arquivo .pfx (manual, "Renovar o certificado").');
        } else {
            item('OK', "{$nome}: certificado valido ate " . $certificado->getValidTo()->format('d/m/Y'));
        }
    } catch (Throwable $e) {
        $mensagem = $e->getMessage();
        $dica = str_contains(strtolower($mensagem), 'senha') || str_contains(strtolower($mensagem), 'password')
            ? 'confira a senha do certificado em config/nfse.local.php (ou certPassword em config/nfe.local.php).'
            : "confira se o arquivo existe e se o usuario {$usuario} consegue ler: sudo chown root:www-data ARQUIVO.pfx && sudo chmod 640 ARQUIVO.pfx";
        item('ERRO', "{$nome}: certificado nao abriu - {$mensagem}", $dica);
    }
}

// ===========================================================================
secao('5. Acesso a API (tokens)');
// ===========================================================================

$consumidores = $cfg['consumidores'];
if ($consumidores === []) {
    item('ERRO', 'Nenhum sistema (consumidor) com token configurado: ninguem consegue usar a API.', "cadastre em 'consumidores' no config/nfse.local.php. Gere um token com: openssl rand -hex 32");
}
foreach ($consumidores as $nome => $dados) {
    $token = (string)($dados['token'] ?? '');
    if (!empty($dados['token_hash'])) {
        $token = str_repeat('x', 64); // token gerado pelo painel admin (64 caracteres)
    }
    if (strlen($token) < 24 || str_contains(strtoupper($token), 'TOKEN')) {
        item('ERRO', "Sistema {$nome}: token vazio, curto ou de exemplo.", 'gere um token forte com: openssl rand -hex 32');
        continue;
    }
    $cnpjs = \Xande\NfeIntegracao\Nfse\NfseConfig::cnpjsPermitidos($cfg, (string)$nome);
    if ($cnpjs === []) {
        item('ERRO', "Sistema {$nome}: nenhum CNPJ liberado (com varias empresas, 'cnpjs' e obrigatorio).", "adicione 'cnpjs' => ['CNPJ1', ...] no bloco do sistema {$nome}.");
    } else {
        item('OK', "Sistema {$nome}: token ok, pode emitir por " . implode(', ', $cnpjs)
            . (!empty($dados['webhook_url']) ? ' | webhook: ' . $dados['webhook_url'] : ''));
    }
    if (!empty($dados['webhook_url']) && empty($dados['webhook_segredo'])) {
        item('ATENCAO', "Sistema {$nome}: webhook sem segredo (o sistema nao consegue conferir a assinatura).", 'gere com: openssl rand -hex 32');
    }
}

$tokenPainel = (string)($cfg['monitor_token'] ?? '');
if ($tokenPainel === '' || strlen($tokenPainel) < 24 || str_contains(strtoupper($tokenPainel), 'TOKEN')) {
    item('ATENCAO', 'Token do Monitor (monitor_token) vazio ou fraco: a pagina /monitor/ nao vai abrir.', 'gere com: openssl rand -hex 32 e coloque em monitor_token.');
} else {
    item('OK', 'Token do Monitor configurado');
}

// ===========================================================================
secao('6. Pastas e armazenamento dos XMLs');
// ===========================================================================

foreach (['storage/logs', 'storage/xml'] as $pasta) {
    $caminho = "{$raiz}/{$pasta}";
    if (!is_dir($caminho)) {
        @mkdir($caminho, 0775, true);
    }
    if (is_dir($caminho) && is_writable($caminho)) {
        item('OK', "{$pasta} com permissao de escrita");
    } else {
        item('ERRO', "{$pasta} sem permissao de escrita para {$usuario}.", "sudo mkdir -p {$caminho} && sudo chown -R www-data:www-data {$caminho}");
    }
}

$tipo = $cfg['armazenamento']['tipo'] ?? 'nenhum';
if ($tipo === 'nenhum') {
    item('ATENCAO', 'Copia dos XMLs fora do banco desligada (armazenamento = nenhum).', 'decisao C6: configure "local" ou "s3" em config/nfse.local.php. Enquanto isso, faca backup diario do banco.');
} else {
    try {
        $armazenamento = \Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoFactory::criar($cfg);
        if ($tipo === 'local') {
            $armazenamento->salvar('_verificacao/teste.txt', 'ok ' . date(DATE_ATOM), 'text/plain');
        }
        item('OK', "Armazenamento externo: {$tipo}" . ($tipo === 'local' ? ' (gravacao de teste ok)' : ' (credenciais preenchidas)'));
    } catch (Throwable $e) {
        item('ERRO', "Armazenamento {$tipo}: " . $e->getMessage(), "revise o bloco 'armazenamento' em config/nfse.local.php.");
    }
}

// ===========================================================================
secao('7. Acesso ao fisco pela internet' . ($testarRede ? '' : ' (pulado: use --rede)'));
// ===========================================================================

if ($testarRede) {
    $tpAmb = (int)$cfg['tpAmb'];
    $destinos = [
        'Sefin Nacional' => $cfg['nacional']['url_sefin'][$tpAmb],
        'ADN (DANFSe)' => $cfg['nacional']['url_danfse'][$tpAmb],
        'NFS-e Salvador' => $cfg['salvador']['url_base'][$tpAmb],
    ];

    // NF-e: endereco da SEFAZ de cada UF de emitente, da lista oficial da sped-nfe.
    $listaSefaz = @simplexml_load_file("{$raiz}/vendor/nfephp-org/sped-nfe/storage/wsnfe_4.00_mod55.xml");
    $ambiente = $tpAmb === 1 ? 'producao' : 'homologacao';
    foreach (array_unique(array_filter(array_column($cfg['emitentes'], 'uf'))) as $uf) {
        $url = '';
        foreach ($listaSefaz ? $listaSefaz->UF : [] as $no) {
            if ((string)$no->sigla === $uf) {
                $url = (string)$no->{$ambiente}->NfeAutorizacao;
            }
        }
        if ($url !== '') {
            $destinos["SEFAZ {$uf} (NF-e)"] = $url;
        } else {
            item('ATENCAO', "SEFAZ {$uf}: estado usa SEFAZ virtual; teste o acesso com bin/nfse_homologar.php --padrao=NFE.");
        }
    }
    foreach ($destinos as $nome => $url) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($curl);
        curl_close($curl);
        // Qualquer resposta HTTP (mesmo 403/404) prova que o servidor alcanca o fisco.
        if ($status > 0) {
            item('OK', "{$nome} alcancavel ({$url} respondeu HTTP {$status})");
        } else {
            item('ERRO', "{$nome} inacessivel: {$erro}", 'libere no firewall a saida HTTPS (porta 443) para ' . parse_url($url, PHP_URL_HOST) . '.');
        }
    }
}

// ===========================================================================
secao('8. Servicos do servidor');
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
