<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * Status padronizado de um documento NFS-e, igual para TODOS os padroes.
 *
 * O sistema consumidor so precisa conhecer esta lista; nunca precisa saber
 * como o Nacional ou Salvador chamam cada situacao.
 *
 *   RECEBIDO     -> aceito pela API e aguardando na fila.
 *   PROCESSANDO  -> enviado ao fisco, aguardando resposta (Salvador e assincrono).
 *   AUTORIZADO   -> NFS-e gerada. XML autorizado disponivel. Pode gerar PDF.
 *   REJEITADO    -> fisco recusou por erro nos dados. Corrija e reenvie com a
 *                   MESMA referencia (o numero da DPS/RPS e reaproveitado).
 *   ERRO         -> falha tecnica que persistiu apos todas as tentativas.
 *                   NAO significa que a nota nao existe: use "consultar" antes
 *                   de reenviar.
 *   CANCELADO    -> NFS-e cancelada.
 *   SUBSTITUIDO  -> NFS-e substituida por outra (somente Nacional).
 */
final class StatusNfse
{
    public const RECEBIDO = 'RECEBIDO';
    public const PROCESSANDO = 'PROCESSANDO';
    public const AUTORIZADO = 'AUTORIZADO';
    public const REJEITADO = 'REJEITADO';
    public const ERRO = 'ERRO';
    public const CANCELADO = 'CANCELADO';
    public const SUBSTITUIDO = 'SUBSTITUIDO';

    /** Status em que o documento ainda esta "andando" no fluxo. */
    public const EM_ANDAMENTO = [self::RECEBIDO, self::PROCESSANDO];

    /** Status em que o consumidor pode reenviar a mesma referencia. */
    public const PERMITE_REENVIO = [self::REJEITADO, self::ERRO];

    /** Status em que existe uma NFS-e valida ou que ja existiu. */
    public const COM_NFSE = [self::AUTORIZADO, self::CANCELADO, self::SUBSTITUIDO];

    private function __construct()
    {
    }
}
