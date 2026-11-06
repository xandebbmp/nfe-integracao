<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;

/**
 * Tela "Usuarios" do portal: quem da empresa entra no portal.
 *
 * Todos veem a lista. So administradores da empresa podem:
 *   convidar          cria a pessoa com senha provisoria (aparece uma vez)
 *   redefinir_senha   nova senha provisoria (esqueceu a senha)
 *   desativar/ativar  tira ou devolve o acesso
 *   tornar_admin/remover_admin
 *
 * Ninguem mexe em si mesmo (desativar, tirar o proprio administrador ou
 * redefinir a propria senha). Assim a empresa nunca fica sem administrador
 * ativo: quem age continua administrador. Para trocar a propria senha, use
 * "Minha senha" (rota senha.php).
 *
 * As regras de criar, redefinir e desativar ficam em PortalAcesso (as mesmas
 * do painel admin). Aqui so entra a permissao.
 */
final class PortalUsuarios
{
    public const ACOES = ['convidar', 'redefinir_senha', 'desativar', 'ativar', 'tornar_admin', 'remover_admin'];

    private PortalAcesso $acesso;

    public function __construct(PDO $pdo, array $cfg)
    {
        $this->acesso = new PortalAcesso($pdo, $cfg);
    }

    /** @param array $eu usuario da sessao */
    public function listar(array $eu): array
    {
        $agora = time();
        $usuarios = array_map(static function (array $u) use ($eu, $agora): array {
            return [
                'id' => $u['id'],
                'nome' => $u['nome'],
                'email' => $u['email'],
                'administrador' => $u['administrador'],
                'situacao' => self::situacao($u, $agora),
                'ultimo_acesso' => $u['ultimo_acesso'],
                'eu' => $u['id'] === (int)$eu['id'],
            ];
        }, $this->acesso->usuarios((string)$eu['cnpj']));

        return ['usuarios' => $usuarios, 'pode_gerenciar' => (bool)$eu['administrador']];
    }

    /** ATIVO | PRIMEIRO_ACESSO | BLOQUEADO (senha errada demais) | DESATIVADO */
    private static function situacao(array $u, int $agora): string
    {
        if (!$u['ativo']) {
            return 'DESATIVADO';
        }
        if ($u['bloqueado_ate'] !== null && strtotime((string)$u['bloqueado_ate']) > $agora) {
            return 'BLOQUEADO';
        }
        return $u['trocar_senha'] ? 'PRIMEIRO_ACESSO' : 'ATIVO';
    }

    /**
     * @param array $eu usuario da sessao
     * @param array $e  { acao, id?, nome?, email?, administrador? }
     */
    public function executar(array $eu, array $e, string $ip): array
    {
        $acao = (string)($e['acao'] ?? '');
        if (!in_array($acao, self::ACOES, true)) {
            return self::falha(422, 'ACAO_INVALIDA', 'Ação desconhecida.');
        }
        if (empty($eu['administrador'])) {
            return self::falha(403, 'SEM_PERMISSAO', 'Só um administrador da empresa pode fazer isso.');
        }
        $cnpj = (string)$eu['cnpj'];

        if ($acao === 'convidar') {
            $r = $this->acesso->criarUsuario($cnpj, (string)($e['nome'] ?? ''), (string)($e['email'] ?? ''), $ip, !empty($e['administrador']));
            if ($r['ok']) {
                $r['mensagem'] = 'Acesso criado. Envie o endereço do portal, o e-mail e a senha provisória para a pessoa.';
            }
            return $r;
        }

        $id = (int)($e['id'] ?? 0);
        if ($id === (int)$eu['id']) {
            return self::falha(409, 'NAO_PODE_SI_MESMO', $acao === 'redefinir_senha'
                ? 'Para trocar a sua senha, use "Minha senha" nesta tela.'
                : 'Você não pode mudar o seu próprio acesso. Peça a outro administrador.');
        }
        return match ($acao) {
            'redefinir_senha' => $this->acesso->redefinirSenha($cnpj, $id, $ip),
            'desativar' => $this->acesso->definirAtivo($cnpj, $id, false, $ip),
            'ativar' => $this->acesso->definirAtivo($cnpj, $id, true, $ip),
            'tornar_admin' => $this->acesso->definirAdministrador($cnpj, $id, true, $ip),
            'remover_admin' => $this->acesso->definirAdministrador($cnpj, $id, false, $ip),
        };
    }

    private static function falha(int $http, string $codigo, string $mensagem): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
