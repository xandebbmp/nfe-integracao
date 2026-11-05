<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use PDOException;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EmissorRepositorio;

/**
 * Painel admin -> emissor simples de uma empresa:
 *   - pessoas que entram no /emissor/ (e-mail + senha gerada aqui);
 *   - servicos da empresa (codigo de tributacao, aliquota...), o que deixa
 *     a emissao em 3 campos (valor, tomador, descricao).
 *
 * Toda alteracao vai para o historico (fiscal_admin_log).
 */
final class AdministracaoEmissor
{
    private EmissorRepositorio $emissor;
    private ClienteRepositorio $clientes;

    public function __construct(private PDO $pdo)
    {
        $this->emissor = new EmissorRepositorio($pdo);
        $this->clientes = new ClienteRepositorio($pdo);
    }

    /** Para o detalhe da empresa no painel. */
    public function resumo(string $cnpj): array
    {
        if (!$this->emissor->existe()) {
            return ['disponivel' => false, 'usuarios' => [], 'servicos' => []];
        }
        return [
            'disponivel' => true,
            'usuarios' => $this->emissor->usuariosDaEmpresa($cnpj),
            'servicos' => $this->emissor->servicos($cnpj),
        ];
    }

    // ------------------------------------------------------------ usuarios

    /**
     * @param array{acao?: string, cnpj?: string, id?: mixed, nome?: string, email?: string} $e
     *   acao: CRIAR | NOVA_SENHA | ATIVAR | DESATIVAR
     */
    public function usuario(array $e, string $ip): array
    {
        $cnpj = NfseConfig::normalizarCnpj($e['cnpj'] ?? '');
        if ($this->clientes->buscar($cnpj) === null) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Empresa nao cadastrada.');
        }
        $acao = strtoupper((string)($e['acao'] ?? ''));

