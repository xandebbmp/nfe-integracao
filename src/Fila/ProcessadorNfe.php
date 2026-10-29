<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Fila;

use Xande\NfeIntegracao\Nfe\DocumentoStore;

/**
 * Executa um job de NF-e com o fluxo homologado (lote_emitir.php -> gerar + assinar/enviar)
 * e decide o destino do job pelo estado gravado em nfe_documentos, nao pela saida em texto.
 */
final class ProcessadorNfe
{
    public function __construct(
        private FilaStore $fila,
        private DocumentoStore $docs,
        private string $baseDir
    ) {
    }

    public function processar(array $job): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nfe_job_' . $job['id'] . '_' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->fila->reagendar($job, "Falha ao criar pasta temporaria: {$dir}", '');
            return 'REAGENDADO';
        }

        $antes = $this->docs->buscarPorIdIntegracao((string)$job['cnpj'], (int)$job['tp_amb'], (string)$job['id_integracao']);

        try {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'nota.json', (string)$job['payload']);

            $cmd = 'NFE_EMPRESA_CNPJ=' . escapeshellarg((string)$job['cnpj']) . ' '
                . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($this->baseDir . '/public/lote_emitir.php') . ' '
                . escapeshellarg($dir) . ' 2>&1';

            $out = [];
            exec($cmd, $out);
            $saida = implode("\n", $out);
        } finally {
            @unlink($dir . DIRECTORY_SEPARATOR . 'nota.json');
            @rmdir($dir);
        }

        $doc = $this->docs->buscarPorIdIntegracao((string)$job['cnpj'], (int)$job['tp_amb'], (string)$job['id_integracao']);

        // Não gerou documento, ou não regerou a versão corrigida de uma rejeitada:
        // erro nos dados (JSON/Make/validação) ou de empresa.
        $naoMudou = $antes !== null && $doc !== null && $doc['atualizado_em'] === $antes['atualizado_em'];
        if ($doc === null || ($naoMudou && $doc['status'] === 'REJEITADO')) {
            $this->fila->falhar((int)$job['id'], self::resumoErro($saida), $saida);
            return 'FALHOU';
        }

        $resumoDoc = [
            'status' => $doc['status'],
            'chave' => $doc['chave'],
            'cstat' => $doc['cstat'],
            'x_motivo' => $doc['x_motivo'],
        ];

        switch ($doc['status']) {
            case 'AUTORIZADO':
            case 'DENEGADO':
            case 'REJEITADO':
                // Resultado definitivo da SEFAZ (rejeitada: o consumidor corrige e reenvia).
                $this->fila->concluir((int)$job['id'], $resumoDoc, $saida);
                return (string)$doc['status'];

            default:
                // GERADO / ENVIADO / ERRO_ENVIO: resultado incerto ou falha técnica.
                // Reprocessar reenvia o mesmo XML; se já tinha chegado, cai em 204 e recupera o protocolo.
                $erro = (string)($doc['x_motivo'] ?? '') !== '' ? (string)$doc['x_motivo'] : self::resumoErro($saida);
                return $this->fila->reagendar($job, $erro, $saida, $resumoDoc) ? 'REAGENDADO' : 'FALHOU';
        }
    }

    /**
     * Linha mais informativa da saida dos scripts (para o campo erro do job).
     */
    private static function resumoErro(string $saida): string
    {
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $saida)), static function (string $l): bool {
            return $l !== '' && !str_starts_with($l, '===') && !str_starts_with($l, '>>>')
                && !preg_match('/^ERRO (ao gerar XML|no envio)\.$/u', $l)
                && !preg_match('/^(Pasta|Arquivos|OK|PULADOS|FALHAS|Relatório salvo):/u', $l);
        }));

        foreach ($linhas as $l) {
            if (preg_match('/(erro|falha|inválid|invalid|rejei|diverge|diferente|não|nao)/iu', $l)) {
                return mb_substr($l, 0, 500);
            }
        }
        return $linhas ? mb_substr($linhas[0], 0, 500) : 'Falha sem saída.';
    }
}
