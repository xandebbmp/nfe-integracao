<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Repositorio\AdminRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;

/**
 * Login do PAINEL ADMIN com e-mail e senha (script 0014).
 *
 *   entrar() / sessao() / sair()      login de cada pessoa
 *   trocarSenha()                      a propria pessoa (obrigatorio no 1o acesso)
 *   listar() / criar() / redefinirSenha() / definirAtivo() / enviarAcessoPorEmail()
 *                                      bloco "Pessoas do painel" (tela Sistema)
 *
 * O codigo do login (64 letras) vai no mesmo cabecalho X-ADMIN-TOKEN que o
 * painel ja usava. O banco guarda so o sha256 dele. O admin_token do arquivo
 * continua valendo como CHAVE DE EMERGENCIA (ver Api::exigirAdmin).
 *
 * Erros no formato ['ok' => false, 'http', 'codigo', 'mensagem'].
 */
final class PainelAcesso
{
    public const SESSAO_MINUTOS = 720;          // 12 horas
    public const LEMBRAR_DIAS = 30;
    public const TENTATIVAS = 5;
    public const BLOQUEIO_MINUTOS = 15;

    private AdminRepositorio $pessoas;

    public function __construct(private PDO $pdo, private array $cfg = [])
    {
        $this->pessoas = new AdminRepositorio($pdo);
    }

    public function instalado(): bool
    {
        return $this->pessoas->existe();
    }

    /** Ja existe alguem ativo? (sem ninguem, so a chave de emergencia entra) */
    public function temPessoas(): bool
    {
        return $this->instalado() && $this->pessoas->contarAtivos() > 0;
    }

    // =====================================================================
    // Login
    // =====================================================================

    public function entrar(string $email, string $senha, bool $lembrar, string $ip, string $navegador): array
    {
        if (!$this->instalado()) {
            return self::falha(503, 'PAINEL_SEM_PESSOAS', 'Falta atualizar o banco (script 0014). Entre com a chave de emergência e clique em "Atualizar banco" em Sistema.');
        }
        $email = mb_strtolower(trim($email));
        $pessoa = $email !== '' ? $this->pessoas->porEmail($email) : null;

        // Roda o password_verify mesmo sem pessoa: o tempo nao revela se o e-mail existe.
        $senhaOk = password_verify($senha, $pessoa['senha_hash'] ?? '$2y$10$6ud1U6Y3SQLPSGtgWZxpP.Bz9ccVCtma7TMY1UlPJWEbLxs/0FvGq');
        $invalido = self::falha(401, 'LOGIN_INVALIDO', 'E-mail ou senha incorretos. Confira e tente de novo.');
        if ($pessoa === null || !$pessoa['ativo']) {
            usleep(300_000);
            return $invalido;
        }
        if ($pessoa['bloqueado_ate'] !== null && strtotime((string)$pessoa['bloqueado_ate']) > time()) {
            $minutos = max(1, (int)ceil((strtotime((string)$pessoa['bloqueado_ate']) - time()) / 60));
            return self::falha(429, 'LOGIN_BLOQUEADO', "Muitas tentativas com senha errada. Tente de novo em {$minutos} minuto(s) ou peça para alguém do painel gerar uma senha nova.");
        }
        if (!$senhaOk) {
            $restam = self::TENTATIVAS - $this->pessoas->registrarFalha((int)$pessoa['id'], self::TENTATIVAS, self::BLOQUEIO_MINUTOS);
            if ($restam <= 0) {
                return self::falha(429, 'LOGIN_BLOQUEADO', 'Muitas tentativas com senha errada. Acesso bloqueado por ' . self::BLOQUEIO_MINUTOS . ' minutos.');
            }
            return $restam <= 2 ? self::falha(401, 'LOGIN_INVALIDO', "E-mail ou senha incorretos. Restam {$restam} tentativa(s) antes do bloqueio temporário.") : $invalido;
        }

        $minutos = $lembrar ? self::LEMBRAR_DIAS * 1440 : self::SESSAO_MINUTOS;
        $token = bin2hex(random_bytes(32));
        $this->pessoas->criarSessao(hash('sha256', $token), (int)$pessoa['id'], $minutos, $ip, $navegador);
        $this->pessoas->registrarAcesso((int)$pessoa['id']);
        $this->pessoas->apagarSessoesVencidas();
        return ['ok' => true, 'token' => $token, 'minutos' => $minutos, 'pessoa' => self::publico($pessoa)];
    }

