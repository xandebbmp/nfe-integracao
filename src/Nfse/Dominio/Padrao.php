<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * Padroes (tipos de documento) suportados pela API.
 *
 *   NACIONAL / SALVADOR -> NFS-e (servicos)
 *   NFE                 -> NF-e modelo 55 (produtos), SEFAZ do estado
 *
 * Para incluir um novo municipio:
 *   1. adicione a constante aqui e em TODOS;
 *   2. crie a pasta src/Nfse/<Municipio>/ com um Provedor que implemente ProvedorNfse;
 *   3. registre no ProvedorFactory e descreva o padrao em Capacidades;
 *   4. libere o valor no CHECK das tabelas (novo script SQL).
 */
final class Padrao
{
    public const NACIONAL = 'NACIONAL';
    public const SALVADOR = 'SALVADOR';
    public const NFE = 'NFE';

    public const TODOS = [self::NACIONAL, self::SALVADOR, self::NFE];

    /** Padroes que sao NFS-e (servicos). */
    public const NFSE = [self::NACIONAL, self::SALVADOR];

    private function __construct()
    {
    }
}
