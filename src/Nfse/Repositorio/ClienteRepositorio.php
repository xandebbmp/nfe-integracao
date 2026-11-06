<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Repositorio;

use PDO;

/**
 * SQL das empresas clientes (script 0005): cadastro, certificado, token,
 * regras de emissao, uso (cobranca) e historico do painel admin.
 *
 * Mes de referencia sempre no fuso America/Bahia.
 */
final class ClienteRepositorio
{
    /** Campos que o painel admin pode alterar diretamente. */
    public const CAMPOS_EDITAVEIS = [
        'razao_social', 'nome_fantasia', 'email', 'telefone', 'uf', 'ie', 'inscricao_municipal',
        'codigo_municipio', 'opcao_simples_nacional', 'regime_apuracao_simples', 'percentual_tributos_simples', 'regime_especial_tributacao',
        'incentivador_cultural', 'regime_especial_abrasf', 'webhook_url', 'webhook_segredo',
        'emissao_liberada', 'motivo_bloqueio', 'liberado_ate', 'limite_mensal', 'padroes_permitidos',
        'plano', 'observacao',
    ];

    private const FUSO = 'America/Bahia';

    public function __construct(private PDO $pdo)
    {
    }

    /** O script 0005 ja foi aplicado? */
    public function existe(): bool
    {
        return (bool)$this->pdo->query("SELECT to_regclass('public.fiscal_clientes') IS NOT NULL")->fetchColumn();
    }

