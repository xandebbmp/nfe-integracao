<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use NFePHP\Common\Certificate;
use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\Infra\PfxCompativel;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Regras do painel admin (/admin/ e rotas /v1/admin/*.php):
 * cadastrar/editar empresa cliente, enviar certificado, gerar token,
 * consultar uso e montar o relatorio mensal para cobranca.
 *
 * Toda alteracao fica registrada em fiscal_admin_log.
 * Os metodos devolvem ['ok' => bool, 'http' => int, ...] para as rotas so
 * repassarem a resposta.
 */
final class AdministracaoClientes
{
    private ClienteRepositorio $clientes;
    private ControleEmissao $controle;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->clientes = new ClienteRepositorio($pdo);
        $this->controle = new ControleEmissao($pdo, (int)$cfg['tpAmb']);
    }

    // ------------------------------------------------------------ consultas

    /** Lista para a tela principal: situacao e uso do mes de cada empresa. */
    public function listar(): array
    {
        $tpAmb = (int)$this->cfg['tpAmb'];
        $usoMes = $this->clientes->usoPorMes(self::mesAtual(), $tpAmb);
        $saida = [];
        foreach ($this->clientes->listar() as $c) {
            $saida[] = $this->resumo($c, $usoMes[$c['cnpj']] ?? []);
        }
        return $saida;
    }

    public function detalhe(string $cnpj): ?array
    {
        $cliente = $this->clientes->buscar(NfseConfig::normalizarCnpj($cnpj));
        if ($cliente === null) {
            return null;
        }
        $tpAmb = (int)$this->cfg['tpAmb'];
        $usoMes = $this->clientes->usoPorMes(self::mesAtual(), $tpAmb, $cliente['cnpj']);
        $detalhe = $this->resumo($cliente, $usoMes[$cliente['cnpj']] ?? []) + [
            'uso_mensal' => $this->clientes->usoMensal($cliente['cnpj'], $tpAmb, 12),
            'status_mes' => $this->clientes->statusDoMes($cliente['cnpj'], $tpAmb),
            'historico' => $this->clientes->historico($cliente['cnpj']),
            'numeracao' => $this->clientes->numeracoes($cliente['cnpj'], $tpAmb),
            'series_padrao' => $this->seriesPadrao(),
        ];
        $detalhe['prontidao'] = $this->prontidao($detalhe);
        return $detalhe;
    }

    /** Lista "Pronta para emitir" da ficha (Servico/ProntidaoEmissao). */
    private function prontidao(array $c): array
    {
        $portal = new PortalRepositorio($this->pdo);
        // Sem cadastro de certificado no painel, o emitente pode ter o .pfx no config/nfse.local.php.
        $doArquivo = empty($this->cfg['emitente_implicito'])
            && trim((string)($this->cfg['emitentes'][$c['cnpj']]['certificado']['arquivo'] ?? '')) !== '';
        return ProntidaoEmissao::avaliar($c, [
            'tp_amb' => (int)$this->cfg['tpAmb'],
            'usuarios_portal' => $portal->existe() ? $portal->usuariosAtivos((string)$c['cnpj']) : null,
            'certificado_no_arquivo' => $doArquivo,
        ]);
    }

    /**
     * Relatorio de cobranca: notas AUTORIZADAS no mes, por empresa e tipo.
     *
     * @param string $mes AAAA-MM
     * @return list<array>
     */
    public function relatorioUso(string $mes): array
    {
        $uso = $this->clientes->usoPorMes($mes, (int)$this->cfg['tpAmb']);
        $linhas = [];
        foreach ($this->clientes->listar() as $c) {
            $porTipo = $uso[$c['cnpj']] ?? [];
            $linhas[] = [
                'cnpj' => $c['cnpj'],
                'razao_social' => $c['razao_social'],
                'plano' => $c['plano'],
                'limite_mensal' => $c['limite_mensal'],
                'nfe' => $porTipo[Padrao::NFE] ?? 0,
                'nfse_nacional' => $porTipo[Padrao::NACIONAL] ?? 0,
                'nfse_salvador' => $porTipo[Padrao::SALVADOR] ?? 0,
                'total' => array_sum($porTipo),
            ];
            unset($uso[$c['cnpj']]);
        }
        // Emitentes que estao so no arquivo de configuracao (sem cadastro).
        foreach ($uso as $cnpj => $porTipo) {
            $linhas[] = ['cnpj' => (string)$cnpj, 'razao_social' => '(fora do cadastro)', 'plano' => null, 'limite_mensal' => null,
                'nfe' => $porTipo[Padrao::NFE] ?? 0, 'nfse_nacional' => $porTipo[Padrao::NACIONAL] ?? 0,
                'nfse_salvador' => $porTipo[Padrao::SALVADOR] ?? 0, 'total' => array_sum($porTipo)];
        }
        return $linhas;
    }

    // ------------------------------------------------------------ alteracoes

    /**
     * Cria (se o CNPJ nao existir) ou atualiza uma empresa.
     * So os campos enviados sao alterados.
     */
    public function salvar(array $entrada, string $ip): array
    {
        $cnpj = NfseConfig::normalizarCnpj($entrada['cnpj'] ?? '');
        if (!preg_match('/^[0-9A-Z]{14}$/', $cnpj)) {
            return self::falha(422, 'CNPJ_INVALIDO', 'Informe o CNPJ com 14 caracteres.');
        }
        // CNPJ so com numeros: confere os digitos (pega erro de digitacao no cadastro).
        // O CNPJ alfanumerico (2026) passa so pelo formato acima.
        if (ctype_digit($cnpj) && !Documentos::cnpjValido($cnpj)) {
            return self::falha(422, 'CNPJ_INVALIDO', 'CNPJ inválido: confira os números (os dois últimos são dígitos verificadores).',
                ['erros' => ['CNPJ inválido: confira os números.']]);
        }

        [$campos, $erros] = self::normalizar($entrada);
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', implode(' ', $erros), ['erros' => $erros]);
        }

        $anterior = $this->clientes->buscar($cnpj);
        $this->pdo->beginTransaction();
        try {
            if ($anterior === null) {
                if (empty($campos['razao_social'])) {
                    $this->pdo->rollBack();
                    return self::falha(422, 'DADOS_INVALIDOS', 'Informe a razao social para cadastrar a empresa.');
                }
                $this->clientes->criar($cnpj, (string)$campos['razao_social']);
            }
            $this->clientes->atualizar($cnpj, $campos);
            $this->clientes->registrarLog($cnpj, $anterior === null ? 'CADASTRO' : 'ALTERACAO', self::mudancas($anterior, $campos), $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'http' => $anterior === null ? 201 : 200, 'cliente' => $this->detalhe($cnpj)];
    }

    /**
     * Recebe o .pfx e a senha, confere e guarda criptografado.
     */
    public function enviarCertificado(string $cnpj, string $conteudo, string $senha, string $ip): array
    {
        $cnpj = NfseConfig::normalizarCnpj($cnpj);
        if ($this->clientes->buscar($cnpj) === null) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Empresa nao cadastrada.');
        }
        try {
            $cofre = new CofreCertificado((string)$this->cfg['chave_certificados']);
        } catch (Throwable $e) {
            return self::falha(503, 'SEM_CHAVE', $e->getMessage());
        }
        // .pfx antigo (RC2/3DES) vira um .pfx atual com a mesma senha; e ele que fica guardado.
        $conteudo = PfxCompativel::normalizar($conteudo, $senha);
        try {
            $certificado = Certificate::readPfx($conteudo, $senha);
        } catch (Throwable) {
            return self::falha(422, 'CERTIFICADO_INVALIDO', 'Senha incorreta ou o arquivo nao e um certificado A1 (.pfx/.p12).');
        }

        $cnpjCertificado = NfseConfig::normalizarCnpj($certificado->getCnpj() ?? '');
        if ($cnpjCertificado === '') {
            return self::falha(422, 'CERTIFICADO_INVALIDO', 'Este certificado nao e um e-CNPJ (nao tem CNPJ). Use o certificado A1 da empresa.');
        }
        if (substr($cnpjCertificado, 0, 8) !== substr($cnpj, 0, 8)) {
            return self::falha(422, 'CERTIFICADO_DE_OUTRA_EMPRESA', "Este certificado e do CNPJ {$cnpjCertificado}, que nao e desta empresa ({$cnpj}).");
        }
        if ($certificado->isExpired()) {
            return self::falha(422, 'CERTIFICADO_VENCIDO', 'Certificado vencido em ' . $certificado->getValidTo()->format('d/m/Y') . '.');
        }

        $validade = $certificado->getValidTo()->format(DATE_ATOM);
        $titular = (string)$certificado->getCompanyName();
        $this->clientes->gravarCertificado($cnpj, $cofre->cifrar($conteudo, $senha), $validade, $titular);
        $this->clientes->registrarLog($cnpj, 'CERTIFICADO', ['titular' => $titular, 'validade' => $validade], $ip);

        return ['ok' => true, 'http' => 200, 'mensagem' => "Certificado de {$titular} aceito, valido ate " . $certificado->getValidTo()->format('d/m/Y') . '.',
            'cliente' => $this->detalhe($cnpj)];
    }

    /**
     * Gera um token novo (o anterior para de funcionar na hora).
     * O token so aparece nesta resposta: o banco guarda o hash.
     */
    public function gerarToken(string $cnpj, string $ip): array
    {
        $cnpj = NfseConfig::normalizarCnpj($cnpj);
        if ($this->clientes->buscar($cnpj) === null) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Empresa nao cadastrada.');
        }
        $token = bin2hex(random_bytes(32));
        $this->clientes->gravarToken($cnpj, $token);
        $this->clientes->registrarLog($cnpj, 'TOKEN', ['final' => substr($token, -4)], $ip);

        return ['ok' => true, 'http' => 200, 'token' => $token,
            'mensagem' => 'Copie e entregue ao cliente agora: este token nao sera mostrado de novo.'];
    }

    /**
     * Numeracao inicial: informa o ULTIMO numero ja usado numa serie (no
     * sistema antigo, no portal, nas rotas antigas...). A proxima nota da
     * API sai com o numero seguinte. Nunca volta para tras (evita nota com
     * numero repetido). Vale para o ambiente atual do servidor (tpAmb).
     *
     * @param array{cnpj?: string, padrao?: string, serie?: mixed, ultimo_numero?: mixed} $entrada
     */
    public function definirNumeracao(array $entrada, string $ip): array
    {
        $cnpj = NfseConfig::normalizarCnpj($entrada['cnpj'] ?? '');
        if ($this->clientes->buscar($cnpj) === null) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Empresa nao cadastrada.');
        }

        $padrao = strtoupper(trim((string)($entrada['padrao'] ?? '')));
        if (!in_array($padrao, Padrao::TODOS, true)) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Tipo de nota invalido: use ' . implode(', ', Padrao::TODOS) . '.');
        }

        $serie = trim((string)($entrada['serie'] ?? ''));
        if ($serie === '') {
            $serie = $this->seriesPadrao()[$padrao];
        }
        if ($padrao === Padrao::NFE) {
            if (!preg_match('/^\d{1,3}$/', $serie)) {
                return self::falha(422, 'DADOS_INVALIDOS', 'Serie da NF-e deve ser um numero de 0 a 999.');
            }
            $serie = (string)(int)$serie; // "001" e "1" sao a mesma serie
        } elseif (!preg_match('/^[0-9A-Za-z]{1,5}$/', $serie)) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Serie deve ter de 1 a 5 letras ou numeros.');
        }

        $maximo = $padrao === Padrao::NFE ? 999_999_999 : 999_999_999_999_999;
        $ultimo = filter_var($entrada['ultimo_numero'] ?? null, FILTER_VALIDATE_INT);
        if ($ultimo === false || $ultimo < 0 || $ultimo >= $maximo) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Informe o ULTIMO numero ja usado (0 se a serie nunca foi usada), ate ' . number_format($maximo - 1, 0, ',', '.') . '.');
        }

        $tpAmb = (int)$this->cfg['tpAmb'];
        $this->pdo->beginTransaction();
        try {
            $r = $this->clientes->ajustarNumeracao($cnpj, $padrao, $tpAmb, $serie, $ultimo);
            $aplicado = $r['depois'] === $ultimo && ($r['antes'] === null || $r['antes'] <= $ultimo);
            $this->clientes->registrarLog($cnpj, 'NUMERACAO', [
                'padrao' => $padrao, 'serie' => $serie, 'ambiente' => $tpAmb,
                'informado' => $ultimo, 'antes' => $r['antes'], 'depois' => $r['depois'],
            ], $ip);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $proximo = $r['depois'] + 1;
        $mensagem = $aplicado
            ? "Pronto: a proxima nota desta serie sai com o numero {$proximo}."
            : "Mantido: a API ja usou ate o numero {$r['antes']} nesta serie, maior que o informado ({$ultimo}). A proxima nota sai com o numero {$proximo}.";

        return ['ok' => true, 'http' => 200, 'aplicado' => $aplicado, 'proximo_numero' => $proximo,
            'mensagem' => $mensagem, 'cliente' => $this->detalhe($cnpj)];
    }

    // ------------------------------------------------------------ apoio

    /** Serie usada quando a nota nao informa (configuracao do servidor). */
    private function seriesPadrao(): array
    {
        return [
            Padrao::NFE => (string)(int)($this->cfg['nfe']['serie'] ?? 1),
            Padrao::NACIONAL => (string)($this->cfg['nacional']['serie_dps'] ?? '1'),
            Padrao::SALVADOR => (string)($this->cfg['salvador']['serie_rps'] ?? '1'),
        ];
    }

    private function resumo(array $c, array $usoMesPorTipo): array
    {
        $situacao = $this->controle->situacao($c);
        $diasCertificado = null;
        if (!empty($c['certificado_validade'])) {
            $diasCertificado = (int)floor(((new DateTimeImmutable((string)$c['certificado_validade']))->getTimestamp() - time()) / 86400);
        }
        return $c + [
            'situacao' => $situacao['situacao'],
            'situacao_mensagem' => $situacao['mensagem'],
            'consumo_mes' => $situacao['consumo_mes'],
            'autorizadas_mes' => [
                'nfe' => $usoMesPorTipo[Padrao::NFE] ?? 0,
                'nfse_nacional' => $usoMesPorTipo[Padrao::NACIONAL] ?? 0,
                'nfse_salvador' => $usoMesPorTipo[Padrao::SALVADOR] ?? 0,
                'total' => array_sum($usoMesPorTipo),
            ],
            'certificado_dias_restantes' => $diasCertificado,
        ];
    }

    /**
     * Converte e confere o que veio do painel.
     *
     * @return array{0: array, 1: list<string>} campos prontos e erros
     */
    private static function normalizar(array $e): array
    {
        $campos = [];
        $erros = [];
        $texto = static fn(string $c, int $max) => array_key_exists($c, $e) ? (trim((string)$e[$c]) === '' ? null : mb_substr(trim((string)$e[$c]), 0, $max)) : false;

        foreach (['razao_social' => 150, 'nome_fantasia' => 150, 'email' => 120, 'telefone' => 20, 'motivo_bloqueio' => 300,
                  'plano' => 60, 'observacao' => 2000, 'webhook_url' => 500, 'webhook_segredo' => 200] as $c => $max) {
            $v = $texto($c, $max);
            if ($v !== false) {
                $campos[$c] = $v;
            }
        }
        if (array_key_exists('razao_social', $campos) && $campos['razao_social'] === null) {
            $erros[] = 'Razao social nao pode ficar vazia.';
        }
        if (!empty($campos['webhook_url']) && !preg_match('#^https?://#', $campos['webhook_url'])) {
            $erros[] = 'Webhook deve comecar com https://';
        }

        foreach (['ie', 'inscricao_municipal'] as $c) {
            if (array_key_exists($c, $e)) {
                $campos[$c] = preg_replace('/[^A-Za-z0-9]/', '', (string)$e[$c]) ?: null;
            }
        }
        if (array_key_exists('uf', $e)) {
            $uf = strtoupper(trim((string)$e['uf']));
            if ($uf !== '' && !preg_match('/^[A-Z]{2}$/', $uf)) {
                $erros[] = 'UF deve ter 2 letras (ex.: BA).';
            }
            $campos['uf'] = $uf ?: null;
        }
        if (array_key_exists('codigo_municipio', $e)) {
            $m = preg_replace('/\D/', '', (string)$e['codigo_municipio']);
            if (!preg_match('/^\d{7}$/', (string)$m)) {
                $erros[] = 'Codigo do municipio (IBGE) deve ter 7 digitos.';
            }
            $campos['codigo_municipio'] = $m;
        }

        $inteiros = ['opcao_simples_nacional' => [1, 3, false], 'regime_apuracao_simples' => [1, 3, true],
            'regime_especial_tributacao' => [0, 6, false], 'regime_especial_abrasf' => [1, 6, true]];
        foreach ($inteiros as $c => [$min, $max, $podeVazio]) {
            if (!array_key_exists($c, $e)) {
                continue;
            }
            if ($podeVazio && ($e[$c] === null || $e[$c] === '')) {
                $campos[$c] = null;
                continue;
            }
            $v = filter_var($e[$c], FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) {
                $erros[] = "{$c} deve ser de {$min} a {$max}.";
                continue;
            }
            $campos[$c] = $v;
        }

        if (array_key_exists('percentual_tributos_simples', $e)) {
            $bruto = str_replace(',', '.', trim((string)$e['percentual_tributos_simples']));
            if ($bruto === '') {
                $campos['percentual_tributos_simples'] = null;
            } elseif (!is_numeric($bruto) || (float)$bruto < 0 || (float)$bruto > 100) {
                $erros[] = 'Percentual de tributos do Simples deve ser um numero de 0 a 100 (ex.: 2 ou 6,5).';
            } else {
                $campos['percentual_tributos_simples'] = round((float)$bruto, 2);
            }
        }

        foreach (['emissao_liberada', 'incentivador_cultural'] as $c) {
            if (array_key_exists($c, $e)) {
                $campos[$c] = filter_var($e[$c], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('liberado_ate', $e)) {
            $d = trim((string)$e['liberado_ate']);
            if ($d !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $d) === false) {
                $erros[] = 'Liberado ate deve ser uma data AAAA-MM-DD.';
            }
            $campos['liberado_ate'] = $d ?: null;
        }

        if (array_key_exists('limite_mensal', $e)) {
            if ($e['limite_mensal'] === null || $e['limite_mensal'] === '') {
                $campos['limite_mensal'] = null;
            } else {
                $v = filter_var($e['limite_mensal'], FILTER_VALIDATE_INT);
                if ($v === false || $v < 1) {
                    $erros[] = 'Limite mensal deve ser um numero maior que zero (ou vazio para sem limite).';
                } else {
                    $campos['limite_mensal'] = $v;
                }
            }
        }

        if (array_key_exists('padroes_permitidos', $e)) {
            $lista = array_values(array_unique(array_map('strtoupper', (array)$e['padroes_permitidos'])));
            if (array_diff($lista, Padrao::TODOS) !== []) {
                $erros[] = 'Tipos de nota validos: ' . implode(', ', Padrao::TODOS) . '.';
            }
            $campos['padroes_permitidos'] = $lista;
        }

        return [$campos, $erros];
    }

    /** O que mudou (para o historico). Segredos nao sao gravados. */
    private static function mudancas(?array $anterior, array $campos): array
    {
        $saida = [];
        foreach ($campos as $c => $novo) {
            if ($c === 'webhook_segredo') {
                $saida[$c] = '(alterado)';
                continue;
            }
            if ($anterior === null) {
                if ($novo !== null && $novo !== '') {
                    $saida[$c] = $novo; // cadastro: so o que foi preenchido
                }
                continue;
            }
            $antes = $anterior[$c] ?? null;
            if ($antes != $novo) {
                $saida[$c] = ['de' => $antes, 'para' => $novo];
            }
        }
        return $saida;
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }

    public static function mesAtual(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('America/Bahia')))->format('Y-m');
    }
}
