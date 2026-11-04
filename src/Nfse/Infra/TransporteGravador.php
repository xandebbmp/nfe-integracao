<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use RuntimeException;
use Xande\NfeIntegracao\Nfse\Nacional\SefinNacionalCliente;

/**
 * Transporte que GRAVA cada chamada ao fisco em arquivos, para analise.
 *
 * Envolve outro transporte (normalmente o CurlTransporte) e, para cada
 * chamada, cria na pasta informada:
 *
 *   01-POST-nfse.txt         requisicao + resposta (cabecalhos e corpo)
 *   01-POST-nfse.envio.xml   XML enviado (ja descompactado do gzip+base64)
 *   01-POST-nfse.retorno.xml XML devolvido (ja descompactado)
 *   03-GET-danfse.pdf        quando a resposta e um PDF
 *
 * Usado pelo bin/nfse_homologar.php. Nao grava certificado nem senha: o
 * certificado do mTLS vai por arquivo para o cURL, nunca pelos cabecalhos.
 */
final class TransporteGravador implements TransporteHttp
{
    private int $sequencia = 0;

    public function __construct(private TransporteHttp $transporte, private string $pasta)
    {
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new RuntimeException("Falha ao criar pasta de gravacao: {$pasta}");
        }
    }

    public function enviar(string $metodo, string $url, array $cabecalhos = [], ?string $corpo = null): RespostaHttp
    {
        $inicio = microtime(true);
        $resposta = $this->transporte->enviar($metodo, $url, $cabecalhos, $corpo);
        $duracaoMs = (int)round((microtime(true) - $inicio) * 1000);

        $this->sequencia++;
        $base = sprintf('%s/%02d-%s-%s', rtrim($this->pasta, '/'), $this->sequencia, strtoupper($metodo), self::nomeDaRota($url));

        $texto = "=== REQUISICAO ===\n{$metodo} {$url}\n";
        foreach ($cabecalhos as $nome => $valor) {
            $texto .= "{$nome}: {$valor}\n";
        }
        $texto .= "\n" . ($corpo ?? '(sem corpo)') . "\n\n";
        $texto .= "=== RESPOSTA ({$duracaoMs} ms) ===\n";
        $texto .= $resposta->status === 0
            ? "SEM RESPOSTA: {$resposta->erroRede}\n"
            : "HTTP {$resposta->status}\nContent-Type: {$resposta->contentType}\n\n";

        if (str_starts_with($resposta->corpo, '%PDF')) {
            file_put_contents("{$base}.pdf", $resposta->corpo);
            $texto .= '(PDF com ' . strlen($resposta->corpo) . " bytes salvo em " . basename($base) . ".pdf)\n";
        } else {
            $texto .= $resposta->corpo . "\n";
        }
        file_put_contents("{$base}.txt", $texto);

        // XMLs compactados dentro do JSON do Padrao Nacional (*XmlGZipB64).
        $this->salvarXmlCompactado($corpo, "{$base}.envio.xml");
        $this->salvarXmlCompactado($resposta->corpo, "{$base}.retorno.xml");

        return $resposta;
    }

    private function salvarXmlCompactado(?string $json, string $arquivo): void
    {
        $dados = $json !== null ? json_decode($json, true) : null;
        if (!is_array($dados)) {
            return;
        }
        $xmls = [];
        array_walk_recursive($dados, static function ($valor, $chave) use (&$xmls): void {
            if (is_string($valor) && is_string($chave) && stripos($chave, 'XmlGZipB64') !== false) {
                $xml = SefinNacionalCliente::descompactar($valor);
                if ($xml !== null) {
                    $xmls[] = $xml;
                }
            }
        });
        if ($xmls !== []) {
            file_put_contents($arquivo, implode("\n\n", $xmls));
        }
    }

    /** "https://host/SefinNacional/nfse/123/eventos" -> "nfse-eventos" */
    private static function nomeDaRota(string $url): string
    {
        $caminho = (string)parse_url($url, PHP_URL_PATH);
        $partes = array_filter(explode('/', $caminho), static fn($p) => $p !== '' && !preg_match('/\d{10,}|^DPS\d+/', $p));
        $nome = strtolower(implode('-', array_slice(array_values($partes), -2)));
        $nome = preg_replace('/[^a-z0-9-]+/', '', str_replace('.svc', '', $nome)) ?: 'chamada';
        return substr($nome, 0, 60);
    }
}
