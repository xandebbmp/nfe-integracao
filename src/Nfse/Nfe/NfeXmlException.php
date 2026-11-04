<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Nfe;

use RuntimeException;

/**
 * Erro ao montar o XML da NF-e a partir do JSON (dado faltando ou invalido).
 * Vira REJEITADO com as mensagens do montador (nao chega a ir para a SEFAZ).
 */
final class NfeXmlException extends RuntimeException
{
    /** @param array $errosMake erros devolvidos por NFePHP\NFe\Make::getErrors() */
    public function __construct(string $mensagem, public readonly array $errosMake = [])
    {
        parent::__construct($mensagem);
    }
}
