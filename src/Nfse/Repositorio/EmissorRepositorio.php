<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL do emissor simples (script 0006): usuarios, sessoes, servicos da
 * empresa e tomadores ja usados.
 */
final class EmissorRepositorio
{
    public function __construct(private PDO $pdo)
    {
    }

    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.fiscal_usuarios') IS NOT NULL")->fetchColumn();
    }

    // ------------------------------------------------------------ usuarios

    public function criarUsuario(string $cnpj, string $nome, string $email, string $senhaHash): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fiscal_usuarios (cnpj, nome, email, senha_hash) VALUES (:cnpj, :nome, lower(:email), :senha) RETURNING id'
        );
        $stmt->execute([':cnpj' => $cnpj, ':nome' => $nome, ':email' => $email, ':senha' => $senhaHash]);
        return (int)$stmt->fetchColumn();
    }

    public function usuarioPorEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_usuarios WHERE lower(email) = lower(:email)');
        $stmt->execute([':email' => $email]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::normalizarUsuario($linha);
    }

    public function usuario(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_usuarios WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::normalizarUsuario($linha);
    }

    /** @return list<array> sem o hash da senha */
    public function usuariosDaEmpresa(string $cnpj): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, email, ativo, ultimo_acesso, bloqueado_ate, criado_em FROM fiscal_usuarios WHERE cnpj = :cnpj ORDER BY nome'
        );
        $stmt->execute([':cnpj' => $cnpj]);
        return array_map([self::class, 'normalizarUsuario'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function trocarSenha(int $id, string $senhaHash): void
    {
        $this->pdo->prepare(
            'UPDATE fiscal_usuarios SET senha_hash = :senha, falhas_seguidas = 0, bloqueado_ate = NULL, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':senha' => $senhaHash, ':id' => $id]);
        $this->encerrarSessoesDoUsuario($id);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE fiscal_usuarios SET ativo = :ativo, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute([':ativo' => $ativo ? 'true' : 'false', ':id' => $id]);
        if (!$ativo) {
            $this->encerrarSessoesDoUsuario($id);
        }
    }

    /** Senha errada: conta a falha; na 5a seguida bloqueia por 15 minutos. */
    public function registrarFalha(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE fiscal_usuarios
                SET falhas_seguidas = falhas_seguidas + 1,
                    bloqueado_ate = CASE WHEN falhas_seguidas + 1 >= 5 THEN now() + interval '15 minutes' ELSE bloqueado_ate END
              WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function registrarAcesso(int $id): void
    {
        $this->pdo->prepare('UPDATE fiscal_usuarios SET falhas_seguidas = 0, bloqueado_ate = NULL, ultimo_acesso = now() WHERE id = :id')
            ->execute([':id' => $id]);
    }

    // ------------------------------------------------------------ sessoes

    public function criarSessao(int $usuarioId, string $tokenHash, int $horas, string $ip): void
    {
        $this->pdo->prepare(
            "INSERT INTO fiscal_sessoes (token_hash, usuario_id, expira_em, ip) VALUES (:t, :u, now() + make_interval(hours => :h), :ip)"
        )->execute([':t' => $tokenHash, ':u' => $usuarioId, ':h' => $horas, ':ip' => $ip]);
        // Limpa sessoes vencidas (de todos) de vez em quando.
        $this->pdo->exec('DELETE FROM fiscal_sessoes WHERE expira_em < now()');
    }

    /** Sessao valida -> usuario ativo; renova o prazo (sessao deslizante). */
    public function usuarioDaSessao(string $tokenHash, int $horas): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM fiscal_sessoes s JOIN fiscal_usuarios u ON u.id = s.usuario_id
              WHERE s.token_hash = :t AND s.expira_em > now() AND u.ativo'
        );
        $stmt->execute([':t' => $tokenHash]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($linha === false) {
            return null;
        }
        $this->pdo->prepare('UPDATE fiscal_sessoes SET expira_em = now() + make_interval(hours => :h) WHERE token_hash = :t')
            ->execute([':h' => $horas, ':t' => $tokenHash]);
        return self::normalizarUsuario($linha);
    }

    public function encerrarSessao(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM fiscal_sessoes WHERE token_hash = :t')->execute([':t' => $tokenHash]);
    }

    public function encerrarSessoesDoUsuario(int $usuarioId): void
    {
        $this->pdo->prepare('DELETE FROM fiscal_sessoes WHERE usuario_id = :u')->execute([':u' => $usuarioId]);
    }

    // ------------------------------------------------------------ servicos

    /** @return list<array> */
    public function servicos(string $cnpj, bool $soAtivos = true): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fiscal_clientes_servicos WHERE cnpj = :cnpj' . ($soAtivos ? ' AND ativo' : '') . ' ORDER BY padrao DESC, nome'
        );
        $stmt->execute([':cnpj' => $cnpj]);
        return array_map([self::class, 'normalizarServico'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function servico(string $cnpj, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_clientes_servicos WHERE cnpj = :cnpj AND id = :id');
        $stmt->execute([':cnpj' => $cnpj, ':id' => $id]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::normalizarServico($linha);
    }

    /** Cria (id null) ou altera. Se padrao=true, os outros deixam de ser padrao. */
    public function salvarServico(string $cnpj, ?int $id, array $d): int
    {
        $campos = [
            ':cnpj' => $cnpj, ':nome' => $d['nome'], ':codigo' => $d['codigo_tributacao_nacional'],
            ':nbs' => $d['codigo_nbs'], ':municipal' => $d['codigo_tributacao_municipal'], ':aliquota' => $d['aliquota_iss'],
            ':retido' => $d['iss_retido'] ? 'true' : 'false', ':descricao' => $d['descricao_padrao'],
            ':padrao' => $d['padrao'] ? 'true' : 'false',
        ];
        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO fiscal_clientes_servicos (cnpj, nome, codigo_tributacao_nacional, codigo_nbs, codigo_tributacao_municipal,
                     aliquota_iss, iss_retido, descricao_padrao, padrao)
                 VALUES (:cnpj, :nome, :codigo, :nbs, :municipal, :aliquota, :retido, :descricao, :padrao) RETURNING id'
            );
            $stmt->execute($campos);
            $id = (int)$stmt->fetchColumn();
        } else {
            $this->pdo->prepare(
                'UPDATE fiscal_clientes_servicos
                    SET nome = :nome, codigo_tributacao_nacional = :codigo, codigo_nbs = :nbs, codigo_tributacao_municipal = :municipal,
                        aliquota_iss = :aliquota, iss_retido = :retido, descricao_padrao = :descricao, padrao = :padrao,
                        atualizado_em = CURRENT_TIMESTAMP
                  WHERE cnpj = :cnpj AND id = :id'
            )->execute($campos + [':id' => $id]);
        }
        if ($d['padrao']) {
            $this->pdo->prepare('UPDATE fiscal_clientes_servicos SET padrao = false WHERE cnpj = :cnpj AND id <> :id')
                ->execute([':cnpj' => $cnpj, ':id' => $id]);
        }
        return $id;
    }

    public function desativarServico(string $cnpj, int $id): void
    {
        $this->pdo->prepare('UPDATE fiscal_clientes_servicos SET ativo = false, padrao = false WHERE cnpj = :cnpj AND id = :id')
            ->execute([':cnpj' => $cnpj, ':id' => $id]);
    }

    // ------------------------------------------------------------ tomadores

    public function tomador(string $cnpjEmitente, string $documento): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_tomadores WHERE cnpj_emitente = :e AND documento = :d');
        $stmt->execute([':e' => $cnpjEmitente, ':d' => $documento]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::tomadorLinha($linha);
    }

    /** @return list<array> os mais usados recentemente */
    public function tomadoresRecentes(string $cnpjEmitente, int $limite = 12): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fiscal_tomadores WHERE cnpj_emitente = :e ORDER BY ultimo_uso DESC LIMIT :l');
        $stmt->bindValue(':e', $cnpjEmitente);
        $stmt->bindValue(':l', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'tomadorLinha'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Grava/atualiza o tomador. $usado = true soma um uso (nota emitida). */
    public function salvarTomador(string $cnpjEmitente, array $t, bool $usado): void
    {
        $this->pdo->prepare(
            'INSERT INTO fiscal_tomadores (cnpj_emitente, documento, nome, email, telefone, endereco, usos, ultimo_uso)
             VALUES (:e, :d, :nome, :email, :tel, :end, :usos, now())
             ON CONFLICT (cnpj_emitente, documento) DO UPDATE
                SET nome = EXCLUDED.nome,
                    email = COALESCE(EXCLUDED.email, fiscal_tomadores.email),
                    telefone = COALESCE(EXCLUDED.telefone, fiscal_tomadores.telefone),
                    endereco = COALESCE(EXCLUDED.endereco, fiscal_tomadores.endereco),
                    usos = fiscal_tomadores.usos + EXCLUDED.usos,
                    ultimo_uso = CASE WHEN EXCLUDED.usos > 0 THEN now() ELSE fiscal_tomadores.ultimo_uso END'
        )->execute([
            ':e' => $cnpjEmitente, ':d' => $t['documento'], ':nome' => $t['nome'], ':email' => $t['email'] ?? null,
            ':tel' => $t['telefone'] ?? null, ':end' => !empty($t['endereco']) ? json_encode($t['endereco'], JSON_UNESCAPED_UNICODE) : null,
            ':usos' => $usado ? 1 : 0,
        ]);
    }

    // ------------------------------------------------------------ notas

    /** @return list<array> ultimas NFS-e Nacional da empresa (para a lista do emissor) */
    public function notasRecentes(string $cnpj, int $tpAmb, int $limite = 30): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, referencia, status, numero, numero_nfse, chave_acesso, data_autorizacao, criado_em, atualizado_em,
                    payload->'tomador'->>'nome' AS tomador_nome, payload->'tomador'->>'cpf_cnpj' AS tomador_documento,
                    payload->'valores'->>'valor_servicos' AS valor, payload->'servico'->>'descricao' AS descricao,
                    mensagens
               FROM nfse_documentos
              WHERE emitente_cnpj = :cnpj AND tp_amb = :amb AND padrao = 'NACIONAL'
              ORDER BY criado_em DESC
              LIMIT :limite"
        );
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':amb', $tpAmb, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $l): array {
            $l['mensagens'] = json_decode((string)($l['mensagens'] ?? '[]'), true) ?: [];
            $l['valor'] = $l['valor'] !== null ? (float)$l['valor'] : null;
            return $l;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------ apoio

    private static function normalizarUsuario(array $l): array
    {
        $l['id'] = (int)$l['id'];
        $l['ativo'] = in_array($l['ativo'], [true, 't', 'true', 1, '1'], true);
        if (isset($l['falhas_seguidas'])) {
            $l['falhas_seguidas'] = (int)$l['falhas_seguidas'];
        }
        return $l;
    }

    private static function normalizarServico(array $l): array
    {
        $l['id'] = (int)$l['id'];
        $l['aliquota_iss'] = $l['aliquota_iss'] !== null ? (float)$l['aliquota_iss'] : null;
        foreach (['iss_retido', 'padrao', 'ativo'] as $c) {
            $l[$c] = in_array($l[$c], [true, 't', 'true', 1, '1'], true);
        }
        return $l;
    }

    private static function tomadorLinha(array $l): array
    {
        $l['endereco'] = $l['endereco'] !== null ? json_decode((string)$l['endereco'], true) : null;
        $l['usos'] = (int)$l['usos'];
        return $l;
    }
}
