<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Infra\ConsultaCnpj;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EmissorRepositorio;

/**
 * Emissor simples (/emissor/): o proprio cliente emite NFS-e Nacional
 * informando so VALOR, TOMADOR e DESCRICAO.
 *
 * Tudo o que e complicado no Portal Nacional (codigo de tributacao,
 * aliquota, ISS retido, municipio, regime) vem do cadastro da empresa e
 * do servico, feitos UMA vez no painel admin.
 *
 * Por dentro, a nota segue exatamente o mesmo caminho da API: monta o
 * documento e chama RecebimentoLote (fila, worker, monitor, controle de
 * emissao, cobranca, webhooks). Nada e emitido "por fora".
 *
 * Os metodos devolvem ['ok' => bool, 'http' => int, ...] para as rotas so
 * repassarem a resposta.
 */
final class EmissorSimples
{
    public const HORAS_SESSAO = 12;
    private const MAX_FALHAS = 5;

    private EmissorRepositorio $emissor;
    private ClienteRepositorio $clientes;
    private DocumentoRepositorio $documentos;

    public function __construct(private PDO $pdo, private array $cfg, private ?ConsultaCnpj $consultaCnpj = null)
    {
        $this->emissor = new EmissorRepositorio($pdo);
        $this->clientes = new ClienteRepositorio($pdo);
        $this->documentos = new DocumentoRepositorio($pdo);
    }

    // ================================================================ acesso

    public function entrar(string $email, string $senha, string $ip): array
    {
        $usuario = $this->emissor->usuarioPorEmail(trim($email));
        $mensagem = 'E-mail ou senha incorretos.';

        if ($usuario === null) {
            // Confere contra um hash qualquer: a resposta demora o mesmo, e
            // ninguem descobre quais e-mails estao cadastrados.
            password_verify($senha, '$2y$10$sBrD/zwnUaUKn0OzDBY7LutlILualMKBBM.yPQRePK7mUhmSbpDUm');
            return self::falha(401, 'LOGIN_INVALIDO', $mensagem);
        }
        if (!empty($usuario['bloqueado_ate']) && strtotime((string)$usuario['bloqueado_ate']) > time()) {
            return self::falha(429, 'LOGIN_BLOQUEADO', 'Muitas tentativas com senha errada. Aguarde 15 minutos e tente de novo.');
        }
        if (!password_verify($senha, (string)$usuario['senha_hash'])) {
            $this->emissor->registrarFalha($usuario['id']);
            return self::falha(401, 'LOGIN_INVALIDO', $mensagem);
        }
        if (!$usuario['ativo']) {
            return self::falha(403, 'USUARIO_INATIVO', 'Seu acesso esta desativado. Fale com o suporte.');
        }

        $token = bin2hex(random_bytes(32));
        $this->emissor->registrarAcesso($usuario['id']);
        $this->emissor->criarSessao($usuario['id'], hash('sha256', $token), self::HORAS_SESSAO, $ip);
        return ['ok' => true, 'http' => 200, 'token' => $token];
    }

    /** Usuario da sessao (ou null se vencida/invalida). */
    public function usuarioDaSessao(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return $this->emissor->usuarioDaSessao(hash('sha256', $token), self::HORAS_SESSAO);
    }

    public function sair(string $token): void
    {
        $this->emissor->encerrarSessao(hash('sha256', $token));
    }

    public function trocarSenha(array $usuario, string $atual, string $nova): array
    {
        $completo = $this->emissor->usuario($usuario['id']);
        if ($completo === null || !password_verify($atual, (string)$completo['senha_hash'])) {
            return self::falha(422, 'SENHA_ATUAL_ERRADA', 'A senha atual nao confere.');
        }
        if (mb_strlen($nova) < 8) {
            return self::falha(422, 'SENHA_FRACA', 'A nova senha precisa ter pelo menos 8 caracteres.');
        }
        $this->emissor->trocarSenha($usuario['id'], password_hash($nova, PASSWORD_DEFAULT));
        return ['ok' => true, 'http' => 200, 'mensagem' => 'Senha alterada. Entre de novo com a nova senha.'];
    }

