<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * Codigos de evento, usando a numeracao do Padrao Nacional.
 *
 * Usamos o mesmo codigo para todos os padroes para o consumidor ter um
 * contrato unico. Quais eventos cada padrao aceita fica em Capacidades.
 */
final class TipoEvento
{
    /** Cancelamento de NFS-e pelo emitente. */
    public const CANCELAMENTO = '101101';

    /** Solicitacao de analise fiscal para cancelamento (fora do prazo). */
    public const SOLICITACAO_ANALISE_CANCELAMENTO = '101103';

    /** Cancelamento por substituicao (gerado pelo fisco ao emitir a substituta). */
    public const CANCELAMENTO_POR_SUBSTITUICAO = '105102';

    public const DESCRICOES = [
        self::CANCELAMENTO => 'Cancelamento de NFS-e',
        self::SOLICITACAO_ANALISE_CANCELAMENTO => 'Solicitação de Análise Fiscal para Cancelamento de NFS-e',
        self::CANCELAMENTO_POR_SUBSTITUICAO => 'Cancelamento de NFS-e por Substituição',
    ];

    private function __construct()
    {
    }
}
