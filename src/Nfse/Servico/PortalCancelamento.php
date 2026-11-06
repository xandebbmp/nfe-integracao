<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Capacidades;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;

/**
 * Cancelar nota pelo portal (botao no detalhe de "Minhas notas").
 *
 * Duas formas, conforme o padrao da nota (Dominio/Capacidades):
 *   ENVIAR      Padrao Nacional: o pedido vai para a fila e o worker envia
 *               o evento 101101 (Solicitacoes::registrarEvento, a mesma regra
 *               da API). A nota so vira CANCELADO quando o fisco aceitar.
 *   PREFEITURA  Salvador: nao cancela por webservice. O cliente pede no site
 *               da prefeitura e, depois de aceito, registra aqui o numero do
 *               protocolo (Solicitacoes::registrarCancelamentoExterno).
 *
 * Qualquer usuario da empresa cancela (decisao do xande); o pedido guarda
 * quem fez (registrado_por = "portal:<email>") e o detalhe mostra.
 */
final class PortalCancelamento
{
    public const ENVIAR = 'ENVIAR';
    public const PREFEITURA = 'PREFEITURA';

    /** Codigos do leiaute: 1 erro na emissao, 2 servico nao prestado, 9 outros. */
    public const MOTIVOS = ['1' => 'Erro na emissão', '2' => 'Serviço não prestado', '9' => 'Outro motivo'];

    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    /**
     * Bloco "cancelamento" do detalhe da nota.
     *
     * @return array{forma: ?string, pode: bool, evento: ?array}
     *   forma   ENVIAR | PREFEITURA | null (padrao sem cancelamento)
     *   pode    o botao "Cancelar nota" aparece
     *   evento  ultimo pedido (em andamento, recusado ou registrado)
     */
    public function situacao(array $documento): array
    {
        $forma = self::forma($documento['padrao']);
        $ultimo = $this->ultimoPedido($documento['id']);
        $andamento = $ultimo !== null && in_array($ultimo['status'], [StatusEvento::RECEBIDO, StatusEvento::PROCESSANDO], true);
        return [
            'forma' => $forma,
            'pode' => $forma !== null && $documento['status'] === StatusNfse::AUTORIZADO && !$andamento,
            'evento' => $ultimo === null ? null : [
                'status' => $ultimo['status'],
                'motivo' => self::MOTIVOS[(string)$ultimo['motivo_codigo']] ?? null,
                'texto' => $ultimo['motivo_texto'],
                'numero_processo' => $ultimo['numero_processo'] ?? null,
                'por' => isset($ultimo['registrado_por']) ? preg_replace('/^portal:/', '', (string)$ultimo['registrado_por']) : null,
                'mensagens' => $ultimo['mensagens'] ?? [],
                'criado_em' => $ultimo['criado_em'] ?? null,
            ],
        ];
    }

    /**
     * @param array $e { id, motivo_codigo, motivo, numero_processo? (so Salvador) }
     */
    public function cancelar(array $usuario, array $e): array
    {
        $id = (string)($e['id'] ?? '');
        $documento = preg_match('/^[0-9a-f-]{36}$/i', $id) ? (new DocumentoRepositorio($this->pdo))->buscar($id) : null;
        if ($documento === null || $documento['emitente_cnpj'] !== (string)$usuario['cnpj']
            || !in_array($documento['padrao'], Padrao::NFSE, true)) {
            return self::falha(404, 'NOTA_NAO_ENCONTRADA', 'Nota não encontrada.');
        }
        if ($documento['status'] !== StatusNfse::AUTORIZADO) {
            return self::falha(409, 'STATUS_NAO_PERMITE', 'Só dá para cancelar nota autorizada.');
        }
        $forma = self::forma($documento['padrao']);
        if ($forma === null) {
            return self::falha(422, 'NAO_SUPORTADO_PELO_PADRAO', 'Este tipo de nota não pode ser cancelado pelo portal. Fale com o suporte.');
        }

        $codigo = (string)($e['motivo_codigo'] ?? '');
        $texto = trim((string)($e['motivo'] ?? ''));
        $processo = trim((string)($e['numero_processo'] ?? ''));
        $erros = [];
        if (!isset(self::MOTIVOS[$codigo])) {
            $erros[] = ['campo' => 'motivo_codigo', 'mensagem' => 'Escolha o motivo do cancelamento.'];
        }
        $tamanho = mb_strlen($texto);
        if ($tamanho < 15 || $tamanho > 255) {
            $erros[] = ['campo' => 'motivo', 'mensagem' => 'Explique em 15 a 255 letras (ex.: "Valor errado, será emitida outra nota").'];
        }
        if ($forma === self::PREFEITURA && ($processo === '' || mb_strlen($processo) > 60)) {
            $erros[] = ['campo' => 'numero_processo', 'mensagem' => 'Informe o número do protocolo que a prefeitura deu.'];
        }
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Confira os campos destacados.', $erros);
        }

        $quem = mb_substr('portal:' . ($usuario['email'] ?? $usuario['id']), 0, 60);
        $solicitacoes = new Solicitacoes($this->pdo, $this->cfg);
        $r = $forma === self::ENVIAR
            ? $solicitacoes->registrarEvento($documento, TipoEvento::CANCELAMENTO, $codigo, $texto, $quem)
            : $solicitacoes->registrarCancelamentoExterno($documento, $quem, $processo, $codigo, $texto, null);
        if (!$r['ok']) {
            return self::falha($r['http'], $r['codigo'], $r['mensagem']);
        }
        return [
            'ok' => true,
            'forma' => $forma,
            'mensagem' => $forma === self::ENVIAR
                ? 'Pedido de cancelamento enviado. A nota muda para "Cancelada" quando a prefeitura confirmar.'
                : 'Cancelamento registrado. A nota agora aparece como cancelada.',
        ];
    }

    private static function forma(string $padrao): ?string
    {
        $c = Capacidades::doPadrao($padrao);
        if (isset($c['eventos'][TipoEvento::CANCELAMENTO])) {
            return self::ENVIAR;
        }
        return $c['cancelamento_externo'] ? self::PREFEITURA : null;
    }

    private function ultimoPedido(string $documentoId): ?array
    {
        $doTipo = array_values(array_filter(
            (new EventoRepositorio($this->pdo))->listarDoDocumento($documentoId),
            static fn(array $ev): bool => $ev['tipo_evento'] === TipoEvento::CANCELAMENTO
        ));
        return $doTipo === [] ? null : $doTipo[count($doTipo) - 1];
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
