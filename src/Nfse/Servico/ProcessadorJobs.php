<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoArquivos;
use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoFactory;
use Xande\NfeIntegracao\Nfse\Contrato\RetornoProvedor;
use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;
use Xande\NfeIntegracao\Nfse\ProvedorFactory;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;

/**
 * Executa os jobs da fila. E chamado em loop pelo bin/nfse_worker.php.
 *
 * Fluxo de um job:
 *   1. pega o proximo job (FilaRepositorio::pegarProximo);
 *   2. chama o provedor do padrao do documento (Nacional ou Salvador);
 *   3. traduz o RetornoProvedor em: atualizar documento, registrar no
 *      monitor, avisar o consumidor (webhook) e concluir/reagendar o job.
 *
 * Regra de ouro: este arquivo NAO conhece XML nem regra de prefeitura.
 * Tudo isso fica dentro dos provedores.
 */
final class ProcessadorJobs
{
    private DocumentoRepositorio $documentos;
    private EventoRepositorio $eventos;
    private FilaRepositorio $fila;
    private MonitorRepositorio $monitor;
    private NotificadorWebhook $notificador;

    public function __construct(
        private PDO $pdo,
        private array $cfg,
        private ProvedorFactory $provedores,
        private string $workerId,
        private ?ArmazenamentoArquivos $armazenamento = null
    ) {
        $this->armazenamento ??= ArmazenamentoFactory::criar($cfg);
        $this->documentos = new DocumentoRepositorio($pdo);
        $this->eventos = new EventoRepositorio($pdo);
        $this->fila = new FilaRepositorio($pdo);
        $this->monitor = new MonitorRepositorio($pdo);
        $this->notificador = new NotificadorWebhook($pdo, $cfg);
    }

    /**
     * Processa UM job. Retorna false quando a fila esta vazia.
     */
    public function processarProximo(): bool
    {
        $job = $this->fila->pegarProximo($this->workerId);
        if ($job === null) {
            return false;
        }

        $documento = $this->documentos->buscar((string)$job['documento_id']);
        if ($documento === null) {
            $this->fila->falhar((int)$job['id'], 'Documento nao encontrado.');
            return true;
        }

        // Arquivamento nao mexe no status do documento: tratado a parte.
        if ($job['tipo'] === FilaRepositorio::ARQUIVAR) {
            $this->arquivar($job, $documento);
            return true;
        }

        try {
            match ($job['tipo']) {
                FilaRepositorio::EMITIR => $this->emitir($job, $documento),
                FilaRepositorio::CONSULTAR_PROCESSAMENTO => $this->consultarProcessamento($job, $documento),
                FilaRepositorio::CONSULTAR => $this->consultar($job, $documento),
                FilaRepositorio::EVENTO => $this->evento($job, $documento),
                default => $this->fila->falhar((int)$job['id'], "Tipo de job desconhecido: {$job['tipo']}"),
            };
        } catch (Throwable $e) {
            // Erro inesperado (bug, banco, certificado...): trata como falha
            // temporaria para nao perder o documento.
            $retorno = RetornoProvedor::falhaTemporaria('Erro interno: ' . $e->getMessage());
            // Recarrega: o status pode ter mudado antes do erro (ex.: PROCESSANDO).
            $documento = $this->documentos->buscar($documento['id']) ?? $documento;
            if ($job['tipo'] === FilaRepositorio::EVENTO) {
                $evento = $this->eventos->buscar((string)$job['evento_id']);
                if ($evento !== null) {
                    $this->aplicarRetornoEvento($job, $documento, $evento, $retorno);
                }
            } else {
                $operacao = $job['tipo'] === FilaRepositorio::CONSULTAR ? 'CONSULTA' : 'EMISSAO';
                $this->aplicarRetornoDocumento($job, $documento, $retorno, $operacao);
            }
        }

        return true;
    }

    // =================================================================
    // Tipos de job
    // =================================================================

