<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;
use NFePHP\NFe\Complements;
use NFePHP\DA\NFe\Danfe;
use NFePHP\DA\NFe\Daevento;

final class NfeService
{
    private Tools $tools;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;

        $this->ensureDirs();

        $configJson = json_encode([
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb'       => (int)$cfg['tpAmb'],
            'razaosocial' => (string)$cfg['razao'],
            'siglaUF'     => (string)$cfg['siglaUF'],
            'cnpj'        => preg_replace('/\D+/', '', (string)$cfg['cnpj']),
            'ie'          => preg_replace('/\D+/', '', (string)($cfg['ie'] ?? '')),
            // >>> NÃO hardcode: usa do config, com default correto
            'schemes'     => (string)($cfg['schemes'] ?? 'PL_010_V1.30'),
            'versao'      => (string)($cfg['versao'] ?? '4.00'),
            // se você não usa, pode deixar vazio
            'tokenIBPT'   => '',
            'CSC'         => '',
            'CSCid'       => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!$configJson) {
            throw new \RuntimeException('Falha ao montar JSON do Tools.');
        }

        $pfx = file_get_contents($cfg['certPfxPath']);
        if ($pfx === false) {
            throw new \RuntimeException('Não consegui ler o PFX em: ' . $cfg['certPfxPath']);
        }

        $certificate = Certificate::readPfx($pfx, $cfg['certPassword']);
        $this->tools = new Tools($configJson, $certificate);

        // NF-e modelo 55 (depois troca pra 65 se precisar)
        $this->tools->model((int)($cfg['model'] ?? 55));
    }

    // ==========================
    // Pastas padronizadas
    // ==========================
    private function ensureDirs(): void
    {
        $dirs = [
            $this->pathXml('gerados'),
            $this->pathXml('assinados'),
            $this->pathXml('autorizados'),
            $this->pathXml('eventos'),
            $this->pathPdf('danfe'),
            $this->pathPdf('eventos'),
            rtrim((string)($this->cfg['pathLogs'] ?? ''), '/\\'),
        ];

        foreach ($dirs as $d) {
            if (!$d) continue;
            if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
                throw new \RuntimeException("Falha ao criar pasta: $d");
            }
        }
    }

    public function pathXml(string $subdir): string
    {
        return rtrim((string)$this->cfg['pathXml'], '/\\') . DIRECTORY_SEPARATOR . $subdir;
    }

    public function pathPdf(string $subdir): string
    {
        return rtrim((string)$this->cfg['pathPdf'], '/\\') . DIRECTORY_SEPARATOR . $subdir;
    }

    public function saveXml(string $subdir, string $filename, string $xml): string
    {
        $path = $this->pathXml($subdir) . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $xml) === false) {
            throw new \RuntimeException("Falha ao gravar XML: $path");
        }
        return $path;
    }

    public function readFile(string $path): string
    {
        $c = file_get_contents($path);
        if ($c === false) {
            throw new \RuntimeException("Não consegui ler: $path");
        }
        return $c;
    }

    // ==========================
    // Tools e utilitários
    // ==========================
    public function ping(): string
    {
        return 'Tools OK: ' . get_class($this->tools);
    }

    public function tools(): Tools
    {
        return $this->tools;
    }

    public function statusServico(): string
    {
        return $this->tools->sefazStatus();
    }

    /**
     * BA: CNPJ que deve entrar em autXML (quando aplicável).
     */
    public function autxmlBaCnpj(): string
    {
        return (string)($this->cfg['autxml_ba_cnpj'] ?? '13937073000156');
    }

    // ==========================
    // NFeProc (sem Tools::addProt)
    // ==========================
    public function montarNfeProc(string $xmlNfeAssinada, string $xmlProtOuRet): string
    {
        return Complements::toAuthorize($xmlNfeAssinada, $xmlProtOuRet);
    }

    // ==========================
    // DANFE (nfeProc -> PDF)
    // ==========================
    public function gerarDanfePdfFromProc(string $nfeProcXml, string $pdfPath): void
    {
        $danfe = new Danfe($nfeProcXml);
        $danfe->debugMode(false);
        $pdf = $danfe->render();

        if (file_put_contents($pdfPath, $pdf) === false) {
            throw new \RuntimeException("Falha ao gravar PDF DANFE: $pdfPath");
        }
    }

    public function pathLogs(string $sub = ''): string
{
    $base = rtrim((string)$this->cfg['pathLogs'], "/\\");
    $dir = $base . ($sub !== '' ? DIRECTORY_SEPARATOR . $sub : '');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}


    // ==========================
    // Evento PDF (procEvento -> PDF)
    // IMPORTANTE: Daevento exige 2º parâmetro array
    // ==========================
    public function gerarPdfEvento(string $procEventoXml, array $config, string $pdfPath): void
    {
        $dae = new Daevento($procEventoXml, $config);
        $dae->debugMode(false);
        $pdf = $dae->render();

        if (file_put_contents($pdfPath, $pdf) === false) {
            throw new \RuntimeException("Falha ao gravar PDF Evento: $pdfPath");
        }
    }
}
