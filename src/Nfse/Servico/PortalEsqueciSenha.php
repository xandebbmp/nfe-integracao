<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Repositorio\EmailRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * "Esqueci a senha" do portal (script 0012).
 *
 *   1. pedir(): a pessoa digita o e-mail. Se for de um usuario ativo, cria um
 *      codigo aleatorio (o banco guarda so o sha256) e poe na fila um e-mail
 *      com o link. A resposta e SEMPRE a mesma: ninguem descobre quem tem
 *      cadastro testando e-mails.
 *   2. O worker envia o e-mail (FilaEmails).
 *   3. conferir(): a tela abre o link e mostra para quem e a troca.
 *   4. redefinir(): grava a senha nova, o link para de valer e as sessoes
 *      abertas em outros navegadores saem.
 *
 * So liga com o script 0012, o SMTP completo e o endereco do portal.
 * O link vale 1 hora, uma vez. No maximo 3 pedidos por pessoa a cada hora.
 * O endereco do link vem da configuracao (email.url_portal), nunca do
 * navegador: assim ninguem consegue mandar um link apontando para outro site.
 */
final class PortalEsqueciSenha
{
    public const VALIDADE_MINUTOS = 60;
    public const PEDIDOS_POR_HORA = 3;
    public const RESPOSTA = 'Se este e-mail tiver acesso ao portal, enviamos um link para criar uma senha nova. Confira a caixa de entrada e o spam. O link vale 1 hora.';

    private PortalRepositorio $portal;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->portal = new PortalRepositorio($pdo);
    }

    /**
     * O "Esqueci a senha" pode ser usado? Precisa do script 0012, do endereco
     * do portal e do SMTP completo. Sem isso o portal mostra "peca ao
     * administrador", em vez de prometer um e-mail que nunca chega.
     */
    public function disponivel(): bool
    {
        return $this->portal->temRedefinicoes() && $this->urlPortal() !== '' && (new EnvioEmail($this->cfg))->configurado();
    }

    public function pedir(string $email, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::falha(422, 'EMAIL_INVALIDO', 'Digite o e-mail que você usa para entrar.', [['campo' => 'email', 'mensagem' => 'Digite um e-mail válido.']]);
        }
        if (!$this->disponivel()) {
            return self::falha(503, 'ESQUECI_INDISPONIVEL', 'A recuperação por e-mail ainda não foi ligada. Peça ao administrador da sua empresa para gerar uma senha nova.');
        }

        $usuario = $this->portal->usuarioPorEmail($email);
        if ($usuario !== null && $usuario['ativo']
            && $this->portal->redefinicoesRecentes((int)$usuario['id'], 60) < self::PEDIDOS_POR_HORA) {
            $codigo = bin2hex(random_bytes(32));
            $this->portal->criarRedefinicao((int)$usuario['id'], hash('sha256', $codigo), self::VALIDADE_MINUTOS, $ip);
            [$assunto, $texto, $html] = $this->mensagem((string)$usuario['nome'], $this->urlPortal() . '#redefinir=' . $codigo);
            (new EmailRepositorio($this->pdo))->enfileirar($email, $assunto, $texto, $html);
        }
        return ['ok' => true, 'mensagem' => self::RESPOSTA];
    }

    /** A tela abriu o link: ainda vale? Devolve o primeiro nome e o e-mail para mostrar. */
    public function conferir(string $codigo): array
    {
        $r = $this->buscar($codigo);
        if ($r === null) {
            return self::linkVencido();
        }
        return ['ok' => true, 'nome' => explode(' ', trim((string)$r['nome']))[0], 'email' => $r['email']];
    }

    public function redefinir(string $codigo, string $senha): array
    {
        $r = $this->buscar($codigo);
        if ($r === null) {
            return self::linkVencido();
        }
        $erro = PortalAcesso::problemaNaSenha($senha);
        if ($erro !== null) {
            return self::falha(422, 'SENHA_FRACA', $erro, [['campo' => 'senha', 'mensagem' => $erro]]);
        }
        $id = (int)$r['id'];
        $this->portal->definirSenha($id, password_hash($senha, PASSWORD_DEFAULT), false);
        $this->portal->encerrarRedefinicoes($id);
        $this->portal->apagarSessoesDoUsuario($id);
        return ['ok' => true, 'email' => $r['email'], 'mensagem' => 'Senha criada. Entre com o seu e-mail e a senha nova.'];
    }

    private function buscar(string $codigo): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $codigo) || !$this->portal->temRedefinicoes()) {
            return null;
        }
        return $this->portal->redefinicaoValida(hash('sha256', $codigo));
    }

    private function urlPortal(): string
    {
        return trim((string)($this->cfg['email']['url_portal'] ?? ''));
    }

    /** @return array{0: string, 1: string, 2: string} assunto, texto e html */
    private function mensagem(string $nome, string $link): array
    {
        $primeiro = explode(' ', trim($nome))[0];
        $assunto = 'Criar uma senha nova no portal';
        $texto = "Olá, {$primeiro}.\n\n"
            . "Recebemos um pedido para criar uma senha nova no portal de notas fiscais.\n"
            . "Para continuar, abra o link abaixo (vale 1 hora e só pode ser usado uma vez):\n\n{$link}\n\n"
            . "Se não foi você, ignore este e-mail: a sua senha atual continua valendo.\n";
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#1f2933;max-width:520px">'
            . '<p>Olá, ' . $e($primeiro) . '.</p>'
            . '<p>Recebemos um pedido para criar uma senha nova no portal de notas fiscais.</p>'
            . '<p style="margin:24px 0"><a href="' . $e($link) . '" style="background:#17324d;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Criar senha nova</a></p>'
            . '<p style="color:#52606d;font-size:13px">O link vale 1 hora e só pode ser usado uma vez. Se não foi você, ignore este e-mail: a sua senha atual continua valendo.</p>'
            . '</div>';
        return [$assunto, $texto, $html];
    }

    private static function linkVencido(): array
    {
        return self::falha(410, 'LINK_INVALIDO', 'Este link venceu ou já foi usado. Peça um novo em "Esqueci a senha".');
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $erros = []): array
    {
        $r = ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
        if ($erros !== []) {
            $r['erros'] = $erros;
        }
        return $r;
    }
}