    private function emitir(array $job, array $documento): void
    {
        // Job repetido para documento que ja terminou: nada a fazer.
        if (!in_array($documento['status'], [StatusNfse::RECEBIDO, StatusNfse::PROCESSANDO], true)) {
            $this->fila->concluir((int)$job['id']);
            return;
        }

        if ($documento['status'] === StatusNfse::RECEBIDO) {
            $this->documentos->atualizar($documento['id'], ['status' => StatusNfse::PROCESSANDO]);
            $documento['status'] = StatusNfse::PROCESSANDO;
            $this->monitor->registrar($documento, 'EMISSAO', StatusNfse::PROCESSANDO, 'Enviando ao fisco');
        }

        $retorno = $this->provedores->para($documento['padrao'], $documento['emitente_cnpj'])->emitir($documento);
        $this->aplicarRetornoDocumento($job, $documento, $retorno, 'EMISSAO');
    }

    private function consultarProcessamento(array $job, array $documento): void
    {
        if ($documento['status'] !== StatusNfse::PROCESSANDO) {
            $this->fila->concluir((int)$job['id']);
            return;
        }

        $retorno = $this->provedores->para($documento['padrao'], $documento['emitente_cnpj'])->consultarProcessamento($documento);
        $this->aplicarRetornoDocumento($job, $documento, $retorno, 'EMISSAO');
    }

    private function consultar(array $job, array $documento): void
    {
        $provedor = $this->provedores->para($documento['padrao'], $documento['emitente_cnpj']);

        // Sem NFS-e ainda (processando, erro, rejeitado): pergunta se o envio
        // virou nota. Com NFS-e: pergunta a situacao atual (cancelada?).
        $retorno = in_array($documento['status'], StatusNfse::COM_NFSE, true)
            ? $provedor->consultar($documento)
            : $provedor->consultarProcessamento($documento);

        $this->aplicarRetornoDocumento($job, $documento, $retorno, 'CONSULTA');
    }

    private function evento(array $job, array $documento): void
    {
        $evento = $this->eventos->buscar((string)$job['evento_id']);
        if ($evento === null || !in_array($evento['status'], [StatusEvento::RECEBIDO, StatusEvento::PROCESSANDO], true)) {
            $this->fila->concluir((int)$job['id']);
            return;
        }

        $this->eventos->atualizar($evento['id'], ['status' => StatusEvento::PROCESSANDO]);
        $retorno = $this->provedores->para($documento['padrao'], $documento['emitente_cnpj'])->registrarEvento($documento, $evento);
        $this->aplicarRetornoEvento($job, $documento, $evento, $retorno);
    }

    /**
     * Copia os XMLs para o armazenamento externo (nuvem/pasta).
     * Falha aqui nunca altera o status fiscal: so tenta de novo depois.
     */
    private function arquivar(array $job, array $documento): void
    {
        if ($this->armazenamento === null) {
            $this->fila->concluir((int)$job['id']);
            return;
        }

        try {
            (new Arquivador($this->armazenamento, $this->eventos))->arquivar($documento);
            $this->documentos->atualizar($documento['id'], ['arquivado_em' => date(DATE_ATOM)]);
            $this->fila->concluir((int)$job['id']);
        } catch (Throwable $e) {
            $tentativa = (int)$job['tentativas'];
            if ($tentativa < (int)$job['max_tentativas']) {
                $this->fila->reagendar((int)$job['id'], $this->espera($tentativa), $e->getMessage());
                return;
            }
            $this->fila->falhar((int)$job['id'], $e->getMessage());
            $this->monitor->registrar($documento, 'CONSULTA', $documento['status'], 'Falha ao arquivar XML no armazenamento externo: ' . $e->getMessage());
        }
    }

    // =================================================================
    // Aplicacao do retorno
    // =================================================================

