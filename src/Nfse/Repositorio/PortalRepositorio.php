<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL do portal do cliente (script 0006): usuarios, sessoes, servicos,
 * tomadores e cache de consultas de CNPJ/CEP.
 *
 * Regra de seguranca: toda consulta de servico/tomador recebe o CNPJ da
 * empresa do usuario logado. Assim uma empresa nunca ve dados de outra.
 */
final class PortalRepositorio
{
    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0006 ja foi aplicado? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.portal_usuarios') IS NOT NULL")->fetchColumn();
    }

    // =====================================================================
    // Usuarios
    // =====================================================================

    public function usuarioPorEmail(string $email): ?array
    {
        return $this->uma('SELECT * FROM portal_usuarios WHERE email = :email', [':email' => $email]);
    }

    public function usuarioPorId(int $id): ?array
    {
        return $this->uma('SELECT * FROM portal_usuarios WHERE id = :id', [':id' => $id]);
    }

    /** @return list<array> usuarios de uma empresa (sem o hash da senha) */
    public function usuariosDaEmpresa(string $cnpj): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, cnpj, nome, email, ativo, administrador, trocar_senha, bloqueado_ate, ultimo_acesso, criado_em
               FROM portal_usuarios WHERE cnpj = :cnpj ORDER BY ativo DESC, nome'
        );
        $stmt->execute([':cnpj' => $cnpj]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Quantas pessoas ativas a empresa tem no portal (lista "Pronta para emitir"). */
    public function usuariosAtivos(string $cnpj): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM portal_usuarios WHERE cnpj = :cnpj AND ativo');
        $stmt->execute([':cnpj' => $cnpj]);
        return (int)$stmt->fetchColumn();
    }

    public function criarUsuario(string $cnpj, string $nome, string $email, string $senhaHash, bool $administrador = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_usuarios (cnpj, nome, email, senha_hash, administrador)
             VALUES (:cnpj, :nome, :email, :hash, :administrador) RETURNING id'
        );
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':nome', $nome);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':hash', $senhaHash);
        $stmt->bindValue(':administrador', $administrador, PDO::PARAM_BOOL);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /** A empresa tem pelo menos um administrador ativo? */
    public function temAdministrador(string $cnpj): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM portal_usuarios WHERE cnpj = :cnpj AND administrador AND ativo LIMIT 1');
        $stmt->execute([':cnpj' => $cnpj]);
        return $stmt->fetchColumn() !== false;
    }

    public function definirAdministrador(int $id, bool $administrador): void
    {
        $stmt = $this->pdo->prepare('UPDATE portal_usuarios SET administrador = :administrador, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->bindValue(':administrador', $administrador, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Nova senha. $provisoria = true obriga a trocar no proximo acesso. */
    public function definirSenha(int $id, string $senhaHash, bool $provisoria): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE portal_usuarios
                SET senha_hash = :hash, trocar_senha = :provisoria, tentativas_falhas = 0, bloqueado_ate = NULL,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id'
        );
        $stmt->bindValue(':hash', $senhaHash);
        $stmt->bindValue(':provisoria', $provisoria, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        $stmt = $this->pdo->prepare('UPDATE portal_usuarios SET ativo = :ativo, atualizado_em = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->bindValue(':ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Conta uma senha errada. Ao chegar em $limite, bloqueia por $minutos.
     * Devolve as tentativas ja feitas.
     */
    public function registrarFalha(int $id, int $limite, int $minutos): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE portal_usuarios
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
        $stmt = $this->pdo->prepare(
            'UPDATE portal_usuarios SET tentativas_falhas = 0, bloqueado_ate = NULL, ultimo_acesso = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    // =====================================================================
    // Sessoes
    // =====================================================================

    public function criarSessao(string $tokenHash, int $usuarioId, int $minutos, string $ip, string $navegador): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_sessoes (token_hash, usuario_id, expira_em, ip, navegador)
             VALUES (:hash, :usuario, CURRENT_TIMESTAMP + make_interval(mins => :minutos), :ip, :navegador)'
        );
        $stmt->execute([
            ':hash' => $tokenHash, ':usuario' => $usuarioId, ':minutos' => $minutos,
            ':ip' => substr($ip, 0, 45), ':navegador' => substr($navegador, 0, 300),
        ]);
    }

    /** Sessao valida (nao expirada, usuario ativo) com os dados do usuario. */
    public function sessaoValida(string $tokenHash): ?array
    {
        return $this->uma(
            'SELECT u.id, u.cnpj, u.nome, u.email, u.trocar_senha, u.administrador, s.expira_em
               FROM portal_sessoes s
               JOIN portal_usuarios u ON u.id = s.usuario_id
              WHERE s.token_hash = :hash AND s.expira_em > CURRENT_TIMESTAMP AND u.ativo',
            [':hash' => $tokenHash]
        );
    }

    public function apagarSessao(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM portal_sessoes WHERE token_hash = :hash')->execute([':hash' => $tokenHash]);
    }

    /** Encerra todas as sessoes de um usuario (troca de senha, desativacao). */
    public function apagarSessoesDoUsuario(int $usuarioId, ?string $manterHash = null): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM portal_sessoes WHERE usuario_id = :id AND token_hash <> :manter');
        $stmt->execute([':id' => $usuarioId, ':manter' => $manterHash ?? '']);
    }

    public function apagarSessoesVencidas(): void
    {
        $this->pdo->exec('DELETE FROM portal_sessoes WHERE expira_em < CURRENT_TIMESTAMP');
    }

    // =====================================================================
    // "Esqueci a senha" (script 0012)
    // =====================================================================

    public function temRedefinicoes(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.portal_redefinicoes') IS NOT NULL")->fetchColumn();
    }

    /** Quantos links esta pessoa pediu nos ultimos minutos (limite contra abuso). */
    public function redefinicoesRecentes(int $usuarioId, int $minutos): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM portal_redefinicoes
              WHERE usuario_id = :id AND criado_em > CURRENT_TIMESTAMP - make_interval(mins => :minutos)'
        );
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':minutos', $minutos, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function criarRedefinicao(int $usuarioId, string $tokenHash, int $minutos, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_redefinicoes (usuario_id, token_hash, expira_em, ip)
             VALUES (:id, :hash, CURRENT_TIMESTAMP + make_interval(mins => :minutos), :ip)'
        );
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', $tokenHash);
        $stmt->bindValue(':minutos', $minutos, PDO::PARAM_INT);
        $stmt->bindValue(':ip', mb_substr($ip, 0, 45));
        $stmt->execute();
    }

    /** Link ainda valido (nao usado, nao vencido, usuario ativo) com os dados da pessoa. */
    public function redefinicaoValida(string $tokenHash): ?array
    {
        return $this->uma(
            'SELECT r.id AS redefinicao_id, u.id, u.nome, u.email
               FROM portal_redefinicoes r
               JOIN portal_usuarios u ON u.id = r.usuario_id
              WHERE r.token_hash = :hash AND r.usado_em IS NULL
                AND r.expira_em > CURRENT_TIMESTAMP AND u.ativo',
            [':hash' => $tokenHash]
        );
    }

    /** Senha trocada: este link e todos os outros da pessoa param de valer. */
    public function encerrarRedefinicoes(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE portal_redefinicoes SET usado_em = CURRENT_TIMESTAMP WHERE usuario_id = :id AND usado_em IS NULL'
        );
        $stmt->execute([':id' => $usuarioId]);
    }

    // =====================================================================
    // Servicos salvos
    // =====================================================================

    /** @return list<array> */
    public function servicos(string $cnpj): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM portal_servicos WHERE cnpj = :cnpj AND ativo ORDER BY nome');
        $stmt->execute([':cnpj' => $cnpj]);
        return array_map([self::class, 'servicoComNumeros'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function servico(string $cnpj, int $id): ?array
    {
        $linha = $this->uma('SELECT * FROM portal_servicos WHERE cnpj = :cnpj AND id = :id AND ativo', [':cnpj' => $cnpj, ':id' => $id]);
        return $linha === null ? null : self::servicoComNumeros($linha);
    }

    /** Cria (sem id) ou altera (com id) um servico. Devolve o id. */
    public function salvarServico(string $cnpj, array $s, ?int $id = null): int
    {
        $valores = [
            ':cnpj' => $cnpj,
            ':nome' => $s['nome'],
            ':descricao' => $s['descricao'],
            ':nacional' => $s['codigo_tributacao_nacional'],
            ':item' => $s['item_lista_servico'],
            ':municipal' => $s['codigo_tributacao_municipal'],
            ':cnae' => $s['codigo_cnae'],
            ':aliquota' => $s['aliquota_iss'],
            ':valor' => $s['valor_padrao'],
        ];
        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_servicos (cnpj, nome, descricao, codigo_tributacao_nacional, item_lista_servico,
                        codigo_tributacao_municipal, codigo_cnae, aliquota_iss, valor_padrao)
                 VALUES (:cnpj, :nome, :descricao, :nacional, :item, :municipal, :cnae, :aliquota, :valor) RETURNING id'
            );
            $stmt->execute($valores);
            return (int)$stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            'UPDATE portal_servicos
                SET nome = :nome, descricao = :descricao, codigo_tributacao_nacional = :nacional, item_lista_servico = :item,
                    codigo_tributacao_municipal = :municipal, codigo_cnae = :cnae, aliquota_iss = :aliquota,
                    valor_padrao = :valor, atualizado_em = CURRENT_TIMESTAMP
              WHERE cnpj = :cnpj AND id = :id'
        );
        $stmt->execute($valores + [':id' => $id]);
        return $id;
    }

    /** "Apaga" escondendo (notas antigas continuam com os dados delas). */
    public function desativarServico(string $cnpj, int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE portal_servicos SET ativo = FALSE, atualizado_em = CURRENT_TIMESTAMP WHERE cnpj = :cnpj AND id = :id');
        $stmt->execute([':cnpj' => $cnpj, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // =====================================================================
    // Tomadores
    // =====================================================================

    /** @return list<array> mais usados recentemente primeiro */
    public function tomadores(string $cnpj, string $busca = '', int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM portal_tomadores
              WHERE cnpj = :cnpj AND (:busca = '' OR nome ILIKE :like_nome OR cpf_cnpj LIKE :like_doc)
              ORDER BY ultima_emissao DESC NULLS LAST, nome
              LIMIT :limite"
        );
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':busca', $busca);
        $stmt->bindValue(':like_nome', '%' . $busca . '%');
        $stmt->bindValue(':like_doc', '%' . $busca . '%');
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tomador(string $cnpj, string $cpfCnpj): ?array
    {
        return $this->uma('SELECT * FROM portal_tomadores WHERE cnpj = :cnpj AND cpf_cnpj = :doc', [':cnpj' => $cnpj, ':doc' => $cpfCnpj]);
    }

    /**
     * Cria ou atualiza o tomador (chave: empresa + CPF/CNPJ).
     * $emissao = true: veio de uma nota (sobe na lista de mais usados).
     * $emissao = false: edicao na tela Tomadores (nao mexe na ordem).
     */
    public function salvarTomador(string $cnpj, array $t, bool $emissao = true): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_tomadores (cnpj, cpf_cnpj, nome, email, telefone, cep, logradouro, numero, complemento,
                    bairro, codigo_municipio, cidade, uf, ultima_emissao)
             VALUES (:cnpj, :doc, :nome, :email, :telefone, :cep, :logradouro, :numero, :complemento,
                    :bairro, :municipio, :cidade, :uf, CASE WHEN CAST(:emissao AS BOOLEAN) THEN CURRENT_TIMESTAMP END)
             ON CONFLICT (cnpj, cpf_cnpj) DO UPDATE
                SET nome = EXCLUDED.nome, email = EXCLUDED.email, telefone = EXCLUDED.telefone, cep = EXCLUDED.cep,
                    logradouro = EXCLUDED.logradouro, numero = EXCLUDED.numero, complemento = EXCLUDED.complemento,
                    bairro = EXCLUDED.bairro, codigo_municipio = EXCLUDED.codigo_municipio, cidade = EXCLUDED.cidade,
                    uf = EXCLUDED.uf, ultima_emissao = COALESCE(EXCLUDED.ultima_emissao, portal_tomadores.ultima_emissao),
                    atualizado_em = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':emissao' => $emissao ? 'true' : 'false',
            ':cnpj' => $cnpj,
            ':doc' => $t['cpf_cnpj'],
            ':nome' => $t['nome'],
            ':email' => $t['email'] ?? null,
            ':telefone' => $t['telefone'] ?? null,
            ':cep' => $t['cep'] ?? null,
            ':logradouro' => $t['logradouro'] ?? null,
            ':numero' => $t['numero'] ?? null,
            ':complemento' => $t['complemento'] ?? null,
            ':bairro' => $t['bairro'] ?? null,
            ':municipio' => $t['codigo_municipio'] ?? null,
            ':cidade' => $t['cidade'] ?? null,
            ':uf' => $t['uf'] ?? null,
        ]);
    }

    // =====================================================================
    // Tela inicial
    // =====================================================================

    /** Soma do valor dos servicos das notas autorizadas no mes atual. */
    public function faturadoDoMes(string $cnpj, int $tpAmb): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(sum((payload->'valores'->>'valor_servicos')::numeric), 0)
               FROM nfse_documentos
              WHERE emitente_cnpj = :cnpj AND tp_amb = :amb AND status = 'AUTORIZADO'
                AND padrao IN ('NACIONAL', 'SALVADOR')
                AND criado_em >= (date_trunc('month', now() AT TIME ZONE 'America/Bahia') AT TIME ZONE 'America/Bahia')"
        );
        $stmt->execute([':cnpj' => $cnpj, ':amb' => $tpAmb]);
        return (float)$stmt->fetchColumn();
    }

    // =====================================================================
    // Cache de consultas (CNPJ / CEP)
    // =====================================================================

    public function consultaEmCache(string $tipo, string $chave, int $diasValidade): ?array
    {
        $linha = $this->uma(
            'SELECT dados FROM portal_consultas
              WHERE tipo = :tipo AND chave = :chave AND consultado_em > CURRENT_TIMESTAMP - make_interval(days => :dias)',
            [':tipo' => $tipo, ':chave' => $chave, ':dias' => $diasValidade]
        );
        return $linha === null ? null : json_decode((string)$linha['dados'], true);
    }

    public function guardarConsulta(string $tipo, string $chave, array $dados): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_consultas (tipo, chave, dados) VALUES (:tipo, :chave, CAST(:dados AS JSONB))
             ON CONFLICT (tipo, chave) DO UPDATE SET dados = EXCLUDED.dados, consultado_em = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':tipo' => $tipo, ':chave' => $chave, ':dados' => json_encode($dados, JSON_UNESCAPED_UNICODE)]);
    }

    // =====================================================================

    private function uma(string $sql, array $parametros): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : $linha;
    }

    /** NUMERIC chega como texto no PDO: converte para numero. */
    private static function servicoComNumeros(array $s): array
    {
        $s['id'] = (int)$s['id'];
        $s['aliquota_iss'] = $s['aliquota_iss'] !== null ? (float)$s['aliquota_iss'] : null;
        $s['valor_padrao'] = $s['valor_padrao'] !== null ? (float)$s['valor_padrao'] : null;
        return $s;
    }
    public function removerTomador(string $cnpj, string $cpfCnpj): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM portal_tomadores WHERE cnpj = :cnpj AND cpf_cnpj = :doc');
        $stmt->execute([':cnpj' => $cnpj, ':doc' => $cpfCnpj]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Minhas notas (le nfse_documentos, a mesma tabela da API)
    // ------------------------------------------------------------------

    /**
     * Notas de servico da empresa, mais novas primeiro.
     *
     * @param list<string> $padroes   ex.: ['NACIONAL', 'SALVADOR']
     * @param list<string> $status    vazio = todos
     * @param string       $busca     nome ou CPF/CNPJ do tomador, ou numero da NFS-e/DPS
     * @param string       $mes       'AAAA-MM' ou vazio
     * @return array{total: int, notas: list<array>}
     */
    public function notas(string $cnpj, int $tpAmb, array $padroes, array $status, string $busca, string $mes, int $limite, int $pular): array
    {
        $where = ['emitente_cnpj = :cnpj', 'tp_amb = :amb'];
        $params = [':cnpj' => $cnpj, ':amb' => $tpAmb];
        $where[] = 'padrao IN (' . $this->marcadores('p', $padroes, $params) . ')';
        if ($status !== []) {
            $where[] = 'status IN (' . $this->marcadores('s', $status, $params) . ')';
        }
        if ($busca !== '') {
            $where[] = "(payload->'tomador'->>'nome' ILIKE :busca_nome OR payload->'tomador'->>'cpf_cnpj' LIKE :busca_doc
                         OR numero_nfse = :busca_nfse OR numero::text = :busca_dps)";
            $params[':busca_nome'] = '%' . $busca . '%';
            $params[':busca_doc'] = '%' . (preg_replace('/\D/', '', $busca) ?: $busca) . '%';
            $params[':busca_nfse'] = $busca;
            $params[':busca_dps'] = $busca;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $where[] = "criado_em >= to_date(:mes_ini, 'YYYY-MM') AND criado_em < to_date(:mes_fim, 'YYYY-MM') + INTERVAL '1 month'";
            $params[':mes_ini'] = $mes;
            $params[':mes_fim'] = $mes;
        }
        $filtro = ' FROM nfse_documentos WHERE ' . implode(' AND ', $where);

        $contar = $this->pdo->prepare('SELECT count(*)' . $filtro);
        $contar->execute($params);

        $stmt = $this->pdo->prepare('SELECT *' . $filtro . ' ORDER BY criado_em DESC, numero DESC LIMIT :limite OFFSET :pular');
        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':pular', $pular, PDO::PARAM_INT);
        $stmt->execute();

        $notas = array_map(static function (array $d): array {
            $d['payload'] = json_decode((string)$d['payload'], true) ?? [];
            $d['mensagens'] = json_decode((string)($d['mensagens'] ?? '[]'), true) ?? [];
            $d['tp_amb'] = (int)$d['tp_amb'];
            $d['numero'] = $d['numero'] === null ? null : (int)$d['numero'];
            return $d;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['total' => (int)$contar->fetchColumn(), 'notas' => $notas];
    }

    /** ":p0, :p1..." para um IN (...), ja colocando os valores em $params. */
    private function marcadores(string $prefixo, array $valores, array &$params): string
    {
        $nomes = [];
        foreach (array_values($valores) as $i => $valor) {
            $nomes[] = ":{$prefixo}{$i}";
            $params[":{$prefixo}{$i}"] = (string)$valor;
        }
        return $nomes === [] ? 'NULL' : implode(', ', $nomes);
    }
}
