<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

/**
 * Resultado de uma chamada HTTP.
 *
 * status = 0 significa que nao houve resposta (timeout, DNS, TLS...).
 */
final class RespostaHttp
{
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly ?string $erroRede = null,
        public readonly string $contentType = ''
    ) {
    }

    public function sucesso(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Falhas que valem nova tentativa automatica: sem resposta, timeout,
     * excesso de requisicoes ou erro interno do servidor do fisco.
     */
    public function falhaTemporaria(): bool
    {
        return $this->status === 0
            || $this->status === 408
            || $this->status === 429
            || $this->status >= 500;
    }

    /**
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $dados = json_decode($this->corpo, true);
        return is_array($dados) ? $dados : null;
    }
}
