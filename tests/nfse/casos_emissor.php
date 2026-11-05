<?php
declare(strict_types=1);

/**
 * Testes do emissor simples (script 0006, /emissor/): login, servicos,
 * tomador pela Receita, emissao em 3 campos, cancelamento e isolamento.
 * Incluido por tests/nfse/executar.php (depois dos testes de clientes).
 */

use Xande\NfeIntegracao\Nfse\Infra\ConsultaCnpj;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoEmissor;
use Xande\NfeIntegracao\Nfse\Servico\EmissorSimples;

function testesEmissorUnidade(): void
{
    echo "\n[11a] Emissor simples - valor e CPF/CNPJ\n";
    Apoio::igual([1500.5, 1500.5, 1500.5, 89.9, null, null, null],
        [EmissorSimples::valor('1.500,50'), EmissorSimples::valor('1500.50'), EmissorSimples::valor(1500.5), EmissorSimples::valor('R$ 89,90'),
         EmissorSimples::valor('0'), EmissorSimples::valor('abc'), EmissorSimples::valor('10,999')],
        'valor: aceita formato brasileiro, recusa zero, texto e mais de 2 casas');
    Apoio::igual([true, true, false, false, false],
        [EmissorSimples::documentoValido('11222333000181'), EmissorSimples::documentoValido('52998224725'),
         EmissorSimples::documentoValido('11222333000182'), EmissorSimples::documentoValido('11111111111'), EmissorSimples::documentoValido('123')],
        'CPF/CNPJ: confere os digitos verificadores');
}

