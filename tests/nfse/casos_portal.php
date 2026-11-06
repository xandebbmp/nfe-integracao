<?php
declare(strict_types=1);

/**
 * Testes do portal do cliente (script 0006): login, sessao, bloqueio por
 * tentativas, servicos salvos, consulta de CNPJ/CEP, emissao pelo formulario
 * e as telas Minhas notas e Tomadores.
 * Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\AdministracaoClientes;
use Xande\NfeIntegracao\Nfse\Servico\ConsultaCadastro;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;
use Xande\NfeIntegracao\Nfse\Servico\PortalEmissao;
use Xande\NfeIntegracao\Nfse\Servico\PortalEmpresa;
use Xande\NfeIntegracao\Nfse\Servico\PortalInicio;
use Xande\NfeIntegracao\Nfse\Servico\PortalRecorrencias;
use Xande\NfeIntegracao\Nfse\Servico\PortalServicos;
use Xande\NfeIntegracao\Nfse\Servico\PortalTomadores;
use Xande\NfeIntegracao\Nfse\Servico\PortalUsuarios;
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
    Apoio::igual(true, $r['administrador'], 'admin: primeira pessoa da empresa vira administradora');
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

    // ---- Tela "Minhas notas" ----
    $lista = $inicio->listarNotas($cnpj, []);
    Apoio::igual([2, 1, 1], [$lista['total'], $lista['pagina'], $lista['paginas']], 'notas: lista todas com total e paginas');
    Apoio::igual(0, $inicio->listarNotas($cnpj, ['status' => 'AUTORIZADAS'])['total'], 'notas: filtro "autorizadas"');
    Apoio::igual(2, $inicio->listarNotas($cnpj, ['status' => 'EM_ANDAMENTO'])['total'], 'notas: filtro "na prefeitura"');
    Apoio::igual(2, $inicio->listarNotas($cnpj, ['busca' => 'exemplo'])['total'], 'notas: busca pelo nome do tomador (sem maiusculas)');
    Apoio::igual(2, $inicio->listarNotas($cnpj, ['busca' => '11222333'])['total'], 'notas: busca pelo CPF/CNPJ do tomador');
    Apoio::igual(0, $inicio->listarNotas($cnpj, ['busca' => 'ninguem assim'])['total'], 'notas: busca sem resultado');
    Apoio::igual(2, $inicio->listarNotas($cnpj, ['mes' => date('Y-m')])['total'], 'notas: filtro pelo mes atual');
    Apoio::igual(0, $inicio->listarNotas($cnpj, ['mes' => '2020-01'])['total'], 'notas: outro mes nao mostra nada');
    Apoio::igual(0, $inicio->listarNotas('99999999000191', [])['total'], 'notas: outra empresa nao ve as notas');
    $pagina2 = $inicio->listarNotas($cnpj, ['pagina' => 2]);
    Apoio::igual([[], 2], [$pagina2['notas'], $pagina2['pagina']], 'notas: pagina depois da ultima vem vazia');
    $idNota = $lista['notas'][0]['id'];
    $pdo->prepare("UPDATE nfse_documentos SET status = 'REJEITADO' WHERE id = :id")->execute([':id' => $idNota]);
    Apoio::igual(1, $inicio->listarNotas($cnpj, ['status' => 'COM_PROBLEMA'])['total'], 'notas: filtro "com problema" pega a rejeitada');
    $detalhe = $inicio->nota($cnpj, $idNota);
    // A mais recente e a do servico digitado na hora (010101).
    Apoio::igual(['Cliente Exemplo Ltda', '010101', 1500.0], [$detalhe['tomador_completo']['nome'] ?? null,
        $detalhe['servico']['codigo_tributacao_nacional'] ?? null, (float)($detalhe['valores']['valor_servicos'] ?? 0)], 'nota: detalhe com tomador, servico e valores');
    Apoio::igual(null, $inicio->nota('41986662000160', $idNota), 'nota: outra empresa nao abre o detalhe');
    Apoio::igual(null, $inicio->nota($cnpj, 'nao-e-um-id'), 'nota: id invalido nem consulta');

    // ---- Tela "Tomadores" ----
    $tomadores = new PortalTomadores($pdo);
    $r = $tomadores->salvar($cnpj, ['cpf_cnpj' => '123', 'nome' => 'X', 'email' => 'sem-arroba', 'cep' => '123']);
    Apoio::igual(['cpf_cnpj', 'nome', 'email', 'cep'], array_column($r['erros'] ?? [], 'campo'), 'tomador: documento, nome, e-mail e CEP invalidos explicados');
    $r = $tomadores->salvar($cnpj, ['cpf_cnpj' => '529.982.247-25', 'nome' => 'Joana Lima', 'email' => 'Joana@Mail.com', 'uf' => 'ba']);
    Apoio::igual([true, 'joana@mail.com', 'BA', null], [$r['ok'], $r['tomador']['email'] ?? null, $r['tomador']['uf'] ?? null, array_key_exists('ultima_emissao', $r['tomador'] ?? []) ? $r['tomador']['ultima_emissao'] : 'x'],
        'tomador: cadastro manual salvo (sem data de ultima nota)');
    $antes = (new PortalRepositorio($pdo))->tomador($cnpj, '11222333000181')['ultima_emissao'];
    $r = $tomadores->salvar($cnpj, ['cpf_cnpj' => '11222333000181', 'nome' => 'Cliente Exemplo Ltda ME', 'cep' => '41820020']);
    $depois = (new PortalRepositorio($pdo))->tomador($cnpj, '11222333000181');
    Apoio::igual(['Cliente Exemplo Ltda ME', $antes], [$depois['nome'], $depois['ultima_emissao']], 'tomador: editar corrige o nome e mantem a data da ultima nota');
    Apoio::igual(2, count($tomadores->listar($cnpj, '')), 'tomador: lista da empresa');
    Apoio::igual(['Joana Lima'], array_column($tomadores->listar($cnpj, 'joana'), 'nome'), 'tomador: busca pelo nome');
    Apoio::igual([], $tomadores->listar('41986662000160', ''), 'tomador: outra empresa nao ve');
    Apoio::verificar($tomadores->remover($cnpj, '529.982.247-25')['ok'], 'tomador: removido da lista');
    Apoio::igual('TOMADOR_NAO_ENCONTRADO', $tomadores->remover($cnpj, '52998224725')['codigo'] ?? null, 'tomador: remover de novo explica que nao existe');

    // ---- Tela "Minha empresa" ----
    $empresa = new PortalEmpresa($pdo, $cfgGeral);
    $ficha = $empresa->ficha($cnpj);
    Apoio::igual(['Portal Teste Ltda', '998877', 'Salvador / BA'], [$ficha['cadastro']['razao_social'], $ficha['cadastro']['inscricao_municipal'],
        $ficha['cadastro']['municipio']], 'empresa: cadastro com razao social, inscricao municipal e cidade');
    Apoio::igual(['NACIONAL', 'SALVADOR'], $ficha['fiscal']['padroes'], 'empresa: mostra so os tipos de NFS-e contratados');
    Apoio::igual('Não optante', $ficha['fiscal']['simples_nacional'], 'empresa: codigo do Simples vira texto');
    Apoio::igual(['BLOQUEADA', 'Fatura em aberto'], [$ficha['plano']['situacao'], $ficha['plano']['motivo_bloqueio']], 'empresa: mostra o bloqueio e o motivo');
    Apoio::igual(false, $ficha['certificado']['enviado'], 'empresa: avisa que o certificado nao foi enviado');
    $json = json_encode($ficha);
    Apoio::verificar(!str_contains($json, 'token') && !str_contains($json, 'cifrado') && !str_contains($json, 'segredo') && !str_contains($json, 'observacao'),
        'empresa: nao devolve token, certificado, segredo nem observacao interna');
    Apoio::igual(null, $empresa->ficha('99999999000191'), 'empresa: CNPJ sem cadastro devolve vazio');

    // ---- Tela "Usuarios" ----
    $usuariosPortal = new PortalUsuarios($pdo, $cfgGeral);
    $eu = ['id' => $usuarioId, 'cnpj' => $cnpj, 'administrador' => true];
    $r = $usuariosPortal->executar($eu, ['acao' => 'convidar', 'nome' => 'Carlos Lima', 'email' => 'Carlos@Empresa.com.br'], 'ip');
    Apoio::verificar($r['ok'] && $r['administrador'] === false && strlen($r['senha_provisoria']) === 10, 'usuarios: administrador convida um emissor com senha provisoria');
    $carlosId = $r['id'];
    $carlos = ['id' => $carlosId, 'cnpj' => $cnpj, 'administrador' => false];
    Apoio::igual('SEM_PERMISSAO', $usuariosPortal->executar($carlos, ['acao' => 'convidar', 'nome' => 'X', 'email' => 'x@x.com'], 'ip')['codigo'] ?? null, 'usuarios: emissor nao convida');
    Apoio::igual('SEM_PERMISSAO', $usuariosPortal->executar($carlos, ['acao' => 'desativar', 'id' => $usuarioId], 'ip')['codigo'] ?? null, 'usuarios: emissor nao desativa ninguem');
    Apoio::igual('NAO_PODE_SI_MESMO', $usuariosPortal->executar($eu, ['acao' => 'desativar', 'id' => $usuarioId], 'ip')['codigo'] ?? null, 'usuarios: administrador nao se desativa (empresa nunca fica sem administrador)');
    Apoio::igual('NAO_PODE_SI_MESMO', $usuariosPortal->executar($eu, ['acao' => 'remover_admin', 'id' => $usuarioId], 'ip')['codigo'] ?? null, 'usuarios: administrador nao tira o proprio papel');
    Apoio::igual('ACAO_INVALIDA', $usuariosPortal->executar($eu, ['acao' => 'apagar', 'id' => $carlosId], 'ip')['codigo'] ?? null, 'usuarios: acao desconhecida recusada');
    $lista = $usuariosPortal->listar($carlos);
    $linhaCarlos = array_values(array_filter($lista['usuarios'], static fn($u) => $u['id'] === $carlosId))[0] ?? [];
    Apoio::igual([false, true, 'PRIMEIRO_ACESSO'], [$lista['pode_gerenciar'], $linhaCarlos['eu'] ?? null, $linhaCarlos['situacao'] ?? null], 'usuarios: emissor ve a lista sem poder mudar, com "voce" e 1o acesso');
    Apoio::verificar($usuariosPortal->executar($eu, ['acao' => 'tornar_admin', 'id' => $carlosId], 'ip')['ok'], 'usuarios: torna o colega administrador');
    Apoio::igual(true, $acesso->entrar('carlos@empresa.com.br', $r['senha_provisoria'], false, 'ip', 't')['usuario']['administrador'] ?? null, 'usuarios: login informa que e administrador');
    $usuariosPortal->executar($eu, ['acao' => 'desativar', 'id' => $carlosId], 'ip');
    $linhaCarlos = array_values(array_filter($usuariosPortal->listar($eu)['usuarios'], static fn($u) => $u['id'] === $carlosId))[0] ?? [];
    Apoio::igual('DESATIVADO', $linhaCarlos['situacao'] ?? null, 'usuarios: desativado aparece na lista');
    $outra = ['id' => $usuarioId, 'cnpj' => '41986662000160', 'administrador' => true];
    Apoio::igual('USUARIO_NAO_ENCONTRADO', $usuariosPortal->executar($outra, ['acao' => 'ativar', 'id' => $carlosId], 'ip')['codigo'] ?? null, 'usuarios: administrador de outra empresa nao mexe');

    // ---- Tela "Recorrencias" (datas fixas no passado: a competencia nunca fica no futuro) ----
    Apoio::igual(['2026-02-28', '2026-12-05'], [PortalRecorrencias::mesSeguinte('2026-01-28', 28), PortalRecorrencias::proximoDia('2026-11-06', 5)],
        'recorrencia: proxima data no mes seguinte e a partir de hoje');
    $rec = static fn(string $hoje) => new PortalRecorrencias($pdo, $cfgGeral, $hoje);
    $eu = ['id' => $usuarioId, 'cnpj' => $cnpj, 'administrador' => true];
    $base = [
        'acao' => 'salvar', 'nome' => 'Mensalidade Cliente Exemplo',
        'tomador' => ['cpf_cnpj' => '11.222.333/0001-81', 'nome' => 'Cliente Exemplo Ltda', 'email' => 'fin@cliente.com'],
        'servico_id' => $servicoId, 'descricao' => 'Consultoria mensal referente a {mes}',
        'valores' => ['valor_servicos' => 800], 'primeira_em' => '2026-01-15', 'competencia' => 'ATUAL', 'modo' => 'AUTOMATICA',
    ];
    $r = $rec('2026-01-10')->salvar($eu, ['nome' => 'X', 'primeira_em' => '2026-01-30', 'servico_id' => 999999, 'modo' => 'TALVEZ'] + $base);
    Apoio::igual(['nome', 'servico_id', 'modo', 'primeira_em'], array_column($r['erros'] ?? [], 'campo'), 'recorrencia: nome, servico, modo e dia acima de 28 explicados');
    $r = $rec('2026-01-10')->salvar($eu, ['primeira_em' => '2026-01-05'] + $base);
    Apoio::igual('primeira_em', $r['erros'][0]['campo'] ?? null, 'recorrencia: data no passado recusada');
    $r = $rec('2026-01-10')->salvar($eu, ['valores' => ['valor_servicos' => 0]] + $base);
    Apoio::igual('valores.valor_servicos', $r['erros'][0]['campo'] ?? null, 'recorrencia: valor zero recusado na hora (mesmas regras do Emitir)');
    $r = $rec('2026-01-10')->salvar($eu, $base);
    Apoio::verificar($r['ok'], 'recorrencia: automatica criada');
    $autoId = $r['id'];
    Apoio::igual('AGENDADA', $rec('2026-01-10')->listar($cnpj)[0]['situacao'] ?? null, 'recorrencia: antes do dia fica agendada');
    Apoio::igual([], $rec('2026-01-10')->listar('99999999000191'), 'recorrencia: outra empresa nao ve');

    // Empresa ainda bloqueada no painel: a tentativa falha, guarda o motivo e so tenta de novo amanha.
    Apoio::igual(['emitidas' => 0, 'falhas' => 1], $rec('2026-01-15')->executarAutomaticas(), 'recorrencia: no dia tenta emitir sozinha');
    $linha = $rec('2026-01-15')->listar($cnpj)[0];
    Apoio::verificar($linha['situacao'] === 'PENDENTE' && str_contains((string)$linha['ultima_mensagem'], 'bloqueada'), 'recorrencia: erro fica pendente com o motivo para o cliente');
    Apoio::igual(['emitidas' => 0, 'falhas' => 0], $rec('2026-01-15')->executarAutomaticas(), 'recorrencia: so uma tentativa por dia');
    Apoio::igual(1, $rec('2026-01-15')->pendentes($cnpj), 'recorrencia: conta como pendente (aviso na tela inicial)');

    (new AdministracaoClientes($pdo, $cfgGeral))->salvar(['cnpj' => $cnpj, 'emissao_liberada' => true], 'ip');
    Apoio::igual(['emitidas' => 1, 'falhas' => 0], $rec('2026-01-16')->executarAutomaticas(), 'recorrencia: empresa liberada, sai no dia seguinte');
    $linha = $rec('2026-01-16')->listar($cnpj)[0];
    Apoio::igual(['2026-02-15', 1, null, 'AGENDADA'], [$linha['proxima_em'], $linha['emitidas'], $linha['ultima_mensagem'], $linha['situacao']], 'recorrencia: segue para o mes seguinte');
    $doc = (new DocumentoRepositorio($pdo))->buscar((string)$linha['ultimo_documento_id']);
    Apoio::igual(["PORTAL-rec-{$autoId}-202601", '11222333000181'], [$doc['referencia'], $doc['payload']['tomador']['cpf_cnpj']], 'recorrencia: nota com referencia do mes (nunca sai duas vezes)');
    Apoio::verificar(str_contains($doc['payload']['servico']['descricao'], 'janeiro/2026'), 'recorrencia: {mes} vira o mes da competencia');

    // Confirmar: espera o clique, pular e fim.
    $r = $rec('2026-01-10')->salvar($eu, ['nome' => 'Suporte trimestral', 'modo' => 'CONFIRMAR', 'competencia' => 'ANTERIOR', 'primeira_em' => '2026-02-10', 'fim' => '2026-03'] + $base);
    $confId = $r['id'];
    Apoio::igual(0, $rec('2026-02-10')->executarAutomaticas()['emitidas'], 'recorrencia: "confirmar" nao sai sozinha');
    Apoio::igual('NAO_PENDENTE', $rec('2026-02-09')->emitirMes($eu, $confId)['codigo'] ?? null, 'recorrencia: emitir antes do dia recusado');
    $r = $rec('2026-02-10')->emitirMes($eu, $confId);
    Apoio::verificar($r['ok'] && str_contains($r['mensagem'], 'janeiro/2026'), 'recorrencia: clique emite com a competencia do mes anterior');
    Apoio::igual('NAO_PENDENTE', $rec('2026-02-10')->emitirMes($eu, $confId)['codigo'] ?? null, 'recorrencia: segundo clique nao emite de novo');
    Apoio::verificar($rec('2026-03-10')->pular($eu, $confId)['ok'], 'recorrencia: pula o mes');
    $linha = array_values(array_filter($rec('2026-03-11')->listar($cnpj), static fn($x) => $x['id'] === $confId))[0];
    Apoio::igual(['ENCERRADA', 1], [$linha['situacao'], $linha['emitidas']], 'recorrencia: passou do ultimo mes, encerra sozinha');
    Apoio::igual('RECORRENCIA_ENCERRADA', $rec('2026-03-11')->retomar($eu, $confId)['codigo'] ?? null, 'recorrencia: encerrada nao retoma');

    Apoio::verificar($rec('2026-01-20')->pausar($eu, $autoId)['ok'], 'recorrencia: pausa');
    Apoio::igual(['emitidas' => 0, 'falhas' => 0], $rec('2026-02-15')->executarAutomaticas(), 'recorrencia: pausada nao emite');
    Apoio::verificar(str_contains($rec('2026-04-20')->retomar($eu, $autoId)['mensagem'], '15/05/2026'), 'recorrencia: retoma no proximo dia marcado (meses pausados nao viram nota atrasada)');
    $rec('2026-04-21')->pausar($eu, $autoId);
    Apoio::verificar(str_contains($rec('2026-04-10')->retomar($eu, $autoId)['mensagem'], '15/05/2026'), 'recorrencia: retomar mantem a data marcada que ainda esta a frente');
    Apoio::igual('RECORRENCIA_NAO_ENCONTRADA', $rec('2026-04-20')->remover(['cnpj' => '99999999000191'], $autoId)['codigo'] ?? null, 'recorrencia: outra empresa nao apaga');
    Apoio::verificar($rec('2026-04-20')->remover($eu, $autoId)['ok'], 'recorrencia: apagada');
}
