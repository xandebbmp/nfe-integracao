<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;

/**
 * Recebe um lote de documentos da API e coloca cada um na fila.
 *
 * Passo a passo:
 *   1. Valida TODOS os documentos. Se algum estiver invalido, nada e gravado
 *      e a API devolve a lista de erros por documento (HTTP 422).
 *   2. Em UMA transacao: cria o lote, reserva o numero da DPS/RPS de cada
 *      documento, grava os documentos, cria os jobs EMITIR e registra no
 *      monitor.
 *   3. A API responde na hora (HTTP 202). O worker faz o resto.
 *
 * Idempotencia (mesma "referencia" enviada de novo):
 *   - documento AUTORIZADO / em andamento / cancelado: devolve o existente,
 *     sem emitir outra nota;
 *   - documento REJEITADO ou ERRO: aceita os dados novos e reenvia,
 *     reaproveitando o mesmo numero de DPS/RPS.
 */
final class RecebimentoLote
{
    private DocumentoRepositorio $documentos;
    private FilaRepositorio $fila;
    private MonitorRepositorio $monitor;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->documentos = new DocumentoRepositorio($pdo);
        $this->fila = new FilaRepositorio($pdo);
        $this->monitor = new MonitorRepositorio($pdo);
    }

    /**
     * @return array{ok: bool, erros?: list<array>, lote_id?: string, documentos?: list<array>}
     */
    public function receber(string $consumidor, array $corpo): array
    {
        $padraoLote = strtoupper(trim((string)($corpo['padrao'] ?? '')));
        $lista = $corpo['documentos'] ?? null;

        if (!is_array($lista) || $lista === [] || !array_is_list($lista)) {
            return ['ok' => false, 'erros' => [['indice' => null, 'erros' => ['documentos deve ser uma lista com ao menos 1 documento.']]]];
        }

        $max = (int)$this->cfg['api']['max_documentos_por_lote'];
        if (count($lista) > $max) {
            return ['ok' => false, 'erros' => [['indice' => null, 'erros' => ["Maximo de {$max} documentos por lote."]]]];
        }

        // 1) Validacao de todos antes de gravar qualquer coisa.
        $errosPorDocumento = [];
        $referencias = [];
        foreach ($lista as $indice => $doc) {
            $padrao = strtoupper(trim((string)($doc['padrao'] ?? $padraoLote)));
            $erros = is_array($doc) ? ValidadorPayload::validar($doc, $padrao) : ['documento deve ser um objeto.'];

            $chave = $padrao . '|' . ($doc['referencia'] ?? '');
            if (isset($referencias[$chave])) {
                $erros[] = 'referencia repetida dentro do mesmo lote.';
            }
            $referencias[$chave] = true;

            if ($erros !== []) {
                $errosPorDocumento[] = ['indice' => $indice, 'referencia' => $doc['referencia'] ?? null, 'erros' => $erros];
            }
        }
        if ($errosPorDocumento !== []) {
            return ['ok' => false, 'erros' => $errosPorDocumento];
        }

        // 2) Gravacao atomica.
        $this->pdo->beginTransaction();
        try {
            $loteId = $this->documentos->criarLote($consumidor, count($lista));
            $resultado = [];
            foreach ($lista as $doc) {
                $padrao = strtoupper(trim((string)($doc['padrao'] ?? $padraoLote)));
                $resultado[] = $this->receberDocumento($consumidor, $loteId, $padrao, $doc);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'lote_id' => $loteId, 'documentos' => $resultado];
    }

    /**
     * @return array{id: string, referencia: string, situacao_recebimento: string}
     */
    private function receberDocumento(string $consumidor, string $loteId, string $padrao, array $doc): array
    {
        $cnpj = (string)$this->cfg['emitente']['cnpj'];
        $tpAmb = (int)$this->cfg['tpAmb'];
        $referencia = trim((string)$doc['referencia']);
        $doc['referencia'] = $referencia;
        $doc['padrao'] = $padrao;

        $existente = $this->documentos->buscarPorReferenciaParaAtualizar($consumidor, $cnpj, $padrao, $tpAmb, $referencia);

        // --- Ja existe e nao pode ser reenviado: devolve o que temos.
        if ($existente !== null && !in_array($existente['status'], StatusNfse::PERMITE_REENVIO, true)) {
            return ['id' => $existente['id'], 'referencia' => $referencia, 'situacao_recebimento' => 'JA_EXISTENTE'];
        }

        // --- Ja existe e foi rejeitado/erro: reenvio com o mesmo numero.
        if ($existente !== null) {
            $campos = [
                'lote_id' => $loteId,
                'payload' => $doc,
                'status' => StatusNfse::RECEBIDO,
                'codigo_retorno' => null,
                'mensagem_retorno' => null,
                'mensagens' => [],
            ];
            if ($existente['status'] === StatusNfse::REJEITADO) {
                // Rejeicao = nada foi gerado no fisco. Limpamos o envio
                // anterior para que o novo XML seja montado do zero.
                // (Em ERRO mantemos, para o provedor checar duplicidade.)
                $campos += ['xml_enviado' => null, 'protocolo_lote' => null];
            }
            $this->documentos->atualizar($existente['id'], $campos);
            $this->fila->enfileirar(FilaRepositorio::EMITIR, $existente['id'], null, 0, (int)$this->cfg['fila']['max_tentativas']);
            $this->monitor->registrar(
                ['lote_id' => $loteId] + $existente,
                'RECEBIMENTO',
                StatusNfse::RECEBIDO,
                'Reenvio apos ' . $existente['status']
            );
            return ['id' => $existente['id'], 'referencia' => $referencia, 'situacao_recebimento' => 'REENVIADO'];
        }

        // --- Documento novo.
        $serie = $padrao === Padrao::NACIONAL
            ? (string)$this->cfg['nacional']['serie_dps']
            : (string)$this->cfg['salvador']['serie_rps'];

        $dados = [
            'lote_id' => $loteId,
            'consumidor' => $consumidor,
            'padrao' => $padrao,
            'tp_amb' => $tpAmb,
            'emitente_cnpj' => $cnpj,
            'referencia' => $referencia,
            'status' => StatusNfse::RECEBIDO,
            'payload' => $doc,
            'serie' => $serie,
            'numero' => $this->documentos->proximoNumero($cnpj, $padrao, $tpAmb, $serie),
        ];
        $dados['id'] = $this->documentos->inserir($dados);

        $this->fila->enfileirar(FilaRepositorio::EMITIR, $dados['id'], null, 0, (int)$this->cfg['fila']['max_tentativas']);
        $this->monitor->registrar($dados, 'RECEBIMENTO', StatusNfse::RECEBIDO, 'Documento recebido e enfileirado');

        return ['id' => $dados['id'], 'referencia' => $referencia, 'situacao_recebimento' => 'NOVO'];
    }
}
