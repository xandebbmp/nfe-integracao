<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;

/**
 * Botao "Atualizar banco" da tela Sistema do painel admin.
 *
 *   status()   lista os scripts de database/scripts/ e diz quais faltam
 *   aplicar()  roda os que faltam, em ordem, e para no primeiro erro
 *
 * Faz o mesmo que deploy/aplicar_banco.sh, sem terminal. Os scripts podem
 * rodar mais de uma vez (IF NOT EXISTS), entao reaplicar nao estraga nada.
 *
 * Pendente = script que nao esta em sistema_scripts, ou cujo arquivo mudou
 * depois de aplicado (sha256 diferente). Antes do script 0008 existir, a
 * tabela nao existe e todos aparecem como pendentes: o primeiro clique roda
 * tudo (sem risco) e passa a registrar.
 */
final class AtualizacaoBanco
{
    private SistemaRepositorio $sistema;
    private string $pasta;

    public function __construct(private PDO $pdo, ?string $pasta = null)
    {
        $this->sistema = new SistemaRepositorio($pdo);
        $this->pasta = $pasta ?? dirname(__DIR__, 3) . '/database/scripts';
    }

    public function status(): array
    {
        $aplicados = $this->sistema->scriptsAplicados();
        $scripts = [];
        foreach ($this->arquivos() as $nome => $caminho) {
            $sha = hash_file('sha256', $caminho);
            $registro = $aplicados[$nome] ?? null;
            $scripts[] = [
                'nome' => $nome,
                'situacao' => $registro === null ? 'PENDENTE' : ($registro['sha256'] === $sha ? 'APLICADO' : 'ALTERADO'),
                'aplicado_em' => $registro['aplicado_em'] ?? null,
            ];
        }
        $pendentes = count(array_filter($scripts, static fn(array $s): bool => $s['situacao'] !== 'APLICADO'));
        return [
            'em_dia' => $pendentes === 0,
            'pendentes' => $pendentes,
            'scripts' => $scripts,
        ];
    }

    public function aplicar(?string $ip): array
    {
        if (!$this->sistema->travarAtualizacao()) {
            return ['ok' => false, 'http' => 409, 'codigo' => 'ATUALIZACAO_EM_ANDAMENTO', 'mensagem' => 'Já existe uma atualização do banco rodando. Espere terminar.'];
        }
        try {
            $feitos = [];
            foreach ($this->status()['scripts'] as $s) {
                if ($s['situacao'] === 'APLICADO') {
                    continue;
                }
                $caminho = $this->pasta . '/' . $s['nome'];
                try {
                    $this->sistema->executarScript((string)file_get_contents($caminho));
                } catch (Throwable $e) {
                    $this->registrarLog('BANCO_ERRO', ['aplicados' => $feitos, 'script' => $s['nome'], 'erro' => $e->getMessage()], $ip);
                    return [
                        'ok' => false, 'http' => 500, 'codigo' => 'SCRIPT_COM_ERRO',
                        'mensagem' => "O script {$s['nome']} deu erro e nada dele foi gravado. Os anteriores foram aplicados. Detalhe: " . $e->getMessage(),
                    ];
                }
                $feitos[] = $s['nome'];
                // Depois do 0008 a tabela existe; antes disso nao ha onde registrar.
                $this->registrarSePuder($s['nome'], (string)hash_file('sha256', $caminho));
            }
            // Os que rodaram antes do 0008 criar a tabela: registra agora.
            foreach ($feitos as $nome) {
                $this->registrarSePuder($nome, (string)hash_file('sha256', $this->pasta . '/' . $nome));
            }
            if ($feitos !== []) {
                $this->registrarLog('BANCO_ATUALIZADO', ['scripts' => $feitos], $ip);
            }
            return [
                'ok' => true,
                'mensagem' => $feitos === [] ? 'O banco já estava em dia.' : count($feitos) . ' script(s) aplicado(s). Se o worker estiver ligado, clique em "Reiniciar worker".',
                'aplicados' => $feitos,
                'banco' => $this->status(),
            ];
        } finally {
            $this->sistema->liberarAtualizacao();
        }
    }

    /** @return array<string, string> nome do arquivo => caminho, em ordem (0001, 0002...) */
    private function arquivos(): array
    {
        $lista = glob($this->pasta . '/*.sql') ?: [];
        sort($lista);
        $saida = [];
        foreach ($lista as $caminho) {
            $saida[basename($caminho)] = $caminho;
        }
        return $saida;
    }

    private function registrarSePuder(string $nome, string $sha): void
    {
        if ($this->sistema->tabelaExiste('sistema_scripts')) {
            $this->sistema->registrarScript($nome, $sha, 'PAINEL');
        }
    }

    private function registrarLog(string $acao, array $detalhes, ?string $ip): void
    {
        // fiscal_admin_log vem do script 0005: num banco vazio ainda nao existe.
        if ($this->sistema->tabelaExiste('fiscal_admin_log')) {
            (new ClienteRepositorio($this->pdo))->registrarLog(null, $acao, $detalhes, $ip);
        }
    }
}
