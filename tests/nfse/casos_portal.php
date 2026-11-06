<?php
declare(strict_types=1);

/**
 * Testes do portal do cliente (script 0006): login, sessao, bloqueio por
 * tentativas, servicos salvos, consulta de CNPJ/CEP e emissao pelo formulario.
 * Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;
use Xande\NfeIntegracao\Nfse\Servico\ConsultaCadastro;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;
use Xande\NfeIntegracao\Nfse\Servico\PortalEmissao;
use Xande\NfeIntegracao\Nfse\Servico\PortalInicio;
use Xande\NfeIntegracao\Nfse\Servico\PortalServicos;
use Xande\NfeIntegracao\Nfse\Servico\RecebimentoLote;

/** Sem banco: digitos de CPF/CNPJ. */
function testesPortalUnidade(): void
{
    echo "\n[11a] Portal: CPF e CNPJ\n";
    Apoio::verificar(Documentos::cnpjValido('11444777000161'), 'CNPJ valido aceito');
    Apoio::verificar(!Documentos::cnpjValido('11444777000162'), 'CNPJ com digito errado recusado');
    Apoio::verificar(!Documentos::cnpjValido('11111111111111'), 'CNPJ com numeros repetidos recusado');
    Apoio::verificar(Documentos::cpfValido('52998224725'), 'CPF valido aceito');
    Apoio::verificar(!Documentos::cpfValido('52998224724'), 'CPF com digito errado recusado');
    Apoio::igual('A senha precisa ter pelo menos 8 caracteres.', PortalAcesso::problemaNaSenha('a1'), 'senha curta explicada');
    Apoio::igual(null, PortalAcesso::problemaNaSenha('nova-senha-2026'), 'senha com letras e numeros aceita');
}

