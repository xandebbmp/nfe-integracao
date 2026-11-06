<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL das pessoas que entram no painel admin (script 0014):
 * admin_usuarios e admin_sessoes.
 */
final class AdminRepositorio
{
    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0014 ja foi aplicado? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.admin_usuarios') IS NOT NULL")->fetchColumn();
    }

    // =====================================================================
    // Pessoas
    // =====================================================================

    public function porEmail(string $email): ?array
    {
        return $this->uma('SELECT * FROM admin_usuarios WHERE email = :email', [':email' => $email]);
    }

    public function porId(int $id): ?array
    {
        return $this->uma('SELECT * FROM admin_usuarios WHERE id = :id', [':id' => $id]);
    }

    /** @return list<array> sem o hash da senha */
    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT id, nome, email, trocar_senha, ativo, ultimo_acesso, criado_em,
                    (bloqueado_ate IS NOT NULL AND bloqueado_ate > CURRENT_TIMESTAMP) AS bloqueado
               FROM admin_usuarios ORDER BY ativo DESC, nome'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contarAtivos(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM admin_usuarios WHERE ativo')->fetchColumn();
    }

    public function criar(string $nome, string $email, string $senhaHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_usuarios (nome, email, senha_hash) VALUES (:nome, :email, :hash) RETURNING id'
        );
        $stmt->execute([':nome' => $nome, ':email' => $email, ':hash' => $senhaHash]);
        return (int)$stmt->fetchColumn();
    }

    public function definirSenha(int $id, string $senhaHash, bool $trocar): void
    {
        $this->pdo->prepare(
            'UPDATE admin_usuarios SET senha_hash = :hash, trocar_senha = :trocar, tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = :id'
        )->execute([':hash' => $senhaHash, ':trocar' => $trocar ? 'true' : 'false', ':id' => $id]);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE admin_usuarios SET ativo = :ativo WHERE id = :id')
            ->execute([':ativo' => $ativo ? 'true' : 'false', ':id' => $id]);
    }

    /** Conta uma senha errada; passa do limite, bloqueia por $minutos. Devolve quantas ja errou. */
    public function registrarFalha(int $id, int $limite, int $minutos): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE admin_usuarios
                SET tentativas_falhas = tentativas_falhas + 1,
                    bloqueado_ate = CASE WHEN tentativas_falhas + 1 >= :limite
                                         THEN CURRENT_TIMESTAMP + make_interval(mins => :minutos) ELSE bloqueado_ate END
              WHERE id = :id
          RETURNING tentativas_falhas"
        );
        $stmt->execute([':limite' => $limite, ':minutos' => $minutos, ':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    public function registrarAcesso(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE admin_usuarios SET tentativas_falhas = 0, bloqueado_ate = NULL, ultimo_acesso = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':id' => $id]);
    }

    // =====================================================================
    // Sessoes
    // =====================================================================

    public function criarSessao(string $tokenHash, int $usuarioId, int $minutos, string $ip, string $navegador): void
    {
        $this->pdo->prepare(
            'INSERT INTO admin_sessoes (token_hash, usuario_id, expira_em, ip, navegador)
             VALUES (:hash, :usuario, CURRENT_TIMESTAMP + make_interval(mins => :minutos), :ip, :navegador)'
        )->execute([
            ':hash' => $tokenHash, ':usuario' => $usuarioId, ':minutos' => $minutos,
            ':ip' => substr($ip, 0, 45), ':navegador' => substr($navegador, 0, 300),
        ]);
    }

    /** Sessao valida (nao vencida, pessoa ativa) com os dados da pessoa. */
    public function sessaoValida(string $tokenHash): ?array
    {
        return $this->uma(
            'SELECT u.id, u.nome, u.email, u.trocar_senha, s.expira_em
               FROM admin_sessoes s
               JOIN admin_usuarios u ON u.id = s.usuario_id
              WHERE s.token_hash = :hash AND s.expira_em > CURRENT_TIMESTAMP AND u.ativo',
            [':hash' => $tokenHash]
        );
    }

    public function apagarSessao(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM admin_sessoes WHERE token_hash = :hash')->execute([':hash' => $tokenHash]);
    }

    /** Encerra os logins de uma pessoa (menos o $manterHash, se informado). */
    public function apagarSessoesDoUsuario(int $usuarioId, ?string $manterHash = null): void
    {
        $this->pdo->prepare('DELETE FROM admin_sessoes WHERE usuario_id = :id AND token_hash <> :manter')
            ->execute([':id' => $usuarioId, ':manter' => $manterHash ?? '']);
    }

    public function apagarSessoesVencidas(): void
    {
        $this->pdo->exec('DELETE FROM admin_sessoes WHERE expira_em < CURRENT_TIMESTAMP');
    }

    private function uma(string $sql, array $parametros): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : $linha;
    }
}
