<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * O que cada padrao de NFS-e suporta. Fonte unica usada pela API
 * (/v1/nfse/capacidades.php) e pela validacao de eventos.
 *
 * Ao implementar um evento novo em um provedor, atualize aqui tambem.
 */
final class Capacidades
{
    private function __construct()
    {
    }

    /**
     * @return array{
     *   emissao: string,
     *   consulta: bool,
     *   eventos: array<string, string>,
     *   eventos_nao_suportados: array<string, string>,
     *   pdf: string,
     *   observacoes: list<string>
     * }
     */
    public static function doPadrao(string $padrao): array
    {
        return match ($padrao) {
            Padrao::NACIONAL => self::nacional(),
            Padrao::SALVADOR => self::salvador(),
            default => throw new \InvalidArgumentException("Padrao desconhecido: {$padrao}"),
        };
    }

    public static function todos(): array
    {
        $saida = [];
        foreach (Padrao::TODOS as $padrao) {
            $saida[$padrao] = self::doPadrao($padrao);
        }
        return $saida;
    }

    private static function nacional(): array
    {
        return [
            'emissao' => 'SINCRONA',
            'consulta' => true,
            'eventos' => [
                TipoEvento::CANCELAMENTO => TipoEvento::DESCRICOES[TipoEvento::CANCELAMENTO],
                TipoEvento::SOLICITACAO_ANALISE_CANCELAMENTO => TipoEvento::DESCRICOES[TipoEvento::SOLICITACAO_ANALISE_CANCELAMENTO],
            ],
            'eventos_nao_suportados' => [
                TipoEvento::CANCELAMENTO_POR_SUBSTITUICAO => 'Gerado automaticamente pelo Sefin ao emitir uma NFS-e com o grupo "substituicao".',
                '202201' => 'Confirmacao do prestador: prevista, ainda nao implementada.',
                '203202' => 'Confirmacao do tomador: evento do tomador, nao do emitente.',
                '202205' => 'Rejeicao do prestador: prevista, ainda nao implementada.',
                '105104' => 'Cancelamento deferido: evento exclusivo do fisco.',
                '105105' => 'Cancelamento indeferido: evento exclusivo do fisco.',
                '305101' => 'Cancelamento de oficio: evento exclusivo do fisco.',
            ],
            'pdf' => 'OFICIAL_DANFSE',
            'observacoes' => [
                'Codigos de motivo do cancelamento (101101): 1=Erro na emissao, 2=Servico nao prestado, 9=Outros.',
                'Motivo (texto) deve ter entre 15 e 255 caracteres.',
                'Fora do prazo de cancelamento use 101103 (solicitacao de analise fiscal).',
                'Substituicao: emita uma nova NFS-e informando "substituicao.chave_substituida".',
            ],
        ];
    }

    private static function salvador(): array
    {
        $semWebservice = 'Salvador nao oferece este servico por webservice. Solicite no portal da NFS-e da prefeitura (Cancelamento > Gerar Pedido) ou por processo administrativo.';

        return [
            'emissao' => 'ASSINCRONA',
            'consulta' => true,
            'eventos' => [],
            'eventos_nao_suportados' => [
                TipoEvento::CANCELAMENTO => $semWebservice,
                TipoEvento::SOLICITACAO_ANALISE_CANCELAMENTO => $semWebservice,
                TipoEvento::CANCELAMENTO_POR_SUBSTITUICAO => $semWebservice,
            ],
            'pdf' => 'ESPELHO_GERADO_PELA_API',
            'observacoes' => [
                'Emissao assincrona: o documento fica PROCESSANDO ate a prefeitura processar o lote.',
                'Um RPS por lote: cada documento tem resultado proprio.',
                'Tomador obrigatorio (nome). CNPJ/CPF apenas numericos.',
                'Informe servico.item_lista_servico (LC 116) e valores.aliquota_iss em percentual (ex.: 5).',
            ],
        ];
    }
}
