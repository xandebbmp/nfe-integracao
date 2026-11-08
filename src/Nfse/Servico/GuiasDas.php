<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use PDO;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EmailRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\GuiaDasRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Guia DAS do Simples (script 0015).
 *
 *   Painel admin (Fechamento do mes): o contador anexa o PDF do DAS que
 *   gerou no site da Receita, com valor e vencimento. Pode trocar ou remover.
 *   Portal (menu "Guias do Simples"): o cliente ve e baixa as guias dele.
 *   Os dois lados podem marcar a guia como PAGA (script 0016). O sistema nao
 *   confere na Receita: e so um registro para nao mostrar "Venceu" a toa.
 *
 * Ao anexar, as pessoas ativas da empresa no portal recebem um e-mail
 * (fila sistema_emails; quem envia e o worker).
 *
 * Os metodos devolvem ['ok' => bool, 'http' => int, ...] para as rotas so
 * repassarem a resposta.
 */
final class GuiasDas
{
    /** Tamanho maximo do PDF. Uma guia DAS tem poucos KB. */
    public const TAMANHO_MAXIMO = 2_000_000;

    private GuiaDasRepositorio $guias;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->guias = new GuiaDasRepositorio($pdo);
    }

    public function instalado(): bool
    {
        return $this->guias->existe();
    }

    /** Script 0016 aplicado (da para marcar como paga)? */
    public function pagamentoInstalado(): bool
    {
        return $this->instalado() && $this->guias->temPagamento();
    }

    /**
     * Anexa (ou troca) a guia de uma empresa num mes.
     *
     * @param array $dados cnpj, competencia (AAAA-MM), valor, vencimento (AAAA-MM-DD), linha_digitavel (opcional), avisar (bool)
     */
    public function anexar(array $dados, string $pdf, string $nomeArquivo, string $ip): array
    {
        if (!$this->instalado()) {
            return self::falha(503, 'BANCO_DESATUALIZADO', 'Falta atualizar o banco (script 0015). Abra Sistema > Atualizar banco.');
        }

        $cnpj = NfseConfig::normalizarCnpj((string)($dados['cnpj'] ?? ''));
        $empresa = (new ClienteRepositorio($this->pdo))->buscar($cnpj);
        if ($empresa === null) {
            return self::falha(404, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não encontrada no cadastro.');
        }

        $erros = [];
        $competencia = (string)($dados['competencia'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competencia)) {
            $erros[] = 'Mês da guia inválido.';
        }
        $valor = self::numero($dados['valor'] ?? '');
        if ($valor === null || $valor <= 0) {
            $erros[] = 'Informe o valor da guia (maior que zero).';
        }
        $vencimento = (string)($dados['vencimento'] ?? '');
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $vencimento);
        if ($data === false || $data->format('Y-m-d') !== $vencimento) {
            $erros[] = 'Informe a data de vencimento.';
        }
        $linha = preg_replace('/\D/', '', (string)($dados['linha_digitavel'] ?? ''));
        if ($linha !== '' && strlen($linha) !== 48) {
            $erros[] = 'O código de barras do DAS tem 48 números (confira se copiou inteiro) ou deixe em branco.';
        }
        if (!str_starts_with($pdf, '%PDF-')) {
            $erros[] = 'O arquivo precisa ser o PDF da guia.';
        } elseif (strlen($pdf) > self::TAMANHO_MAXIMO) {
            $erros[] = 'PDF grande demais (máximo 2 MB).';
        }
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', implode(' ', $erros), ['erros' => $erros]);
        }

        $trocou = isset($this->guias->doMes($competencia)[$cnpj]);
        $id = $this->guias->salvar($cnpj, $competencia, (float)$valor, $vencimento, $linha !== '' ? $linha : null,
            $pdf, $nomeArquivo !== '' ? $nomeArquivo : "das-{$competencia}.pdf", ClienteRepositorio::$autor);
        (new ClienteRepositorio($this->pdo))->registrarLog($cnpj, $trocou ? 'GUIA_DAS_TROCADA' : 'GUIA_DAS_ANEXADA',
            ['competencia' => $competencia, 'valor' => $valor, 'vencimento' => $vencimento], $ip);

        $avisados = 0;
        if (!empty($dados['avisar'])) {
            $avisados = $this->avisarCliente($cnpj, $competencia, (float)$valor, $vencimento);
        }

        return ['ok' => true, 'http' => 200, 'guia' => $this->guias->buscar($id), 'trocou' => $trocou, 'avisados' => $avisados,
            'pagamento_instalado' => $this->guias->temPagamento()];
    }

    public function remover(int $id, string $ip): array
    {
        $guia = $this->instalado() ? $this->guias->buscar($id) : null;
        if ($guia === null) {
            return self::falha(404, 'NAO_ENCONTRADA', 'Guia não encontrada.');
        }
        $this->guias->remover($id);
        (new ClienteRepositorio($this->pdo))->registrarLog($guia['cnpj'], 'GUIA_DAS_REMOVIDA',
            ['competencia' => $guia['competencia'], 'valor' => $guia['valor']], $ip);
        return ['ok' => true, 'http' => 200];
    }

    /**
     * Marca a guia como paga ou desmarca.
     *
     * @param ?string $data      AAAA-MM-DD (vazio = hoje) ou null para desmarcar
     * @param string  $quem      quem marcou (vai para o registro)
     * @param ?string $cnpjPortal no portal: CNPJ do usuario logado (so mexe nas guias da empresa dele)
     */
    public function definirPagamento(int $id, ?string $data, string $quem, string $ip, ?string $cnpjPortal = null): array
    {
        $guia = $this->instalado() ? $this->guias->buscar($id) : null;
        if ($guia === null || ($cnpjPortal !== null && $guia['cnpj'] !== $cnpjPortal)) {
            return self::falha(404, 'NAO_ENCONTRADA', 'Guia não encontrada.');
        }
        if (!$this->guias->temPagamento()) {
            return self::falha(503, 'BANCO_DESATUALIZADO', 'Falta atualizar o banco (script 0016). Abra Sistema > Atualizar banco.');
        }
        if ($data !== null) {
            $hoje = (new DateTimeImmutable('now', new \DateTimeZone('America/Bahia')))->format('Y-m-d');
            $data = trim($data) === '' ? $hoje : trim($data);
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
            if ($d === false || $d->format('Y-m-d') !== $data) {
                return self::falha(422, 'DATA_INVALIDA', 'Data do pagamento inválida.');
            }
            if ($data > $hoje) {
                return self::falha(422, 'DATA_INVALIDA', 'A data do pagamento não pode ser no futuro.');
            }
        }
        $this->guias->definirPagamento($id, $data, $quem);
        (new ClienteRepositorio($this->pdo))->registrarLog($guia['cnpj'], $data !== null ? 'GUIA_DAS_PAGA' : 'GUIA_DAS_PAGAMENTO_DESFEITO',
            ['competencia' => $guia['competencia'], 'pago_em' => $data, 'quem' => $quem], $ip);
        return ['ok' => true, 'http' => 200, 'guia' => $this->guias->buscar($id)];
    }

    /** Guia (sem o PDF) ou null. */
    public function buscar(int $id): ?array
    {
        return $this->instalado() ? $this->guias->buscar($id) : null;
    }

    public function pdf(int $id): ?string
    {
        return $this->instalado() ? $this->guias->pdf($id) : null;
    }

    /** cnpj => guia do mes (tela Fechamento do mes). Vazio se o banco ainda nao tem a tabela. */
    public function doMes(string $competencia): array
    {
        return $this->instalado() ? $this->guias->doMes($competencia) : [];
    }

    /**
     * Guias da empresa para o portal, com a situacao de cada uma:
     * PAGA (marcada), ou pela data de hoje A_VENCER, VENCE_HOJE ou VENCIDA.
     */
    public function daEmpresa(string $cnpj, ?string $hoje = null): array
    {
        if (!$this->instalado()) {
            return [];
        }
        $hoje ??= (new DateTimeImmutable('now', new \DateTimeZone('America/Bahia')))->format('Y-m-d');
        return array_map(static function (array $g) use ($hoje): array {
            $g['situacao'] = $g['pago_em'] !== null ? 'PAGA' : ($g['vencimento'] > $hoje ? 'A_VENCER' : ($g['vencimento'] === $hoje ? 'VENCE_HOJE' : 'VENCIDA'));
            return $g;
        }, $this->guias->daEmpresa($cnpj));
    }

    /** Enfileira um e-mail para cada pessoa ativa da empresa no portal. Devolve quantas. */
    private function avisarCliente(string $cnpj, string $competencia, float $valor, string $vencimento): int
    {
        $emails = new EmailRepositorio($this->pdo);
        $portal = new PortalRepositorio($this->pdo);
        if (!$emails->existe() || !$portal->existe()) {
            return 0;
        }
        $quantos = 0;
        foreach ($portal->usuariosDaEmpresa($cnpj) as $u) {
            if (!in_array($u['ativo'], [true, 't', 1, '1'], true)) {
                continue;
            }
            [$assunto, $texto, $html] = $this->mensagem((string)$u['nome'], $competencia, $valor, $vencimento);
            $emails->enfileirar((string)$u['email'], $assunto, $texto, $html);
            $quantos++;
        }
        return $quantos;
    }

    /** @return array{0: string, 1: string, 2: string} assunto, texto e html */
    private function mensagem(string $nome, string $competencia, float $valor, string $vencimento): array
    {
        $primeiro = explode(' ', trim($nome))[0];
        $mes = substr($competencia, 5, 2) . '/' . substr($competencia, 0, 4);
        $valorTexto = 'R$ ' . number_format($valor, 2, ',', '.');
        $venceTexto = DateTimeImmutable::createFromFormat('!Y-m-d', $vencimento)->format('d/m/Y');
        $url = trim((string)($this->cfg['email']['url_portal'] ?? ''));
        $link = $url !== '' ? rtrim($url, '/') . '/#guias' : '';

        $assunto = "Guia do Simples (DAS) de {$mes} disponível";
        $texto = "Olá, {$primeiro}.\n\n"
            . "A guia do Simples Nacional (DAS) referente a {$mes} já está no portal.\n"
            . "Valor: {$valorTexto}\nVencimento: {$venceTexto}\n\n"
            . ($link !== '' ? "Para ver e baixar a guia, acesse: {$link}\n\n" : "Para ver e baixar a guia, entre no portal, menu \"Guias do Simples\".\n\n")
            . "Pague até o vencimento para evitar multa e juros.\n";
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#1f2933;max-width:520px">'
            . '<p>Olá, ' . $e($primeiro) . '.</p>'
            . '<p>A guia do Simples Nacional (DAS) referente a <strong>' . $e($mes) . '</strong> já está no portal.</p>'
            . '<p style="font-size:17px">Valor: <strong>' . $e($valorTexto) . '</strong><br>Vencimento: <strong>' . $e($venceTexto) . '</strong></p>'
            . ($link !== ''
                ? '<p style="margin:24px 0"><a href="' . $e($link) . '" style="background:#17324d;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Ver a guia</a></p>'
                : '<p>Para ver e baixar a guia, entre no portal, menu "Guias do Simples".</p>')
            . '<p style="color:#52606d;font-size:13px">Pague até o vencimento para evitar multa e juros.</p>'
            . '</div>';
        return [$assunto, $texto, $html];
    }

    /** Aceita "1234,56", "1.234,56" e "1234.56". */
    public static function numero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float)$valor;
        }
        $texto = trim((string)$valor);
        if ($texto === '') {
            return null;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }
        return is_numeric($texto) ? round((float)$texto, 2) : null;
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }
}
