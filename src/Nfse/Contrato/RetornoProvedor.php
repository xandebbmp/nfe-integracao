<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Contrato;

use Xande\NfeIntegracao\Nfse\Dominio\StatusEvento;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;

/**
 * Resposta de um provedor (Nacional, Salvador...) ja traduzida para o
 * vocabulario padronizado da API.
 *
 * O worker so olha para este objeto; ele nao conhece XML de prefeitura.
 *
 * Campos:
 *   - status: um valor de StatusNfse (documento) ou StatusEvento (evento).
 *   - mensagens: lista de ['codigo' => ..., 'mensagem' => ..., 'correcao' => ...].
 *   - dados: colunas a atualizar no documento/evento (chave_acesso, xml_autorizado...).
 *   - consultarNovamenteEmSegundos: preenchido quando o fisco ainda nao terminou
 *     (ex.: lote de Salvador em processamento).
 *   - falhaTemporaria: true quando vale tentar de novo (timeout, fisco fora do ar).
 */
final class RetornoProvedor
{
    /**
     * Status especial de CONSULTA: o fisco nao encontrou o documento.
     * O worker mantem o status atual do documento e so registra a mensagem.
     */
    public const NAO_ENCONTRADO = 'NAO_ENCONTRADO';

    /**
     * @param list<array{codigo: string, mensagem: string, correcao?: string}> $mensagens
     * @param array<string, mixed> $dados
     */
    private function __construct(
        public readonly string $status,
        public readonly array $mensagens = [],
        public readonly array $dados = [],
        public readonly ?int $consultarNovamenteEmSegundos = null,
        public readonly bool $falhaTemporaria = false
    ) {
    }

    // ---------------------------------------------------------------
    // Documento
    // ---------------------------------------------------------------

    public static function autorizado(array $dados, array $mensagens = []): self
    {
        return new self(StatusNfse::AUTORIZADO, $mensagens, $dados);
    }

    public static function rejeitado(array $mensagens, array $dados = []): self
    {
        return new self(StatusNfse::REJEITADO, $mensagens, $dados);
    }

    /** Fisco recebeu mas ainda nao terminou. O worker agenda nova consulta. */
    public static function processando(array $dados, int $consultarEmSegundos, array $mensagens = []): self
    {
        return new self(StatusNfse::PROCESSANDO, $mensagens, $dados, $consultarEmSegundos);
    }

    /** Resultado de uma consulta: status atual do documento no fisco. */
    public static function situacao(string $status, array $dados = [], array $mensagens = []): self
    {
        return new self($status, $mensagens, $dados);
    }

    // ---------------------------------------------------------------
    // Evento
    // ---------------------------------------------------------------

    public static function eventoRegistrado(array $dados, array $mensagens = []): self
    {
        return new self(StatusEvento::REGISTRADO, $mensagens, $dados);
    }

    public static function eventoRejeitado(array $mensagens, array $dados = []): self
    {
        return new self(StatusEvento::REJEITADO, $mensagens, $dados);
    }

    // ---------------------------------------------------------------
    // Falha tecnica (serve para documento e evento)
    // ---------------------------------------------------------------

    public static function falhaTemporaria(string $mensagem, array $dados = []): self
    {
        return new self(
            StatusNfse::ERRO,
            [['codigo' => 'FALHA_TEMPORARIA', 'mensagem' => $mensagem]],
            $dados,
            null,
            true
        );
    }

    /** Primeira mensagem, para exibir resumida no monitor. */
    public function resumo(): ?string
    {
        if ($this->mensagens === []) {
            return null;
        }
        $primeira = $this->mensagens[0];
        return trim(($primeira['codigo'] ?? '') . ' - ' . ($primeira['mensagem'] ?? ''), ' -');
    }
}