    /** @return list<array> todos os clientes (sem o certificado cifrado) */
    public function listar(): array
    {
        $linhas = $this->pdo->query(
            'SELECT ' . self::colunasSemSegredo() . ' FROM fiscal_clientes ORDER BY razao_social'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'decodificar'], $linhas);
    }

    public function buscar(string $cnpj): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::colunasSemSegredo() . ' FROM fiscal_clientes WHERE cnpj = :cnpj');
        $stmt->execute([':cnpj' => $cnpj]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : self::decodificar($linha);
    }

    /**
     * Todos os clientes com o certificado cifrado (usado para montar a config).
     *
     * @return list<array>
     */
    public function paraConfiguracao(): array
    {
        $linhas = $this->pdo->query('SELECT * FROM fiscal_clientes ORDER BY cnpj')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linhas as &$linha) {
            $linha = self::decodificar($linha);
            $linha['certificado_cifrado'] = self::bytes($linha['certificado_cifrado']);
        }
        return $linhas;
    }

    public function criar(string $cnpj, string $razaoSocial): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO fiscal_clientes (cnpj, razao_social) VALUES (:cnpj, :razao)');
        $stmt->execute([':cnpj' => $cnpj, ':razao' => $razaoSocial]);
    }

    /** Atualiza so os campos de CAMPOS_EDITAVEIS presentes em $campos. */
    public function atualizar(string $cnpj, array $campos): void
    {
        $campos = array_intersect_key($campos, array_flip(self::CAMPOS_EDITAVEIS));
        if ($campos === []) {
            return;
        }
        $sets = [];
        $parametros = [':cnpj' => $cnpj];
        foreach ($campos as $coluna => $valor) {
            if ($coluna === 'padroes_permitidos') {
                $sets[] = "padroes_permitidos = string_to_array(:padroes_permitidos, ',')::VARCHAR(20)[]";
                $parametros[':padroes_permitidos'] = implode(',', (array)$valor);
                continue;
            }
            $sets[] = "{$coluna} = :{$coluna}";
            $parametros[":{$coluna}"] = is_bool($valor) ? ($valor ? 'true' : 'false') : $valor;
        }
        $sets[] = 'atualizado_em = CURRENT_TIMESTAMP';
        $this->pdo->prepare('UPDATE fiscal_clientes SET ' . implode(', ', $sets) . ' WHERE cnpj = :cnpj')->execute($parametros);
    }

    public function gravarCertificado(string $cnpj, string $cifrado, string $validade, string $titular): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fiscal_clientes
                SET certificado_cifrado = :cifrado, certificado_validade = :validade,
                    certificado_titular = :titular, certificado_atualizado_em = CURRENT_TIMESTAMP,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE cnpj = :cnpj'
        );
        $stmt->bindValue(':cifrado', $cifrado, PDO::PARAM_LOB);
        $stmt->bindValue(':validade', $validade);
        $stmt->bindValue(':titular', mb_substr($titular, 0, 200));
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->execute();
    }

    public function gravarToken(string $cnpj, string $token): void
    {
        $this->pdo->prepare(
            'UPDATE fiscal_clientes
                SET token_hash = :hash, token_final = :final, token_gerado_em = CURRENT_TIMESTAMP,
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE cnpj = :cnpj'
        )->execute([':hash' => hash('sha256', $token), ':final' => substr($token, -4), ':cnpj' => $cnpj]);
    }

    // ---------------------------------------------------------------- uso

    /**
     * Notas que contam para a franquia do mes atual: autorizadas no mes +
     * em andamento (recebidas/processando, que ainda podem ser autorizadas).
     */
    public function consumoDoMes(string $cnpj, int $tpAmb): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                (SELECT count(*) FROM fiscal_uso
                  WHERE emitente_cnpj = :cnpj AND tp_amb = :amb
                    AND autorizado_em >= (date_trunc('month', now() AT TIME ZONE :fuso) AT TIME ZONE :fuso))
              + (SELECT count(*) FROM nfse_documentos
                  WHERE emitente_cnpj = :cnpj AND tp_amb = :amb AND status IN ('RECEBIDO', 'PROCESSANDO'))"
        );
        $stmt->execute([':cnpj' => $cnpj, ':amb' => $tpAmb, ':fuso' => self::FUSO]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Notas autorizadas no mes, por cliente e por tipo (relatorio de cobranca).
     *
     * @param string $mes AAAA-MM
     * @return array<string, array<string, int>> cnpj => [padrao => quantidade]
     */
    public function usoPorMes(string $mes, int $tpAmb, ?string $cnpj = null): array
    {
        $sql = "SELECT emitente_cnpj, padrao, count(*) AS quantidade
                  FROM fiscal_uso
                 WHERE tp_amb = :amb
                   AND to_char(autorizado_em AT TIME ZONE :fuso, 'YYYY-MM') = :mes"
            . ($cnpj !== null ? ' AND emitente_cnpj = :cnpj' : '')
            . ' GROUP BY emitente_cnpj, padrao';
        $parametros = [':amb' => $tpAmb, ':fuso' => self::FUSO, ':mes' => $mes];
        if ($cnpj !== null) {
            $parametros[':cnpj'] = $cnpj;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parametros);

        $saida = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[(string)$l['emitente_cnpj']][(string)$l['padrao']] = (int)$l['quantidade'];
        }
        return $saida;
    }

    /**
     * Ultimos N meses de um cliente: mes => [padrao => quantidade].
     *
     * @return array<string, array<string, int>>
     */
    public function usoMensal(string $cnpj, int $tpAmb, int $meses = 12): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT to_char(autorizado_em AT TIME ZONE :fuso, 'YYYY-MM') AS mes, padrao, count(*) AS quantidade
               FROM fiscal_uso
              WHERE emitente_cnpj = :cnpj AND tp_amb = :amb
                AND autorizado_em >= (date_trunc('month', now() AT TIME ZONE :fuso) AT TIME ZONE :fuso) - make_interval(months => :meses - 1)
              GROUP BY 1, 2
              ORDER BY 1 DESC"
        );
        $stmt->execute([':cnpj' => $cnpj, ':amb' => $tpAmb, ':fuso' => self::FUSO, ':meses' => $meses]);
        $saida = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[(string)$l['mes']][(string)$l['padrao']] = (int)$l['quantidade'];
        }
        return $saida;
    }

    /**
     * Quantos documentos do cliente estao em cada status (mes atual, por data
     * de recebimento). So informativo: nao entra na cobranca.
     *
     * @return array<string, int>
     */
    public function statusDoMes(string $cnpj, int $tpAmb): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT status, count(*) AS quantidade
               FROM nfse_documentos
              WHERE emitente_cnpj = :cnpj AND tp_amb = :amb
                AND criado_em >= (date_trunc('month', now() AT TIME ZONE :fuso) AT TIME ZONE :fuso)
              GROUP BY status"
        );
        $stmt->execute([':cnpj' => $cnpj, ':amb' => $tpAmb, ':fuso' => self::FUSO]);
        $saida = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $saida[(string)$l['status']] = (int)$l['quantidade'];
        }
        return $saida;
    }

    // ---------------------------------------------------------------- numeracao

    /**
     * Series em uso pela empresa no ambiente: ultimo numero reservado e o
     * maior numero que ja apareceu numa nota (conferencia).
     *
     * @return list<array{padrao: string, serie: string, ultimo_numero: int, maior_em_notas: ?int, atualizado_em: string}>
     */
    public function numeracoes(string $cnpj, int $tpAmb): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.padrao, n.serie, n.ultimo_numero, n.atualizado_em,
                    (SELECT max(d.numero) FROM nfse_documentos d
                      WHERE d.emitente_cnpj = n.emitente_cnpj AND d.padrao = n.padrao
                        AND d.tp_amb = n.tp_amb AND d.serie = n.serie) AS maior_em_notas
               FROM nfse_numeracao n
              WHERE n.emitente_cnpj = :cnpj AND n.tp_amb = :amb
              ORDER BY n.padrao, n.serie'
        );
        $stmt->execute([':cnpj' => $cnpj, ':amb' => $tpAmb]);
        return array_map(static fn(array $l): array => [
            'padrao' => (string)$l['padrao'],
            'serie' => (string)$l['serie'],
            'ultimo_numero' => (int)$l['ultimo_numero'],
            'maior_em_notas' => $l['maior_em_notas'] !== null ? (int)$l['maior_em_notas'] : null,
            'atualizado_em' => (string)$l['atualizado_em'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Informa o ULTIMO numero ja usado (fora da API) numa serie. Nunca volta
     * para tras: se a API ja reservou um numero maior, ele e mantido.
     * Chame dentro de uma transacao.
     *
     * @return array{antes: ?int, depois: int}
     */
    public function ajustarNumeracao(string $cnpj, string $padrao, int $tpAmb, string $serie, int $ultimo): array
    {
        $chave = [':cnpj' => $cnpj, ':padrao' => $padrao, ':amb' => $tpAmb, ':serie' => $serie];
        $stmt = $this->pdo->prepare(
            'SELECT ultimo_numero FROM nfse_numeracao
              WHERE emitente_cnpj = :cnpj AND padrao = :padrao AND tp_amb = :amb AND serie = :serie
              FOR UPDATE'
        );
        $stmt->execute($chave);
        $antes = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO nfse_numeracao (emitente_cnpj, padrao, tp_amb, serie, ultimo_numero)
             VALUES (:cnpj, :padrao, :amb, :serie, :ultimo)
             ON CONFLICT (emitente_cnpj, padrao, tp_amb, serie)
             DO UPDATE SET ultimo_numero = GREATEST(nfse_numeracao.ultimo_numero, EXCLUDED.ultimo_numero),
                           atualizado_em = CURRENT_TIMESTAMP
             RETURNING ultimo_numero'
        );
        $stmt->execute($chave + [':ultimo' => $ultimo]);

        return ['antes' => $antes === false ? null : (int)$antes, 'depois' => (int)$stmt->fetchColumn()];
    }

    // ---------------------------------------------------------------- historico

    public function registrarLog(?string $cnpj, string $acao, array $detalhes, ?string $ip): void
    {
        $this->pdo->prepare('INSERT INTO fiscal_admin_log (cnpj, acao, detalhes, ip) VALUES (:cnpj, :acao, :detalhes, :ip)')
            ->execute([':cnpj' => $cnpj, ':acao' => $acao, ':detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE), ':ip' => $ip]);
    }

    /** @return list<array> */
    public function historico(string $cnpj, int $limite = 30): array
    {
        $stmt = $this->pdo->prepare('SELECT acao, detalhes, ip, criado_em FROM fiscal_admin_log WHERE cnpj = :cnpj ORDER BY id DESC LIMIT :limite');
        $stmt->bindValue(':cnpj', $cnpj);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $l): array {
            $l['detalhes'] = json_decode((string)$l['detalhes'], true) ?: [];
            return $l;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ---------------------------------------------------------------- apoio

    private static function colunasSemSegredo(): string
    {
        return 'cnpj, razao_social, nome_fantasia, email, telefone, uf, ie, inscricao_municipal, codigo_municipio,
                opcao_simples_nacional, regime_apuracao_simples, percentual_tributos_simples, regime_especial_tributacao, incentivador_cultural,
                regime_especial_abrasf, certificado_validade, certificado_titular, certificado_atualizado_em,
                (certificado_cifrado IS NOT NULL) AS tem_certificado, token_final, token_gerado_em,
                (token_hash IS NOT NULL) AS tem_token, webhook_url, (webhook_segredo IS NOT NULL) AS tem_webhook_segredo,
                emissao_liberada, motivo_bloqueio, liberado_ate, limite_mensal, padroes_permitidos, plano, observacao,
                criado_em, atualizado_em';
    }

    private static function decodificar(array $linha): array
    {
        // Array do PostgreSQL chega como texto: {NFE,NACIONAL}
        if (isset($linha['padroes_permitidos']) && is_string($linha['padroes_permitidos'])) {
            $texto = trim($linha['padroes_permitidos'], '{}');
            $linha['padroes_permitidos'] = $texto === '' ? [] : explode(',', $texto);
        }
        foreach (['emissao_liberada', 'incentivador_cultural', 'tem_certificado', 'tem_token', 'tem_webhook_segredo'] as $campo) {
            if (array_key_exists($campo, $linha)) {
                $linha[$campo] = in_array($linha[$campo], [true, 't', 'true', 1, '1'], true);
            }
        }
        foreach (['limite_mensal', 'opcao_simples_nacional', 'regime_apuracao_simples', 'regime_especial_tributacao', 'regime_especial_abrasf'] as $campo) {
            if (isset($linha[$campo])) {
                $linha[$campo] = (int)$linha[$campo];
            }
        }
        // NUMERIC chega como texto ("2.00"): vira numero.
        if (isset($linha['percentual_tributos_simples'])) {
            $linha['percentual_tributos_simples'] = (float)$linha['percentual_tributos_simples'];
        }
        return $linha;
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
