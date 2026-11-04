<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * Status padronizado de um evento fiscal (cancelamento etc.).
 */
final class StatusEvento
{
    public const RECEBIDO = 'RECEBIDO';
    public const PROCESSANDO = 'PROCESSANDO';
    public const REGISTRADO = 'REGISTRADO';
    public const REJEITADO = 'REJEITADO';
    public const ERRO = 'ERRO';

    private function __construct()
    {
    }
}