    // ================================================================ tela

    /** Tudo o que a tela precisa ao abrir. */
    public function painel(array $usuario): array
    {
        $cnpj = (string)$usuario['cnpj'];
        $empresa = $this->clientes->buscar($cnpj) ?? ['cnpj' => $cnpj, 'razao_social' => $cnpj];
        $situacao = isset($empresa['emissao_liberada'])
            ? (new ControleEmissao($this->pdo, (int)$this->cfg['tpAmb']))->situacao($empresa)
            : ['situacao' => ControleEmissao::LIBERADA, 'mensagem' => ''];

        return [
            'usuario' => ['nome' => $usuario['nome'], 'email' => $usuario['email']],
            'empresa' => [
                'cnpj' => $cnpj,
                'nome' => ($empresa['nome_fantasia'] ?? '') !== '' ? $empresa['nome_fantasia'] : $empresa['razao_social'],
                'razao_social' => $empresa['razao_social'],
                'situacao' => $situacao['situacao'],
                'situacao_mensagem' => $situacao['mensagem'],
                'tem_certificado' => (bool)($empresa['tem_certificado'] ?? false),
                'pode_nacional' => in_array(Padrao::NACIONAL, (array)($empresa['padroes_permitidos'] ?? [Padrao::NACIONAL]), true),
            ],
            'ambiente' => (int)$this->cfg['tpAmb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
            'servicos' => array_map(static fn(array $s): array => [
                'id' => $s['id'], 'nome' => $s['nome'], 'padrao' => $s['padrao'], 'descricao_padrao' => $s['descricao_padrao'],
            ], $this->emissor->servicos($cnpj)),
            'tomadores' => array_map([self::class, 'tomadorParaTela'], $this->emissor->tomadoresRecentes($cnpj)),
            'notas' => $this->notas($usuario),
        ];
    }

    /** @return list<array> */
    public function notas(array $usuario): array
    {
        return array_map([self::class, 'notaParaTela'], $this->emissor->notasRecentes((string)$usuario['cnpj'], (int)$this->cfg['tpAmb']));
    }

    /** Uma nota da empresa do usuario (ou null). */
    public function nota(array $usuario, string $id): ?array
    {
        $doc = $this->documentoDaEmpresa($usuario, $id);
        if ($doc === null) {
            return null;
        }
        $linha = array_values(array_filter($this->emissor->notasRecentes((string)$usuario['cnpj'], (int)$this->cfg['tpAmb'], 200),
            static fn(array $n): bool => $n['id'] === $doc['id']))[0] ?? null;
        return $linha !== null ? self::notaParaTela($linha) : null;
    }

    public function documentoDaEmpresa(array $usuario, string $id): ?array
    {
        $doc = $this->documentos->buscar($id);
        return $doc !== null && $doc['emitente_cnpj'] === $usuario['cnpj'] && $doc['padrao'] === Padrao::NACIONAL ? $doc : null;
    }

    /**
     * Completa o tomador pelo CPF/CNPJ: primeiro os ja usados pela empresa,
     * depois (so CNPJ) a base publica da Receita.
     */
    public function tomador(array $usuario, string $documento): array
    {
        $documento = self::soDocumento($documento);
        if (!self::documentoValido($documento)) {
            return self::falha(422, 'DOCUMENTO_INVALIDO', 'CPF ou CNPJ invalido. Confira os numeros.');
        }
        $salvo = $this->emissor->tomador((string)$usuario['cnpj'], $documento);
        if ($salvo !== null) {
            return ['ok' => true, 'http' => 200, 'origem' => 'SALVO', 'tomador' => self::tomadorParaTela($salvo)];
        }
        if (strlen($documento) === 14 && $this->consultaCnpj !== null) {
            $receita = $this->consultaCnpj->buscar($documento);
            if ($receita !== null) {
                return ['ok' => true, 'http' => 200, 'origem' => 'RECEITA', 'tomador' => ['documento' => $documento] + $receita];
            }
        }
        return ['ok' => true, 'http' => 200, 'origem' => null, 'tomador' => ['documento' => $documento, 'nome' => '', 'email' => null, 'endereco' => null]];
    }

    // ================================================================ emitir

    /**
     * @param array{valor?: mixed, tomador?: array, descricao?: string, servico_id?: mixed, chave?: string} $entrada
     *   chave: identificador do formulario (o mesmo em caso de clique duplo
     *   ou nova tentativa). Garante que nao sai nota em dobro.
     */
    public function emitir(array $usuario, array $entrada): array
    {
        $cnpj = (string)$usuario['cnpj'];

        $bloqueio = (new ControleEmissao($this->pdo, (int)$this->cfg['tpAmb']))->bloqueioDoCliente($cnpj);
        if ($bloqueio !== null) {
            return self::falha(403, $bloqueio['codigo'], $bloqueio['mensagem']);
        }

        $erros = [];
        $valor = self::valor($entrada['valor'] ?? null);
        if ($valor === null) {
            $erros[] = ['campo' => 'valor', 'mensagem' => 'Informe o valor do servico (ex.: 1.500,00).'];
        }

        $t = is_array($entrada['tomador'] ?? null) ? $entrada['tomador'] : [];
        $documento = self::soDocumento((string)($t['documento'] ?? ''));
        if (!self::documentoValido($documento)) {
            $erros[] = ['campo' => 'tomador.documento', 'mensagem' => 'CPF ou CNPJ do tomador invalido.'];
        }
        $nome = mb_substr(trim((string)($t['nome'] ?? '')), 0, 150);
        if ($nome === '') {
            $erros[] = ['campo' => 'tomador.nome', 'mensagem' => 'Informe o nome do tomador (para quem foi o servico).'];
        }
        $email = trim((string)($t['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = ['campo' => 'tomador.email', 'mensagem' => 'E-mail do tomador invalido.'];
        }

        $descricao = trim((string)($entrada['descricao'] ?? ''));
        if ($descricao === '') {
            $erros[] = ['campo' => 'descricao', 'mensagem' => 'Descreva o servico prestado.'];
        } elseif (mb_strlen($descricao) > 2000) {
            $erros[] = ['campo' => 'descricao', 'mensagem' => 'Descricao muito longa (maximo 2.000 caracteres).'];
        }

        $servico = $this->escolherServico($cnpj, $entrada['servico_id'] ?? null);
        if ($servico === null) {
            return self::falha(409, 'SEM_SERVICO', 'O servico da sua empresa ainda nao foi configurado. Fale com o suporte.');
        }
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', $erros[0]['mensagem'], ['erros' => $erros]);
        }

        // Endereco so vai se estiver completo (e opcional no Padrao Nacional).
        $endereco = is_array($t['endereco'] ?? null) ? $t['endereco'] : null;
        $enderecoCompleto = $endereco !== null
            && trim((string)($endereco['logradouro'] ?? '')) !== '' && trim((string)($endereco['bairro'] ?? '')) !== ''
            && preg_match('/^\d{7}$/', (string)($endereco['codigo_municipio'] ?? '')) && preg_match('/^\d{8}$/', preg_replace('/\D/', '', (string)($endereco['cep'] ?? '')));

        $tomador = array_filter([
            'cpf_cnpj' => $documento,
            'nome' => $nome,
            'email' => $email !== '' ? strtolower($email) : null,
            'telefone' => preg_replace('/\D/', '', (string)($t['telefone'] ?? '')) ?: null,
            'endereco' => $enderecoCompleto ? array_filter([
                'logradouro' => mb_substr((string)$endereco['logradouro'], 0, 255),
                'numero' => (string)($endereco['numero'] ?? '') ?: 'S/N',
                'complemento' => (string)($endereco['complemento'] ?? '') ?: null,
                'bairro' => (string)$endereco['bairro'],
                'codigo_municipio' => (string)$endereco['codigo_municipio'],
                'uf' => (string)($endereco['uf'] ?? '') ?: null,
                'cep' => preg_replace('/\D/', '', (string)$endereco['cep']),
            ], static fn($v) => $v !== null) : null,
        ], static fn($v) => $v !== null);

        $payload = [
            'referencia' => 'EMISSOR-' . self::chave($entrada['chave'] ?? null),
            'tomador' => $tomador,
            'servico' => array_filter([
                'descricao' => $descricao,
                'codigo_tributacao_nacional' => $servico['codigo_tributacao_nacional'],
                'codigo_nbs' => $servico['codigo_nbs'],
                'codigo_tributacao_municipal' => $servico['codigo_tributacao_municipal'],
            ], static fn($v) => $v !== null && $v !== ''),
            'valores' => array_filter([
                'valor_servicos' => $valor,
                'aliquota_iss' => $servico['aliquota_iss'],
                'iss_retido' => $servico['iss_retido'],
            ], static fn($v) => $v !== null),
        ];

        $r = (new RecebimentoLote($this->pdo, $this->cfg))->receber($cnpj, [
            'padrao' => Padrao::NACIONAL,
            'emitente_cnpj' => $cnpj,
            'documentos' => [$payload],
        ]);
        if (!$r['ok']) {
            return self::falha(422, 'NAO_RECEBIDO', $r['erro']);
        }

        $item = $r['documentos'][0];
        if ($item['situacao_recebimento'] === RecebimentoLote::RECUSADO) {
            $erro = $item['erros'][0] ?? ['codigo' => 'RECUSADO', 'mensagem' => 'Nota recusada.'];
            return self::falha(422, (string)$erro['codigo'], (string)$erro['mensagem'], ['erros' => $item['erros'] ?? []]);
        }

        if (in_array($item['situacao_recebimento'], [RecebimentoLote::NOVO, RecebimentoLote::REENVIADO], true)) {
            $this->emissor->salvarTomador($cnpj, [
                'documento' => $documento, 'nome' => $nome, 'email' => $tomador['email'] ?? null,
                'telefone' => $tomador['telefone'] ?? null, 'endereco' => $enderecoCompleto ? $endereco : null,
            ], true);
        }

        return ['ok' => true, 'http' => 202, 'situacao_recebimento' => $item['situacao_recebimento'], 'id' => $item['id']];
    }

    // ================================================================ cancelar

    public function cancelar(array $usuario, string $id, string $motivoCodigo, string $motivo): array
    {
        $doc = $this->documentoDaEmpresa($usuario, $id);
        if ($doc === null) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Nota nao encontrada.');
        }
        if ($doc['status'] !== StatusNfse::AUTORIZADO) {
            return self::falha(409, 'NAO_PODE_CANCELAR', 'So da para cancelar nota autorizada.');
        }
        if (!in_array($motivoCodigo, ['1', '2', '9'], true)) {
            $motivoCodigo = '9';
        }
        $r = (new Solicitacoes($this->pdo, $this->cfg))->registrarEvento($doc, TipoEvento::CANCELAMENTO, $motivoCodigo, trim($motivo));
        if (!$r['ok']) {
            return self::falha((int)($r['http'] ?? 409), (string)($r['codigo'] ?? 'NAO_PODE_CANCELAR'), (string)$r['mensagem']);
        }
        return ['ok' => true, 'http' => 202, 'mensagem' => 'Cancelamento enviado. Em instantes a nota aparece como cancelada.'];
    }

    // ================================================================ apoio

    private function escolherServico(string $cnpj, mixed $id): ?array
    {
        if ($id !== null && $id !== '' && filter_var($id, FILTER_VALIDATE_INT) !== false) {
            $s = $this->emissor->servico($cnpj, (int)$id);
            if ($s !== null && $s['ativo']) {
                return $s;
            }
        }
        $lista = $this->emissor->servicos($cnpj);
        return $lista[0] ?? null; // padrao primeiro
    }

    /** "1.500,50", "1500.50", 1500.5 -> 1500.5 (ate 2 casas). */
    public static function valor(mixed $entrada): ?float
    {
        if (is_int($entrada) || is_float($entrada)) {
            $numero = (float)$entrada;
        } else {
            $texto = preg_replace('/[^\d,.]/', '', (string)$entrada) ?? '';
            if ($texto === '') {
                return null;
            }
            if (str_contains($texto, ',')) {
                $texto = str_replace(['.', ','], ['', '.'], $texto); // formato brasileiro
            } elseif (substr_count($texto, '.') > 1) {
                $texto = str_replace('.', '', $texto);
            }
            if (!is_numeric($texto)) {
                return null;
            }
            $numero = (float)$texto;
        }
        if ($numero <= 0 || $numero > 999_999_999.99 || abs(round($numero, 2) - $numero) > 0.000001) {
            return null;
        }
        return round($numero, 2);
    }

    public static function soDocumento(string $documento): string
    {
        return preg_replace('/\D/', '', $documento) ?? '';
    }

    /** CPF (11) ou CNPJ (14) com digitos verificadores corretos. */
    public static function documentoValido(string $d): bool
    {
        if (!preg_match('/^(\d{11}|\d{14})$/', $d) || preg_match('/^(\d)\1+$/', $d)) {
            return false;
        }
        if (strlen($d) === 11) {
            for ($t = 9; $t < 11; $t++) {
                $soma = 0;
                for ($i = 0; $i < $t; $i++) {
                    $soma += (int)$d[$i] * (($t + 1) - $i);
                }
                if ((int)$d[$t] !== ((10 * $soma) % 11) % 10) {
                    return false;
                }
            }
            return true;
        }
        foreach ([12, 13] as $t) {
            $soma = 0;
            $peso = $t - 7;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int)$d[$i] * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }
            $digito = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int)$d[$t] !== $digito) {
                return false;
            }
        }
        return true;
    }

    private static function chave(mixed $chave): string
    {
        $limpa = preg_replace('/[^A-Za-z0-9-]/', '', (string)$chave) ?? '';
        return strlen($limpa) >= 8 ? substr($limpa, 0, 40) : bin2hex(random_bytes(10));
    }

    private static function tomadorParaTela(array $t): array
    {
        return ['documento' => $t['documento'], 'nome' => $t['nome'], 'email' => $t['email'] ?? null,
            'telefone' => $t['telefone'] ?? null, 'endereco' => $t['endereco'] ?? null];
    }

    private static function notaParaTela(array $n): array
    {
        $mensagem = $n['mensagens'][0] ?? null;
        return [
            'id' => $n['id'],
            'status' => $n['status'],
            'numero' => $n['numero_nfse'],
            'valor' => $n['valor'],
            'tomador_nome' => $n['tomador_nome'],
            'tomador_documento' => $n['tomador_documento'],
            'descricao' => mb_substr((string)$n['descricao'], 0, 140),
            'data' => $n['data_autorizacao'] ?: $n['criado_em'],
            'mensagem' => $mensagem['mensagem'] ?? null,
            'correcao' => $mensagem['correcao'] ?? null,
            'tem_pdf' => in_array($n['status'], [StatusNfse::AUTORIZADO, StatusNfse::CANCELADO, StatusNfse::SUBSTITUIDO], true),
            'pode_cancelar' => $n['status'] === StatusNfse::AUTORIZADO,
        ];
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }
}