    /** Pessoa do login (ou null se o codigo for invalido/vencido). */
    public function sessao(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !$this->instalado()) {
            return null;
        }
        $sessao = $this->pessoas->sessaoValida(hash('sha256', $token));
        return $sessao === null ? null : self::publico($sessao);
    }

    public function sair(string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) && $this->instalado()) {
            $this->pessoas->apagarSessao(hash('sha256', $token));
        }
    }

    /** A propria pessoa troca a senha. Os outros logins dela saem. */
    public function trocarSenha(array $pessoa, string $token, string $atual, string $nova): array
    {
        $registro = $this->pessoas->porId((int)$pessoa['id']);
        if ($registro === null || !password_verify($atual, (string)$registro['senha_hash'])) {
            return self::falha(422, 'SENHA_ATUAL_ERRADA', 'A senha atual não confere.');
        }
        $problema = PortalAcesso::problemaNaSenha($nova);
        if ($problema !== null) {
            return self::falha(422, 'SENHA_FRACA', $problema);
        }
        if (hash_equals($atual, $nova)) {
            return self::falha(422, 'SENHA_IGUAL', 'Escolha uma senha diferente da atual.');
        }
        $this->pessoas->definirSenha((int)$pessoa['id'], password_hash($nova, PASSWORD_DEFAULT), false);
        $this->pessoas->apagarSessoesDoUsuario((int)$pessoa['id'], hash('sha256', $token));
        return ['ok' => true, 'mensagem' => 'Senha trocada.'];
    }

    // =====================================================================
    // Pessoas do painel (tela Sistema)
    // =====================================================================

    public function listar(): array
    {
        return array_map(static fn(array $p): array => [
            'id' => (int)$p['id'], 'nome' => $p['nome'], 'email' => $p['email'],
            'ativo' => (bool)$p['ativo'], 'trocar_senha' => (bool)$p['trocar_senha'], 'bloqueado' => (bool)$p['bloqueado'],
            'ultimo_acesso' => $p['ultimo_acesso'], 'criado_em' => $p['criado_em'],
        ], $this->pessoas->listar());
    }

    /** Cria com senha PROVISORIA (devolvida so nesta resposta). */
    public function criar(string $nome, string $email, string $ip): array
    {
        $nome = trim($nome);
        $email = mb_strtolower(trim($email));
        if (mb_strlen($nome) < 2) {
            return self::falha(422, 'NOME_INVALIDO', 'Informe o nome da pessoa.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::falha(422, 'EMAIL_INVALIDO', 'Informe um e-mail válido.');
        }
        if ($this->pessoas->porEmail($email) !== null) {
            return self::falha(409, 'EMAIL_EM_USO', 'Este e-mail já entra no painel.');
        }
        $senha = PortalAcesso::senhaProvisoria();
        $id = $this->pessoas->criar($nome, $email, password_hash($senha, PASSWORD_DEFAULT));
        $this->log('PAINEL_PESSOA_CRIADA', ['id' => $id, 'email' => $email], $ip);
        return ['ok' => true, 'id' => $id, 'email' => $email, 'senha_provisoria' => $senha,
            'mensagem' => 'Pessoa cadastrada. Envie o acesso: ela troca a senha no primeiro login.'];
    }

    public function redefinirSenha(int $id, string $ip): array
    {
        $pessoa = $this->pessoas->porId($id);
        if ($pessoa === null) {
            return self::falha(404, 'PESSOA_NAO_ENCONTRADA', 'Pessoa não encontrada.');
        }
        $senha = PortalAcesso::senhaProvisoria();
        $this->pessoas->definirSenha($id, password_hash($senha, PASSWORD_DEFAULT), true);
        $this->pessoas->apagarSessoesDoUsuario($id);
        $this->log('PAINEL_SENHA_REDEFINIDA', ['id' => $id, 'email' => $pessoa['email']], $ip);
        return ['ok' => true, 'id' => $id, 'email' => $pessoa['email'], 'senha_provisoria' => $senha,
            'mensagem' => 'Senha nova gerada. A pessoa troca no próximo login.'];
    }

    /** $euId: quem esta logado (ninguem desativa a si mesmo). */
    public function definirAtivo(int $id, bool $ativo, ?int $euId, string $ip): array
    {
        $pessoa = $this->pessoas->porId($id);
        if ($pessoa === null) {
            return self::falha(404, 'PESSOA_NAO_ENCONTRADA', 'Pessoa não encontrada.');
        }
        if (!$ativo && $euId === $id) {
            return self::falha(409, 'NAO_PODE_DESATIVAR_A_SI', 'Você não pode desativar o seu próprio acesso.');
        }
        $this->pessoas->definirAtivo($id, $ativo);
        if (!$ativo) {
            $this->pessoas->apagarSessoesDoUsuario($id);
        }
        $this->log($ativo ? 'PAINEL_PESSOA_ATIVADA' : 'PAINEL_PESSOA_DESATIVADA', ['id' => $id, 'email' => $pessoa['email']], $ip);
        return ['ok' => true, 'mensagem' => $ativo ? 'Acesso liberado.' : 'Acesso desativado.'];
    }

    /** Manda por e-mail o endereco do painel, o e-mail e a senha provisoria atual. */
    public function enviarAcessoPorEmail(int $id, string $senha, string $linkPainel, string $ip, ?EnvioEmail $envio = null): array
    {
        $pessoa = $this->pessoas->porId($id);
        if ($pessoa === null) {
            return self::falha(404, 'PESSOA_NAO_ENCONTRADA', 'Pessoa não encontrada.');
        }
        if (empty($pessoa['trocar_senha']) || !password_verify($senha, (string)$pessoa['senha_hash'])) {
            return self::falha(409, 'SENHA_NAO_E_A_ATUAL', 'Esta senha não vale mais. Clique em "Enviar acesso por e-mail" de novo.');
        }
        $envio ??= new EnvioEmail($this->cfg);
        if (!$envio->configurado()) {
            return self::falha(503, 'EMAIL_NAO_CONFIGURADO', 'O envio de e-mail ainda não foi configurado. Falta: '
                . implode(', ', $envio->pendencias()) . '. Configure em Sistema > E-mail, ou copie os dados e envie você mesmo.');
        }
        $link = preg_match('#^https?://[^\s]+$#i', $linkPainel) ? $linkPainel : '';
        $primeiro = explode(' ', trim((string)$pessoa['nome']))[0];
        $texto = "Olá, {$primeiro}.\n\nVocê recebeu acesso ao painel administrativo do sistema fiscal.\n\n"
            . ($link !== '' ? "Painel: {$link}\n" : '')
            . "E-mail: {$pessoa['email']}\nSenha provisória: {$senha}\n\n"
            . "No primeiro acesso o painel pede para você criar a sua própria senha.\n";
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#1f2933;max-width:520px">'
            . '<p>Olá, ' . $e($primeiro) . '.</p><p>Você recebeu acesso ao painel administrativo do sistema fiscal.</p>'
            . '<table style="border-collapse:collapse;margin:16px 0">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#52606d">E-mail</td><td><strong>' . $e((string)$pessoa['email']) . '</strong></td></tr>'
            . '<tr><td style="padding:4px 12px 4px 0;color:#52606d">Senha provisória</td><td style="font-family:monospace;font-size:17px"><strong>' . $e($senha) . '</strong></td></tr>'
            . '</table>'
            . ($link !== '' ? '<p style="margin:24px 0"><a href="' . $e($link) . '" style="background:#17324d;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Entrar no painel</a></p>' : '')
            . '<p style="color:#52606d;font-size:13px">No primeiro acesso o painel pede para você criar a sua própria senha.</p></div>';
        try {
            $envio->enviar((string)$pessoa['email'], 'Seu acesso ao painel do sistema fiscal', $texto, $html);
        } catch (Throwable $erro) {
            return self::falha(502, 'EMAIL_FALHOU', 'O servidor de e-mail recusou: ' . $erro->getMessage());
        }
        $this->log('PAINEL_ACESSO_ENVIADO', ['id' => $id, 'email' => $pessoa['email']], $ip);
        return ['ok' => true, 'email' => $pessoa['email'], 'mensagem' => "Enviado para {$pessoa['email']}. Peça para a pessoa olhar também o spam."];
    }

    // =====================================================================

    private function log(string $acao, array $detalhes, string $ip): void
    {
        (new ClienteRepositorio($this->pdo))->registrarLog(null, $acao, $detalhes, $ip);
    }

    private static function publico(array $p): array
    {
        return ['id' => (int)$p['id'], 'nome' => (string)$p['nome'], 'email' => (string)$p['email'], 'trocar_senha' => (bool)$p['trocar_senha']];
    }

    private static function falha(int $http, string $codigo, string $mensagem): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
