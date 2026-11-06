<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * Tabelas da tela "Sistema" do painel (database/scripts/0007):
 *
 *   sistema_workers   sinal de vida de cada worker
 *   sistema_controle  ordens do painel (pausar, reiniciar)
 *
 * Os workers escutam o canal CANAL: o painel grava a ordem e avisa,
 * e o worker obedece na hora em vez de esperar a proxima volta.
 */
final class SistemaRepositorio
{
    public const CANAL = 'nfse_controle';

    public function __construct(private PDO $pdo)
    {
    }

    /** As tabelas existem? (false = script 0007 ainda nao aplicado) */
    public function existe(): bool
    {
        return $this->tabelaExiste('sistema_controle');
    }

    /** A tabela ja foi criada? (usado antes de os scripts rodarem) */
    public function tabelaExiste(string $tabela): bool
    {
        $stmt = $this->pdo->prepare('SELECT to_regclass(:t) IS NOT NULL');
        $stmt->execute([':t' => 'public.' . $tabela]);
        return (bool)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Ordens do painel
    // ------------------------------------------------------------------

    public function controle(string $chave): ?string
    {
        $stmt = $this->pdo->prepare('SELECT valor FROM sistema_controle WHERE chave = :chave');
        $stmt->execute([':chave' => $chave]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? null : (string)$valor;
    }

    /** Grava a ordem e acorda os workers. */
    public function definirControle(string $chave, string $valor): void
    {
        $this->pdo->prepare(
            'INSERT INTO sistema_controle (chave, valor) VALUES (:chave, :valor)
             ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor, atualizado_em = CURRENT_TIMESTAMP'
        )->execute([':chave' => $chave, ':valor' => $valor]);
        $this->pdo->prepare('SELECT pg_notify(:canal, :chave)')->execute([':canal' => self::CANAL, ':chave' => $chave]);
    }

    /** Pausado e "reiniciar ate" numa consulta so (o worker le a cada volta). */
    public function ordensDoWorker(): array
    {
        $linhas = $this->pdo->query("SELECT chave, valor FROM sistema_controle WHERE chave IN ('worker_pausado', 'worker_reiniciar')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'pausado' => ($linhas['worker_pausado'] ?? '0') === '1',
            'reiniciar' => isset($linhas['worker_reiniciar']) ? (int)$linhas['worker_reiniciar'] : 0,
        ];
    }

    // ------------------------------------------------------------------
    // Sinal de vida dos workers
    // ------------------------------------------------------------------

    public function batimento(string $workerId, string $servidor, int $pid, string $estado, int $jobsFeitos): void
    {
        $this->pdo->prepare(
            'INSERT INTO sistema_workers (worker_id, servidor, pid, estado, jobs_feitos)
             VALUES (:id, :servidor, :pid, :estado, :jobs)
             ON CONFLICT (worker_id) DO UPDATE
                SET estado = EXCLUDED.estado, jobs_feitos = EXCLUDED.jobs_feitos, batimento_em = CURRENT_TIMESTAMP'
        )->execute([':id' => $workerId, ':servidor' => $servidor, ':pid' => $pid, ':estado' => $estado, ':jobs' => $jobsFeitos]);
    }

    public function removerWorker(string $workerId): void
    {
        $this->pdo->prepare('DELETE FROM sistema_workers WHERE worker_id = :id')->execute([':id' => $workerId]);
    }

    /** Workers que deram sinal nos ultimos $segundos, mais novos primeiro. */
    public function workersVivos(int $segundos): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT worker_id, servidor, pid, estado, jobs_feitos, iniciado_em, batimento_em
               FROM sistema_workers
              WHERE batimento_em >= CURRENT_TIMESTAMP - make_interval(secs => :s)
              ORDER BY iniciado_em DESC"
        );
        $stmt->execute([':s' => $segundos]);
        return array_map(static function (array $w): array {
            $w['pid'] = (int)$w['pid'];
            $w['jobs_feitos'] = (int)$w['jobs_feitos'];
            return $w;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Apaga linhas de workers que morreram sem se despedir (queda de energia...). */
    public function apagarWorkersMortos(int $segundos): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sistema_workers WHERE batimento_em < CURRENT_TIMESTAMP - make_interval(secs => :s)');
        $stmt->execute([':s' => $segundos]);
        return $stmt->rowCount();
    }

    /** Ultimo sinal de qualquer worker (null = nunca ligou). */
    public function ultimoBatimento(): ?string
    {
        $v = $this->pdo->query('SELECT max(batimento_em) FROM sistema_workers')->fetchColumn();
        return $v === null || $v === false ? null : (string)$v;
    }

    // ------------------------------------------------------------------
    // Fila
    // ------------------------------------------------------------------

    /** Quantos trabalhos esperando, em andamento e com falha (ultimas 24h). */
    public function resumoFila(): array
    {
        $r = $this->pdo->query(
            "SELECT
                count(*) FILTER (WHERE status = 'PENDENTE' AND executar_apos <= CURRENT_TIMESTAMP) AS esperando,
                count(*) FILTER (WHERE status = 'PENDENTE' AND executar_apos > CURRENT_TIMESTAMP) AS agendados,
                count(*) FILTER (WHERE status = 'EXECUTANDO') AS executando,
                count(*) FILTER (WHERE status = 'FALHOU' AND atualizado_em >= CURRENT_TIMESTAMP - INTERVAL '24 hours') AS falhas_24h,
                min(criado_em) FILTER (WHERE status = 'PENDENTE' AND executar_apos <= CURRENT_TIMESTAMP) AS mais_antigo
               FROM nfse_jobs"
        )->fetch(PDO::FETCH_ASSOC);
        return [
            'esperando' => (int)$r['esperando'],
            'agendados' => (int)$r['agendados'],
            'executando' => (int)$r['executando'],
            'falhas_24h' => (int)$r['falhas_24h'],
            'mais_antigo' => $r['mais_antigo'],
        ];
    }

    // ------------------------------------------------------------------
    // Historico (o registro em si e gravado por ClienteRepositorio::registrarLog)
    // ------------------------------------------------------------------

    /** Ultimas ordens dadas na tela Sistema (worker, banco, configuracoes e pessoas do painel). */
    public function acoesRecentes(int $limite = 10): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT acao, ip, criado_em, to_jsonb(l)->>'autor' AS autor FROM fiscal_admin_log l
              WHERE cnpj IS NULL AND (acao LIKE 'WORKER\\_%' OR acao LIKE 'BANCO\\_%' OR acao LIKE 'CONFIG\\_%' OR acao LIKE 'PAINEL\\_%')
              ORDER BY id DESC LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Scripts do banco (botao "Atualizar banco", script 0008)
    // ------------------------------------------------------------------

    /** @return array<string, array{sha256: string, aplicado_em: string}> nome => dados ([] se a tabela ainda nao existe) */
    public function scriptsAplicados(): array
    {
        if (!$this->tabelaExiste('sistema_scripts')) {
            return [];
        }
        $saida = [];
        foreach ($this->pdo->query('SELECT nome, sha256, aplicado_em FROM sistema_scripts')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[(string)$l['nome']] = ['sha256' => (string)$l['sha256'], 'aplicado_em' => (string)$l['aplicado_em']];
        }
        return $saida;
    }

    public function registrarScript(string $nome, string $sha256, string $origem): void
    {
        $this->pdo->prepare(
            'INSERT INTO sistema_scripts (nome, sha256, origem) VALUES (:nome, :sha, :origem)
             ON CONFLICT (nome) DO UPDATE SET sha256 = EXCLUDED.sha256, origem = EXCLUDED.origem, aplicado_em = CURRENT_TIMESTAMP'
        )->execute([':nome' => $nome, ':sha' => $sha256, ':origem' => $origem]);
    }

    /**
     * Roda um script inteiro (ele tem o proprio BEGIN/COMMIT).
     * Se falhar no meio, desfaz o que ficou aberto e repassa o erro.
     */
    public function executarScript(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
                // nada aberto
            }
            throw $e;
        }
    }

