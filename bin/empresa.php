<?php
declare(strict_types=1);

/**
 * Administracao multiempresa (somente CLI).
 *
 *   php bin/empresa.php chave-mestra
 *   php bin/empresa.php cadastrar --cnpj=... --razao=... --ie=... --uf=BA --cmun=2927408 --crt=3 [--fantasia=...] [--im=...] [--tpamb=2]
 *   NFE_PFX_SENHA=... php bin/empresa.php certificado --cnpj=... --pfx=/caminho/cert.pfx
 *   php bin/empresa.php numeracao --cnpj=... --serie=1 --ultimo=1234 [--modelo=55] [--tpamb=2]
 *   php bin/empresa.php listar
 *   php bin/empresa.php vencimentos [--dias=30]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Support/HttpSecurity.php';

use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Empresa\CertificadoCofre;
use Xande\NfeIntegracao\Empresa\EmpresaRepository;
use Xande\NfeIntegracao\Nfe\DocumentoStore;
use Xande\NfeIntegracao\Support\ChaveAcesso;

function sair(string $msg, int $code = 1): void
{
    fwrite($code === 0 ? STDOUT : STDERR, $msg . PHP_EOL);
    exit($code);
}

function opt(array $opts, string $name, bool $required = true): ?string
{
    $v = $opts[$name] ?? null;
    if ($required && ($v === null || $v === '' || $v === false)) {
        sair("Parametro obrigatorio: --{$name}");
    }
    return $v === null || $v === false ? null : (string)$v;
}

$cmd = $argv[1] ?? '';
// getopt() para no primeiro argumento posicional (o comando); parse manual de --nome=valor.
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } else {
        sair("Argumento invalido: {$arg} (use --nome=valor)");
    }
}

if ($cmd === 'chave-mestra') {
    sair("NFE_MASTER_KEY=" . CertificadoCofre::gerarChave() . "\nGuarde fora do repositorio (secret do Docker / variavel de ambiente).", 0);
}

$cfg = nfe_load_config();
$pdo = ConnectionFactory::make();
$repo = new EmpresaRepository($pdo, CertificadoCofre::fromConfig($cfg));

switch ($cmd) {
    case 'cadastrar':
        $row = $repo->salvar([
            'cnpj' => ChaveAcesso::normalizarCnpj((string)opt($opts, 'cnpj')),
            'razao_social' => opt($opts, 'razao'),
            'nome_fantasia' => opt($opts, 'fantasia', false),
            'ie' => preg_replace('/\D+/', '', (string)opt($opts, 'ie', false)) ?: null,
            'im' => opt($opts, 'im', false),
            'uf' => strtoupper((string)opt($opts, 'uf')),
            'c_mun' => (int)opt($opts, 'cmun'),
            'crt' => (int)opt($opts, 'crt'),
            'tp_amb' => (int)(opt($opts, 'tpamb', false) ?? 2),
        ]);
        sair("Empresa salva: {$row['cnpj']} - {$row['razao_social']} (tpAmb={$row['tp_amb']})", 0);

    case 'certificado':
        $cnpj = ChaveAcesso::normalizarCnpj((string)opt($opts, 'cnpj'));
        $pfxPath = (string)opt($opts, 'pfx');
        $pfx = @file_get_contents($pfxPath);
        if ($pfx === false || $pfx === '') {
            sair("Nao consegui ler o PFX: {$pfxPath}");
        }

        // Senha fora da linha de comando (nao fica no historico do shell).
        $senha = getenv('NFE_PFX_SENHA');
        if ($senha === false) {
            fwrite(STDOUT, 'Senha do certificado: ');
            $senha = rtrim((string)fgets(STDIN), "\r\n");
        }

        $row = $repo->salvarCertificado($cnpj, $pfx, $senha);
        sair("Certificado ativo: {$row['titular']} (valido ate {$row['valido_ate']})", 0);

    case 'numeracao':
        $cnpj = ChaveAcesso::normalizarCnpj((string)opt($opts, 'cnpj'));
        $empresa = $repo->buscarPorCnpj($cnpj) ?? sair("Empresa {$cnpj} nao cadastrada.");
        $tpAmb = (int)(opt($opts, 'tpamb', false) ?? $empresa['tp_amb']);
        $modelo = (int)(opt($opts, 'modelo', false) ?? 55);
        $serie = (int)opt($opts, 'serie');
        $ultimo = (int)opt($opts, 'ultimo');

        (new DocumentoStore($pdo))->definirUltimoNumero($cnpj, $tpAmb, $modelo, $serie, $ultimo);
        sair("Numeracao {$cnpj} tpAmb={$tpAmb} mod={$modelo} serie={$serie}: proximo nNF = " . ($ultimo + 1), 0);

    case 'listar':
        foreach ($repo->listar() as $e) {
            echo sprintf(
                "%s  %-40s  %s  tpAmb=%d  %s\n",
                $e['cnpj'],
                $e['razao_social'],
                $e['uf'],
                $e['tp_amb'],
                $e['ativo'] ? 'ativa' : 'inativa'
            );
        }
        exit(0);

    case 'vencimentos':
        $dias = (int)(opt($opts, 'dias', false) ?? 30);
        $rows = $repo->certificadosVencendo(new DateTimeImmutable("+{$dias} days"));
        if (!$rows) {
            sair("Nenhum certificado vence nos proximos {$dias} dias.", 0);
        }
        foreach ($rows as $r) {
            echo "{$r['cnpj']}  {$r['razao_social']}  vence em {$r['valido_ate']}\n";
        }
        exit(0);

    default:
        sair("Comandos: chave-mestra | cadastrar | certificado | numeracao | listar | vencimentos (ver cabecalho do arquivo)");
}
