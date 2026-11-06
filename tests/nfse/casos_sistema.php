<?php
declare(strict_types=1);

/**
 * Testes da tela "Sistema" do painel (script 0007): sinal de vida do
 * worker, pausar, retomar, reiniciar e resumo da fila (0007) e o botao
 * "Atualizar banco" (0008).
 * Incluido por tests/nfse/executar.php.
 */

use Xande\NfeIntegracao\Nfse\Dominio\AjustesPainel;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Servico\EnvioEmail;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\AtualizacaoBanco;
use Xande\NfeIntegracao\Nfse\Servico\ConfiguracoesPainel;
use Xande\NfeIntegracao\Nfse\Servico\ControleSistema;
use Xande\NfeIntegracao\Nfse\Servico\Diagnostico;

/** Sem banco: o diagnostico avisa e continua conferindo o resto. */
function testesSistemaUnidade(array $cfg): void
{
    echo "\n[12a] Diagnostico sem banco\n";
    $d = (new Diagnostico($cfg, null, dirname(__DIR__, 2)))->executar(false);
    $banco = array_values(array_filter($d['grupos'], fn($g) => $g['titulo'] === 'Banco de dados e worker'))[0] ?? null;
    Apoio::igual('ERRO', $banco['itens'][0]['nivel'] ?? null, 'sem banco: item de erro');
    Apoio::verificar(str_contains($banco['itens'][0]['resolver'] ?? '', 'database.local.php'), 'diz onde conferir a conexao');
    Apoio::igual('ERRO', $d['resultado'], 'resultado geral ERRO');
    Apoio::verificar(!in_array('Internet: acesso ao fisco', array_column($d['grupos'], 'titulo'), true), 'internet pulada quando pedido');

    echo "\n[12b] Configuracoes editaveis pelo painel\n";
    Apoio::igual([7, null], AjustesPainel::validar('portal.tentativas_login', '7'), 'numero dentro do limite aceito');
    Apoio::verificar(AjustesPainel::validar('portal.tentativas_login', '1')[1] !== null, 'numero fora do limite recusado');
    Apoio::verificar(AjustesPainel::validar('admin_token', 'x')[1] !== null, 'senha/token nao pode ser alterado pelo painel');
    Apoio::verificar(AjustesPainel::validar('salvador.url_visualizacao', 'http://site')[1] !== null, 'link sem https recusado');
    Apoio::igual(['https://nfse.salvador.ba.gov.br/v?n={numero}', null], AjustesPainel::validar('salvador.url_visualizacao', 'https://nfse.salvador.ba.gov.br/v?n={numero}'), 'link com {numero} aceito');
    $aplicado = AjustesPainel::aplicar($cfg, ['portal.tentativas_login' => 9, 'tpAmb' => 1, 'fila.max_tentativas' => 999]);
    Apoio::igual(9, $aplicado['portal']['tentativas_login'], 'ajuste valido aplicado na config');
    Apoio::igual($cfg['tpAmb'], $aplicado['tpAmb'], 'ambiente nunca muda pelo painel');
    Apoio::igual($cfg['fila']['max_tentativas'], $aplicado['fila']['max_tentativas'], 'valor invalido salvo no banco e ignorado');

    echo "\n[12c] E-mail (SMTP) do Esqueci a senha\n";
    Apoio::verificar(AjustesPainel::validar('email.remetente', 'Nome <a@b.com>')[1] !== null, 'remetente com nome e <> recusado');
    Apoio::igual(['a@b.com.br', null], AjustesPainel::validar('email.remetente', ' a@b.com.br '), 'remetente valido aceito');
    Apoio::verificar(AjustesPainel::validar('email.host', "mail\nx")[1] !== null, 'texto com quebra de linha recusado');
    Apoio::verificar(AjustesPainel::validar('email.senha', 'x')[1] !== null, 'senha do e-mail nao muda pelo painel');
    putenv('SMTP_HOST=mail.exemplo.com.br');
    putenv('SMTP_PORT=587');
    putenv('SMTP_SECURE=false');
    putenv('SMTP_PASSWORD=segredo');
    $comAmbiente = NfseConfig::montar(['tpAmb' => 2, 'cnpj' => '41986662000160', 'razao' => 'EMPRESA TESTE LTDA', 'siglaUF' => 'BA', 'ie' => '31638351'], ['email' => ['usuario' => 'contato@exemplo.com.br', 'remetente' => 'contato@exemplo.com.br']]);
    putenv('SMTP_HOST');
    putenv('SMTP_PORT');
    putenv('SMTP_SECURE');
    putenv('SMTP_PASSWORD');
    Apoio::igual(['mail.exemplo.com.br', 587, 'tls', 'segredo'], [$comAmbiente['email']['host'], $comAmbiente['email']['porta'], $comAmbiente['email']['seguranca'], $comAmbiente['email']['senha']],
        'variaveis SMTP_* do servidor preenchem o que falta (587 sem SSL = STARTTLS)');
    $envio = new EnvioEmail($cfg);
    Apoio::igual(['servidor (host)', 'usuário', 'senha', 'remetente'], $envio->pendencias(), 'sem config, diz o que falta');
    Apoio::verificar((new EnvioEmail($comAmbiente))->configurado(), 'com tudo preenchido, pronto para enviar');
}

