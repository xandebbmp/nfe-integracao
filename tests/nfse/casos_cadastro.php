<?php
declare(strict_types=1);

/**
 * Testes do cadastro no banco (script 0005, telas do Scriptcase).
 * Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Servico\ProcessadorCertificados;

/** Grava uma empresa como o Scriptcase faria (arquivo no campo BYTEA). */
function cadastrarEmpresa(PDO $pdo, string $cnpj, ?string $pfx, ?string $senha, bool $ativo = true): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO fiscal_empresas (cnpj, razao_social, uf, ie, inscricao_municipal, certificado_arquivo, certificado_senha, ativo)
         VALUES (:cnpj, :razao, :uf, :ie, :im, :arquivo, :senha, :ativo)
         ON CONFLICT (cnpj) DO UPDATE SET certificado_arquivo = EXCLUDED.certificado_arquivo,
                                          certificado_senha = EXCLUDED.certificado_senha, ativo = EXCLUDED.ativo'
    );
    $stmt->bindValue(':cnpj', $cnpj);
    $stmt->bindValue(':razao', "Empresa {$cnpj}");
    $stmt->bindValue(':uf', 'ba');
    $stmt->bindValue(':ie', '31.638.351');
    $stmt->bindValue(':im', '123.456');
    $stmt->bindValue(':arquivo', $pfx, $pfx === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $stmt->bindValue(':senha', $senha);
    $stmt->bindValue(':ativo', $ativo, PDO::PARAM_BOOL);
    $stmt->execute();
}

function empresaCadastro(PDO $pdo, string $cnpj): array
{
    $stmt = $pdo->prepare('SELECT * FROM fiscal_empresas WHERE cnpj = ?');
    $stmt->execute([$cnpj]);
    return (array)$stmt->fetch(PDO::FETCH_ASSOC);
}