        if ($acao === 'CRIAR') {
            $nome = mb_substr(trim((string)($e['nome'] ?? '')), 0, 120);
            $email = strtolower(trim((string)($e['email'] ?? '')));
            if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return self::falha(422, 'DADOS_INVALIDOS', 'Informe o nome e um e-mail valido.');
            }
            if ($this->emissor->usuarioPorEmail($email) !== null) {
                return self::falha(409, 'EMAIL_EM_USO', 'Este e-mail ja tem acesso (talvez em outra empresa).');
            }
            $senha = self::senhaAleatoria();
            try {
                $id = $this->emissor->criarUsuario($cnpj, $nome, $email, password_hash($senha, PASSWORD_DEFAULT));
            } catch (PDOException) {
                return self::falha(409, 'EMAIL_EM_USO', 'Este e-mail ja tem acesso.');
            }
            $this->clientes->registrarLog($cnpj, 'EMISSOR_USUARIO', ['acao' => 'CRIAR', 'email' => $email], $ip);
            return ['ok' => true, 'http' => 201, 'id' => $id, 'senha' => $senha,
                'mensagem' => 'Acesso criado. Entregue o e-mail e a senha agora: a senha nao sera mostrada de novo.'];
        }

        $id = filter_var($e['id'] ?? null, FILTER_VALIDATE_INT);
        $usuario = $id !== false ? $this->emissor->usuario((int)$id) : null;
        if ($usuario === null || $usuario['cnpj'] !== $cnpj) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Acesso nao encontrado nesta empresa.');
        }

        switch ($acao) {
            case 'NOVA_SENHA':
                $senha = self::senhaAleatoria();
                $this->emissor->trocarSenha($usuario['id'], password_hash($senha, PASSWORD_DEFAULT));
                $this->clientes->registrarLog($cnpj, 'EMISSOR_USUARIO', ['acao' => 'NOVA_SENHA', 'email' => $usuario['email']], $ip);
                return ['ok' => true, 'http' => 200, 'senha' => $senha,
                    'mensagem' => 'Senha nova gerada (a anterior parou de funcionar). Entregue agora: ela nao sera mostrada de novo.'];
            case 'ATIVAR':
            case 'DESATIVAR':
                $this->emissor->definirAtivo($usuario['id'], $acao === 'ATIVAR');
                $this->clientes->registrarLog($cnpj, 'EMISSOR_USUARIO', ['acao' => $acao, 'email' => $usuario['email']], $ip);
                return ['ok' => true, 'http' => 200, 'mensagem' => $acao === 'ATIVAR' ? 'Acesso reativado.' : 'Acesso desativado (saiu na hora).'];
        }
        return self::falha(422, 'ACAO_INVALIDA', 'Acao invalida.');
    }

    // ------------------------------------------------------------ servicos

    /**
     * @param array $e acao: SALVAR (id vazio = novo) | REMOVER
     */
    public function servico(array $e, string $ip): array
    {
        $cnpj = NfseConfig::normalizarCnpj($e['cnpj'] ?? '');
        if ($this->clientes->buscar($cnpj) === null) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Empresa nao cadastrada.');
        }
        $acao = strtoupper((string)($e['acao'] ?? 'SALVAR'));
        $id = ($e['id'] ?? '') === '' || ($e['id'] ?? null) === null ? null : filter_var($e['id'], FILTER_VALIDATE_INT);
        if ($id === false || ($id !== null && $this->emissor->servico($cnpj, (int)$id) === null)) {
            return self::falha(404, 'NAO_ENCONTRADO', 'Servico nao encontrado nesta empresa.');
        }

        if ($acao === 'REMOVER') {
            if ($id === null) {
                return self::falha(422, 'DADOS_INVALIDOS', 'Informe o servico.');
            }
            $this->emissor->desativarServico($cnpj, (int)$id);
            $this->clientes->registrarLog($cnpj, 'EMISSOR_SERVICO', ['acao' => 'REMOVER', 'id' => $id], $ip);
            return ['ok' => true, 'http' => 200, 'mensagem' => 'Servico removido.'];
        }

        $erros = [];
        $nome = mb_substr(trim((string)($e['nome'] ?? '')), 0, 80);
        if ($nome === '') {
            $erros[] = 'Informe um nome curto para o servico (ex.: "Consultoria").';
        }
        $codigo = preg_replace('/\D/', '', (string)($e['codigo_tributacao_nacional'] ?? '')) ?? '';
        if (!preg_match('/^\d{6}$/', $codigo)) {
            $erros[] = 'Codigo de tributacao nacional deve ter 6 digitos (ex.: 010101). Pergunte ao contador.';
        }
        $nbs = preg_replace('/\D/', '', (string)($e['codigo_nbs'] ?? '')) ?? '';
        if ($nbs !== '' && !preg_match('/^\d{9}$/', $nbs)) {
            $erros[] = 'Codigo NBS deve ter 9 digitos (ou deixe vazio).';
        }
        $aliquota = null;
        if (($e['aliquota_iss'] ?? '') !== '' && ($e['aliquota_iss'] ?? null) !== null) {
            $aliquota = EmissorSimples::valor($e['aliquota_iss']);
            if ($aliquota === null && (string)$e['aliquota_iss'] !== '0') {
                $erros[] = 'Aliquota de ISS invalida (use 5 para 5%).';
            } elseif ($aliquota !== null && $aliquota > 100) {
                $erros[] = 'Aliquota e um percentual: use 5 para 5%.';
            }
            $aliquota ??= 0.0;
        }
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', implode(' ', $erros), ['erros' => $erros]);
        }

        $padrao = filter_var($e['padrao'] ?? false, FILTER_VALIDATE_BOOLEAN) || $this->emissor->servicos($cnpj) === [];
        $novoId = $this->emissor->salvarServico($cnpj, $id === null ? null : (int)$id, [
            'nome' => $nome,
            'codigo_tributacao_nacional' => $codigo,
            'codigo_nbs' => $nbs ?: null,
            'codigo_tributacao_municipal' => mb_substr(trim((string)($e['codigo_tributacao_municipal'] ?? '')), 0, 20) ?: null,
            'aliquota_iss' => $aliquota,
            'iss_retido' => filter_var($e['iss_retido'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'descricao_padrao' => mb_substr(trim((string)($e['descricao_padrao'] ?? '')), 0, 2000) ?: null,
            'padrao' => $padrao,
        ]);
        $this->clientes->registrarLog($cnpj, 'EMISSOR_SERVICO', ['acao' => $id === null ? 'CRIAR' : 'ALTERAR', 'nome' => $nome, 'codigo' => $codigo], $ip);
        return ['ok' => true, 'http' => $id === null ? 201 : 200, 'id' => $novoId, 'mensagem' => 'Servico salvo.'];
    }

    /** Senha facil de ditar: sem letras/numeros parecidos (l, 1, o, 0). */
    private static function senhaAleatoria(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyz23456789';
        $senha = '';
        for ($i = 0; $i < 10; $i++) {
            $senha .= $letras[random_int(0, strlen($letras) - 1)];
        }
        return $senha;
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }
}