function testesSistemaIntegracao(PDO $pdo, array $cfg): void
{
    echo "\n[12] Painel: tela Sistema (worker e fila)\n";

    $pdo->exec('DELETE FROM sistema_workers');
    $pdo->exec("UPDATE sistema_controle SET valor = '0' WHERE chave = 'worker_pausado'");
    $pdo->exec("DELETE FROM sistema_controle WHERE chave = 'worker_reiniciar'");

    $repo = new SistemaRepositorio($pdo);
    $controle = new ControleSistema($pdo);
    Apoio::verificar($controle->instalado(), 'tabelas do script 0007 existem');

    $s = $controle->status();
    Apoio::igual('PARADO', $s['situacao'], 'sem sinal de worker: PARADO');
    Apoio::verificar(str_contains($s['texto'], 'Nenhum worker ligou'), 'explica que o worker nunca ligou');

    // Worker da sinal de vida.
    $repo->batimento('srv:100', 'srv', 100, 'AGUARDANDO', 0);
    $s = $controle->status();
    Apoio::igual('LIGADO', $s['situacao'], 'com sinal recente: LIGADO');
    Apoio::igual(1, count($s['workers']), '1 worker listado');
    Apoio::igual('AGUARDANDO', $s['workers'][0]['estado'], 'estado do worker aparece');

    $repo->batimento('srv:100', 'srv', 100, 'TRABALHANDO', 3);
    Apoio::igual(3, $controle->status()['workers'][0]['jobs_feitos'], 'batimento atualiza a mesma linha');

    // Pausar e retomar.
    $r = $controle->executar('pausar', '10.0.0.1');
    Apoio::verificar($r['ok'] && $r['status']['situacao'] === 'PAUSADO', 'pausar: situacao PAUSADO');
    Apoio::verificar($repo->ordensDoWorker()['pausado'], 'worker le a ordem de pausa');
    $r = $controle->executar('retomar', '10.0.0.1');
    Apoio::verificar($r['ok'] && $r['status']['situacao'] === 'LIGADO', 'retomar: volta a LIGADO');
    Apoio::verificar(!$repo->ordensDoWorker()['pausado'], 'worker le a ordem de retomar');

    // Reiniciar: o worker antigo (ligado antes do pedido) deve sair.
    $pdo->exec("UPDATE sistema_workers SET iniciado_em = CURRENT_TIMESTAMP - INTERVAL '10 minutes'");
    $r = $controle->executar('reiniciar', '10.0.0.1');
    Apoio::igual('REINICIANDO', $r['status']['situacao'], 'reiniciar: aguardando o worker voltar');
    $pedido = $repo->ordensDoWorker()['reiniciar'];
    Apoio::verificar($pedido > time() - 600, 'worker ligado antes do pedido percebe que deve sair');
    $repo->removerWorker('srv:100');
    $repo->batimento('srv:200', 'srv', 200, 'AGUARDANDO', 0);
    Apoio::igual('LIGADO', $controle->status()['situacao'], 'worker novo ligado depois do pedido: LIGADO');

    // Historico das ordens.
    $acoes = array_column($controle->status()['acoes_recentes'], 'acao');
    Apoio::igual(['WORKER_REINICIADO', 'WORKER_RETOMADO', 'WORKER_PAUSADO'], array_slice($acoes, 0, 3), 'ordens ficam no historico (mais nova primeiro)');

    $r = $controle->executar('desligar_tudo', null);
    Apoio::verificar(!$r['ok'] && $r['http'] === 400, 'acao desconhecida recusada');

    // Worker que morreu sem se despedir (fora da janela de reinicio).
    $pdo->exec("DELETE FROM sistema_controle WHERE chave = 'worker_reiniciar'");
    $pdo->exec("UPDATE sistema_workers SET batimento_em = CURRENT_TIMESTAMP - INTERVAL '2 minutes'");
    $s = $controle->status();
    Apoio::igual('PARADO', $s['situacao'], 'sem sinal ha 2 minutos: PARADO');
    Apoio::verificar(str_contains($s['texto'], 'deixou de dar sinal'), 'explica que o worker parou de dar sinal');
    Apoio::igual(1, $repo->apagarWorkersMortos(60), 'limpeza apaga worker morto');

    // Resumo da fila (le nfse_jobs).
    $f = $controle->status()['fila'];
    Apoio::verificar(isset($f['esperando'], $f['executando'], $f['agendados'], $f['falhas_24h']), 'resumo da fila com os 4 numeros');

    $pdo->exec('DELETE FROM sistema_workers');

    // ---- Botao "Atualizar banco" ----
    $pdo->exec('DELETE FROM sistema_scripts');
    $banco = new AtualizacaoBanco($pdo);
    $st = $banco->status();
    Apoio::verificar(!$st['em_dia'] && $st['pendentes'] === count($st['scripts']), 'sem registro: todos os scripts pendentes');
    $r = $banco->aplicar('10.0.0.1');
    Apoio::verificar($r['ok'] && count($r['aplicados']) === count($st['scripts']), 'atualizar banco roda todos os scripts (podem repetir)');
    Apoio::verificar($r['banco']['em_dia'], 'depois de atualizar: banco em dia');
    $r = $banco->aplicar('10.0.0.1');
    Apoio::igual('O banco já estava em dia.', $r['mensagem'], 'segundo clique nao roda nada');
    Apoio::igual('BANCO_ATUALIZADO', $controle->status()['acoes_recentes'][0]['acao'], 'atualizacao fica no historico');

    // Arquivo mudou depois de aplicado: aparece de novo.
    $pdo->exec("UPDATE sistema_scripts SET sha256 = repeat('0', 64) WHERE nome LIKE '0001%'");
    $st = $banco->status();
    Apoio::igual('ALTERADO', $st['scripts'][0]['situacao'], 'script com arquivo diferente aparece como alterado');
    $banco->aplicar(null);

    // Script com erro: para, desfaz o script e mantem os anteriores.
    $pasta = sys_get_temp_dir() . '/nfse_scripts_' . bin2hex(random_bytes(4));
    mkdir($pasta);
    file_put_contents("{$pasta}/9001_ok.sql", 'BEGIN; CREATE TABLE IF NOT EXISTS teste_banco_ok (id INT); COMMIT;');
    file_put_contents("{$pasta}/9002_quebrado.sql", 'BEGIN; CREATE TABLE teste_banco_parcial (id INT); SELECT * FROM tabela_que_nao_existe; COMMIT;');
    $r = (new AtualizacaoBanco($pdo, $pasta))->aplicar(null);
    Apoio::verificar(!$r['ok'] && str_contains($r['mensagem'], '9002_quebrado.sql'), 'script com erro: mensagem diz qual foi');
    Apoio::verificar($repo->tabelaExiste('teste_banco_ok'), 'script anterior ao erro ficou aplicado');
    Apoio::verificar(!$repo->tabelaExiste('teste_banco_parcial'), 'nada do script com erro foi gravado');
    $st = (new AtualizacaoBanco($pdo, $pasta))->status();
    Apoio::igual(['APLICADO', 'PENDENTE'], array_column($st['scripts'], 'situacao'), 'o com erro continua pendente');
    $pdo->exec('DROP TABLE IF EXISTS teste_banco_ok');
    $pdo->exec("DELETE FROM sistema_scripts WHERE nome LIKE '900%'");
    array_map('unlink', glob("{$pasta}/*.sql"));
    rmdir($pasta);

    // ---- Diagnostico (sem internet) ----
    $d = (new Diagnostico($cfg, $pdo, dirname(__DIR__, 2)))->executar(false);
    $textos = [];
    foreach ($d['grupos'] as $g) {
        foreach ($g['itens'] as $i) {
            $textos[] = $i['texto'];
            Apoio::verificar(in_array($i['nivel'], ['OK', 'ATENCAO', 'ERRO'], true) && ($i['nivel'] === 'OK' || $i['resolver'] !== ''), "diagnostico: '{$i['texto']}' tem nivel e como resolver");
        }
    }
    Apoio::verificar(in_array('Conexão com o PostgreSQL', $textos, true), 'diagnostico confere a conexao');
    Apoio::verificar((bool)preg_grep('/^Banco em dia/', $textos), 'diagnostico ve o banco em dia');
    Apoio::igual(array_sum($d['total']), count($textos), 'total bate com os itens');

    // ---- Configuracoes ----
    $pdo->exec('DELETE FROM sistema_ajustes');
    $config = new ConfiguracoesPainel($pdo, $cfg);
    Apoio::verificar($config->instalado(), 'tabela do script 0009 existe');
    $lista = array_column($config->listar()['campos'], null, 'chave');
    Apoio::igual(count(AjustesPainel::CAMPOS), count($lista), 'lista mostra todas as configuracoes liberadas');
    Apoio::igual($cfg['portal']['tentativas_login'], $lista['portal.tentativas_login']['valor'], 'sem ajuste: vale o arquivo');

    $r = $config->salvar(['portal.tentativas_login' => '8', 'portal.padrao' => 'SALVADOR'], '10.0.0.1');
    Apoio::verificar($r['ok'] && count($r['alteradas']) === 2, 'salvar grava as duas mudancas');
    $comPainel = NfseConfig::comClientes($cfg, $pdo);
    Apoio::igual([8, 'SALVADOR'], [$comPainel['portal']['tentativas_login'], $comPainel['portal']['padrao']], 'API e portal passam a usar o valor do painel');
    Apoio::igual('CONFIG_ALTERADA', $controle->status()['acoes_recentes'][0]['acao'], 'mudanca fica no historico');

    $r = $config->salvar(['portal.tentativas_login' => (string)$cfg['portal']['tentativas_login']], null);
    Apoio::verificar(!array_key_exists('portal.tentativas_login', (new SistemaRepositorio($pdo))->ajustes()), 'voltar ao valor do arquivo apaga o ajuste');
    Apoio::igual('Nada mudou.', $config->salvar(['portal.padrao' => 'SALVADOR'], null)['mensagem'], 'salvar sem mudanca nao grava nada');

    $r = $config->salvar(['portal.bloqueio_minutos' => '0', 'tpAmb' => '1'], null);
    Apoio::verificar(!$r['ok'] && $r['http'] === 422, 'valores invalidos recusados');
    Apoio::igual(['portal.bloqueio_minutos', 'tpAmb'], array_column($r['erros'], 'campo'), 'erro aponta cada campo');
    $pdo->exec('DELETE FROM sistema_ajustes');
}
