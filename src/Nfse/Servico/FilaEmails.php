<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Repositorio\EmailRepositorio;

/**
 * Worker: envia os e-mails da fila (sistema_emails, script 0012).
 *
 * Falhou (SMTP fora, senha errada)? Tenta de novo em 1, 2, 4 e 8 minutos e,
 * depois de 5 tentativas, desiste (status ERRO, aparece no painel).
 */
final class FilaEmails
{
    private EmailRepositorio $repo;

    public function __construct(PDO $pdo, private EnvioEmail $envio)
    {
        $this->repo = new EmailRepositorio($pdo);
    }

    /** @return array{enviados: int, falhas: int} */
    public function processar(int $limite = 10): array
    {
        $saida = ['enviados' => 0, 'falhas' => 0];
        if (!$this->repo->existe()) {
            return $saida;
        }
        foreach ($this->repo->reservarProximos($limite) as $e) {
            try {
                $this->envio->enviar((string)$e['para'], (string)$e['assunto'], (string)$e['texto'], $e['html'] !== null ? (string)$e['html'] : null);
                $this->repo->marcarEnviado((int)$e['id']);
                $saida['enviados']++;
            } catch (Throwable $erro) {
                $this->repo->marcarFalha((int)$e['id'], (int)$e['tentativas'], $erro->getMessage());
                $saida['falhas']++;
            }
        }
        return $saida;
    }
}