function testesCadastroIntegracao(PDO $pdo, array $cfgGeral): void
{
    echo "\n[10] Cadastro no banco (Scriptcase): empresas, certificados, sistemas, numeracao\n";

    $chave = CofreCertificado::gerarChave();
    $cofre = new CofreCertificado($chave);
    $aberto = $cofre->decifrar($cofre->cifrar('PFX-BINARIO', 'senha 1'));
    Apoio::igual(['pfx' => 'PFX-BINARIO', 'senha' => 'senha 1'], $aberto, 'cofre: cifra e decifra o certificado e a senha');
    $outraChave = false;
    try {
        (new CofreCertificado(CofreCertificado::gerarChave()))->decifrar($cofre->cifrar('x', 'y'));
    } catch (RuntimeException) {
        $outraChave = true;
    }
    Apoio::verificar($outraChave, 'cofre: outra chave nao abre o certificado');

    $pfx = Apoio::certificado()->writePfx('senha-do-pfx');
    $processador = new ProcessadorCertificados($pdo, $chave);

    // Empresa com certificado correto (CNPJ digitado com pontuacao).
    cadastrarEmpresa($pdo, '41.986.662/0001-60', $pfx, 'senha-do-pfx');
    $e = empresaCadastro($pdo, '41986662000160');
    Apoio::igual('AGUARDANDO', $e['certificado_situacao'] ?? null, 'empresa: CNPJ normalizado e certificado AGUARDANDO apos o upload');
    Apoio::igual('BA', $e['uf'], 'empresa: UF em maiusculo');
    Apoio::igual('31638351', $e['ie'], 'empresa: IE so com numeros');

    // Senha errada; certificado de outra empresa; arquivo em base64.
    cadastrarEmpresa($pdo, '11222333000181', $pfx, 'senha-do-pfx');
    cadastrarEmpresa($pdo, '41986662000241', base64_encode($pfx), 'senha-do-pfx');
    cadastrarEmpresa($pdo, '41986662000322', $pfx, 'errada');

    Apoio::igual(4, $processador->processarPendentes(), 'worker confere os 4 certificados enviados');

    $e = empresaCadastro($pdo, '41986662000160');
    Apoio::igual('OK', $e['certificado_situacao'], 'certificado correto fica OK');
    Apoio::verificar($e['certificado_arquivo'] === null && $e['certificado_senha'] === null, 'arquivo e senha enviados sao APAGADOS apos conferir');
    Apoio::verificar(str_contains((string)$e['certificado_mensagem'], 'valido ate'), 'mensagem mostra a validade');
    Apoio::verificar($e['certificado_validade'] !== null, 'validade gravada para a grade do Scriptcase');

    $e = empresaCadastro($pdo, '11222333000181');
    Apoio::igual('ERRO', $e['certificado_situacao'], 'certificado de outra empresa e recusado');
    Apoio::verificar(str_contains((string)$e['certificado_mensagem'], 'nao e desta empresa'), 'mensagem explica que o CNPJ do certificado e outro');
    Apoio::verificar($e['certificado_arquivo'] === null, 'arquivo recusado tambem e apagado');

    Apoio::igual('OK', empresaCadastro($pdo, '41986662000241')['certificado_situacao'], 'certificado gravado em base64 (filial, mesma raiz) e aceito');

    $e = empresaCadastro($pdo, '41986662000322');
    Apoio::igual('ERRO', $e['certificado_situacao'], 'senha errada vira ERRO');
    Apoio::verificar(str_contains((string)$e['certificado_mensagem'], 'Senha incorreta'), 'mensagem orienta sobre a senha');

    $cifrado = $pdo->query("SELECT count(*) FROM fiscal_empresas_certificados")->fetchColumn();
    Apoio::igual(2, (int)$cifrado, 'so os certificados aprovados sao guardados (criptografados)');

    // Sistemas e permissoes.
    $erroToken = false;
    try {
        $pdo->exec("INSERT INTO fiscal_sistemas (codigo, nome, token) VALUES ('CURTO', 'x', 'abc')");
    } catch (PDOException $ex) {
        $erroToken = str_contains($ex->getMessage(), 'Token muito curto');
    }
    Apoio::verificar($erroToken, 'sistema: token curto e recusado pelo banco');

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO fiscal_sistemas (codigo, nome, token, webhook_url) VALUES ('erp', 'ERP da matriz', ?, 'https://erp.teste/hook')")->execute([$token]);
    $pdo->exec("INSERT INTO fiscal_sistemas_empresas (sistema_codigo, empresa_cnpj) VALUES ('ERP', '41.986.662/0001-60')");
    $tokenPainel = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO fiscal_sistemas (codigo, nome, perfil, token) VALUES ('PAINEL_FISCAL', 'Monitor do fiscal', 'PAINEL', ?)")->execute([$tokenPainel]);

    $s = $pdo->query("SELECT * FROM fiscal_sistemas WHERE codigo = 'ERP'")->fetch(PDO::FETCH_ASSOC);
    Apoio::verificar($s['token'] === null, 'sistema: token digitado e apagado do banco');
    Apoio::igual(hash('sha256', $token), $s['token_hash'], 'sistema: banco guarda so o hash sha256 do token');
    Apoio::igual(substr($token, -4), $s['token_final'], 'sistema: guarda os 4 ultimos caracteres para conferencia');

    // Empresa inativa nao entra.
    cadastrarEmpresa($pdo, '41986662000403', null, null, false);

    $cfgGeral['emitente_implicito'] = true;
    $cfgGeral['cadastro']['chave'] = $chave;
    $cfg = NfseConfig::comCadastro($cfgGeral, $pdo);
    $cnpjs = array_map('strval', array_keys($cfg['emitentes']));
    sort($cnpjs);
    Apoio::igual(['11222333000181', '41986662000160', '41986662000241', '41986662000322'], $cnpjs, 'config: empresas ativas do cadastro (inativa fica de fora)');
    Apoio::igual('BA', $cfg['emitentes']['41986662000160']['uf'], 'config: UF da empresa vem do cadastro');
    Apoio::igual(['41986662000160'], NfseConfig::cnpjsPermitidos($cfg, 'ERP'), 'config: sistema ERP so pode usar a empresa vinculada');
    Apoio::igual(hash('sha256', $token), $cfg['consumidores']['ERP']['token_hash'], 'config: sistema do cadastro autentica pelo hash');
    Apoio::igual('https://erp.teste/hook', $cfg['consumidores']['ERP']['webhook_url'], 'config: webhook do sistema vem do cadastro');
    Apoio::igual([hash('sha256', $tokenPainel)], $cfg['paineis'], 'config: perfil PAINEL vira acesso ao monitor');
    Apoio::verificar(isset($cfg['consumidores']['TESTE']), 'config: sistemas do arquivo continuam valendo');

    $certificado = CertificadoCliente::doEmitente($cfg['emitentes']['41986662000160'], $chave)->certificado();
    Apoio::igual('41986662000160', $certificado->getCnpj(), 'certificado do cadastro abre para assinar (decifrado em memoria)');

    // Numeracao inicial.
    $pdo->exec("INSERT INTO fiscal_numeracao_inicial (empresa_cnpj, padrao, tp_amb, serie, ultimo_numero) VALUES ('41986662000160', 'NFE', 2, '001', 500)");
    $ultimo = $pdo->query("SELECT ultimo_numero FROM nfse_numeracao WHERE emitente_cnpj = '41986662000160' AND padrao = 'NFE' AND tp_amb = 2 AND serie = '1'")->fetchColumn();
    Apoio::igual(500, (int)$ultimo, 'numeracao: ultimo numero aplicado na serie (001 vira 1)');
    $resultado = $pdo->query("SELECT resultado FROM fiscal_numeracao_inicial ORDER BY id DESC LIMIT 1")->fetchColumn();
    Apoio::igual('Aplicado. Proximo numero: 501.', $resultado, 'numeracao: resultado mostra o proximo numero');

    $pdo->exec("INSERT INTO fiscal_numeracao_inicial (empresa_cnpj, padrao, tp_amb, serie, ultimo_numero) VALUES ('41986662000160', 'NFE', 2, '1', 100)");
    $ultimo = $pdo->query("SELECT ultimo_numero FROM nfse_numeracao WHERE emitente_cnpj = '41986662000160' AND padrao = 'NFE' AND tp_amb = 2 AND serie = '1'")->fetchColumn();
    Apoio::igual(500, (int)$ultimo, 'numeracao: numero menor NAO volta a numeracao para tras');
    $resultado = (string)$pdo->query("SELECT resultado FROM fiscal_numeracao_inicial ORDER BY id DESC LIMIT 1")->fetchColumn();
    Apoio::verificar(str_starts_with($resultado, 'Mantido'), 'numeracao: avisa que manteve o numero maior');
}
