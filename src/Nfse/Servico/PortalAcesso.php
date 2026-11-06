<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Login do portal do cliente e cadastro dos usuarios (feito no painel admin).
 *
 * Como funciona a sessao:
 *   1. entrar() confere e-mail e senha e cria um valor aleatorio (token).
 *   2. O endpoint grava esse token num cookie HttpOnly (o JavaScript nao le).
 *   3. O banco guarda so o sha256 do token (portal_sessoes).
 *   4. A cada chamada, sessao() procura o sha256 do cookie.
 *
 * Protecao contra tentativa e erro: depois de 'tentativas_login' senhas
 * erradas seguidas, o usuario fica bloqueado por 'bloqueio_minutos'.
 *
 * Todos os metodos devolvem ['ok' => true, ...] ou
 * ['ok' => false, 'http' => ..., 'codigo' => ..., 'mensagem' => ...].
 */
final class PortalAcesso
{
    public const SENHA_MINIMA = 8;

    private PortalRepositorio $portal;
    private array $opcoes;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->portal = new PortalRepositorio($pdo);
        $this->opcoes = ($cfg['portal'] ?? []) + [
            'sessao_minutos' => 720,
            'sessao_lembrar_dias' => 30,
            'tentativas_login' => 5,
            'bloqueio_minutos' => 15,
        ];
    }

    // =====================================================================
    // Login / sessao
    // =====================================================================

    /**
     * @return array{ok: true, token: string, minutos: int, lembrar: bool, usuario: array}|array{ok: false, http: int, codigo: string, mensagem: string}
     */
    public function entrar(string $email, string $senha, bool $lembrar, string $ip, string $navegador): array
    {
        $email = self::normalizarEmail($email);
        $usuario = $email !== '' ? $this->portal->usuarioPorEmail($email) : null;

        // Mesmo sem usuario, roda o password_verify: o tempo de resposta nao
        // revela se o e-mail existe.
        $hash = $usuario['senha_hash'] ?? '$2y$10$6ud1U6Y3SQLPSGtgWZxpP.Bz9ccVCtma7TMY1UlPJWEbLxs/0FvGq';
        $senhaOk = password_verify($senha, $hash);

        $invalido = self::falha(401, 'LOGIN_INVALIDO', 'E-mail ou senha incorretos. Confira e tente de novo.');
        if ($usuario === null || !$usuario['ativo']) {
            return $invalido;
        }

        if ($usuario['bloqueado_ate'] !== null && strtotime((string)$usuario['bloqueado_ate']) > time()) {
            $minutos = max(1, (int)ceil((strtotime((string)$usuario['bloqueado_ate']) - time()) / 60));
            return self::falha(429, 'LOGIN_BLOQUEADO', "Muitas tentativas com senha errada. Tente de novo em {$minutos} minuto(s) ou peça ao administrador para redefinir a senha.");
        }

        if (!$senhaOk) {
            $feitas = $this->portal->registrarFalha((int)$usuario['id'], (int)$this->opcoes['tentativas_login'], (int)$this->opcoes['bloqueio_minutos']);
            $restam = (int)$this->opcoes['tentativas_login'] - $feitas;
            if ($restam <= 0) {
                return self::falha(429, 'LOGIN_BLOQUEADO', 'Muitas tentativas com senha errada. Acesso bloqueado por ' . $this->opcoes['bloqueio_minutos'] . ' minutos.');
            }
            return $restam <= 2
                ? self::falha(401, 'LOGIN_INVALIDO', "E-mail ou senha incorretos. Restam {$restam} tentativa(s) antes do bloqueio temporário.")
                : $invalido;
        }

        // Senha antiga com custo menor: atualiza o hash sem a pessoa perceber.
        if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
            $this->portal->definirSenha((int)$usuario['id'], password_hash($senha, PASSWORD_DEFAULT), (bool)$usuario['trocar_senha']);
        }

        $minutos = $lembrar ? (int)$this->opcoes['sessao_lembrar_dias'] * 1440 : (int)$this->opcoes['sessao_minutos'];
        $token = bin2hex(random_bytes(32));
        $this->portal->criarSessao(hash('sha256', $token), (int)$usuario['id'], $minutos, $ip, $navegador);
        $this->portal->registrarAcesso((int)$usuario['id']);
        $this->portal->apagarSessoesVencidas();

        return ['ok' => true, 'token' => $token, 'minutos' => $minutos, 'lembrar' => $lembrar, 'usuario' => self::publico($usuario)];
    }

    /** Usuario da sessao (ou null se o cookie for invalido/expirado). */
    public function sessao(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $sessao = $this->portal->sessaoValida(hash('sha256', $token));
        if ($sessao === null) {
            return null;
        }
        $sessao['id'] = (int)$sessao['id'];
        $sessao['trocar_senha'] = (bool)$sessao['trocar_senha'];
        $sessao['administrador'] = (bool)$sessao['administrador'];
        return $sessao;
    }

    public function sair(string $token): void
    {
        if ($token !== '') {
            $this->portal->apagarSessao(hash('sha256', $token));
        }
    }

    /** O proprio usuario troca a senha (obrigatorio quando ela e provisoria). */
    public function trocarSenha(int $usuarioId, string $atual, string $nova, string $tokenAtual): array
    {
        $usuario = $this->portal->usuarioPorId($usuarioId);
        if ($usuario === null || !password_verify($atual, $usuario['senha_hash'])) {
            return self::falha(422, 'SENHA_ATUAL_INCORRETA', 'A senha atual não confere.');
        }
        $erro = self::problemaNaSenha($nova);
        if ($erro !== null) {
            return self::falha(422, 'SENHA_FRACA', $erro);
        }
        if (password_verify($nova, $usuario['senha_hash'])) {
            return self::falha(422, 'SENHA_REPETIDA', 'Escolha uma senha diferente da atual.');
        }
        $this->portal->definirSenha($usuarioId, password_hash($nova, PASSWORD_DEFAULT), false);
        // Outros navegadores abertos com a senha antiga saem.
        $this->portal->apagarSessoesDoUsuario($usuarioId, hash('sha256', $tokenAtual));
        return ['ok' => true, 'mensagem' => 'Senha alterada.'];
    }

    // =====================================================================
    // Painel admin: usuarios de cada empresa
    // =====================================================================

    public function usuarios(string $cnpj): array
    {
        return array_map(static function (array $u): array {
            $u['id'] = (int)$u['id'];
            $u['ativo'] = (bool)$u['ativo'];
            $u['trocar_senha'] = (bool)$u['trocar_senha'];
            $u['administrador'] = (bool)$u['administrador'];
            return $u;
        }, $this->portal->usuariosDaEmpresa(NfseConfig::normalizarCnpj($cnpj)));
    }

    /**
     * Cria o usuario com uma senha PROVISORIA, devolvida so nesta resposta.
     * No 1o acesso o portal pede para a pessoa trocar.
     * Empresa sem administrador ativo: a pessoa criada vira administradora.
     */
    public function criarUsuario(string $cnpj, string $nome, string $email, string $ip, bool $administrador = false): array
    {
        $cnpj = NfseConfig::normalizarCnpj($cnpj);
        $nome = trim($nome);
        $email = self::normalizarEmail($email);

        $clientes = new ClienteRepositorio($this->pdo);
        if ($clientes->buscar($cnpj) === null) {
            return self::falha(404, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não cadastrada no painel.');
        }
        if (mb_strlen($nome) < 2) {
            return self::falha(422, 'NOME_INVALIDO', 'Informe o nome da pessoa.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::falha(422, 'EMAIL_INVALIDO', 'Informe um e-mail válido.');
        }
        if ($this->portal->usuarioPorEmail($email) !== null) {
            return self::falha(409, 'EMAIL_EM_USO', 'Este e-mail já tem acesso ao portal (nesta ou em outra empresa).');
        }

        $administrador = $administrador || !$this->portal->temAdministrador($cnpj);
        $senha = self::senhaProvisoria();
        $id = $this->portal->criarUsuario($cnpj, $nome, $email, password_hash($senha, PASSWORD_DEFAULT), $administrador);
        $clientes->registrarLog($cnpj, 'PORTAL_USUARIO_CRIADO', ['usuario_id' => $id, 'email' => $email, 'administrador' => $administrador], $ip);

        return ['ok' => true, 'id' => $id, 'email' => $email, 'administrador' => $administrador, 'senha_provisoria' => $senha,
            'mensagem' => 'Usuário criado. Envie o e-mail e a senha provisória para a pessoa: ela troca no primeiro acesso.'];
    }

    /** Gera nova senha provisoria (esqueci a senha) e encerra as sessoes. */
    public function redefinirSenha(string $cnpj, int $id, string $ip): array
    {
        $usuario = $this->usuarioDaEmpresa($cnpj, $id);
        if ($usuario === null) {
            return self::falha(404, 'USUARIO_NAO_ENCONTRADO', 'Usuário não encontrado nesta empresa.');
        }
        $senha = self::senhaProvisoria();
        $this->portal->definirSenha($id, password_hash($senha, PASSWORD_DEFAULT), true);
        $this->portal->apagarSessoesDoUsuario($id);
        (new ClienteRepositorio($this->pdo))->registrarLog($usuario['cnpj'], 'PORTAL_SENHA_REDEFINIDA', ['usuario_id' => $id], $ip);
        return ['ok' => true, 'id' => $id, 'email' => $usuario['email'], 'senha_provisoria' => $senha,
            'mensagem' => 'Senha redefinida. A pessoa troca no próximo acesso.'];
    }

    /**
     * Manda por e-mail os dados de acesso (endereco do portal, e-mail e senha
     * provisoria) que o painel acabou de mostrar. Envia NA HORA (nao passa
     * pela fila) para o painel mostrar se deu certo, e a senha nao fica gravada
     * em lugar nenhum. So aceita a senha provisoria atual da pessoa.
     *
     * $linkPainel: endereco do portal visto pelo painel; usado so quando o
     * "Endereço do portal" (email.url_portal) nao foi configurado.
     */
    public function enviarAcessoPorEmail(string $cnpj, int $id, string $senha, string $linkPainel, string $ip, ?EnvioEmail $envio = null): array
    {
        $usuario = $this->usuarioDaEmpresa($cnpj, $id);
        if ($usuario === null) {
            return self::falha(404, 'USUARIO_NAO_ENCONTRADO', 'Usuário não encontrado nesta empresa.');
        }
        if (empty($usuario['trocar_senha']) || !password_verify($senha, (string)$usuario['senha_hash'])) {
            return self::falha(409, 'SENHA_NAO_E_A_ATUAL', 'Esta senha não vale mais (a pessoa já trocou ou foi gerada outra). Clique em "Nova senha" e envie de novo.');
        }
        $envio ??= new EnvioEmail($this->cfg);
        if (!$envio->configurado()) {
            return self::falha(503, 'EMAIL_NAO_CONFIGURADO', 'O envio de e-mail ainda não foi configurado. Falta: '
                . implode(', ', $envio->pendencias()) . '. Configure em Sistema > E-mail, ou copie os dados e envie você mesmo.');
        }
        $link = trim((string)($this->cfg['email']['url_portal'] ?? ''));
        if ($link === '' && preg_match('#^https?://[^\s]+$#i', $linkPainel)) {
            $link = $linkPainel;
        }

        $primeiro = explode(' ', trim((string)$usuario['nome']))[0];
        $assunto = 'Seu acesso ao portal de notas fiscais';
        $texto = "Olá, {$primeiro}.\n\nVocê recebeu acesso ao portal de notas fiscais.\n\n"
            . ($link !== '' ? "Portal: {$link}\n" : '')
            . "E-mail: {$usuario['email']}\nSenha provisória: {$senha}\n\n"
            . "No primeiro acesso o portal pede para você criar a sua própria senha.\n";
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#1f2933;max-width:520px">'
            . '<p>Olá, ' . $e($primeiro) . '.</p>'
            . '<p>Você recebeu acesso ao portal de notas fiscais.</p>'
            . '<table style="border-collapse:collapse;margin:16px 0">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#52606d">E-mail</td><td style="padding:4px 0"><strong>' . $e((string)$usuario['email']) . '</strong></td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#52606d">Senha provisória</td><td style="padding:4px 0;font-family:monospace;font-size:17px"><strong>' . $e($senha) . '</strong></td></tr>'
            . '</table>'
            . ($link !== '' ? '<p style="margin:24px 0"><a href="' . $e($link) . '" style="background:#17324d;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Entrar no portal</a></p>' : '')
            . '<p style="color:#52606d;font-size:13px">No primeiro acesso o portal pede para você criar a sua própria senha.</p>'
            . '</div>';
        try {
            $envio->enviar((string)$usuario['email'], $assunto, $texto, $html);
        } catch (\Throwable $erro) {
            return self::falha(502, 'EMAIL_FALHOU', 'O servidor de e-mail recusou: ' . $erro->getMessage());
        }
        (new ClienteRepositorio($this->pdo))->registrarLog($usuario['cnpj'], 'PORTAL_ACESSO_ENVIADO', ['usuario_id' => $id, 'email' => $usuario['email']], $ip);
        return ['ok' => true, 'email' => $usuario['email'], 'mensagem' => "Enviado para {$usuario['email']}. Peça para a pessoa olhar também o spam."];
    }

    public function definirAtivo(string $cnpj, int $id, bool $ativo, string $ip): array
    {
        $usuario = $this->usuarioDaEmpresa($cnpj, $id);
        if ($usuario === null) {
            return self::falha(404, 'USUARIO_NAO_ENCONTRADO', 'Usuário não encontrado nesta empresa.');
        }
        $this->portal->definirAtivo($id, $ativo);
        if (!$ativo) {
            $this->portal->apagarSessoesDoUsuario($id);
        }
        (new ClienteRepositorio($this->pdo))->registrarLog($usuario['cnpj'], $ativo ? 'PORTAL_USUARIO_ATIVADO' : 'PORTAL_USUARIO_DESATIVADO', ['usuario_id' => $id], $ip);
        return ['ok' => true, 'mensagem' => $ativo ? 'Acesso liberado.' : 'Acesso desativado.'];
    }

    public function definirAdministrador(string $cnpj, int $id, bool $administrador, string $ip): array
    {
        $usuario = $this->usuarioDaEmpresa($cnpj, $id);
        if ($usuario === null) {
            return self::falha(404, 'USUARIO_NAO_ENCONTRADO', 'Usuário não encontrado nesta empresa.');
        }
        $this->portal->definirAdministrador($id, $administrador);
        (new ClienteRepositorio($this->pdo))->registrarLog($usuario['cnpj'], $administrador ? 'PORTAL_ADMIN_CONCEDIDO' : 'PORTAL_ADMIN_RETIRADO', ['usuario_id' => $id], $ip);
        return ['ok' => true, 'mensagem' => $administrador ? 'Agora é administrador.' : 'Agora só emite notas.'];
    }

    // =====================================================================

    private function usuarioDaEmpresa(string $cnpj, int $id): ?array
    {
        $usuario = $this->portal->usuarioPorId($id);
        return $usuario !== null && $usuario['cnpj'] === NfseConfig::normalizarCnpj($cnpj) ? $usuario : null;
    }

    public static function problemaNaSenha(string $senha): ?string
    {
        if (mb_strlen($senha) < self::SENHA_MINIMA) {
            return 'A senha precisa ter pelo menos ' . self::SENHA_MINIMA . ' caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
            return 'Use letras e números na senha.';
        }
        return null;
    }

    /** 10 caracteres faceis de ditar (sem 0/O, 1/l/I). */
    private static function senhaProvisoria(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyz';
        $numeros = '23456789';
        $todos = $letras . strtoupper($letras) . $numeros;
        $senha = $letras[random_int(0, strlen($letras) - 1)] . $numeros[random_int(0, strlen($numeros) - 1)];
        for ($i = 0; $i < 8; $i++) {
            $senha .= $todos[random_int(0, strlen($todos) - 1)];
        }
        return str_shuffle($senha);
    }

    private static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function publico(array $u): array
    {
        return ['id' => (int)$u['id'], 'cnpj' => $u['cnpj'], 'nome' => $u['nome'], 'email' => $u['email'],
            'trocar_senha' => (bool)$u['trocar_senha'], 'administrador' => (bool)($u['administrador'] ?? false)];
    }

    private static function falha(int $http, string $codigo, string $mensagem): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
