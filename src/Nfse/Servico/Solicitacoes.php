<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoFactory;
use Xande\NfeIntegracao\Nfse\Dominio\Capacidades;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;

/**
 * Pedidos que o consumidor faz sobre um documento ja existente:
 * consultar no fisco e registrar evento (cancelamento...).
 *
 * Ambos so ENFILEIRAM o trabalho. Quem fala com o fisco e o worker.
 */
final class Solicitacoes
{
    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    /**
     * @return array{ok: bool, mensagem: string}
     */
    public function consultar(array $documento): array
    {
        if ($documento['status'] === StatusNfse::RECEBIDO) {
            return ['ok' => false, 'mensagem' => 'Documento ainda esta na fila para envio. Aguarde o processamento.'];
        }
        if ($documento['numero'] === null) {
            return ['ok' => false, 'mensagem' => 'Documento rejeitado na entrada da API: nunca foi enviado ao fisco. Corrija e reenvie com a mesma referencia.'];
        }

        $fila = new FilaRepositorio($this->pdo);
        if (!$fila->existePendente(FilaRepositorio::CONSULTAR, $documento['id'])) {
            $fila->enfileirar(FilaRepositorio::CONSULTAR, $documento['id'], null, 0, (int)$this->cfg['fila']['max_tentativas']);
        }

        return ['ok' => true, 'mensagem' => 'Consulta enfileirada.'];
    }

    /**
     * @return array{ok: bool, http: int, mensagem: string, evento?: array}
     */
    public function registrarEvento(array $documento, string $tipo, string $motivoCodigo, string $motivoTexto): array
    {
        $capacidades = Capacidades::doPadrao($documento['padrao']);

        if (!isset($capacidades['eventos'][$tipo])) {
            return [
                'ok' => false,
                'http' => 422,
                'codigo' => 'NAO_SUPORTADO_PELO_PADRAO',
                'mensagem' => $capacidades['eventos_nao_suportados'][$tipo]
                    ?? "Evento {$tipo} nao suportado no padrao {$documento['padrao']}.",
            ];
        }

        if ($documento['status'] !== StatusNfse::AUTORIZADO) {
            return [
                'ok' => false,
                'http' => 409,
                'codigo' => 'STATUS_NAO_PERMITE',
                'mensagem' => "Evento so pode ser registrado em nota AUTORIZADA (status atual: {$documento['status']}).",
            ];
        }

        $erro = self::validarMotivo($documento, $tipo, $motivoCodigo, $motivoTexto);
        if ($erro !== null) {
            return ['ok' => false, 'http' => 400, 'codigo' => 'MOTIVO_INVALIDO', 'mensagem' => $erro];
        }

        if ($tipo === TipoEvento::NFE_CANCELAMENTO && empty($documento['protocolo_autorizacao'])) {
            return ['ok' => false, 'http' => 409, 'codigo' => 'SEM_PROTOCOLO',
                'mensagem' => 'NF-e sem protocolo de autorizacao gravado. Use "consultar" para atualizar e tente de novo.'];
        }

        $eventos = new EventoRepositorio($this->pdo);
        // CC-e pode ter varias (cada uma substitui a anterior); as demais sao unicas.
        $cartaCorrecao = $tipo === TipoEvento::NFE_CARTA_CORRECAO;

        $this->pdo->beginTransaction();
        try {
            // Evita dois cancelamentos em paralelo para a mesma nota.
            $this->pdo->prepare('SELECT 1 FROM nfse_documentos WHERE id = :id FOR UPDATE')->execute([':id' => $documento['id']]);

            $ativo = $eventos->buscarAtivo($documento['id'], $tipo, $cartaCorrecao);
            if ($ativo !== null) {
                $this->pdo->commit();
                return ['ok' => true, 'http' => 200, 'mensagem' => 'Evento ja solicitado anteriormente.', 'evento' => $ativo];
            }

            $extras = [];
            if ($cartaCorrecao) {
                $extras['sequencia'] = $eventos->contarDoTipo($documento['id'], $tipo) + 1;
                if ($extras['sequencia'] > 20) {
                    $this->pdo->rollBack();
                    return ['ok' => false, 'http' => 409, 'codigo' => 'LIMITE_CCE', 'mensagem' => 'Limite de 20 Cartas de Correcao por NF-e atingido.'];
                }
            }

            $eventoId = $eventos->criar($documento['id'], $tipo, $motivoCodigo, trim($motivoTexto), $extras);
            (new FilaRepositorio($this->pdo))->enfileirar(
                FilaRepositorio::EVENTO,
                $documento['id'],
                $eventoId,
                0,
                (int)$this->cfg['fila']['max_tentativas']
            );
            (new MonitorRepositorio($this->pdo))->registrar(
                $documento,
                'EVENTO',
                $documento['status'],
                "Evento {$tipo} solicitado"
            );
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'ok' => true,
            'http' => 202,
            'mensagem' => 'Evento enfileirado.',
            'evento' => $eventos->buscar($eventoId) ?? ['id' => $eventoId, 'status' => StatusEvento::RECEBIDO],
        ];
    }

