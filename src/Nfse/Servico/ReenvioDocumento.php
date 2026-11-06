<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\StatusNfse;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\DocumentoRepositorio;

/**
 * Botao "Reenviar" de uma nota rejeitada ou com erro (portal e monitor).
 *
 * Manda de novo os MESMOS dados guardados da nota, pelo mesmo caminho da API
 * (RecebimentoLote): mesma referencia, entao nao duplica; a nota rejeitada
 * nao gerou NFS-e, entao reaproveita o mesmo numero da DPS/RPS. O worker
 * envia em seguida.
 *
 * So reenvia no ambiente em que a nota foi feita (producao fica em producao).
 */
final class ReenvioDocumento
{
    public function __construct(private PDO $pdo, private array $cfg)
    {
    }

    /**
     * @param string|null $cnpjDono portal: so notas desta empresa; painel: null (qualquer uma)
     * @return array ok=true com mensagem e producao, ou ok=false com http/codigo/mensagem
     */
    public function reenviar(string $id, ?string $cnpjDono, ?string $ip): array
    {
        $doc = (new DocumentoRepositorio($this->pdo))->buscar($id);
        if ($doc === null || ($cnpjDono !== null && $doc['emitente_cnpj'] !== $cnpjDono)) {
            return self::falha(404, 'NOTA_NAO_ENCONTRADA', 'Nota não encontrada.');
        }
        if (!in_array($doc['status'], StatusNfse::PERMITE_REENVIO, true)) {
            return self::falha(409, 'NAO_PODE_REENVIAR', 'Só nota rejeitada ou com erro pode ser reenviada.');
        }
        if ((int)$doc['tp_amb'] !== (int)$this->cfg['tpAmb']) {
            return self::falha(409, 'AMBIENTE_DIFERENTE', (int)$doc['tp_amb'] === 1
                ? 'Esta nota é de PRODUÇÃO, mas o sistema está em homologação agora. Volte para produção em Sistema > Ambiente de emissão para reenviar.'
                : 'Esta nota é de homologação, mas o sistema está em PRODUÇÃO agora. Emita uma nota nova se quiser.');
        }

        // Nota do portal: o "consumidor" e a propria empresa.
        $cfg = $this->cfg;
        $consumidor = (string)$doc['consumidor'];
        if ($consumidor === $doc['emitente_cnpj']) {
            $cliente = (new ClienteRepositorio($this->pdo))->buscar($consumidor);
            if ($cliente === null) {
                return self::falha(404, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não encontrada.');
            }
            $cfg = (new PortalEmissao($this->pdo, $this->cfg))->comConsumidor($consumidor, $cliente);
        }

        // Os dados guardados, presos a ESTA nota (mesma referencia, padrao e emitente).
        $payload = array_replace((array)$doc['payload'], [
            'referencia' => $doc['referencia'], 'padrao' => $doc['padrao'], 'emitente_cnpj' => $doc['emitente_cnpj'],
        ]);
        $r = (new RecebimentoLote($this->pdo, $cfg))->receber($consumidor, ['documentos' => [$payload]]);
        if (!$r['ok']) {
            return self::falha(422, 'NAO_REENVIADO', (string)$r['erro']);
        }
        $item = $r['documentos'][0];
        if ($item['situacao_recebimento'] !== RecebimentoLote::REENVIADO) {
            $erro = $item['erros'][0]['mensagem'] ?? 'A nota não pôde ser reenviada.';
            return self::falha(422, 'NAO_REENVIADO', (string)$erro);
        }

        (new ClienteRepositorio($this->pdo))->registrarLog($doc['emitente_cnpj'], 'NOTA_REENVIADA',
            ['id' => $doc['id'], 'serie' => $doc['serie'], 'numero' => $doc['numero'], 'status_anterior' => $doc['status']], $ip);

        $producao = (int)$doc['tp_amb'] === 1;
        return ['ok' => true, 'id' => $doc['id'], 'producao' => $producao,
            'mensagem' => 'Nota reenviada: está na fila e o worker manda em seguida.'];
    }

    private static function falha(int $http, string $codigo, string $mensagem): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
