<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Repositorio\WebhookRepositorio;

/**
 * Grava (no outbox) o aviso para o sistema consumidor quando um documento
 * ou evento muda de situacao. A entrega HTTP e feita pelo EntregadorWebhook.
 *
 * So notifica consumidores que configuraram webhook_url em config/nfse.php.
 */
final class NotificadorWebhook
{
    private WebhookRepositorio $webhooks;

    public function __construct(PDO $pdo, private array $cfg)
    {
        $this->webhooks = new WebhookRepositorio($pdo);
    }

    public function documentoAtualizado(array $documento): void
    {
        $this->enfileirar($documento, [
            'tipo' => 'documento.atualizado',
            'documento' => DocumentoApresentador::documento($documento),
        ]);
    }

    public function eventoAtualizado(array $documento, array $evento): void
    {
        $this->enfileirar($documento, [
            'tipo' => 'evento.atualizado',
            'evento' => DocumentoApresentador::evento($evento),
            'documento' => DocumentoApresentador::documento($documento),
        ]);
    }

    private function enfileirar(array $documento, array $corpo): void
    {
        $consumidor = (string)$documento['consumidor'];
        $url = trim((string)($this->cfg['consumidores'][$consumidor]['webhook_url'] ?? ''));
        if ($url === '') {
            return;
        }
        $corpo['enviado_em'] = date(DATE_ATOM);
        $this->webhooks->enfileirar($documento['id'], $consumidor, $url, $corpo);
    }
}
