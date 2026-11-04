<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;
use Xande\NfeIntegracao\Nfse\Repositorio\WebhookRepositorio;

/**
 * Entrega os webhooks pendentes (rodado pelo worker a cada ciclo).
 *
 * Seguranca: cada POST leva o cabecalho
 *   X-NFSe-Assinatura: sha256=<HMAC-SHA256 do corpo com o webhook_segredo>
 * O consumidor deve recalcular e comparar antes de confiar no conteudo.
 *
 * Tentativas: 1min, 2min, 4min... ate 10 tentativas. Depois vira FALHOU
 * (o consumidor ainda pode consultar pela API a qualquer momento).
 */
final class EntregadorWebhook
{
    private const MAX_TENTATIVAS = 10;

    private WebhookRepositorio $webhooks;
    private TransporteHttp $http;

    public function __construct(PDO $pdo, private array $cfg, ?TransporteHttp $http = null)
    {
        $this->webhooks = new WebhookRepositorio($pdo);
        // Sem certificado do cliente: webhook e HTTPS comum.
        $this->http = $http ?? new CurlTransporte(null, 10);
    }

    /** @return int quantos webhooks foram tentados */
    public function entregarPendentes(int $limite = 10): int
    {
        $pendentes = $this->webhooks->pegarPendentes($limite);

        foreach ($pendentes as $webhook) {
            $corpo = (string)$webhook['corpo'];
            $segredo = (string)($this->cfg['consumidores'][$webhook['consumidor']]['webhook_segredo'] ?? '');

            $resposta = $this->http->enviar('POST', (string)$webhook['url'], [
                'Content-Type' => 'application/json',
                'X-NFSe-Assinatura' => 'sha256=' . hash_hmac('sha256', $corpo, $segredo),
                'X-NFSe-Entrega' => (string)$webhook['id'],
            ], $corpo);

            $tentativas = (int)$webhook['tentativas'];
            $proxima = $tentativas >= self::MAX_TENTATIVAS ? null : 60 * (2 ** ($tentativas - 1));

            $this->webhooks->registrarResultado(
                (int)$webhook['id'],
                $resposta->sucesso(),
                $resposta->status,
                $resposta->sucesso() ? null : ($resposta->erroRede ?? mb_substr($resposta->corpo, 0, 500)),
                $resposta->sucesso() ? null : $proxima
            );
        }

        return count($pendentes);
    }
}
