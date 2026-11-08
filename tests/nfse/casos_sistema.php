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
use Xande\NfeIntegracao\Nfse\ArquivoEnv;
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

    echo "\n[12a2] Diagnostico: avisos que nao sao problema\n";
    $prod = $cfg;
    $prod['tpAmb'] = 1;
    $prod['emitentes'] = [
        '11111111000191' => ['razao_social' => 'NACIONAL SO', 'inscricao_municipal' => '', 'padroes_permitidos' => ['NACIONAL'], 'certificado' => ['arquivo' => '/nao/existe.pfx']],
        '22222222000191' => ['razao_social' => 'SALVADOR', 'inscricao_municipal' => '', 'padroes_permitidos' => ['SALVADOR'], 'certificado' => ['arquivo' => '/nao/existe.pfx']],
    ];
    $d = (new Diagnostico($prod, null, dirname(__DIR__, 2)))->executar(false);
    $itens = array_merge(...array_column($d['grupos'], 'itens'));
    $ambiente = array_values(array_filter($itens, fn($i) => str_starts_with($i['texto'], 'Ambiente')))[0] ?? [];
    Apoio::igual('OK', $ambiente['nivel'] ?? null, 'producao nao vira aviso (a tarja do painel ja avisa)');
    $im = array_values(array_filter($itens, fn($i) => str_contains($i['texto'], 'inscrição municipal')));
    Apoio::igual(1, count($im), 'inscricao municipal: so avisa quem emite NFS-e Salvador');
    Apoio::verificar(str_contains($im[0]['texto'] ?? '', '22222222000191'), 'inscricao municipal: aviso e da empresa de Salvador');
    foreach ($itens as $i) {
        Apoio::verificar(!preg_match('/systemctl|sudo |openssl rand|bash deploy/', $i['resolver']), "dica sem comando de terminal: '{$i['texto']}'");
    }

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
    Apoio::verificar(AjustesPainel::validar('email.senha', 'x')[1] !== null, 'senha do e-mail nao passa pelos ajustes comuns (tem campo proprio, criptografado)');
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
    $lido = ArquivoEnv::ler("# comentario\nSMTP_HOST=mail.exemplo.com.br\nSMTP_PASSWORD=\"s#nha com espaco\"\nexport SMTP_PORT=587 # porta\nlinha estranha\nSMTP_FROM='a@b.com'\n");
    Apoio::igual(['SMTP_HOST' => 'mail.exemplo.com.br', 'SMTP_PASSWORD' => 's#nha com espaco', 'SMTP_PORT' => '587', 'SMTP_FROM' => 'a@b.com'], $lido,
        '.env: le chave=valor, aspas, export e comentarios; ignora linha estranha');
    $arquivoEnv = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($arquivoEnv, "TESTE_ENV_NOVA=do-arquivo\nTESTE_ENV_SERVIDOR=do-arquivo\n");
    putenv('TESTE_ENV_SERVIDOR=do-servidor');
    $carregadas = ArquivoEnv::carregar($arquivoEnv);
    Apoio::igual([['TESTE_ENV_NOVA'], 'do-arquivo', 'do-servidor'], [$carregadas, getenv('TESTE_ENV_NOVA'), getenv('TESTE_ENV_SERVIDOR')],
        '.env: preenche o que falta; variavel do servidor vale mais');
    putenv('TESTE_ENV_NOVA');
    putenv('TESTE_ENV_SERVIDOR');
    unlink($arquivoEnv);
    Apoio::igual([], ArquivoEnv::carregar('/nao/existe/.env'), '.env: sem arquivo, segue normal');
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

    // Ligar e desligar pelo painel (o processo e trocado por um falso no teste).
    $ligou = 0;
    $falso = new ControleSistema($pdo, function () use (&$ligou): ?string { $ligou++; return null; });
    Apoio::igual('WORKER_JA_LIGADO', $falso->executar('ligar', null)['codigo'] ?? null, 'ligar: recusa se ja tem worker dando sinal');
    $repo->removerWorker('srv:200');
    $r = $falso->executar('ligar', '10.0.0.1');
    Apoio::verificar($r['ok'] && $ligou === 1, 'ligar: chama o iniciador do worker');
    $quebrado = new ControleSistema($pdo, fn (): ?string => 'sem exec');
    $r = $quebrado->executar('ligar', null);
    Apoio::verificar(!$r['ok'] && $r['codigo'] === 'WORKER_NAO_LIGOU' && $r['mensagem'] === 'sem exec', 'ligar: mostra por que nao ligou');
    // Nota de PRODUCAO na fila: so liga com confirmacao (tudo desfeito no fim).
    $pdo->beginTransaction();
    $lote = $pdo->query("INSERT INTO nfse_lotes (consumidor, quantidade_documentos) VALUES ('teste', 1) RETURNING id")->fetchColumn();
    $doc = $pdo->query("INSERT INTO nfse_documentos (lote_id, consumidor, padrao, tp_amb, emitente_cnpj, referencia, payload, serie, numero)
        VALUES ('{$lote}', 'teste', 'NACIONAL', 1, '00000000000191', 'prod-1', '{}', '900', 1) RETURNING id")->fetchColumn();
    $pdo->exec("INSERT INTO nfse_jobs (tipo, documento_id) VALUES ('EMITIR', '{$doc}')");
    Apoio::igual(1, $controle->status()['fila']['producao'], 'fila: conta notas de producao esperando');
    $ligou = 0;
    $r = $falso->executar('ligar', null);
    Apoio::verificar(!$r['ok'] && $r['codigo'] === 'CONFIRMAR_PRODUCAO' && $r['producao'] === 1 && $ligou === 0, 'ligar: com nota de producao na fila pede confirmacao e nao liga');
    $r = $falso->executar('ligar', null, ['confirmar_producao' => true]);
    Apoio::verificar($r['ok'] && $ligou === 1, 'ligar: confirmado, liga');
    $pdo->rollBack();

    $r = $controle->executar('desligar', '10.0.0.1');
    Apoio::verificar($r['ok'] && $repo->ordensDoWorker()['desligar'] >= time() - 5, 'desligar: grava a ordem para o worker');
    $falso->executar('ligar', null);
    Apoio::igual(0, $repo->ordensDoWorker()['desligar'], 'ligar: apaga a ordem de desligar antiga');
    $repo->batimento('srv:200', 'srv', 200, 'AGUARDANDO', 0);

    $r = $controle->executar('desligar_tudo', null);
    Apoio::verificar(!$r['ok'] && $r['http'] === 400, 'acao desconhecida recusada');

    // Worker que morreu sem se despedir (fora da janela de reinicio).
    $pdo->exec("DELETE FROM sistema_controle WHERE chave = 'worker_reiniciar'");
    $pdo->exec("UPDATE sistema_workers SET batimento_em = CURRENT_TIMESTAMP - INTERVAL '2 minutes'");
    $s = $controle->status();
    Apoio::igual('PARADO', $s['situacao'], 'sem sinal ha 2 minutos: PARADO');
    Apoio::verificar(str_contains($s['texto'], 'está desligado'), 'explica que o worker parou de dar sinal');
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

    // ---- Senha do e-mail digitada no painel (criptografada) ----
    $comChave = $cfg;
    $comChave['chave_certificados'] = base64_encode(random_bytes(32));
    $comChave['email']['senha'] = '';
    $configChave = new ConfiguracoesPainel($pdo, $comChave);
    Apoio::igual('CHAVE_AUSENTE', (new ConfiguracoesPainel($pdo, ['chave_certificados' => ''] + $cfg))->salvarSenhaEmail('x', null)['codigo'] ?? null,
        'senha do e-mail: sem a chave do servidor explica e nao grava');
    Apoio::verificar($configChave->salvarSenhaEmail('s3nh@ do email', '10.0.0.1')['ok'] && $configChave->senhaEmailNoPainel(), 'senha do e-mail: salva pelo painel');
    $guardado = (string)$pdo->query("SELECT valor FROM sistema_ajustes WHERE chave = 'email.senha_cifrada'")->fetchColumn();
    Apoio::verificar($guardado !== '' && !str_contains($guardado, 's3nh@'), 'senha do e-mail: banco guarda so criptografada');
    $log = (string)$pdo->query("SELECT detalhes FROM fiscal_admin_log ORDER BY id DESC LIMIT 1")->fetchColumn();
    Apoio::verificar(str_contains($log, 'email.senha') && !str_contains($log, 's3nh@'), 'senha do e-mail: historico registra a troca sem a senha');
    Apoio::igual('s3nh@ do email', NfseConfig::comClientes($comChave, $pdo)['email']['senha'], 'senha do e-mail: API usa a senha do painel');
    $outraChave = ['chave_certificados' => base64_encode(random_bytes(32))] + $comChave;
    Apoio::igual('', NfseConfig::comClientes($outraChave, $pdo)['email']['senha'], 'senha do e-mail: chave trocada nao quebra (fica sem senha)');
    Apoio::verificar($configChave->salvarSenhaEmail('', null)['ok'] && !$configChave->senhaEmailNoPainel(), 'senha do e-mail: vazio apaga');

    // ---- Senha do Monitor gerada pelo painel ----
    $semMonitor = ['monitor_token' => ''] + $cfg;
    $configMonitor = new ConfiguracoesPainel($pdo, $semMonitor);
    Apoio::verificar(!$configMonitor->senhaMonitor()['definida'], 'monitor: sem senha no inicio');
    $d = (new Diagnostico(NfseConfig::comClientes($semMonitor, $pdo), $pdo, dirname(__DIR__, 2)))->executar(false);
    $itensMonitor = array_values(array_filter(array_merge(...array_column($d['grupos'], 'itens')), fn($i) => str_contains($i['texto'], 'Monitor')));
    Apoio::igual('ATENCAO', $itensMonitor[0]['nivel'] ?? null, 'monitor: diagnostico avisa que falta a senha');
    Apoio::verificar(str_contains($itensMonitor[0]['resolver'] ?? '', 'Gerar senha'), 'monitor: dica aponta o botao do painel');
    $r = $configMonitor->gerarSenhaMonitor('10.0.0.1');
    Apoio::verificar($r['ok'] && strlen($r['senha']) === 48 && $r['situacao']['no_painel'], 'monitor: senha gerada pelo painel');
    $guardado = (string)$pdo->query("SELECT valor FROM sistema_ajustes WHERE chave = 'monitor.senha'")->fetchColumn();
    Apoio::verificar(!str_contains($guardado, $r['senha']) && str_contains($guardado, hash('sha256', $r['senha'])), 'monitor: banco guarda so o hash');
    $comMonitor = NfseConfig::comClientes($semMonitor, $pdo);
    Apoio::igual(hash('sha256', $r['senha']), $comMonitor['monitor_token_hash'] ?? null, 'monitor: API passa a conhecer a senha nova');
    $d = (new Diagnostico($comMonitor, $pdo, dirname(__DIR__, 2)))->executar(false);
    $itensMonitor = array_values(array_filter(array_merge(...array_column($d['grupos'], 'itens')), fn($i) => str_contains($i['texto'], 'Monitor')));
    Apoio::igual('OK', $itensMonitor[0]['nivel'] ?? null, 'monitor: diagnostico ok depois de gerar');
    $r2 = $configMonitor->gerarSenhaMonitor(null);
    Apoio::verificar($r2['senha'] !== $r['senha'] && NfseConfig::comClientes($semMonitor, $pdo)['monitor_token_hash'] === hash('sha256', $r2['senha']), 'monitor: senha nova substitui a anterior');
    $pdo->exec('DELETE FROM sistema_ajustes');
}