function testesPortalIntegracao(PDO $pdo, array $cfgGeral): void
{
    echo "\n[11] Portal do cliente: login, servicos, consultas e emissao\n";

    $cnpj = '11444777000161';
    $cfgGeral['chave_certificados'] = base64_encode(random_bytes(32));
    $cfgGeral['portal']['tentativas_login'] = 3;
    (new AdministracaoClientes($pdo, $cfgGeral))->salvar(['cnpj' => $cnpj, 'razao_social' => 'Portal Teste Ltda',
        'inscricao_municipal' => '998877', 'padroes_permitidos' => ['NACIONAL', 'SALVADOR']], 'ip');

    // ---- Usuario criado no painel admin ----
    $acesso = new PortalAcesso($pdo, $cfgGeral);
    $r = $acesso->criarUsuario($cnpj, 'Mariana Souza', ' Mariana@Empresa.com.BR ', '10.0.0.1');
    Apoio::verificar($r['ok'] && strlen($r['senha_provisoria']) === 10, 'admin: cria usuario com senha provisoria de 10 caracteres');
    Apoio::igual('mariana@empresa.com.br', $r['email'], 'admin: e-mail guardado em minusculas');
    $provisoria = $r['senha_provisoria'];
    $usuarioId = $r['id'];
    Apoio::igual('EMAIL_EM_USO', $acesso->criarUsuario($cnpj, 'Outra', 'mariana@empresa.com.br', 'ip')['codigo'] ?? null, 'admin: e-mail repetido recusado');
    Apoio::igual('EMPRESA_NAO_ENCONTRADA', $acesso->criarUsuario('99999999000191', 'X', 'x@x.com', 'ip')['codigo'] ?? null, 'admin: empresa inexistente recusada');
    $hash = $pdo->query("SELECT senha_hash FROM portal_usuarios WHERE id = {$usuarioId}")->fetchColumn();
    Apoio::verificar($hash !== $provisoria && password_verify($provisoria, (string)$hash), 'senha guardada so como hash');

    // ---- Login ----
    Apoio::igual('LOGIN_INVALIDO', $acesso->entrar('ninguem@x.com', 'abc', false, 'ip', 'teste')['codigo'], 'login: e-mail inexistente com mensagem generica');
    $r = $acesso->entrar('MARIANA@empresa.com.br', $provisoria, false, 'ip', 'teste');
    Apoio::verificar($r['ok'] && $r['usuario']['trocar_senha'], 'login: entra com senha provisoria e pede troca');
    $token = $r['token'];
    Apoio::igual(720, $r['minutos'], 'login: sessao normal de 12 horas');
    Apoio::igual(30 * 1440, $acesso->entrar('mariana@empresa.com.br', $provisoria, true, 'ip', 'teste')['minutos'], 'login: "manter conectado" dura 30 dias');
    $guardado = $pdo->query('SELECT count(*) FROM portal_sessoes WHERE token_hash = ' . $pdo->quote(hash('sha256', $token)))->fetchColumn();
    Apoio::igual(1, (int)$guardado, 'sessao: banco guarda so o sha256 do cookie');
    Apoio::igual($cnpj, $acesso->sessao($token)['cnpj'] ?? null, 'sessao: cookie valido devolve o usuario e a empresa');
    Apoio::igual(null, $acesso->sessao(str_repeat('a', 64)), 'sessao: cookie inventado e recusado');

    // ---- Troca de senha ----
    Apoio::igual('SENHA_ATUAL_INCORRETA', $acesso->trocarSenha($usuarioId, 'errada', 'nova-senha-2026', $token)['codigo'] ?? null, 'senha: atual errada recusada');
    Apoio::igual('SENHA_FRACA', $acesso->trocarSenha($usuarioId, $provisoria, 'curta', $token)['codigo'] ?? null, 'senha: fraca recusada');
    Apoio::verificar($acesso->trocarSenha($usuarioId, $provisoria, 'nova-senha-2026', $token)['ok'], 'senha: trocada');
    Apoio::verificar($acesso->sessao($token)['trocar_senha'] === false, 'senha: deixa de pedir troca e a sessao atual continua');
    Apoio::igual(1, (int)$pdo->query("SELECT count(*) FROM portal_sessoes WHERE usuario_id = {$usuarioId}")->fetchColumn(), 'senha: outras sessoes encerradas');

    // ---- Bloqueio por tentativas ----
    $acesso->entrar('mariana@empresa.com.br', 'errada-1', false, 'ip', 't');
    $r = $acesso->entrar('mariana@empresa.com.br', 'errada-2', false, 'ip', 't');
    Apoio::verificar(str_contains($r['mensagem'], 'Restam 1'), 'bloqueio: avisa quantas tentativas restam');
    Apoio::igual('LOGIN_BLOQUEADO', $acesso->entrar('mariana@empresa.com.br', 'errada-3', false, 'ip', 't')['codigo'], 'bloqueio: bloqueia na 3a senha errada');
    Apoio::igual('LOGIN_BLOQUEADO', $acesso->entrar('mariana@empresa.com.br', 'nova-senha-2026', false, 'ip', 't')['codigo'], 'bloqueio: nem a senha certa entra enquanto bloqueado');
    $r = $acesso->redefinirSenha($cnpj, $usuarioId, 'ip');
    Apoio::verificar($r['ok'] && $acesso->entrar('mariana@empresa.com.br', $r['senha_provisoria'], false, 'ip', 't')['ok'], 'bloqueio: admin redefine a senha e libera');
    Apoio::igual(null, $acesso->sessao($token), 'redefinir: sessoes antigas encerradas');
    $acesso->definirAtivo($cnpj, $usuarioId, false, 'ip');
    Apoio::igual('LOGIN_INVALIDO', $acesso->entrar('mariana@empresa.com.br', $r['senha_provisoria'], false, 'ip', 't')['codigo'], 'desativado: nao entra');
    $acesso->definirAtivo($cnpj, $usuarioId, true, 'ip');
    $usuario = ['id' => $usuarioId, 'cnpj' => $cnpj];

    // ---- Servicos salvos ----
    $servicos = new PortalServicos($pdo);
    $r = $servicos->salvar($cnpj, ['nome' => 'X', 'descricao' => 'abc', 'codigo_tributacao_nacional' => '123', 'aliquota_iss' => 9]);
    Apoio::igual(4, count($r['erros'] ?? []), 'servico: nome, descricao, codigo e aliquota invalidos explicados');
    $r = $servicos->salvar($cnpj, ['nome' => 'Consultoria', 'descricao' => 'Consultoria em gestao referente a {mes}',
        'codigo_tributacao_nacional' => '17.01.01', 'item_lista_servico' => '17.01', 'aliquota_iss' => '5']);
    Apoio::verificar($r['ok'] && $r['servico']['codigo_tributacao_nacional'] === '170101' && $r['servico']['aliquota_iss'] === 5.0, 'servico: salvo com codigo so em numeros');
    $servicoId = $r['servico']['id'];
    Apoio::igual(1, count($servicos->listar($cnpj)), 'servico: aparece na lista da empresa');
    Apoio::igual([], $servicos->listar('41986662000160'), 'servico: nao aparece para outra empresa');

    // ---- Consulta de CNPJ e CEP (APIs publicas simuladas) ----
    $http = new TransporteFalso();
    $consulta = new ConsultaCadastro($pdo, $cfgGeral, $http);
    $http->responder(200, json_encode(['cnpj' => '11222333000181', 'razao_social' => 'CLIENTE EXEMPLO LTDA', 'email' => 'Fin@Cliente.com',
        'cep' => '41820020', 'descricao_tipo_de_logradouro' => 'AVENIDA', 'logradouro' => 'TANCREDO NEVES', 'numero' => '1000',
        'bairro' => 'CAMINHO DAS ARVORES', 'municipio' => 'SALVADOR', 'uf' => 'BA', 'codigo_municipio_ibge' => 2927408]));
    $r = $consulta->cnpj('11.222.333/0001-81');
    Apoio::igual(['CLIENTE EXEMPLO LTDA', 'fin@cliente.com', 'AVENIDA TANCREDO NEVES', '2927408'], [$r['nome'], $r['email'], $r['logradouro'], $r['codigo_municipio']],
        'consulta: CNPJ preenche nome, e-mail, endereco e codigo IBGE');
    Apoio::igual('CLIENTE EXEMPLO LTDA', $consulta->cnpj('11222333000181')['nome'] ?? null, 'consulta: segunda vez vem do cache (sem chamar a API publica)');
    Apoio::igual(1, count($http->requisicoes), 'consulta: so uma chamada externa');
    Apoio::igual('CNPJ_INVALIDO', $consulta->cnpj('11222333000182')['codigo'] ?? null, 'consulta: CNPJ com digito errado nem consulta');
    $http->responder(200, '{"erro": true}');
    Apoio::igual('CEP_NAO_ENCONTRADO', $consulta->cep('99999-999')['codigo'] ?? null, 'consulta: CEP inexistente explicado');
    $http->responder(0, '');
    Apoio::igual('CONSULTA_INDISPONIVEL', $consulta->cep('40015970')['codigo'] ?? null, 'consulta: API fora do ar pede preenchimento manual');

    // ---- Emissao pelo formulario ----
    $emissao = new PortalEmissao($pdo, $cfgGeral);
    $formulario = [
        'chave_envio' => 'teste-portal-0001',
        'tomador' => ['cpf_cnpj' => '11.222.333/0001-81', 'nome' => 'Cliente Exemplo Ltda', 'email' => 'fin@cliente.com',
            'cep' => '41820-020', 'logradouro' => 'Avenida Tancredo Neves', 'numero' => '1000', 'bairro' => 'Caminho das Arvores',
            'codigo_municipio' => '2927408', 'uf' => 'BA'],
        'servico' => ['id' => $servicoId],
        'descricao' => 'Consultoria em gestao referente a {mes}',
        'competencia' => date('Y-m'),
        'valores' => ['valor_servicos' => 1500, 'iss_retido' => false],
    ];

    $r = $emissao->emitir($usuario, ['chave_envio' => 'x'] + $formulario);
    Apoio::igual('CHAVE_ENVIO_INVALIDA', $r['codigo'] ?? null, 'emissao: chave de envio curta recusada');

    $invalido = $formulario;
    $invalido['tomador']['cpf_cnpj'] = '11222333000182';
    $invalido['valores']['valor_servicos'] = 0;
    $antes = (int)$pdo->query('SELECT count(*) FROM nfse_documentos')->fetchColumn();
    $r = $emissao->emitir($usuario, $invalido);
    Apoio::igual([422, 'DADOS_INVALIDOS'], [$r['http'] ?? null, $r['codigo'] ?? null], 'emissao: dados invalidos voltam para o formulario');
    Apoio::igual($antes, (int)$pdo->query('SELECT count(*) FROM nfse_documentos')->fetchColumn(), 'emissao: dado invalido nao grava nota rejeitada');

    $r = $emissao->emitir($usuario, $formulario);
    Apoio::igual([true, RecebimentoLote::NOVO, 'NACIONAL'], [$r['ok'], $r['situacao_recebimento'] ?? null, $r['padrao'] ?? null], 'emissao: nota entra na fila (Nacional)');
    $doc = (new DocumentoRepositorio($pdo))->buscar($r['id']);
    Apoio::igual([$cnpj, $cnpj, 'PORTAL-teste-portal-0001'], [$doc['consumidor'], $doc['emitente_cnpj'], $doc['referencia']],
        'emissao: empresa e a dona da nota, referencia PORTAL-');
    Apoio::igual(['170101', 5.0, '11222333000181'], [$doc['payload']['servico']['codigo_tributacao_nacional'],
        (float)$doc['payload']['valores']['aliquota_iss'], $doc['payload']['tomador']['cpf_cnpj']], 'emissao: codigos do servico salvo e tomador so com numeros');
    Apoio::verificar(!str_contains($doc['payload']['servico']['descricao'], '{mes}'), 'emissao: {mes} trocado pelo mes por extenso');
    $tomador = (new PortalRepositorio($pdo))->tomador($cnpj, '11222333000181');
    Apoio::igual(['Cliente Exemplo Ltda', '41820020'], [$tomador['nome'] ?? null, $tomador['cep'] ?? null], 'emissao: tomador salvo para a proxima vez');

    $r = $emissao->emitir($usuario, $formulario);
    Apoio::igual(RecebimentoLote::JA_EXISTENTE, $r['situacao_recebimento'] ?? null, 'emissao: clique duplo nao duplica a nota');

    $futuro = ['chave_envio' => 'teste-portal-0002', 'competencia' => date('Y-m', strtotime('+2 months'))] + $formulario;
    Apoio::igual('competencia', $emissao->emitir($usuario, $futuro)['erros'][0]['campo'] ?? null, 'emissao: competencia no futuro recusada');

    // Servico digitado na hora e salvo para depois
    $novo = ['chave_envio' => 'teste-portal-0003', 'servico' => ['codigo_tributacao_nacional' => '010101', 'aliquota_iss' => 2,
        'salvar' => true, 'nome' => 'Software']] + $formulario;
    Apoio::verificar($emissao->emitir($usuario, $novo)['ok'] ?? false, 'emissao: servico digitado na hora');
    Apoio::igual(2, count($servicos->listar($cnpj)), 'emissao: "salvar este servico" guarda na lista');

    // Empresa bloqueada no painel: nao emite e explica
    (new AdministracaoClientes($pdo, $cfgGeral))->salvar(['cnpj' => $cnpj, 'emissao_liberada' => false, 'motivo_bloqueio' => 'Fatura em aberto'], 'ip');
    $r = $emissao->emitir($usuario, ['chave_envio' => 'teste-portal-0004'] + $formulario);
    Apoio::igual([false, 'EMISSAO_BLOQUEADA'], [$r['ok'], $r['codigo'] ?? null], 'emissao: empresa bloqueada no painel nao emite');

    // ---- Tela inicial ----
    $inicio = new PortalInicio($pdo, $cfgGeral);
    $notas = $inicio->notas($cnpj);
    Apoio::igual(['Cliente Exemplo Ltda', 1500.0], [$notas[0]['tomador'] ?? null, $notas[0]['valor'] ?? null], 'inicio: ultimas notas com tomador e valor');
    Apoio::igual(2, $inicio->resumo($cnpj)['notas_no_mes'], 'inicio: notas do mes');
    Apoio::igual('SEM_CERTIFICADO', $inicio->resumo($cnpj)['avisos'][0]['tipo'] ?? null, 'inicio: avisa que falta o certificado');
    Apoio::igual('HOMOLOGACAO', $inicio->empresa($cnpj)['ambiente'], 'inicio: mostra o ambiente');
}
