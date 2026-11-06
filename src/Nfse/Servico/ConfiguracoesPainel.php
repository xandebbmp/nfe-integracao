<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Dominio\AjustesPainel;
use Xande\NfeIntegracao\Nfse\Infra\CofreCertificado;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\SistemaRepositorio;

/**
 * Secao "Configuracoes" da tela Sistema do painel admin.
 *
 *   listar()  cada configuracao liberada (Dominio/AjustesPainel.php) com o
 *             valor em uso, o valor do arquivo e se foi mudada no painel
 *   salvar()  confere e grava. Valor igual ao do arquivo apaga o ajuste
 *             (volta a valer o arquivo).
 *
 *   salvarSenhaEmail()  senha do SMTP digitada no painel: vai CRIPTOGRAFADA
 *             para sistema_ajustes ('email.senha_cifrada'), com a mesma chave
 *             dos certificados. Nunca volta para a tela.
 *
 * Os valores passam a valer na proxima requisicao da API/portal. O worker
 * rele a cada minuto.
 */
final class ConfiguracoesPainel
{
    public const CHAVE_SENHA_EMAIL = 'email.senha_cifrada';

    private SistemaRepositorio $sistema;

    /** @param array $cfgArquivo config SO dos arquivos (NfseConfig::carregar()), sem os ajustes */
    public function __construct(private PDO $pdo, private array $cfgArquivo)
    {
        $this->sistema = new SistemaRepositorio($pdo);
    }

    public function instalado(): bool
    {
        return $this->sistema->tabelaExiste('sistema_ajustes');
    }

    public function listar(): array
    {
        $ajustes = $this->sistema->ajustes();
        $campos = [];
        foreach (AjustesPainel::CAMPOS as $chave => $campo) {
            $doArquivo = AjustesPainel::ler($this->cfgArquivo, $chave);
            $alterado = array_key_exists($chave, $ajustes) && AjustesPainel::validar($chave, $ajustes[$chave])[1] === null;
            $campos[] = $campo + [
                'chave' => $chave,
                'valor' => $alterado ? $ajustes[$chave] : $doArquivo,
                'padrao' => $doArquivo,
                'alterado' => $alterado,
            ];
        }
        return [
            'campos' => $campos,
            'ambiente' => (int)$this->cfgArquivo['tpAmb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
        ];
    }

    /** @param array<string, mixed> $valores chave => valor (so as que a tela mandou) */
    public function salvar(array $valores, ?string $ip): array
    {
        $erros = [];
        $convertidos = [];
        foreach ($valores as $chave => $valor) {
            [$convertido, $erro] = AjustesPainel::validar((string)$chave, $valor);
            if ($erro !== null) {
                $erros[] = ['campo' => (string)$chave, 'mensagem' => $erro];
            } else {
                $convertidos[(string)$chave] = $convertido;
            }
        }
        if ($erros !== []) {
            return ['ok' => false, 'http' => 422, 'codigo' => 'DADOS_INVALIDOS', 'mensagem' => 'Confira os campos marcados.', 'erros' => $erros];
        }

        $atual = [];
        foreach ($this->listar()['campos'] as $c) {
            $atual[$c['chave']] = $c;
        }
        $mudancas = [];
        foreach ($convertidos as $chave => $valor) {
            if ($valor === $atual[$chave]['valor']) {
                continue;
            }
            if ($valor === $atual[$chave]['padrao']) {
                $this->sistema->removerAjuste($chave);
            } else {
                $this->sistema->salvarAjuste($chave, $valor);
            }
            $mudancas[$chave] = ['de' => $atual[$chave]['valor'], 'para' => $valor];
        }
        if ($mudancas !== []) {
            (new ClienteRepositorio($this->pdo))->registrarLog(null, 'CONFIG_ALTERADA', $mudancas, $ip);
        }
        return [
            'ok' => true,
            'mensagem' => $mudancas === [] ? 'Nada mudou.' : count($mudancas) . ' configuração(ões) salva(s). Já valem para o portal e a API; o worker passa a usar em até 1 minuto.',
            'alteradas' => array_keys($mudancas),
            'configuracoes' => $this->listar(),
        ];
    }

    /** Senha do e-mail salva pelo painel? */
    public function senhaEmailNoPainel(): bool
    {
        return array_key_exists(self::CHAVE_SENHA_EMAIL, $this->sistema->ajustes());
    }

    /** Grava (criptografada) ou apaga (texto vazio) a senha do e-mail. */
    public function salvarSenhaEmail(string $senha, ?string $ip): array
    {
        if (strlen($senha) > 200 || preg_match('/[\x00-\x1F]/', $senha)) {
            return ['ok' => false, 'http' => 422, 'codigo' => 'SENHA_INVALIDA', 'mensagem' => 'Senha com mais de 200 letras ou com quebra de linha.'];
        }
        $log = new ClienteRepositorio($this->pdo);
        if ($senha === '') {
            $this->sistema->removerAjuste(self::CHAVE_SENHA_EMAIL);
            $log->registrarLog(null, 'CONFIG_ALTERADA', ['email.senha' => 'apagada no painel'], $ip);
            return ['ok' => true, 'mensagem' => 'Senha do e-mail apagada do painel.'];
        }
        try {
            $cofre = new CofreCertificado((string)($this->cfgArquivo['chave_certificados'] ?? ''));
        } catch (Throwable) {
            return ['ok' => false, 'http' => 503, 'codigo' => 'CHAVE_AUSENTE',
                'mensagem' => 'Falta a chave de segurança do servidor (a mesma dos certificados). Rode o Diagnóstico para ver como resolver.'];
        }
        $this->sistema->salvarAjuste(self::CHAVE_SENHA_EMAIL, $cofre->cifrarTexto($senha));
        // Nunca grava a senha no historico, so que mudou.
        $log->registrarLog(null, 'CONFIG_ALTERADA', ['email.senha' => 'alterada no painel'], $ip);
        return ['ok' => true, 'mensagem' => 'Senha do e-mail salva (guardada criptografada).'];
    }

    /** Usa na config a senha do e-mail salva pelo painel (se abrir com a chave). */
    public static function aplicarSenhaEmail(array $cfg, array $ajustes): array
    {
        $cifrada = $ajustes[self::CHAVE_SENHA_EMAIL] ?? null;
        if (!is_string($cifrada) || $cifrada === '') {
            return $cfg;
        }
        try {
            $cfg['email']['senha'] = (new CofreCertificado((string)($cfg['chave_certificados'] ?? '')))->decifrarTexto($cifrada);
        } catch (Throwable) {
            // Chave mudou ou sumiu: vale a senha do arquivo (se houver).
        }
        return $cfg;
    }
}