    /**
     * @param string $operacao EMISSAO | CONSULTA (aparece no monitor)
     */
    private function aplicarRetornoDocumento(array $job, array $documento, RetornoProvedor $retorno, string $operacao): void
    {
        $jobId = (int)$job['id'];
        $campos = $retorno->dados + [
            'mensagens' => $retorno->mensagens,
            'codigo_retorno' => $retorno->mensagens[0]['codigo'] ?? null,
            'mensagem_retorno' => $retorno->mensagens[0]['mensagem'] ?? null,
        ];

        // ---------- Falha temporaria: tenta de novo mais tarde ----------
        if ($retorno->falhaTemporaria) {
            $this->documentos->atualizar($documento['id'], $campos);
            $tentativa = (int)$job['tentativas'];
            $maximo = (int)$job['max_tentativas'];

            if ($tentativa < $maximo) {
                $espera = $this->espera($tentativa);
                $this->fila->reagendar($jobId, $espera, (string)$retorno->resumo());
                $this->registrarMonitor($documento, $operacao, $documento['status'],
                    "Falha temporaria (tentativa {$tentativa}/{$maximo}). Nova tentativa em {$espera}s. " . $retorno->resumo());
                return;
            }

            $this->fila->falhar($jobId, (string)$retorno->resumo());
            if ($operacao === 'EMISSAO') {
                $this->mudarStatus($documento, StatusNfse::ERRO, $campos, $operacao,
                    'Falha apos todas as tentativas. Use "consultar" antes de reenviar. ' . $retorno->resumo());
            } else {
                $this->registrarMonitor($documento, $operacao, $documento['status'], 'Consulta falhou: ' . $retorno->resumo());
            }
            return;
        }

        // ---------- Fisco nao encontrou (so em consultas) ----------
        if ($retorno->status === RetornoProvedor::NAO_ENCONTRADO) {
            $this->documentos->atualizar($documento['id'], array_intersect_key($campos, ['mensagens' => 1, 'codigo_retorno' => 1, 'mensagem_retorno' => 1]));
            $this->fila->concluir($jobId);
            $this->registrarMonitor($documento, $operacao, $documento['status'], $retorno->resumo());
            return;
        }

        // ---------- Ainda processando (lote assincrono) ----------
        if ($retorno->status === StatusNfse::PROCESSANDO) {
            $this->documentos->atualizar($documento['id'], $campos + ['status' => StatusNfse::PROCESSANDO]);
            $this->fila->concluir($jobId);

            $consultasFeitas = $this->fila->contar(FilaRepositorio::CONSULTAR_PROCESSAMENTO, $documento['id']);
            $limite = (int)($this->cfg['salvador']['consulta_lote_max'] ?? 20);
            if ($consultasFeitas >= $limite) {
                $this->mudarStatus($documento, StatusNfse::ERRO, $campos, $operacao,
                    "Fisco nao concluiu o processamento apos {$limite} consultas. Use \"consultar\" mais tarde.");
                return;
            }

            // So agenda se ja nao houver uma consulta agendada (ex.: o consumidor
            // pediu "consultar" enquanto o lote ainda processava).
            if (!$this->fila->existePendente(FilaRepositorio::CONSULTAR_PROCESSAMENTO, $documento['id'])) {
                $this->fila->enfileirar(
                    FilaRepositorio::CONSULTAR_PROCESSAMENTO,
                    $documento['id'],
                    null,
                    (int)$retorno->consultarNovamenteEmSegundos,
                    (int)$this->cfg['fila']['max_tentativas']
                );
            }
            $this->registrarMonitor($documento + $campos, $operacao, StatusNfse::PROCESSANDO,
                'Aguardando processamento do fisco' . (!empty($campos['protocolo_lote']) ? " (protocolo {$campos['protocolo_lote']})" : ''));
            return;
        }

        // ---------- Resultado final: AUTORIZADO, REJEITADO, CANCELADO... ----------
        $this->fila->concluir($jobId);
        $this->mudarStatus($documento, $retorno->status, $campos, $operacao, $retorno->resumo());
    }

