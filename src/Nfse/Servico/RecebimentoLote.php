<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\FilaRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\MonitorRepositorio;

/**
 * Recebe um lote de documentos da API e coloca cada um na fila.
 *
 * Para cada documento do lote, um destes resultados ("situacao_recebimento"):
 *
 *   NOVO          valido: gravado, numero DPS/RPS reservado, job EMITIR criado.
 *   INVALIDO      dados com erro: gravado como REJEITADO (sem numero) com o
 *                 motivo e a sugestao de correcao. Aparece no monitor.
 *                 Corrija e reenvie com a MESMA referencia.
 *   REENVIADO     referencia ja existia como REJEITADO/ERRO e veio corrigida.
 *   JA_EXISTENTE  referencia ja autorizada/em andamento: nada muda (sem nota
 *                 duplicada).
 *   RECUSADO      nem da para gravar (padrao desconhecido, emitente nao
 *                 autorizado, referencia repetida no mesmo lote...). Volta
 *                 so na resposta, nao entra no monitor.
 *
 * Tudo acontece em UMA transacao: ou o lote inteiro e registrado, ou nada.
 */
final class RecebimentoLote
{
    public const NOVO = 'NOVO';
    public const INVALIDO = 'INVALIDO';
    public const REENVIADO = 'REENVIADO';
    public const JA_EXISTENTE = 'JA_EXISTENTE';
    public const RECUSADO = 'RECUSADO';

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
     * @return array{ok: bool, erro?: string, lote_id?: string, documentos?: list<array>}
     *   ok=false so para erro na REQUISICAO como um todo (lista vazia, lote
     *   grande demais, sistema sem emitente liberado).
     */
    public function receber(string $consumidor, array $corpo): array
    {
        $lista = $corpo['documentos'] ?? null;
        if (!is_array($lista) || $lista === [] || !array_is_list($lista)) {
            return ['ok' => false, 'erro' => 'Envie "documentos" como uma lista com ao menos 1 documento.'];
        }

        $max = (int)$this->cfg['api']['max_documentos_por_lote'];
        if (count($lista) > $max) {
            return ['ok' => false, 'erro' => "Maximo de {$max} documentos por lote. Divida o envio em lotes menores."];
        }

        $permitidos = NfseConfig::cnpjsPermitidos($this->cfg, $consumidor);
        if ($permitidos === []) {
            return ['ok' => false, 'erro' => "O sistema {$consumidor} nao tem emitente (CNPJ) liberado. Configure 'cnpjs' do consumidor em config/nfse.local.php."];
        }

        $this->pdo->beginTransaction();
        try {
            $loteId = $this->documentos->criarLote($consumidor, count($lista));
            $resultado = [];
            $referenciasNoLote = [];

            foreach ($lista as $indice => $doc) {
                $item = $this->receberItem($consumidor, $loteId, $indice, $doc, $corpo, $permitidos, $referenciasNoLote);
                $resultado[] = ['indice' => $indice] + $item;
            }

            // Nada gravado (todos recusados): desfaz para nao sobrar lote vazio.
            $gravou = array_filter($resultado, static fn($r) => in_array($r['situacao_recebimento'], [self::NOVO, self::INVALIDO, self::REENVIADO], true));
            if ($gravou === []) {
                $this->pdo->rollBack();
                return ['ok' => true, 'lote_id' => null, 'documentos' => $resultado];
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['ok' => true, 'lote_id' => $loteId, 'documentos' => $resultado];
    }

    /**
     * @return array{situacao_recebimento: string, id?: string, referencia?: ?string, erros?: list<array>}
     */
    private function receberItem(
        string $consumidor,
        string $loteId,
        int $indice,
        mixed $doc,
        array $corpo,
        array $permitidos,
        array &$referenciasNoLote
    ): array {
        // ---- 1. Dados minimos para conseguir gravar ----
        if (!is_array($doc)) {
            return self::recusado(null, 'documento', 'Documento nao e um objeto JSON.', 'Envie cada item de "documentos" como objeto { ... }.');
        }

        $padrao = strtoupper(trim((string)($doc['padrao'] ?? $corpo['padrao'] ?? '')));
        if (!in_array($padrao, Padrao::TODOS, true)) {
            return self::recusado($doc['referencia'] ?? null, 'padrao', "Padrao '{$padrao}' desconhecido.",
                'Informe "padrao" no lote ou no documento: ' . implode(' ou ', Padrao::TODOS) . '.');
        }

        $cnpjInformado = $doc['emitente_cnpj'] ?? $corpo['emitente_cnpj'] ?? null;

        // NF-e: o JSON ja traz o emitente em emit.CNPJ. Se vier tambem
        // emitente_cnpj, os dois precisam ser iguais.
        $cnpjEmit = $padrao === Padrao::NFE && !empty($doc['emit']['CNPJ']) ? NfseConfig::normalizarCnpj($doc['emit']['CNPJ']) : null;
        if ($cnpjEmit !== null && $cnpjInformado !== null && NfseConfig::normalizarCnpj($cnpjInformado) !== $cnpjEmit) {
            return self::recusado($doc['referencia'] ?? null, 'emit.CNPJ', "emit.CNPJ ({$cnpjEmit}) diferente de emitente_cnpj (" . NfseConfig::normalizarCnpj($cnpjInformado) . ').',
                'Envie o mesmo CNPJ nos dois campos, ou so em emit.CNPJ.');
        }
        $cnpjInformado ??= $cnpjEmit;

        $cnpj = $cnpjInformado !== null ? NfseConfig::normalizarCnpj($cnpjInformado) : (count($permitidos) === 1 ? $permitidos[0] : '');
        if ($cnpj === '') {
            return self::recusado($doc['referencia'] ?? null, 'emitente_cnpj', 'Emitente nao informado.',
                'Este sistema pode emitir por mais de um CNPJ: informe "emitente_cnpj" no lote ou no documento.');
        }
        if (!in_array($cnpj, $permitidos, true)) {
            return self::recusado($doc['referencia'] ?? null, 'emitente_cnpj', "Emitente {$cnpj} nao liberado para este sistema.",
                'Use um dos CNPJs liberados: ' . implode(', ', $permitidos) . '.');
        }

        // Sem referencia valida geramos uma provisoria so para poder exibir
        // o erro no monitor. O consumidor deve reenviar com referencia propria.
        $referencia = is_string($doc['referencia'] ?? null) ? trim($doc['referencia']) : '';
        if ($referencia === '' || mb_strlen($referencia) > 60) {
            $referencia = 'SEM-REFERENCIA-' . substr($loteId, 0, 8) . '-' . ($indice + 1);
        }

        $chave = "{$padrao}|{$cnpj}|{$referencia}";
        if (isset($referenciasNoLote[$chave])) {
            return self::recusado($referencia, 'referencia', 'Referencia repetida dentro do mesmo lote.',
                'Cada documento do lote precisa de uma referencia diferente.');
        }
        $referenciasNoLote[$chave] = true;

        $doc['padrao'] = $padrao;
        $doc['emitente_cnpj'] = $cnpj;
        if ($padrao === Padrao::NFE && is_array($doc['emit'] ?? null) && empty($doc['emit']['CNPJ']) && empty($doc['emit']['CPF'])) {
            $doc['emit']['CNPJ'] = $cnpj; // emit sem CNPJ: usa o emitente escolhido
        }

        // ---- 2. Validacao de conteudo ----
        $erros = ValidadorPayload::validar($doc, $padrao);
        $doc['referencia'] = $referencia;

        // ---- 3. Idempotencia ----
        $tpAmb = (int)$this->cfg['tpAmb'];
        $existente = $this->documentos->buscarPorReferenciaParaAtualizar($consumidor, $cnpj, $padrao, $tpAmb, $referencia);

        if ($existente !== null && !in_array($existente['status'], StatusNfse::PERMITE_REENVIO, true)) {
            // Ja autorizado / em andamento: nada muda, nem com dados novos.
            return ['situacao_recebimento' => self::JA_EXISTENTE, 'id' => $existente['id'], 'referencia' => $referencia];
        }

        if ($erros !== []) {
            return $this->gravarInvalido($consumidor, $loteId, $padrao, $cnpj, $tpAmb, $doc, $erros, $existente);
        }

        return $existente !== null
            ? $this->reenviar($loteId, $doc, $existente)
            : $this->gravarNovo($consumidor, $loteId, $padrao, $cnpj, $tpAmb, $doc);
    }

    private function gravarNovo(string $consumidor, string $loteId, string $padrao, string $cnpj, int $tpAmb, array $doc): array
    {
        $serie = $this->serie($padrao, $doc);
        $dados = $this->dadosBase($consumidor, $loteId, $padrao, $cnpj, $tpAmb, $doc) + [
            'status' => StatusNfse::RECEBIDO,
            'serie' => $serie,
            'numero' => $this->documentos->proximoNumero($cnpj, $padrao, $tpAmb, $serie),
            'mensagens' => [],
        ];
        $dados['id'] = $this->documentos->inserir($dados);

        $this->enfileirarEmissao($dados['id']);
        $this->monitor->registrar($dados, 'RECEBIMENTO', StatusNfse::RECEBIDO, 'Documento recebido e enfileirado');

        return ['situacao_recebimento' => self::NOVO, 'id' => $dados['id'], 'referencia' => $doc['referencia']];
    }

    /**
     * Documento ja existia (REJEITADO ou ERRO) e voltou com dados validos.
     */
    private function reenviar(string $loteId, array $doc, array $existente): array
    {
        $campos = [
            'lote_id' => $loteId,
            'payload' => $doc,
            'status' => StatusNfse::RECEBIDO,
            'codigo_retorno' => null,
            'mensagem_retorno' => null,
            'mensagens' => [],
        ];

        // Documento que nunca foi valido ainda nao tem numero: reserva agora.
        if ($existente['numero'] === null) {
            $campos['serie'] = $this->serie($existente['padrao'], $doc);
            $campos['numero'] = $this->documentos->proximoNumero(
                $existente['emitente_cnpj'], $existente['padrao'], (int)$existente['tp_amb'], $campos['serie']
            );
        }

        if ($existente['status'] === StatusNfse::REJEITADO) {
            // Rejeicao = nada foi gerado no fisco. O XML sera montado do zero.
            // (Em ERRO mantemos o envio anterior para o provedor checar duplicidade.)
            $campos += ['xml_enviado' => null, 'protocolo_lote' => null];
        }

        $this->documentos->atualizar($existente['id'], $campos);
        $this->enfileirarEmissao($existente['id']);
        $this->monitor->registrar(
            array_replace($existente, $campos),
            'RECEBIMENTO',
            StatusNfse::RECEBIDO,
            'Reenvio apos ' . $existente['status']
        );

        return ['situacao_recebimento' => self::REENVIADO, 'id' => $existente['id'], 'referencia' => $doc['referencia']];
    }

    /**
     * Grava (ou atualiza) o documento como REJEITADO na entrada, com os erros.
     */
    private function gravarInvalido(
        string $consumidor,
        string $loteId,
        string $padrao,
        string $cnpj,
        int $tpAmb,
        array $doc,
        array $erros,
        ?array $existente
    ): array {
        $resumo = count($erros) === 1 ? $erros[0]['mensagem'] : count($erros) . ' erros nos dados enviados';
        $campos = [
            'status' => StatusNfse::REJEITADO,
            'mensagens' => $erros,
            'codigo_retorno' => $erros[0]['codigo'],
            'mensagem_retorno' => $erros[0]['mensagem'],
        ];

        if ($existente === null) {
            $dados = $this->dadosBase($consumidor, $loteId, $padrao, $cnpj, $tpAmb, $doc) + $campos + ['serie' => null, 'numero' => null];
            $dados['id'] = $this->documentos->inserir($dados);
            $this->monitor->registrar($dados, 'RECEBIMENTO', StatusNfse::REJEITADO, 'Dados invalidos: ' . $resumo);
            return ['situacao_recebimento' => self::INVALIDO, 'id' => $dados['id'], 'referencia' => $doc['referencia'], 'erros' => $erros];
        }

        if ($existente['status'] === StatusNfse::ERRO) {
            // Em ERRO a nota PODE existir no fisco. Nao sobrescrevemos o
            // documento com dados invalidos: so devolvemos os erros.
            return ['situacao_recebimento' => self::RECUSADO, 'id' => $existente['id'], 'referencia' => $doc['referencia'], 'erros' => $erros];
        }

        $campos += ['lote_id' => $loteId, 'payload' => $doc];
        $this->documentos->atualizar($existente['id'], $campos);
        $this->monitor->registrar(array_replace($existente, $campos), 'RECEBIMENTO', StatusNfse::REJEITADO, 'Dados invalidos: ' . $resumo);

        return ['situacao_recebimento' => self::INVALIDO, 'id' => $existente['id'], 'referencia' => $doc['referencia'], 'erros' => $erros];
    }

    private function dadosBase(string $consumidor, string $loteId, string $padrao, string $cnpj, int $tpAmb, array $doc): array
    {
        return [
            'lote_id' => $loteId,
            'consumidor' => $consumidor,
            'padrao' => $padrao,
            'tp_amb' => $tpAmb,
            'emitente_cnpj' => $cnpj,
            'referencia' => $doc['referencia'],
            'payload' => $doc,
        ];
    }

    /**
     * Serie do documento. NF-e: ide.serie do JSON (ou a padrao da config).
     * A numeracao e controlada por serie: cada serie tem a sua sequencia.
     */
    private function serie(string $padrao, array $doc): string
    {
        return match ($padrao) {
            Padrao::NACIONAL => (string)$this->cfg['nacional']['serie_dps'],
            Padrao::SALVADOR => (string)$this->cfg['salvador']['serie_rps'],
            Padrao::NFE => (string)(int)($doc['ide']['serie'] ?? $this->cfg['nfe']['serie']),
        };
    }

    private function enfileirarEmissao(string $documentoId): void
    {
        $this->fila->enfileirar(FilaRepositorio::EMITIR, $documentoId, null, 0, (int)$this->cfg['fila']['max_tentativas']);
    }

    private static function recusado(mixed $referencia, string $campo, string $mensagem, string $correcao): array
    {
        return [
            'situacao_recebimento' => self::RECUSADO,
            'referencia' => is_scalar($referencia) ? (string)$referencia : null,
            'erros' => [['codigo' => 'NAO_ARMAZENADO', 'campo' => $campo, 'mensagem' => $mensagem, 'correcao' => $correcao]],
        ];
    }
}
