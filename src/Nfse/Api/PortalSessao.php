<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Api;

use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\PortalAcesso;

/**
 * Cookie de sessao do portal do cliente (rotas public/v1/portal/).
 *
 * Roteiro de cada rota do portal:
 *
 *   $api = Api::iniciar(['GET']);
 *   $usuario = PortalSessao::exigir($api);   // 401 se nao estiver logado
 *   ... usa $usuario['cnpj'] em tudo ...
 *
 * Seguranca:
 *   - cookie HttpOnly (JavaScript nao le), SameSite=Strict, Secure no HTTPS;
 *   - chamadas que alteram algo (POST/DELETE) exigem o cabecalho
 *     "X-Portal: 1". Outro site nao consegue enviar esse cabecalho, entao
 *     nao consegue emitir nota usando o cookie de quem esta logado (CSRF).
 */
final class PortalSessao
{
    public const COOKIE = 'portal_sessao';

    /** Usuario logado, ou responde 401 e encerra. */
    public static function exigir(Api $api, bool $permiteSenhaProvisoria = false): array
    {
        self::exigirPortalInstalado($api);
        self::exigirCabecalhoEmAlteracao($api);

        $usuario = (new PortalAcesso($api->pdo(), $api->cfg))->sessao(self::token());
        if ($usuario === null) {
            self::apagarCookie();
            $api->erro(401, 'SESSAO_EXPIRADA', 'Sua sessão expirou. Entre de novo.');
        }
        if ($usuario['trocar_senha'] && !$permiteSenhaProvisoria) {
            $api->erro(403, 'TROCAR_SENHA', 'Troque a senha provisória antes de continuar.');
        }
        return $usuario;
    }

    /** Valor do cookie (vazio se nao houver). */
    public static function token(): string
    {
        return (string)($_COOKIE[self::COOKIE] ?? '');
    }

    /** $minutos = null: cookie de sessao (some ao fechar o navegador). */
    public static function gravarCookie(string $token, ?int $minutos): void
    {
        setcookie(self::COOKIE, $token, self::opcoes($minutos === null ? 0 : time() + $minutos * 60));
    }

    public static function apagarCookie(): void
    {
        if (isset($_COOKIE[self::COOKIE])) {
            setcookie(self::COOKIE, '', self::opcoes(time() - 3600));
        }
    }

    public static function exigirCabecalhoEmAlteracao(Api $api): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($metodo !== 'GET' && ($_SERVER['HTTP_X_PORTAL'] ?? '') !== '1') {
            $api->erro(400, 'CABECALHO_AUSENTE', 'Requisição sem o cabeçalho X-Portal.');
        }
    }

    public static function exigirPortalInstalado(Api $api): void
    {
        if (!(new PortalRepositorio($api->pdo()))->existe()) {
            $api->erro(503, 'PORTAL_NAO_INSTALADO', 'Portal sem banco: aplique database/scripts/0006_portal_criar_usuarios.sql.');
        }
    }

    private static function opcoes(int $expira): array
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ['expires' => $expira, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict'];
    }
}
