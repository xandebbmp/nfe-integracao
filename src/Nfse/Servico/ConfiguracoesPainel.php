<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\AjustesPainel;
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
 * Os valores passam a valer na proxima requisicao da API/portal. O worker
 * rele a cada minuto.
 */
final class ConfiguracoesPainel
{
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
}
