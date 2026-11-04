<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * Leitura do cadastro mantido pelo Scriptcase (script 0005):
 * empresas, certificados, sistemas e permissoes.
 */
final class CadastroRepositorio
{
    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0005 ja foi aplicado neste banco? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.fiscal_empresas') IS NOT NULL")->fetchColumn();
    }

    /**
     * Empresas ativas, com o certificado criptografado (quando houver).
     *
     * @return list<array<string, mixed>>
     */
    public function empresasAtivas(): array
    {
        $linhas = $this->pdo->query(
            'SELECT e.cnpj, e.razao_social, e.uf, e.ie, e.inscricao_municipal, e.codigo_municipio,
                    e.telefone, e.email, e.opcao_simples_nacional, e.regime_apuracao_simples,
                    e.regime_especial_tributacao, e.incentivador_cultural, e.regime_especial_abrasf,
                    c.conteudo_cifrado
               FROM fiscal_empresas e
               LEFT JOIN fiscal_empresas_certificados c ON c.cnpj = e.cnpj
              WHERE e.ativo
              ORDER BY e.cnpj'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($linhas as &$linha) {
            $linha['conteudo_cifrado'] = self::bytes($linha['conteudo_cifrado']);
        }
        return $linhas;
    }

    /**
     * Sistemas ativos com a lista de CNPJs permitidos.
     *
     * @return list<array<string, mixed>>
     */
    public function sistemasAtivos(): array
    {
        return $this->pdo->query(
            "SELECT s.codigo, s.perfil, s.token_hash, s.webhook_url, s.webhook_segredo,
                    COALESCE(string_agg(se.empresa_cnpj, ',' ORDER BY se.empresa_cnpj), '') AS cnpjs
               FROM fiscal_sistemas s
               LEFT JOIN fiscal_sistemas_empresas se ON se.sistema_codigo = s.codigo
              WHERE s.ativo AND s.token_hash IS NOT NULL
              GROUP BY s.codigo
              ORDER BY s.codigo"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Trava e devolve UM certificado enviado e ainda nao processado.
     * Chame dentro de uma transacao.
     *
     * @return array{cnpj: string, arquivo: string, senha: string}|null
     */
    public function travarCertificadoPendente(): ?array
    {
        $linha = $this->pdo->query(
            'SELECT cnpj, certificado_arquivo, certificado_senha
               FROM fiscal_empresas
              WHERE certificado_arquivo IS NOT NULL
              ORDER BY atualizado_em
              LIMIT 1
              FOR UPDATE SKIP LOCKED'
        )->fetch(PDO::FETCH_ASSOC);

        if ($linha === false) {
            return null;
        }
        return [
            'cnpj' => (string)$linha['cnpj'],
            'arquivo' => self::bytes($linha['certificado_arquivo']) ?? '',
            'senha' => (string)($linha['certificado_senha'] ?? ''),
        ];
    }

    /**
     * Grava o resultado da conferencia e APAGA o arquivo e a senha enviados.
     * $cifrado = null mantem o certificado anterior (se houver).
     */
    public function concluirCertificado(
        string $cnpj,
        string $situacao,
        string $mensagem,
        ?string $cifrado = null,
        ?string $validade = null,
        ?string $titular = null
    ): void {
        if ($cifrado !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO fiscal_empresas_certificados (cnpj, conteudo_cifrado)
                 VALUES (:cnpj, :conteudo)
                 ON CONFLICT (cnpj) DO UPDATE SET conteudo_cifrado = EXCLUDED.conteudo_cifrado,
                                                  atualizado_em = CURRENT_TIMESTAMP'
            );
            $stmt->bindValue(':cnpj', $cnpj);
            $stmt->bindValue(':conteudo', $cifrado, PDO::PARAM_LOB);
            $stmt->execute();
        }

        $stmt = $this->pdo->prepare(
            'UPDATE fiscal_empresas
                SET certificado_arquivo = NULL,
                    certificado_senha = NULL,
                    certificado_situacao = :situacao,
                    certificado_mensagem = :mensagem,
                    certificado_validade = COALESCE(:validade, certificado_validade),
                    certificado_titular = COALESCE(:titular, certificado_titular),
                    certificado_processado_em = CURRENT_TIMESTAMP
              WHERE cnpj = :cnpj'
        );
        $stmt->execute([
            ':cnpj' => $cnpj,
            ':situacao' => $situacao,
            ':mensagem' => mb_substr($mensagem, 0, 500),
            ':validade' => $validade,
            ':titular' => $titular !== null ? mb_substr($titular, 0, 200) : null,
        ]);
    }

    /** BYTEA chega do PDO como stream; devolve string. */
    private static function bytes(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_resource($valor)) {
            $valor = stream_get_contents($valor);
        }
        return (string)$valor;
    }
}
