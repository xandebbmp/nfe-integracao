<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Contrato;

/**
 * Contrato que TODO padrao de NFS-e precisa cumprir.
 * (O que cada padrao suporta fica em Dominio\Capacidades.)
 *
 * O resto do sistema (API, fila, worker, monitor) so conversa com esta
 * interface. Para suportar um novo municipio basta criar uma nova classe
 * que implemente estes metodos.
 *
 * $documento e a linha da tabela nfse_documentos, com 'payload' ja
 * decodificado para array.
 */
interface ProvedorNfse
{
    /** Ex.: 'NACIONAL', 'SALVADOR'. */
    public function padrao(): string;

    /** Envia o documento ao fisco pela primeira vez (ou reenvia apos falha). */
    public function emitir(array $documento): RetornoProvedor;

    /**
     * Acompanha um envio anterior: lote assincrono (Salvador) ou
     * verificacao de "a DPS ja virou nota?" (Nacional).
     */
    public function consultarProcessamento(array $documento): RetornoProvedor;

    /** Consulta a situacao atual de uma NFS-e ja autorizada. */
    public function consultar(array $documento): RetornoProvedor;

    /**
     * Registra um evento (cancelamento etc.).
     *
     * @param array $evento linha da tabela nfse_eventos
     */
    public function registrarEvento(array $documento, array $evento): RetornoProvedor;

    /** Retorna o PDF (binario) de uma NFS-e autorizada. */
    public function gerarPdf(array $documento): string;
}