    /**
     * Regras do texto/motivo de cada evento. Retorna a mensagem de erro ou null.
     */
    private static function validarMotivo(array $documento, string $tipo, string $codigo, string $texto): ?string
    {
        $tamanho = mb_strlen(trim($texto));

        if ($tipo === TipoEvento::NFE_CARTA_CORRECAO) {
            return ($tamanho < 15 || $tamanho > 1000)
                ? 'motivo (texto da correcao) deve ter entre 15 e 1000 caracteres.'
                : null;
        }
        if ($tamanho < 15 || $tamanho > 255) {
            return 'motivo deve ter entre 15 e 255 caracteres.';
        }
        // NFS-e exige o codigo do motivo; a NF-e nao tem esse campo.
        if (in_array($documento['padrao'], Padrao::NFSE, true) && !in_array($codigo, ['1', '2', '9'], true)) {
            return 'motivo_codigo deve ser 1 (erro na emissao), 2 (servico nao prestado) ou 9 (outros).';
        }
        return null;
    }

    /**
     * Registra um cancelamento feito FORA do webservice (ex.: portal da
     * Prefeitura de Salvador ou processo administrativo).
     *
     * Nao fala com o fisco: so deixa a API, o monitor e os webhooks
     * coerentes com o que ja aconteceu na prefeitura.
     *
     * @return array{ok: bool, http: int, codigo?: string, mensagem: string, evento?: array}
     */
    public function registrarCancelamentoExterno(
        array $documento,
        string $consumidor,
        string $numeroProcesso,
        string $motivoCodigo,
        string $motivoTexto,
        ?string $observacao
    ): array {
        if (!Capacidades::doPadrao($documento['padrao'])['cancelamento_externo']) {
            return ['ok' => false, 'http' => 422, 'codigo' => 'NAO_SUPORTADO_PELO_PADRAO',
                'mensagem' => "No padrao {$documento['padrao']} o cancelamento e feito pela API (POST /v1/nfse/evento.php), nao por registro manual."];
        }
        if ($documento['status'] !== StatusNfse::AUTORIZADO) {
            return ['ok' => false, 'http' => 409, 'codigo' => 'STATUS_NAO_PERMITE',
                'mensagem' => "So e possivel registrar cancelamento de NFS-e AUTORIZADA (status atual: {$documento['status']})."];
        }
        $numeroProcesso = trim($numeroProcesso);
        if ($numeroProcesso === '' || mb_strlen($numeroProcesso) > 60) {
            return ['ok' => false, 'http' => 400, 'codigo' => 'PROCESSO_INVALIDO',
                'mensagem' => 'Informe numero_processo (protocolo do pedido de cancelamento na prefeitura), ate 60 caracteres.'];
        }
        if (!in_array($motivoCodigo, ['1', '2', '9'], true)) {
            return ['ok' => false, 'http' => 400, 'codigo' => 'MOTIVO_INVALIDO', 'mensagem' => 'motivo_codigo deve ser 1 (erro na emissao), 2 (servico nao prestado) ou 9 (outros).'];
        }
        $tamanho = mb_strlen(trim($motivoTexto));
        if ($tamanho < 15 || $tamanho > 255) {
            return ['ok' => false, 'http' => 400, 'codigo' => 'MOTIVO_INVALIDO', 'mensagem' => 'motivo deve ter entre 15 e 255 caracteres.'];
        }

        $eventos = new EventoRepositorio($this->pdo);
        $documentos = new DocumentoRepositorio($this->pdo);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT 1 FROM nfse_documentos WHERE id = :id FOR UPDATE')->execute([':id' => $documento['id']]);

            $eventoId = $eventos->criar($documento['id'], TipoEvento::CANCELAMENTO, $motivoCodigo, trim($motivoTexto), [
                'origem' => 'EXTERNO',
                'status' => StatusEvento::REGISTRADO,
                'numero_processo' => $numeroProcesso,
                'observacao' => $observacao !== null && trim($observacao) !== '' ? trim($observacao) : null,
                'registrado_por' => $consumidor,
            ]);
            $documentos->atualizar($documento['id'], ['status' => StatusNfse::CANCELADO]);

            $atualizado = $documentos->buscar($documento['id']);
            (new MonitorRepositorio($this->pdo))->registrar($atualizado, 'EVENTO', StatusNfse::CANCELADO,
                "Cancelamento registrado manualmente (processo {$numeroProcesso}) por {$consumidor}");

            $evento = $eventos->buscar($eventoId);
            $notificador = new NotificadorWebhook($this->pdo, $this->cfg);
            $notificador->eventoAtualizado($atualizado, $evento);

            if (ArmazenamentoFactory::ativo($this->cfg)) {
                (new FilaRepositorio($this->pdo))->enfileirar(FilaRepositorio::ARQUIVAR, $documento['id'], null, 0, 10);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'http' => 201, 'mensagem' => 'Cancelamento registrado.', 'evento' => $evento];
    }
}
