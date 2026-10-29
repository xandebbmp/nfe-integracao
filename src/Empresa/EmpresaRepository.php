<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Empresa;

use NFePHP\Common\Certificate;
use PDO;
use RuntimeException;

final class EmpresaRepository
{
    public function __construct(private PDO $pdo, private CertificadoCofre $cofre)
    {
    }

    public function buscarPorCnpj(string $cnpj): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM empresas WHERE cnpj = :cnpj LIMIT 1');
        $stmt->execute([':cnpj' => $cnpj]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function listar(): array
    {
        return $this->pdo->query('SELECT * FROM empresas ORDER BY razao_social')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cria ou atualiza a empresa pelo CNPJ.
     */
    public function salvar(array $dados): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO empresas (
                cnpj, razao_social, nome_fantasia, ie, im, uf, c_mun, crt, tp_amb, ativo,
                criado_em, atualizado_em
            ) VALUES (
                :cnpj, :razao_social, :nome_fantasia, :ie, :im, :uf, :c_mun, :crt, :tp_amb, :ativo,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            ON CONFLICT (cnpj) DO UPDATE
                SET razao_social = EXCLUDED.razao_social,
                    nome_fantasia = EXCLUDED.nome_fantasia,
                    ie = EXCLUDED.ie,
                    im = EXCLUDED.im,
                    uf = EXCLUDED.uf,
                    c_mun = EXCLUDED.c_mun,
                    crt = EXCLUDED.crt,
                    tp_amb = EXCLUDED.tp_amb,
                    ativo = EXCLUDED.ativo,
                    atualizado_em = CURRENT_TIMESTAMP
            RETURNING *'
        );

        $stmt->bindValue(':cnpj', $dados['cnpj']);
        $stmt->bindValue(':razao_social', $dados['razao_social']);
        $stmt->bindValue(':nome_fantasia', $dados['nome_fantasia'] ?? null);
        $stmt->bindValue(':ie', $dados['ie'] ?? null);
        $stmt->bindValue(':im', $dados['im'] ?? null);
        $stmt->bindValue(':uf', $dados['uf']);
        $stmt->bindValue(':c_mun', (int)$dados['c_mun'], PDO::PARAM_INT);
        $stmt->bindValue(':crt', (int)$dados['crt'], PDO::PARAM_INT);
        $stmt->bindValue(':tp_amb', (int)($dados['tp_amb'] ?? 2), PDO::PARAM_INT);
        $stmt->bindValue(':ativo', (bool)($dados['ativo'] ?? true), PDO::PARAM_BOOL);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Valida o PFX, cifra e torna-o o certificado ativo da empresa.
     */
    public function salvarCertificado(string $cnpj, string $pfx, string $senha): array
    {
        $empresa = $this->buscarPorCnpj($cnpj);
        if ($empresa === null) {
            throw new RuntimeException("Empresa {$cnpj} nao cadastrada.");
        }

        $cert = Certificate::readPfx($pfx, $senha);
        $validoDe = $cert->getValidFrom();
        $validoAte = $cert->getValidTo();
        $impressao = (string)openssl_x509_fingerprint((string)$cert->publicKey, 'sha256');

        // Filial pode usar o certificado da matriz: compara a raiz (8 primeiras posicoes).
        $cnpjCert = (string)($cert->getCnpj() ?? '');
        if ($cnpjCert !== '' && substr($cnpjCert, 0, 8) !== substr($cnpj, 0, 8)) {
            throw new RuntimeException("Certificado pertence ao CNPJ {$cnpjCert}, nao a {$cnpj}.");
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE empresa_certificados SET ativo = FALSE WHERE empresa_id = :id AND ativo')
                ->execute([':id' => $empresa['id']]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO empresa_certificados (
                    empresa_id, pfx_cifrado, senha_cifrada, titular, impressao_digital,
                    valido_de, valido_ate, ativo, criado_em
                ) VALUES (
                    :empresa_id, :pfx, :senha, :titular, :impressao,
                    :valido_de, :valido_ate, TRUE, CURRENT_TIMESTAMP
                )
                ON CONFLICT (empresa_id, impressao_digital) DO UPDATE
                    SET pfx_cifrado = EXCLUDED.pfx_cifrado,
                        senha_cifrada = EXCLUDED.senha_cifrada,
                        ativo = TRUE
                RETURNING id, titular, impressao_digital, valido_de, valido_ate'
            );
            $stmt->bindValue(':empresa_id', $empresa['id'], PDO::PARAM_INT);
            $stmt->bindValue(':pfx', $this->cofre->cifrar($pfx), PDO::PARAM_LOB);
            $stmt->bindValue(':senha', $this->cofre->cifrar($senha), PDO::PARAM_LOB);
            $stmt->bindValue(':titular', (string)$cert->getCompanyName());
            $stmt->bindValue(':impressao', $impressao);
            $stmt->bindValue(':valido_de', $validoDe->format('Y-m-d H:i:s'));
            $stmt->bindValue(':valido_ate', $validoAte->format('Y-m-d H:i:s'));
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->pdo->commit();
            return $row;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array{pfx: string, senha: string, valido_ate: string}|null
     */
    public function certificadoAtivo(int $empresaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pfx_cifrado, senha_cifrada, valido_ate
               FROM empresa_certificados
              WHERE empresa_id = :id AND ativo
              LIMIT 1'
        );
        $stmt->execute([':id' => $empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'pfx' => $this->cofre->decifrar(self::lob($row['pfx_cifrado'])),
            'senha' => $this->cofre->decifrar(self::lob($row['senha_cifrada'])),
            'valido_ate' => (string)$row['valido_ate'],
        ];
    }

    /**
     * Certificados ativos que vencem ate a data informada (para alerta no monitor).
     */
    public function certificadosVencendo(\DateTimeImmutable $ate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.cnpj, e.razao_social, c.titular, c.valido_ate
               FROM empresa_certificados c
               JOIN empresas e ON e.id = c.empresa_id
              WHERE c.ativo AND c.valido_ate <= :ate
              ORDER BY c.valido_ate'
        );
        $stmt->execute([':ate' => $ate->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * pdo_pgsql devolve BYTEA como stream.
     */
    private static function lob($value): string
    {
        return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
    }
}
