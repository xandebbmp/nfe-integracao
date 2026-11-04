<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Armazenamento;

use RuntimeException;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;

/**
 * Grava em AWS S3 ou servico compativel, sem SDK (so cURL).
 *
 * A autenticacao segue o padrao "AWS Signature Version 4", aceito por
 * S3, MinIO, Wasabi, Cloudflare R2, DigitalOcean Spaces e outros.
 *
 * Retencao de 5 anos: configure no proprio bucket (regra de ciclo de vida
 * e, se possivel, Object Lock), nao no codigo.
 */
final class ArmazenamentoS3 implements ArmazenamentoArquivos
{
    public function __construct(
        private TransporteHttp $http,
        private string $endpoint,
        private string $regiao,
        private string $bucket,
        private string $chaveAcesso,
        private string $chaveSecreta,
        private string $prefixo = '',
        private bool $pathStyle = false
    ) {
        if ($bucket === '' || $chaveAcesso === '' || $chaveSecreta === '') {
            throw new RuntimeException('Armazenamento S3 sem bucket ou credenciais (config armazenamento.s3).');
        }
    }

    public function salvar(string $caminho, string $conteudo, string $contentType = 'application/xml'): void
    {
        $partes = parse_url($this->endpoint);
        $esquema = $partes['scheme'] ?? 'https';
        $hostBase = ($partes['host'] ?? '') . (isset($partes['port']) ? ':' . $partes['port'] : '');

        $chave = trim($this->prefixo, '/') !== '' ? trim($this->prefixo, '/') . '/' . ltrim($caminho, '/') : ltrim($caminho, '/');
        $chaveCodificada = implode('/', array_map('rawurlencode', explode('/', $chave)));

        if ($this->pathStyle) {
            $host = $hostBase;
            $uri = '/' . rawurlencode($this->bucket) . '/' . $chaveCodificada;
        } else {
            $host = $this->bucket . '.' . $hostBase;
            $uri = '/' . $chaveCodificada;
        }

        $dataHora = gmdate('Ymd\THis\Z');
        $cabecalhos = [
            'content-type' => $contentType,
            'host' => $host,
            'x-amz-content-sha256' => hash('sha256', $conteudo),
            'x-amz-date' => $dataHora,
        ];
        $cabecalhos['authorization'] = self::autorizacao(
            'PUT', $uri, '', $cabecalhos, $this->regiao, $this->chaveAcesso, $this->chaveSecreta, $dataHora
        );
        unset($cabecalhos['host']); // o cURL envia o Host sozinho

        $resposta = $this->http->enviar('PUT', "{$esquema}://{$host}{$uri}", $cabecalhos, $conteudo);
        if (!$resposta->sucesso()) {
            throw new RuntimeException(
                "Falha ao gravar no S3 (HTTP {$resposta->status}): " . ($resposta->erroRede ?? mb_substr(strip_tags($resposta->corpo), 0, 300))
            );
        }
    }

    /**
     * Cabecalho Authorization no padrao AWS Signature Version 4.
     *
     * Publico e estatico para ser testado com o exemplo oficial da AWS
     * (tests/nfse/executar.php).
     *
     * @param array<string, string> $cabecalhos nomes em minusculo, incluindo host e x-amz-*
     */
    public static function autorizacao(
        string $metodo,
        string $uri,
        string $query,
        array $cabecalhos,
        string $regiao,
        string $chaveAcesso,
        string $chaveSecreta,
        string $dataHora
    ): string {
        ksort($cabecalhos);
        $canonicos = '';
        foreach ($cabecalhos as $nome => $valor) {
            $canonicos .= strtolower($nome) . ':' . trim($valor) . "\n";
        }
        $assinados = implode(';', array_map('strtolower', array_keys($cabecalhos)));

        $requisicaoCanonica = implode("\n", [
            $metodo,
            $uri,
            $query,
            $canonicos,
            $assinados,
            $cabecalhos['x-amz-content-sha256'],
        ]);

        $data = substr($dataHora, 0, 8);
        $escopo = "{$data}/{$regiao}/s3/aws4_request";
        $textoParaAssinar = "AWS4-HMAC-SHA256\n{$dataHora}\n{$escopo}\n" . hash('sha256', $requisicaoCanonica);

        $chave = hash_hmac('sha256', $data, 'AWS4' . $chaveSecreta, true);
        $chave = hash_hmac('sha256', $regiao, $chave, true);
        $chave = hash_hmac('sha256', 's3', $chave, true);
        $chave = hash_hmac('sha256', 'aws4_request', $chave, true);
        $assinatura = hash_hmac('sha256', $textoParaAssinar, $chave);

        return "AWS4-HMAC-SHA256 Credential={$chaveAcesso}/{$escopo}, SignedHeaders={$assinados}, Signature={$assinatura}";
    }
}
