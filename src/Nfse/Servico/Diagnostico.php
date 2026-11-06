<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoFactory;
use Xande\NfeIntegracao\Nfse\Infra\CertificadoCliente;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;

/**
 * Diagnostico do sistema: confere item por item se esta tudo pronto para
 * emitir e diz COMO RESOLVER cada problema. Nao altera nada e nao envia
 * nota nenhuma.
 *
 * Usado em dois lugares, com as mesmas regras:
 *   - botao "Diagnostico" da tela Sistema do painel (public/v1/admin/diagnostico.php)
 *   - bin/nfse_verificar.php no terminal
 *
 * Resultado: lista de grupos, cada um com itens
 *   ['nivel' => OK|ATENCAO|ERRO, 'texto' => '...', 'resolver' => '...']
 *
 * Certificados: so abre o arquivo para ler validade e CNPJ (como o painel
 * ja faz ao receber o .pfx). Nao assina nada.
 * Internet: so pergunta "voce responde?" aos enderecos do fisco, sem
 * certificado e sem enviar documento.
 */
final class Diagnostico
{
    /** @var list<array{titulo: string, itens: list<array>}> */
    private array $grupos = [];

    public function __construct(private array $cfg, private ?PDO $pdo, private string $raiz)
    {
    }

    public function executar(bool $testarInternet = true): array
    {
        $this->grupos = [];
        $this->php();
        $this->configuracao();
        $this->banco();
        $this->empresas();
        $this->acessoApi();
        $this->pastas();
        if ($testarInternet) {
            $this->internet();
        }

        $total = ['OK' => 0, 'ATENCAO' => 0, 'ERRO' => 0];
        foreach ($this->grupos as $g) {
            foreach ($g['itens'] as $i) {
                $total[$i['nivel']]++;
            }
        }
        return [
            'resultado' => $total['ERRO'] > 0 ? 'ERRO' : ($total['ATENCAO'] > 0 ? 'ATENCAO' : 'OK'),
            'total' => $total,
            'grupos' => $this->grupos,
            'feito_em' => date('c'),
        ];
    }

    // ------------------------------------------------------------------

    private function grupo(string $titulo): void
    {
        $this->grupos[] = ['titulo' => $titulo, 'itens' => []];
    }

    private function item(string $nivel, string $texto, string $resolver = ''): void
    {
        $this->grupos[count($this->grupos) - 1]['itens'][] = [
            'nivel' => $nivel,
            'texto' => $texto,
            'resolver' => $nivel === 'OK' ? '' : $resolver,
        ];
    }

