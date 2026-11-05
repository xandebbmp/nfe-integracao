<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Dominio\TipoEvento;

/**
 * Converte a linha do banco no JSON publico da API.
 *
 * E o UNICO lugar que define o formato de saida de um documento. A mesma
 * estrutura vai para: resposta HTTP, webhook e painel do monitor.
 */
final class DocumentoApresentador
{
    private function __construct()
    {
    }

    public static function documento(array $doc, bool $incluirXml = false, array $eventos = []): array
    {
        $temNfse = in_array($doc['status'], StatusNfse::COM_NFSE, true);

        $saida = [
            'id' => $doc['id'],
            'referencia' => $doc['referencia'],
            'padrao' => $doc['padrao'],
            'emitente_cnpj' => $doc['emitente_cnpj'],
            'ambiente' => (int)$doc['tp_amb'] === 1 ? 'PRODUCAO' : 'HOMOLOGACAO',
            'lote_id' => $doc['lote_id'],
            'status' => $doc['status'],
            'finalizado' => !in_array($doc['status'], StatusNfse::EM_ANDAMENTO, true),
            'pode_reenviar' => in_array($doc['status'], StatusNfse::PERMITE_REENVIO, true),
            // null quando o documento foi rejeitado na entrada (ainda sem numero).
            'dps_rps' => $doc['numero'] !== null ? [
                'serie' => $doc['serie'],
                'numero' => (int)$doc['numero'],
            ] : null,
            'nfse' => $temNfse ? [
                'numero' => $doc['numero_nfse'],
                'chave_acesso' => $doc['chave_acesso'],
                'codigo_verificacao' => $doc['codigo_verificacao'],
                // NF-e: protocolo de autorizacao da SEFAZ.
                'protocolo' => $doc['protocolo_autorizacao'] ?? null,
                'data_autorizacao' => $doc['data_autorizacao'],
            ] : null,
            'mensagens' => $doc['mensagens'] ?? [],
            'links' => [
                'documento' => '/v1/nfse/documento.php?id=' . $doc['id'],
                // Nome sugerido para salvar o XML (NF-e: <chave>-procNFe.xml).
                'xml_nome' => $temNfse ? NomeArquivoXml::documento($doc) : null,
                'xml' => $temNfse ? '/v1/nfse/documento.php?id=' . $doc['id'] . '&formato=xml' : null,
                'pdf' => $temNfse && $doc['status'] !== StatusNfse::DENEGADO ? '/v1/nfse/pdf.php?id=' . $doc['id'] : null,
            ],
            'criado_em' => $doc['criado_em'] ?? null,
            'atualizado_em' => $doc['atualizado_em'] ?? null,
        ];

        if ($eventos !== []) {
            $saida['eventos'] = array_map(static fn(array $e): array => self::evento($e, $doc), $eventos);
        }

        if ($incluirXml) {
            $saida['xml_autorizado'] = $doc['xml_autorizado'] ?? null;
        }

        return $saida;
    }

    /** @param array|null $documento a nota do evento (usada no nome do arquivo XML) */
    public static function evento(array $evento, ?array $documento = null): array
    {
        return [
            'id' => $evento['id'],
            'documento_id' => $evento['documento_id'],
            'tipo_evento' => $evento['tipo_evento'],
            'descricao' => TipoEvento::DESCRICOES[$evento['tipo_evento']] ?? $evento['tipo_evento'],
            'status' => $evento['status'],
            'motivo_codigo' => $evento['motivo_codigo'],
            'motivo_texto' => $evento['motivo_texto'],
            'sequencia' => isset($evento['sequencia']) ? (int)$evento['sequencia'] : null,
            'origem' => $evento['origem'] ?? 'API',
            'numero_processo' => $evento['numero_processo'] ?? null,
            'observacao' => $evento['observacao'] ?? null,
            'mensagens' => $evento['mensagens'] ?? [],
            // XML do evento registrado (cancelamento, CC-e...). Cancelamento
            // registrado manualmente (portal de Salvador) nao tem XML.
            'links' => [
                'xml' => $evento['status'] === StatusEvento::REGISTRADO && !empty($evento['xml_retorno'])
                    ? '/v1/nfse/documento.php?id=' . $evento['documento_id'] . '&evento=' . $evento['id'] . '&formato=xml'
                    : null,
                'xml_nome' => $documento !== null ? NomeArquivoXml::evento($documento, $evento) : null,
            ],
            'criado_em' => $evento['criado_em'] ?? null,
            'atualizado_em' => $evento['atualizado_em'] ?? null,
        ];
    }

    /** Linha do monitor para o painel (SSE). */
    public static function monitor(array $linha): array
    {
        return [
            'id' => (int)$linha['id'],
            'documento_id' => $linha['documento_id'],
            'lote_id' => $linha['lote_id'],
            'padrao' => $linha['padrao'],
            'emitente_cnpj' => $linha['emitente_cnpj'] ?? null,
            'referencia' => $linha['referencia'],
            'operacao' => $linha['operacao'],
            'status' => $linha['status'],
            'mensagem' => $linha['mensagem'],
            'numero_nfse' => $linha['numero_nfse'],
            'chave_acesso' => $linha['chave_acesso'],
            'criado_em' => $linha['criado_em'],
        ];
    }
}
