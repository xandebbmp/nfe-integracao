<?php
declare(strict_types=1);

/**
 * Testes do login do painel admin com e-mail e senha (script 0014):
 * pessoas, sessoes, troca de senha, bloqueio, envio do acesso e o "autor"
 * no historico. Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\ConfiguracoesPainel;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;
use Xande\NfeIntegracao\Nfse\Servico\EnvioEmail;
use Xande\NfeIntegracao\Nfse\Nacional\DpsXmlBuilder;
use Xande\NfeIntegracao\Nfse\Servico\PainelAcesso;

function testesPainelIntegracao(PDO $pdo, array $cfg): void
{
    echo "\n[14] Painel admin: login com e-mail e senha\n";

    $acesso = new PainelAcesso($pdo, $cfg);
    Apoio::verificar($acesso->instalado() && !$acesso->temPessoas(), 'painel: tabelas do 0014 existem, ninguem cadastrado ainda');

    Apoio::igual('PAINEL_SEM_PESSOAS', $acesso->entrar('ana@empresa.com.br', 'qualquer', false, 'ip', 't')['codigo'] ?? null, 'login: sem ninguem cadastrado, manda usar a chave de emergencia');

    $r = $acesso->criar('Ana Paula Lima', ' Ana@Empresa.COM.br ', 'ip');
    Apoio::verificar($r['ok'] && strlen($r['senha_provisoria']) === 10 && $r['email'] === 'ana@empresa.com.br', 'painel: cria pessoa com senha provisoria');
    $ana = $r['id'];
    $provisoria = $r['senha_provisoria'];
    Apoio::igual('EMAIL_EM_USO', $acesso->criar('Outra', 'ana@empresa.com.br', 'ip')['codigo'] ?? null, 'painel: e-mail repetido recusado');
    Apoio::igual('EMAIL_INVALIDO', $acesso->criar('Outra', 'nao-e-email', 'ip')['codigo'] ?? null, 'painel: e-mail invalido recusado');
    Apoio::verificar($acesso->temPessoas(), 'painel: agora tem pessoa ativa');

    // ---- Login ----
    Apoio::igual('LOGIN_INVALIDO', $acesso->entrar('ninguem@x.com', 'abc', false, 'ip', 'teste')['codigo'] ?? null, 'login: e-mail inexistente, mensagem generica');
    $r = $acesso->entrar('ANA@empresa.com.br', $provisoria, false, 'ip', 'teste');
    Apoio::verificar($r['ok'] && $r['pessoa']['trocar_senha'] && $r['minutos'] === PainelAcesso::SESSAO_MINUTOS, 'login: entra com a provisoria e pede troca');
    $token = $r['token'];
    Apoio::igual(1, (int)$pdo->query('SELECT COUNT(*) FROM admin_sessoes WHERE token_hash = ' . $pdo->quote(hash('sha256', $token)))->fetchColumn(),
        'login: banco guarda so o sha256 do codigo');
    Apoio::igual('Ana Paula Lima', $acesso->sessao($token)['nome'] ?? null, 'sessao: codigo valido devolve a pessoa');
    Apoio::igual(null, $acesso->sessao(str_repeat('a', 64)), 'sessao: codigo inventado recusado');
    Apoio::igual(PainelAcesso::LEMBRAR_DIAS * 1440, $acesso->entrar('ana@empresa.com.br', $provisoria, true, 'ip', 't')['minutos'], 'login: "manter conectado" dura 30 dias');

    // ---- Troca de senha ----
    $eu = $acesso->sessao($token);
    Apoio::igual('SENHA_ATUAL_ERRADA', $acesso->trocarSenha($eu, $token, 'errada', 'nova-senha-2026')['codigo'] ?? null, 'troca: senha atual conferida');
    Apoio::igual('SENHA_FRACA', $acesso->trocarSenha($eu, $token, $provisoria, '123')['codigo'] ?? null, 'troca: senha fraca recusada');
    Apoio::verificar($acesso->trocarSenha($eu, $token, $provisoria, 'nova-senha-2026')['ok'], 'troca: senha nova gravada');
    Apoio::igual(false, $acesso->sessao($token)['trocar_senha'] ?? null, 'troca: nao pede mais troca e este login continua');
    Apoio::igual(1, (int)$pdo->query("SELECT COUNT(*) FROM admin_sessoes WHERE usuario_id = {$ana}")->fetchColumn(), 'troca: os outros logins dela saem');

    // ---- Bloqueio ----
    $r = $acesso->criar('Bruno Souza', 'bruno@empresa.com.br', 'ip');
    $bruno = $r['id'];
    for ($i = 0; $i < PainelAcesso::TENTATIVAS - 1; $i++) {
        $acesso->entrar('bruno@empresa.com.br', 'errada', false, 'ip', 't');
    }
    Apoio::igual('LOGIN_BLOQUEADO', $acesso->entrar('bruno@empresa.com.br', 'errada', false, 'ip', 't')['codigo'] ?? null, 'bloqueio: depois de 5 senhas erradas');
    Apoio::igual('LOGIN_BLOQUEADO', $acesso->entrar('bruno@empresa.com.br', $r['senha_provisoria'], false, 'ip', 't')['codigo'] ?? null, 'bloqueio: nem a senha certa entra');
    $nova = $acesso->redefinirSenha($bruno, 'ip');
    Apoio::verificar($acesso->entrar('bruno@empresa.com.br', $nova['senha_provisoria'], false, 'ip', 't')['ok'], 'bloqueio: senha nova destrava');

    // ---- Desativar ----
    Apoio::igual('NAO_PODE_DESATIVAR_A_SI', $acesso->definirAtivo($ana, false, $ana, 'ip')['codigo'] ?? null, 'desativar: ninguem desativa a si mesmo');
    $sessaoBruno = $acesso->entrar('bruno@empresa.com.br', $nova['senha_provisoria'], false, 'ip', 't')['token'];
    Apoio::verificar($acesso->definirAtivo($bruno, false, $ana, 'ip')['ok'] && $acesso->sessao($sessaoBruno) === null, 'desativar: login dele cai na hora');
    Apoio::igual('LOGIN_INVALIDO', $acesso->entrar('bruno@empresa.com.br', $nova['senha_provisoria'], false, 'ip', 't')['codigo'] ?? null, 'desativar: nao entra mais');

    // ---- Enviar acesso por e-mail ----
    $cartas = [];
    $smtp = ['email' => ['host' => 'smtp.teste', 'usuario' => 'u@teste.com', 'senha' => 'x', 'remetente' => 'u@teste.com']];
    $carteiro = new EnvioEmail($smtp, static function (array $m) use (&$cartas): void { $cartas[] = $m; });
    $c = $acesso->criar('Carla Dias', 'carla@empresa.com.br', 'ip');
    Apoio::igual('EMAIL_NAO_CONFIGURADO', $acesso->enviarAcessoPorEmail($c['id'], $c['senha_provisoria'], '', 'ip', new EnvioEmail(['email' => []]))['codigo'] ?? null,
        'enviar acesso: sem SMTP explica');
    Apoio::igual('SENHA_NAO_E_A_ATUAL', $acesso->enviarAcessoPorEmail($c['id'], 'outra', '', 'ip', $carteiro)['codigo'] ?? null, 'enviar acesso: so a provisoria atual');
    $r = $acesso->enviarAcessoPorEmail($c['id'], $c['senha_provisoria'], 'http://127.0.0.1:8099/admin/', 'ip', $carteiro);
    Apoio::verificar($r['ok'] && ($cartas[0]['para'] ?? '') === 'carla@empresa.com.br' && str_contains($cartas[0]['texto'], $c['senha_provisoria'])
        && str_contains($cartas[0]['texto'], 'http://127.0.0.1:8099/admin/'), 'enviar acesso: e-mail com link, e-mail e senha provisoria');

    // ---- Autor no historico ----
    ClienteRepositorio::$autor = 'Ana Paula Lima <ana@empresa.com.br>';
    (new ClienteRepositorio($pdo))->registrarLog('11444777000161', 'ALTERACAO', ['campo' => 'x'], 'ip');
    ClienteRepositorio::$autor = null;
    Apoio::igual('Ana Paula Lima <ana@empresa.com.br>', (new ClienteRepositorio($pdo))->historico('11444777000161', 1)[0]['autor'] ?? null,
        'historico: mostra quem fez a mudanca');

    // ---- Ambiente de emissao (so o painel troca) ----
    $arquivo = ['tpAmb' => 2] + $cfg;
    $config = new ConfiguracoesPainel($pdo, $arquivo);
    Apoio::igual(['atual' => 2, 'arquivo' => 2, 'no_painel' => false, 'producao_modelo_novo' => false], $config->ambiente(), 'ambiente: sem troca vale o arquivo');

    // Modelo da nota NFS-e Nacional na producao (a Receita troca um dia).
    $leiauteProducao = fn () => DpsXmlBuilder::leiaute(['tpAmb' => 1] + NfseConfig::comClientes($arquivo, $pdo));
    Apoio::igual(['xsd' => '1.01-producao', 'finNFSe' => false], $leiauteProducao(), 'modelo: producao comeca no antigo (do arquivo)');
    $r = $config->trocarModeloProducao(true, 'ip');
    Apoio::verificar($r['ok'] && $r['ambiente']['producao_modelo_novo'], 'modelo: painel passa a producao para o novo');
    Apoio::igual(['xsd' => '1.01', 'finNFSe' => true], $leiauteProducao(), 'modelo: API e worker usam o XSD novo e enviam finNFSe');
    Apoio::igual('AMBIENTE_MODELO_ALTERADO', $pdo->query("SELECT acao FROM fiscal_admin_log ORDER BY id DESC LIMIT 1")->fetchColumn(), 'modelo: troca fica no historico');
    Apoio::verificar($config->trocarModeloProducao(false, 'ip')['ok'] && $leiauteProducao()['finNFSe'] === false, 'modelo: volta para o antigo');
    Apoio::igual(['xsd' => '1.01', 'finNFSe' => true], DpsXmlBuilder::leiaute(['tpAmb' => 2] + NfseConfig::comClientes($arquivo, $pdo)), 'modelo: homologacao continua no novo');
    $pdo->exec("DELETE FROM sistema_ajustes WHERE chave = 'nacional.producao_modelo_novo'");
    Apoio::igual('CONFIRMACAO_FALTANDO', $config->trocarAmbiente(1, 'sim', 'ip')['codigo'] ?? null, 'ambiente: producao sem digitar PRODUCAO nao troca');
    Apoio::igual(2, NfseConfig::comClientes($arquivo, $pdo)['tpAmb'], 'ambiente: continua homologacao');
    ClienteRepositorio::$autor = 'Ana Paula Lima <ana@empresa.com.br>';
    Apoio::verificar($config->trocarAmbiente(1, ' produção ', '10.0.0.9')['ok'], 'ambiente: digitando PRODUCAO troca');
    ClienteRepositorio::$autor = null;
    Apoio::igual(1, NfseConfig::comClientes($arquivo, $pdo)['tpAmb'], 'ambiente: API, portal e worker passam a usar producao');
    $log = $pdo->query("SELECT detalhes, autor, ip FROM fiscal_admin_log WHERE acao = 'AMBIENTE_ALTERADO' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    Apoio::verificar(str_contains((string)$log['detalhes'], 'PRODUCAO') && $log['autor'] === 'Ana Paula Lima <ana@empresa.com.br>' && $log['ip'] === '10.0.0.9',
        'ambiente: historico com de/para, quem trocou e IP');
    Apoio::verificar($config->trocarAmbiente(2, '', 'ip')['ok'] && !$config->ambiente()['no_painel'], 'ambiente: voltar para homologacao nao pede palavra e apaga o ajuste');
    Apoio::igual('AMBIENTE_INVALIDO', $config->trocarAmbiente(3, '', 'ip')['codigo'] ?? null, 'ambiente: valor estranho recusado');
    $portal = new PortalAcesso($pdo, $cfg);
    $cnpjPortal = (string)$pdo->query('SELECT cnpj FROM fiscal_clientes LIMIT 1')->fetchColumn();
    $u = $portal->criarUsuario($cnpjPortal, 'Cliente Admin', 'cliente.admin@empresa.com.br', 'ip', true);
    $tokenPortal = $portal->entrar('cliente.admin@empresa.com.br', $u['senha_provisoria'], false, 'ip', 't')['token'] ?? '';
    Apoio::verificar($tokenPortal !== '' && $acesso->sessao($tokenPortal) === null, 'ambiente: login do portal (mesmo administrador da empresa) nao entra no painel');

    $acesso->sair($token);
    Apoio::igual(null, $acesso->sessao($token), 'sair: codigo deixa de valer');
    $pdo->exec('DELETE FROM admin_usuarios');
}
