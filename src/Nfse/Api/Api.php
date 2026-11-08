<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Api;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Database\ConnectionFactory;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Servico\PainelAcesso;

/**
 * Funcoes comuns a todos os endpoints de public/v1/nfse/.
 *
 * Cada endpoint segue o mesmo roteiro:
 *
 *   $api = Api::iniciar(['POST']);          // metodo, config, tratamento de erro
 *   $acesso = $api->autenticar();           // quem esta chamando
 *   $corpo = $api->corpoJson();             // le o JSON
 *   ... usa os servicos ...
 *   $api->responder(200, [...]);            // devolve JSON e encerra
 *
 * Formato de erro (sempre igual):
 *   { "erro": { "codigo": "...", "mensagem": "...", ...detalhes } }
 */
final class Api
{
    private ?PDO $pdo = null;

    private function __construct(public readonly array $cfg)
    {
    }

    /**
     * @param list<string> $metodos metodos HTTP aceitos
     */
    public static function iniciar(array $metodos): self
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        // Qualquer excecao nao tratada vira JSON 500, sem expor detalhes.
        set_exception_handler(static function (Throwable $e): void {
            error_log('[nfse-api] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['erro' => [
                'codigo' => 'ERRO_INTERNO',
                'mensagem' => 'Erro interno ao processar a requisicao.',
            ]]);
        });

        // Config dos arquivos + empresas clientes cadastradas no painel admin.
        $pdo = ConnectionFactory::make();
        $api = new self(NfseConfig::comClientes(NfseConfig::carregar(), $pdo));
        $api->pdo = $pdo;

        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($metodo, $metodos, true)) {
            header('Allow: ' . implode(', ', $metodos));
            $api->erro(405, 'METODO_NAO_PERMITIDO', 'Use ' . implode(' ou ', $metodos) . '.');
        }

        return $api;
    }

    /**
     * Identifica quem esta chamando pelo token.
     *
     * Cabecalho aceito: "X-NFE-API-TOKEN: <token>" ou "Authorization: Bearer <token>".
     * Se $aceitaTokenNaUrl, tambem aceita ?token= (necessario para EventSource
     * do navegador, que nao envia cabecalhos). Use HTTPS sempre.
     *
     * @return array{consumidor: ?string, painel: bool}
     *   consumidor: nome do sistema (ve apenas os proprios documentos)
     *   painel: true para o token do monitor ou do administrador (ve todos os documentos)
     */
    public function autenticar(bool $aceitaTokenNaUrl = false): array
    {
        $token = self::cabecalho('X-NFE-API-TOKEN');
        if ($token === '' && preg_match('/^Bearer\s+(.+)$/i', self::cabecalho('Authorization'), $m)) {
            $token = trim($m[1]);
        }
        if ($token === '' && $aceitaTokenNaUrl) {
            $token = trim((string)($_GET['token'] ?? ''));
        }

        if ($token !== '') {
            // Empresas clientes (painel admin): o banco guarda so o sha256 do token.
            $hash = hash('sha256', $token);
            foreach ($this->cfg['consumidores'] as $nome => $dados) {
                $esperado = (string)($dados['token'] ?? '');
                $esperadoHash = (string)($dados['token_hash'] ?? '');
                if (($esperado !== '' && hash_equals($esperado, $token))
                    || ($esperadoHash !== '' && hash_equals($esperadoHash, $hash))) {
                    return ['consumidor' => (string)$nome, 'painel' => false];
                }
            }
            $tokenPainel = (string)($this->cfg['monitor_token'] ?? '');
            $hashPainel = (string)($this->cfg['monitor_token_hash'] ?? '');
            if (($tokenPainel !== '' && hash_equals($tokenPainel, $token))
                || ($hashPainel !== '' && hash_equals($hashPainel, $hash))) {
                return ['consumidor' => null, 'painel' => true];
            }
            // Quem entrou no painel admin abre o monitor sem outro login.
            // So leitura: emitir.php e as outras rotas de envio exigem consumidor.
            $tokenAdmin = (string)($this->cfg['admin_token'] ?? '');
            if (strlen($tokenAdmin) >= 24 && hash_equals($tokenAdmin, $token)) {
                return ['consumidor' => null, 'painel' => true];
            }
            if ((new PainelAcesso($this->pdo(), $this->cfg))->sessao($token) !== null) {
                return ['consumidor' => null, 'painel' => true];
            }
        }

        // Ambiente de desenvolvimento sem nenhum token configurado: libera
        // somente chamadas da propria maquina.
        $semTokens = $this->cfg['consumidores'] === [] && empty($this->cfg['monitor_token']) && empty($this->cfg['monitor_token_hash']);
        if ($semTokens && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            return ['consumidor' => 'LOCAL', 'painel' => true];
        }

        $this->erro(401, 'NAO_AUTORIZADO', 'Token de API ausente ou invalido.');
    }

    /** Quem entrou no painel (null = chave de emergencia). Preenchido por exigirAdmin(). */
    public ?array $pessoaPainel = null;

    /**
     * Exige login no PAINEL ADMIN (cabecalho X-ADMIN-TOKEN). Aceita:
     *   - o codigo do login com e-mail e senha (Servico/PainelAcesso.php);
     *   - o 'admin_token' de config/nfse.local.php, que e a CHAVE DE EMERGENCIA
     *     (cadastrar a primeira pessoa ou se todos ficarem sem acesso).
     * Quem esta com senha provisoria so passa onde $aceitaSenhaProvisoria.
     * Tambem diz ao historico quem esta fazendo as mudancas.
     */
    public function exigirAdmin(bool $aceitaSenhaProvisoria = false): void
    {
        $token = self::cabecalho('X-ADMIN-TOKEN');
        $chave = (string)($this->cfg['admin_token'] ?? '');
        if (strlen($chave) >= 24 && $token !== '' && hash_equals($chave, $token)) {
            ClienteRepositorio::$autor = 'Chave de emergência';
            return;
        }
        $pessoa = $token !== '' ? (new PainelAcesso($this->pdo(), $this->cfg))->sessao($token) : null;
        if ($pessoa === null) {
            usleep(300_000); // dificulta tentativa e erro
            $this->erro(401, 'NAO_AUTORIZADO', 'Sessão encerrada. Entre de novo com seu e-mail e senha.');
        }
        if ($pessoa['trocar_senha'] && !$aceitaSenhaProvisoria) {
            $this->erro(403, 'TROCAR_SENHA', 'Crie a sua senha antes de continuar.');
        }
        $this->pessoaPainel = $pessoa;
        ClienteRepositorio::$autor = "{$pessoa['nome']} <{$pessoa['email']}>";
    }

    /** IP de quem chamou (para o historico do painel admin). */
    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Exige um consumidor (o token do painel nao pode emitir). */
    public function exigirConsumidor(array $acesso): string
    {
        if ($acesso['consumidor'] === null) {
            $this->erro(403, 'PROIBIDO', 'Este token so pode ser usado no monitor.');
        }
        return $acesso['consumidor'];
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ConnectionFactory::make();
    }

    public function corpoJson(): array
    {
        $bruto = trim((string)file_get_contents('php://input'));
        if ($bruto === '') {
            $this->erro(400, 'CORPO_VAZIO', 'Corpo da requisicao vazio (JSON esperado).');
        }
        $dados = json_decode($bruto, true);
        if (!is_array($dados)) {
            $this->erro(400, 'JSON_INVALIDO', 'JSON invalido: ' . json_last_error_msg());
        }
        return $dados;
    }

    /**
     * Busca o documento e confere se quem chama pode ve-lo.
     * Responde 404 (e nao 403) para nao revelar que o id existe.
     */
    public function documentoPermitido(string $id, array $acesso): array
    {
        $documento = (new DocumentoRepositorio($this->pdo()))->buscar($id);
        if ($documento === null || (!$acesso['painel'] && $documento['consumidor'] !== $acesso['consumidor'])) {
            $this->erro(404, 'NAO_ENCONTRADO', 'Documento nao encontrado.');
        }
        return $documento;
    }

    /**
     * Espera ate $terminou() devolver true ou o tempo acabar.
     * Usado pelo parametro opcional "aguardar" (em segundos).
     */
    public function aguardar(int $segundos, callable $terminou): void
    {
        $segundos = max(0, min($segundos, (int)$this->cfg['api']['max_aguardar_segundos']));
        $limite = microtime(true) + $segundos;
        while (!$terminou() && microtime(true) < $limite) {
            usleep(500_000);
        }
    }

    public function responder(int $http, array $dados): never
    {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function erro(int $http, string $codigo, string $mensagem, array $extra = []): never
    {
        $this->responder($http, ['erro' => ['codigo' => $codigo, 'mensagem' => $mensagem] + $extra]);
    }

    private static function cabecalho(string $nome): string
    {
        $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
        return trim((string)($_SERVER[$chave] ?? ''));
    }
}