    private function aplicarRetornoEvento(array $job, array $documento, array $evento, RetornoProvedor $retorno): void
    {
        $jobId = (int)$job['id'];
        $campos = $retorno->dados + [
            'mensagens' => $retorno->mensagens,
            'codigo_retorno' => $retorno->mensagens[0]['codigo'] ?? null,
            'mensagem_retorno' => $retorno->mensagens[0]['mensagem'] ?? null,
        ];
        $descricao = "Evento {$evento['tipo_evento']}";

        if ($retorno->falhaTemporaria) {
            $tentativa = (int)$job['tentativas'];
            if ($tentativa < (int)$job['max_tentativas']) {
                $this->eventos->atualizar($evento['id'], $campos + ['status' => StatusEvento::PROCESSANDO]);
                $espera = $this->espera($tentativa);
                $this->fila->reagendar($jobId, $espera, (string)$retorno->resumo());
                $this->registrarMonitor($documento, 'EVENTO', $documento['status'], "{$descricao}: falha temporaria, nova tentativa em {$espera}s. " . $retorno->resumo());
                return;
            }
            $this->eventos->atualizar($evento['id'], $campos + ['status' => StatusEvento::ERRO]);
            $this->fila->falhar($jobId, (string)$retorno->resumo());
            $this->registrarMonitor($documento, 'EVENTO', $documento['status'], "{$descricao}: erro apos todas as tentativas. " . $retorno->resumo());
            $this->notificador->eventoAtualizado($documento, $this->eventos->buscar($evento['id']) ?? $evento);
            return;
        }

        $this->eventos->atualizar($evento['id'], $campos + ['status' => $retorno->status]);
        $this->fila->concluir($jobId);

        if ($retorno->status === StatusEvento::REGISTRADO && in_array($evento['tipo_evento'], TipoEvento::CANCELAMENTOS, true)) {
            $this->mudarStatus($documento, StatusNfse::CANCELADO, [], 'EVENTO', 'Cancelamento registrado');
        } else {
            if ($retorno->status === StatusEvento::REGISTRADO) {
                $this->agendarArquivamento($documento['id']);
            }
            $this->registrarMonitor($documento, 'EVENTO', $documento['status'], "{$descricao}: {$retorno->status}. " . $retorno->resumo());
        }

        $this->notificador->eventoAtualizado($documento, $this->eventos->buscar($evento['id']) ?? $evento);
    }

    // =================================================================
    // Auxiliares
    // =================================================================

    private function mudarStatus(array $documento, string $status, array $campos, string $operacao, ?string $mensagem): void
    {
        $this->documentos->atualizar($documento['id'], $campos + ['status' => $status]);
        $atualizado = $this->documentos->buscar($documento['id']) ?? $documento;
        $this->registrarMonitor($atualizado, $operacao, $status, $mensagem);

        // O consumidor so e avisado quando o status muda de verdade.
        if ($documento['status'] === $status) {
            return;
        }
        $this->notificador->documentoAtualizado($atualizado);

        if (in_array($status, StatusNfse::COM_NFSE, true)) {
            $this->agendarArquivamento($atualizado['id']);
        }

        // Nota substituta autorizada: consulta a substituida para ela passar
        // a SUBSTITUIDO. Espera 1 minuto para o Sefin registrar o evento.
        $chaveSubstituida = $atualizado['payload']['substituicao']['chave_substituida'] ?? null;
        if ($status === StatusNfse::AUTORIZADO && is_string($chaveSubstituida)) {
            $antiga = $this->documentos->buscarPorChave($atualizado['emitente_cnpj'], $chaveSubstituida);
            if ($antiga !== null && !$this->fila->existePendente(FilaRepositorio::CONSULTAR, $antiga['id'])) {
                $this->fila->enfileirar(FilaRepositorio::CONSULTAR, $antiga['id'], null, 60, (int)$this->cfg['fila']['max_tentativas']);
                $this->monitor->registrar($antiga, 'CONSULTA', $antiga['status'],
                    'Substituida pela NFS-e ' . ($atualizado['numero_nfse'] ?? $atualizado['id']) . ': consulta agendada');
            }
        }
    }

    private function agendarArquivamento(string $documentoId): void
    {
        if ($this->armazenamento !== null && !$this->fila->existePendente(FilaRepositorio::ARQUIVAR, $documentoId)) {
            $this->fila->enfileirar(FilaRepositorio::ARQUIVAR, $documentoId, null, 0, 10);
        }
    }

    private function registrarMonitor(array $documento, string $operacao, string $status, ?string $mensagem): void
    {
        $this->monitor->registrar($documento, $operacao, $status, $mensagem);
    }

    /** Espera exponencial: base, 2x base, 4x base... limitada a 30 minutos. */
    private function espera(int $tentativa): int
    {
        $base = (int)$this->cfg['fila']['espera_base_segundos'];
        return (int)min(1800, $base * (2 ** max(0, $tentativa - 1)));
    }
}
