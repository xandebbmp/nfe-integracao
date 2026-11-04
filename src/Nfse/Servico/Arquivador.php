<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use Xande\NfeIntegracao\Nfse\Armazenamento\ArmazenamentoArquivos;
use Xande\NfeIntegracao\Nfse\Repositorio\EventoRepositorio;

/**
 * Copia os XMLs de um documento (e dos eventos dele) para o armazenamento
 * externo configurado. Rodado pelo worker no job ARQUIVAR.
 *
 * Organizacao dos arquivos:
 *   {ambiente}/{cnpj}/{AAAA}/{MM}/{documento_id}/
 *       envio.xml                         DPS ou lote RPS assinado
 *       nfse.xml                          NFS-e autorizada
 *       evento-101101-{id}-pedido.xml     pedido de evento
 *       evento-101101-{id}-retorno.xml    evento registrado pelo fisco
 *
 * Pode rodar mais de uma vez (sobrescreve): e seguro repetir.
 */
final class Arquivador
{
    public function __construct(
        private ArmazenamentoArquivos $armazenamento,
        private EventoRepositorio $eventos
    ) {
    }

    /** @return int quantos arquivos foram gravados */
    public function arquivar(array $documento): int
    {
        $data = new DateTimeImmutable((string)($documento['data_autorizacao'] ?? $documento['criado_em'] ?? 'now'));
        $pasta = sprintf(
            '%s/%s/%s/%s',
            (int)$documento['tp_amb'] === 1 ? 'producao' : 'homologacao',
            $documento['emitente_cnpj'],
            $data->format('Y/m'),
            $documento['id']
        );

        $arquivos = [
            'envio.xml' => $documento['xml_enviado'] ?? null,
            'nfse.xml' => $documento['xml_autorizado'] ?? null,
        ];
        foreach ($this->eventos->listarDoDocumento($documento['id']) as $evento) {
            $base = "evento-{$evento['tipo_evento']}-{$evento['id']}";
            $arquivos["{$base}-pedido.xml"] = $evento['xml_enviado'] ?? null;
            $arquivos["{$base}-retorno.xml"] = $evento['xml_retorno'] ?? null;
        }

        $gravados = 0;
        foreach ($arquivos as $nome => $conteudo) {
            if ($conteudo === null || $conteudo === '') {
                continue;
            }
            $this->armazenamento->salvar("{$pasta}/{$nome}", (string)$conteudo);
            $gravados++;
        }

        return $gravados;
    }
}