function testesEmissorIntegracao(PDO $pdo, array $cfgGeral): void
{
    echo "\n[11] Emissor simples: login, servico, tomador, emissao em 3 campos\n";

    $cnpj = '11222333000181';
    $cfgGeral['chave_certificados'] = base64_encode(random_bytes(32));
    $admin = new AdministracaoClientes($pdo, $cfgGeral);
    $admin->salvar(['cnpj' => $cnpj, 'razao_social' => 'Consultoria Exemplo Ltda', 'padroes_permitidos' => ['NFE', 'NACIONAL', 'SALVADOR'],
        'emissao_liberada' => true], 'ip');
    $cfg = NfseConfig::comClientes($cfgGeral, $pdo);
    $adminEmissor = new AdministracaoEmissor($pdo);

    // ---- Acesso e servico (painel admin) ----
    $u = $adminEmissor->usuario(['acao' => 'CRIAR', 'cnpj' => $cnpj, 'nome' => 'Maria', 'email' => 'Maria@Cliente.com.br'], 'ip');
    Apoio::verificar($u['ok'] && strlen($u['senha']) === 10, 'admin: cria acesso com senha gerada (mostrada uma vez)');
    Apoio::igual('EMAIL_EM_USO', $adminEmissor->usuario(['acao' => 'CRIAR', 'cnpj' => $cnpj, 'nome' => 'X', 'email' => 'maria@cliente.com.br'], 'ip')['codigo'] ?? null,
        'admin: o mesmo e-mail nao entra duas vezes');
    Apoio::igual('DADOS_INVALIDOS', $adminEmissor->servico(['cnpj' => $cnpj, 'nome' => 'Consultoria', 'codigo_tributacao_nacional' => '123'], 'ip')['codigo'] ?? null,
        'admin: codigo de tributacao precisa de 6 digitos');
    $s = $adminEmissor->servico(['cnpj' => $cnpj, 'nome' => 'Consultoria', 'codigo_tributacao_nacional' => '17.01.01', 'aliquota_iss' => '5',
        'iss_retido' => false, 'descricao_padrao' => 'Consultoria empresarial'], 'ip');
    Apoio::verificar($s['ok'], 'admin: servico cadastrado (codigo com pontos e aceito)');
    $resumo = $admin->detalhe($cnpj)['emissor'];
    Apoio::igual([1, true, '170101'], [count($resumo['servicos']), $resumo['servicos'][0]['padrao'] ?? null, $resumo['servicos'][0]['codigo_tributacao_nacional'] ?? null],
        'admin: primeiro servico vira o padrao');

    // ---- Login ----
    $emissorHttp = new TransporteFalso();
    $emissor = new EmissorSimples($pdo, $cfg, new ConsultaCnpj($emissorHttp));
    Apoio::igual('LOGIN_INVALIDO', $emissor->entrar('naoexiste@x.com', 'abc', 'ip')['codigo'] ?? null, 'login: e-mail desconhecido');
    for ($i = 0; $i < 5; $i++) {
        $emissor->entrar('maria@cliente.com.br', 'errada', 'ip');
    }
    Apoio::igual('LOGIN_BLOQUEADO', $emissor->entrar('maria@cliente.com.br', $u['senha'], 'ip')['codigo'] ?? null,
        'login: 5 senhas erradas seguidas bloqueiam por 15 minutos (mesmo com a senha certa)');
    $nova = $adminEmissor->usuario(['acao' => 'NOVA_SENHA', 'cnpj' => $cnpj, 'id' => $u['id']], 'ip');
    $login = $emissor->entrar('MARIA@cliente.com.br', $nova['senha'], 'ip');
    Apoio::verificar($login['ok'] && strlen($login['token']) === 64, 'login: senha nova do admin libera; e-mail sem diferenciar maiusculas');
    $usuario = $emissor->usuarioDaSessao($login['token']);
    Apoio::igual($cnpj, $usuario['cnpj'] ?? null, 'sessao: identifica a empresa do usuario');
    Apoio::verificar(!str_contains((string)$pdo->query('SELECT token_hash FROM fiscal_sessoes LIMIT 1')->fetchColumn(), $login['token']), 'sessao: banco guarda so o hash');

    // ---- Tomador ----
    Apoio::igual('DOCUMENTO_INVALIDO', $emissor->tomador($usuario, '123.456.789-00')['codigo'] ?? null, 'tomador: CPF invalido e recusado');
    $emissorHttp->responder(200, json_encode([
        'razao_social' => 'BANCO EXEMPLO S.A.', 'email' => 'contato@banco.exemplo', 'ddd_telefone_1' => '7133334444',
        'descricao_tipo_de_logradouro' => 'AVENIDA', 'logradouro' => 'TANCREDO NEVES', 'numero' => '1000', 'complemento' => 'SALA 10',
        'bairro' => 'CAMINHO DAS ARVORES', 'cep' => '41820020', 'uf' => 'BA', 'codigo_municipio_ibge' => 2927408, 'municipio' => 'SALVADOR',
    ]));
    $t = $emissor->tomador($usuario, '00.000.000/0001-91');
    Apoio::igual(['RECEITA', 'BANCO EXEMPLO S.A.', '2927408', 'AVENIDA TANCREDO NEVES'],
        [$t['origem'] ?? null, $t['tomador']['nome'] ?? null, $t['tomador']['endereco']['codigo_municipio'] ?? null, $t['tomador']['endereco']['logradouro'] ?? null],
        'tomador: CNPJ completa nome e endereco pela Receita (BrasilAPI)');
    Apoio::verificar(str_ends_with($emissorHttp->requisicoes[0]['url'] ?? '', '/cnpj/v1/00000000000191'), 'tomador: consulta a BrasilAPI pelo CNPJ');
    $emissorHttp->responder(404, '{"message":"CNPJ nao encontrado"}');
    $t404 = $emissor->tomador($usuario, '11444777000161');
    Apoio::igual([true, null, ''], [$t404['ok'], $t404['origem'], $t404['tomador']['nome']], 'tomador: fora da Receita, segue e a pessoa digita o nome');

    // ---- Emitir (3 campos) ----
    $erro = $emissor->emitir($usuario, ['valor' => '0', 'tomador' => ['documento' => '00000000000191', 'nome' => 'Banco'], 'descricao' => 'x', 'chave' => 'teste-0001']);
    Apoio::igual('DADOS_INVALIDOS', $erro['codigo'] ?? null, 'emitir: valor zero e recusado com mensagem simples');

    $entrada = ['valor' => '1.500,50', 'tomador' => $t['tomador'], 'descricao' => 'Consultoria de novembro', 'chave' => 'form-abc-12345'];
    $r = $emissor->emitir($usuario, $entrada);
    Apoio::igual([true, 'NOVO'], [$r['ok'], $r['situacao_recebimento'] ?? null], 'emitir: nota entra na fila (mesmo caminho da API)');
    $doc = (new DocumentoRepositorio($pdo))->buscar((string)$r['id']);
    Apoio::igual(['NACIONAL', '170101', 5, false, 1500.5, '00000000000191', '2927408'],
        [$doc['padrao'], $doc['payload']['servico']['codigo_tributacao_nacional'] ?? null, $doc['payload']['valores']['aliquota_iss'] ?? null,
         $doc['payload']['valores']['iss_retido'] ?? null, $doc['payload']['valores']['valor_servicos'] ?? null,
         $doc['payload']['tomador']['cpf_cnpj'] ?? null, $doc['payload']['tomador']['endereco']['codigo_municipio'] ?? null],
        'emitir: codigo de tributacao, aliquota e ISS vem do servico; o resto, da tela');
    $repetido = $emissor->emitir($usuario, $entrada);
    Apoio::igual(['JA_EXISTENTE', $r['id']], [$repetido['situacao_recebimento'] ?? null, $repetido['id'] ?? null], 'emitir: clique duplo nao gera nota em dobro');

    $painel = $emissor->painel($usuario);
    Apoio::igual(['00000000000191', 1500.5, 'Consultoria'],
        [$painel['tomadores'][0]['documento'] ?? null, $painel['notas'][0]['valor'] ?? null, $painel['servicos'][0]['nome'] ?? null],
        'painel: tomador salvo, nota na lista e servico disponivel');
    Apoio::igual('SALVO', $emissor->tomador($usuario, '00000000000191')['origem'] ?? null, 'tomador: na proxima nota vem do cadastro (sem consultar a Receita)');

    // ---- Bloqueio da empresa ----
    $admin->salvar(['cnpj' => $cnpj, 'emissao_liberada' => false, 'motivo_bloqueio' => 'Mensalidade em aberto'], 'ip');
    Apoio::igual('EMISSAO_BLOQUEADA', $emissor->emitir($usuario, ['chave' => 'form-bloq-1234'] + $entrada)['codigo'] ?? null, 'bloqueio: empresa bloqueada nao emite pelo emissor');
    $admin->salvar(['cnpj' => $cnpj, 'emissao_liberada' => true], 'ip');

    // ---- Cancelar ----
    Apoio::igual('NAO_PODE_CANCELAR', $emissor->cancelar($usuario, $r['id'], '1', 'Valor errado na nota')['codigo'] ?? null, 'cancelar: so nota autorizada');
    $pdo->exec("UPDATE nfse_documentos SET status = 'AUTORIZADO', numero_nfse = '77', chave_acesso = '" . str_repeat('3', 50) . "' WHERE id = '{$r['id']}'");
    $c = $emissor->cancelar($usuario, $r['id'], '1', 'Valor digitado errado na nota');
    $eventos = (int)$pdo->query("SELECT count(*) FROM nfse_eventos WHERE documento_id = '{$r['id']}' AND tipo_evento = '101101'")->fetchColumn();
    Apoio::igual([true, 1], [$c['ok'], $eventos], 'cancelar: pedido de cancelamento vai para a fila');

    // ---- Isolamento e desativacao ----
    $outra = $adminEmissor->usuario(['acao' => 'CRIAR', 'cnpj' => '41986662000160', 'nome' => 'Joao', 'email' => 'joao@outra.com'], 'ip');
    $sessaoOutra = $emissor->usuarioDaSessao($emissor->entrar('joao@outra.com', $outra['senha'], 'ip')['token']);
    Apoio::verificar($emissor->documentoDaEmpresa($sessaoOutra, $r['id']) === null && $emissor->nota($sessaoOutra, $r['id']) === null,
        'isolamento: usuario de outra empresa nao ve a nota');
    Apoio::igual('NAO_ENCONTRADO', $emissor->cancelar($sessaoOutra, $r['id'], '1', 'Tentando cancelar nota alheia')['codigo'] ?? null,
        'isolamento: nem consegue cancelar nota de outra empresa');

    $adminEmissor->usuario(['acao' => 'DESATIVAR', 'cnpj' => $cnpj, 'id' => $u['id']], 'ip');
    Apoio::verificar($emissor->usuarioDaSessao($login['token']) === null, 'desativar: sessao aberta cai na hora');

    $troca = $emissor->trocarSenha($sessaoOutra, $outra['senha'], 'curta');
    Apoio::igual('SENHA_FRACA', $troca['codigo'] ?? null, 'trocar senha: minimo de 8 caracteres');
    Apoio::verificar($emissor->trocarSenha($sessaoOutra, $outra['senha'], 'senha-nova-123')['ok'] && $emissor->entrar('joao@outra.com', 'senha-nova-123', 'ip')['ok'],
        'trocar senha: a nova senha passa a valer');
}