    private function php(): void
    {
        $this->grupo('Servidor (PHP)');
        if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
            $this->item('OK', 'PHP ' . PHP_VERSION);
        } else {
            $this->item('ERRO', 'PHP ' . PHP_VERSION . ' é antigo (mínimo 8.1).', 'Peça a quem cuida do servidor: sudo bash deploy/instalar_pacotes.sh');
        }
        $extensoes = [
            'curl' => 'php8.3-curl', 'dom' => 'php8.3-xml', 'openssl' => 'php8.3-cli', 'pdo_pgsql' => 'php8.3-pgsql',
            'soap' => 'php8.3-soap', 'mbstring' => 'php8.3-mbstring', 'zlib' => 'php8.3-cli', 'gd' => 'php8.3-gd',
            'bcmath' => 'php8.3-bcmath', 'xml' => 'php8.3-xml',
        ];
        $faltando = array_filter($extensoes, static fn(string $pacote, string $ext): bool => !extension_loaded($ext), ARRAY_FILTER_USE_BOTH);
        if ($faltando === []) {
            $this->item('OK', 'Extensões do PHP instaladas');
        } else {
            $this->item('ERRO', 'Extensões do PHP faltando: ' . implode(', ', array_keys($faltando)),
                'Peça a quem cuida do servidor: sudo apt install ' . implode(' ', array_unique($faltando)) . ' && sudo systemctl restart php8.3-fpm');
        }
    }

    private function configuracao(): void
    {
        $this->grupo('Configuração');
        if ((int)$this->cfg['tpAmb'] === 1) {
            $this->item('ATENCAO', 'Ambiente: PRODUÇÃO. As notas emitidas têm valor fiscal.', 'Confirme que a homologação foi concluída (docs/nfse-homologacao.md).');
        } else {
            $this->item('OK', 'Ambiente: HOMOLOGAÇÃO (notas sem valor fiscal)');
        }
        try {
            new CofreCertificado((string)($this->cfg['chave_certificados'] ?? ''));
            $this->item('OK', 'Chave para guardar os certificados configurada');
        } catch (Throwable) {
            $this->item('ERRO', 'Chave dos certificados ausente: o painel não aceita certificados.',
                "Peça a quem cuida do servidor para preencher 'chave_certificados' em config/nfse.local.php (manual de implantação, passo 7).");
        }
        $monitor = (string)($this->cfg['monitor_token'] ?? '');
        if (strlen($monitor) < 24 || str_contains(strtoupper($monitor), 'TOKEN')) {
            $this->item('ATENCAO', 'Senha do Monitor (monitor_token) vazia ou fraca: a página /monitor/ não abre.', "Preencha 'monitor_token' em config/nfse.local.php (gere com: openssl rand -hex 32).");
        } else {
            $this->item('OK', 'Monitor configurado');
        }
    }

    private function banco(): void
    {
        $this->grupo('Banco de dados e worker');
        if ($this->pdo === null) {
            $this->item('ERRO', 'Sem conexão com o banco de dados.', 'Confira config/database.local.php e se o PostgreSQL está ligado (sudo systemctl status postgresql).');
            return;
        }
        $this->item('OK', 'Conexão com o PostgreSQL');
        $sistema = new SistemaRepositorio($this->pdo);

        foreach (['nfse_documentos' => 'notas', 'fiscal_clientes' => 'empresas'] as $tabela => $nome) {
            if (!$sistema->tabelaExiste($tabela)) {
                $this->item('ERRO', "Tabelas de {$nome} não existem.", 'Clique em "Atualizar banco" na tela Sistema (ou rode bash deploy/aplicar_banco.sh).');
                return;
            }
        }
        $banco = (new AtualizacaoBanco($this->pdo))->status();
        if ($banco['em_dia']) {
            $this->item('OK', 'Banco em dia (' . count($banco['scripts']) . ' scripts aplicados)');
        } else {
            $this->item('ATENCAO', "{$banco['pendentes']} atualização(ões) do banco pendente(s).", 'Clique em "Atualizar banco" na tela Sistema.');
        }

        if ($sistema->existe()) {
            $w = (new ControleSistema($this->pdo))->status();
            $nivel = ['LIGADO' => 'OK', 'PAUSADO' => 'ATENCAO', 'REINICIANDO' => 'ATENCAO', 'PARADO' => 'ERRO'][$w['situacao']];
            $resolver = [
                'PAUSADO' => 'Clique em "Retomar envios" na tela Sistema.',
                'REINICIANDO' => 'Espere alguns segundos e rode o diagnóstico de novo.',
                'PARADO' => 'O worker é instalado uma vez no servidor (manual de implantação, passo do worker): sudo systemctl enable --now nfse-worker@1 nfse-worker@2',
            ][$w['situacao']] ?? '';
            $this->item($nivel, "{$w['titulo']}. {$w['texto']}", $resolver);
        }

        if ($sistema->tabelaExiste('nfse_jobs')) {
            $fila = $sistema->resumoFila();
            $atrasada = $fila['mais_antigo'] !== null && strtotime((string)$fila['mais_antigo']) < time() - 300;
            if ($atrasada) {
                $this->item('ERRO', "{$fila['esperando']} nota(s) esperando há mais de 5 minutos.", 'Veja se o worker está ligado e sem pausa na tela Sistema.');
            } else {
                $this->item('OK', 'Fila sem notas atrasadas');
            }
            if ($fila['falhas_24h'] > 0) {
                $this->item('ATENCAO', "{$fila['falhas_24h']} trabalho(s) falharam nas últimas 24 horas.", 'Abra o Monitor para ver o motivo de cada um.');
            }
        }
    }

    private function empresas(): void
    {
        $this->grupo('Empresas e certificados');
        if ($this->cfg['emitentes'] === []) {
            $this->item('ATENCAO', 'Nenhuma empresa cadastrada.', 'Clique em "+ Nova empresa" no painel.');
            return;
        }
        $publico = realpath("{$this->raiz}/public") ?: "{$this->raiz}/public";
        foreach ($this->cfg['emitentes'] as $cnpj => $emitente) {
            $cnpj = (string)$cnpj;
            $nome = trim((string)($emitente['razao_social'] ?? '')) . " ({$cnpj})";
            $arquivo = (string)($emitente['certificado']['arquivo'] ?? '');
            $doBanco = !empty($emitente['certificado']['cifrado']);

            if (!$doBanco && $arquivo === '') {
                $this->item('ERRO', "{$nome}: sem certificado digital. As notas dela não podem ser assinadas.", 'Abra a empresa no painel e envie o certificado A1 (.pfx).');
                continue;
            }
            if (!$doBanco && str_starts_with(realpath($arquivo) ?: $arquivo, $publico)) {
                $this->item('ERRO', "{$nome}: o certificado está dentro da pasta public (visível na internet).", 'Envie o certificado pelo painel e apague o arquivo da pasta public.');
            }
            try {
                $certificado = CertificadoCliente::doEmitente($emitente, (string)($this->cfg['chave_certificados'] ?? ''))->certificado();
                $validade = $certificado->getValidTo();
                $dias = (int)floor(($validade->getTimestamp() - time()) / 86400);
                $cnpjCertificado = NfseConfig::normalizarCnpj($certificado->getCnpj() ?? '');
                if ($cnpjCertificado !== '' && substr($cnpjCertificado, 0, 8) !== substr($cnpj, 0, 8)) {
                    $this->item('ERRO', "{$nome}: o certificado é do CNPJ {$cnpjCertificado}, de outra empresa.", 'Envie no painel o e-CNPJ A1 desta empresa.');
                } elseif ($dias < 0) {
                    $this->item('ERRO', "{$nome}: certificado vencido em " . $validade->format('d/m/Y') . '.', 'Renove o certificado e envie o novo no painel.');
                } elseif ($dias < 30) {
                    $this->item('ATENCAO', "{$nome}: certificado vence em {$dias} dia(s) (" . $validade->format('d/m/Y') . ').', 'Providencie a renovação e envie o novo no painel.');
                } else {
                    $this->item('OK', "{$nome}: certificado válido até " . $validade->format('d/m/Y'));
                }
            } catch (Throwable $e) {
                $this->item('ERRO', "{$nome}: o certificado não abriu ({$e->getMessage()}).", 'Envie o certificado de novo no painel, conferindo a senha.');
            }
            if (trim((string)($emitente['inscricao_municipal'] ?? '')) === '') {
                $this->item('ATENCAO', "{$nome}: inscrição municipal vazia (obrigatória em Salvador).", 'Preencha no cadastro da empresa no painel.');
            }
        }
    }

    private function acessoApi(): void
    {
        $this->grupo('Acesso à API');
        $consumidores = $this->cfg['consumidores'];
        if ($consumidores === []) {
            $this->item('ATENCAO', 'Nenhum sistema com token: só o portal emite notas.', 'Se outro sistema for integrar, gere o token na empresa, no painel.');
            return;
        }
        foreach ($consumidores as $nome => $dados) {
            $token = !empty($dados['token_hash']) ? str_repeat('x', 64) : (string)($dados['token'] ?? '');
            if (strlen($token) < 24 || str_contains(strtoupper($token), 'TOKEN')) {
                $this->item('ERRO', "Sistema {$nome}: token vazio, curto ou de exemplo.", 'Gere um token novo no painel (ou: openssl rand -hex 32).');
                continue;
            }
            if (NfseConfig::cnpjsPermitidos($this->cfg, (string)$nome) === []) {
                $this->item('ERRO', "Sistema {$nome}: nenhum CNPJ liberado.", "Adicione 'cnpjs' no bloco do sistema {$nome} em config/nfse.local.php.");
                continue;
            }
            $this->item('OK', "Sistema {$nome}: token ok");
            if (!empty($dados['webhook_url']) && empty($dados['webhook_segredo'])) {
                $this->item('ATENCAO', "Sistema {$nome}: aviso automático (webhook) sem segredo.", 'Preencha o segredo do webhook no cadastro da empresa.');
            }
        }
    }

    private function pastas(): void
    {
        $this->grupo('Pastas e cópia dos XMLs');
        foreach (['storage/logs', 'storage/xml'] as $pasta) {
            $caminho = "{$this->raiz}/{$pasta}";
            if (!is_dir($caminho)) {
                @mkdir($caminho, 0775, true);
            }
            if (is_dir($caminho) && is_writable($caminho)) {
                $this->item('OK', "{$pasta} com permissão de escrita");
            } else {
                $this->item('ERRO', "{$pasta} sem permissão de escrita.", "Peça a quem cuida do servidor: sudo mkdir -p {$caminho} && sudo chown -R www-data:www-data {$caminho}");
            }
        }
        $tipo = $this->cfg['armazenamento']['tipo'] ?? 'nenhum';
        if ($tipo === 'nenhum') {
            $this->item('ATENCAO', 'Cópia dos XMLs fora do banco desligada.', 'Configure "local" ou "s3" em armazenamento (config/nfse.local.php). Enquanto isso, mantenha o backup diário do banco.');
            return;
        }
        try {
            $armazenamento = ArmazenamentoFactory::criar($this->cfg);
            if ($tipo === 'local') {
                $armazenamento->salvar('_verificacao/teste.txt', 'ok ' . date(DATE_ATOM), 'text/plain');
            }
            $this->item('OK', "Cópia dos XMLs: {$tipo}" . ($tipo === 'local' ? ' (gravação de teste ok)' : ''));
        } catch (Throwable $e) {
            $this->item('ERRO', "Cópia dos XMLs ({$tipo}): " . $e->getMessage(), "Revise o bloco 'armazenamento' em config/nfse.local.php.");
        }
    }

    /** Pergunta "voce responde?" a cada endereco do fisco, todos ao mesmo tempo (ate 10 s). */
    private function internet(): void
    {
        $this->grupo('Internet: acesso ao fisco');
        $tpAmb = (int)$this->cfg['tpAmb'];
        $destinos = array_filter([
            'NFS-e Nacional (Sefin)' => $this->cfg['nacional']['url_sefin'][$tpAmb] ?? '',
            'NFS-e Salvador' => $this->cfg['salvador']['url_base'][$tpAmb] ?? '',
        ]);
        // NF-e: SEFAZ de cada UF das empresas, pela lista oficial da sped-nfe.
        $lista = @simplexml_load_file("{$this->raiz}/vendor/nfephp-org/sped-nfe/storage/wsnfe_4.00_mod55.xml");
        $ambiente = $tpAmb === 1 ? 'producao' : 'homologacao';
        foreach (array_unique(array_filter(array_column($this->cfg['emitentes'], 'uf'))) as $uf) {
            foreach ($lista ? $lista->UF : [] as $no) {
                if ((string)$no->sigla === $uf && (string)$no->{$ambiente}->NfeAutorizacao !== '') {
                    $destinos["SEFAZ {$uf} (NF-e)"] = (string)$no->{$ambiente}->NfeAutorizacao;
                }
            }
        }

        $multi = curl_multi_init();
        $conexoes = [];
        foreach ($destinos as $nome => $url) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 10]);
            curl_multi_add_handle($multi, $c);
            $conexoes[$nome] = $c;
        }
        do {
            $status = curl_multi_exec($multi, $ativos);
            if ($ativos) {
                curl_multi_select($multi, 1.0);
            }
        } while ($ativos && $status === CURLM_OK);

        // No modo "varios ao mesmo tempo" o motivo da falha vem por aqui.
        $erros = [];
        while (($info = curl_multi_info_read($multi)) !== false) {
            if ($info['result'] !== CURLE_OK) {
                $erros[(int)array_search($info['handle'], array_values($conexoes), true)] = curl_strerror($info['result']);
            }
        }

        $indice = 0;
        foreach ($conexoes as $nome => $c) {
            $http = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
            $erro = $erros[$indice++] ?? (curl_error($c) ?: 'sem resposta');
            $host = (string)parse_url((string)$destinos[$nome], PHP_URL_HOST);
            // Qualquer resposta (mesmo 403/404) prova que o servidor alcanca o fisco.
            if ($http > 0) {
                $this->item('OK', "{$nome} respondeu ({$host})");
            } else {
                $this->item('ERRO', "{$nome} não respondeu ({$host}): {$erro}", "Peça a quem cuida do servidor para liberar a saída HTTPS (porta 443) para {$host}.");
            }
            curl_multi_remove_handle($multi, $c);
            curl_close($c);
        }
        curl_multi_close($multi);
    }
}