    /** Trava para dois cliques (ou duas pessoas) nao atualizarem ao mesmo tempo. */
    public function travarAtualizacao(): bool
    {
        return (bool)$this->pdo->query('SELECT pg_try_advisory_lock(70080008)')->fetchColumn();
    }

    public function liberarAtualizacao(): void
    {
        $this->pdo->query('SELECT pg_advisory_unlock(70080008)');
    }

    // ------------------------------------------------------------------
    // Configuracoes alteradas pelo painel (script 0009)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> chave => valor ([] se a tabela ainda nao existe) */
    public function ajustes(): array
    {
        if (!$this->tabelaExiste('sistema_ajustes')) {
            return [];
        }
        $saida = [];
        foreach ($this->pdo->query('SELECT chave, valor FROM sistema_ajustes')->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[(string)$l['chave']] = json_decode((string)$l['valor'], true);
        }
        return $saida;
    }

    public function salvarAjuste(string $chave, mixed $valor): void
    {
        $this->pdo->prepare(
            'INSERT INTO sistema_ajustes (chave, valor) VALUES (:chave, :valor)
             ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor, atualizado_em = CURRENT_TIMESTAMP'
        )->execute([':chave' => $chave, ':valor' => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** Volta a valer o valor do arquivo. */
    public function removerAjuste(string $chave): void
    {
        $this->pdo->prepare('DELETE FROM sistema_ajustes WHERE chave = :chave')->execute([':chave' => $chave]);
    }
}
