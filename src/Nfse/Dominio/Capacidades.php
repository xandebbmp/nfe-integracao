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
     *   cancelamento_externo: bool,
     *   pdf: string,
     *   observacoes: list<string>
     * }
     *
     * cancelamento_externo = true quando o padrao NAO cancela por webservice
     * e a API aceita registrar o cancelamento feito no portal da prefeitura
     * (rota /v1/nfse/cancelamento_externo.php).
     */
    public static function doPadrao(string $padrao): array
    {
        return match ($padrao) {
            Padrao::NACIONAL => self::nacional(),
            Padrao::SALVADOR => self::salvador(),
            Padrao::NFE => self::nfe(),
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
            'cancelamento_externo' => false,
            'pdf' => 'OFICIAL_DANFSE',
            'observacoes' => [
                'Codigos de motivo do cancelamento (101101): 1=Erro na emissao, 2=Servico nao prestado, 9=Outros.',
                'Motivo (texto) deve ter entre 15 e 255 caracteres.',
                'Fora do prazo de cancelamento use 101103 (solicitacao de analise fiscal).',
                'Substituicao: emita uma nova NFS-e informando "substituicao.chave_substituida".',
            ],
        ];
    }

    private static function nfe(): array
    {
        return [
            'emissao' => 'SINCRONA',
            'consulta' => true,
            'eventos' => [
                TipoEvento::NFE_CANCELAMENTO => TipoEvento::DESCRICOES[TipoEvento::NFE_CANCELAMENTO],
                TipoEvento::NFE_CARTA_CORRECAO => TipoEvento::DESCRICOES[TipoEvento::NFE_CARTA_CORRECAO],
            ],
            'eventos_nao_suportados' => [
                '110112' => 'Cancelamento por substituicao (NFC-e): nao se aplica a NF-e modelo 55.',
                'INUTILIZACAO' => 'Inutilizacao de numeracao: continua pela rota antiga /v1/nfe/inutilizar_padrao.php.',
                '210200/210210/210220/210240' => 'Manifestacao do destinatario: fora do escopo desta API (ver Distribuicao DF-e).',
            ],
            'cancelamento_externo' => false,
            'pdf' => 'OFICIAL_DANFE',
            'observacoes' => [
                'Envie a nota no mesmo formato JSON de sempre (ide, emit, dest, itens, totais, transp, pag, infAdic, reformaTributaria) + "referencia".',
                'A API define o numero (ide.nNF) e o ambiente (ide.tpAmb). A serie vem de ide.serie (padrao da configuracao).',
                'emit.CNPJ precisa ser um emitente liberado para o seu sistema.',
                'Cancelamento (110111): informe "motivo" com 15 a 255 caracteres; dentro do prazo da SEFAZ (em geral 24h).',
                'Carta de Correcao (110110): informe "motivo" com o texto da correcao (15 a 1000 caracteres). Ate 20 por nota.',
                'DENEGADO: a nota nao tem valor e o numero foi consumido; nao pode ser reenviada.',
            ],
        ];
    }

    private static function salvador(): array
    {
        $semWebservice = 'Salvador nao oferece este servico por webservice. Solicite no portal da NFS-e da prefeitura (Cancelamento > Gerar Pedido) ou por processo administrativo e, depois de deferido, registre na API em /v1/nfse/cancelamento_externo.php.';

        return [
            'emissao' => 'ASSINCRONA',
            'consulta' => true,
            'eventos' => [],
            'eventos_nao_suportados' => [
                TipoEvento::CANCELAMENTO => $semWebservice,
                TipoEvento::SOLICITACAO_ANALISE_CANCELAMENTO => $semWebservice,
                TipoEvento::CANCELAMENTO_POR_SUBSTITUICAO => $semWebservice,
            ],
            'cancelamento_externo' => true,
            'pdf' => 'ESPELHO_GERADO_PELA_API',
            'observacoes' => [
                'Emissao assincrona: o documento fica PROCESSANDO ate a prefeitura processar o lote.',
                'Um RPS por lote: cada documento tem resultado proprio.',
                'Tomador obrigatorio (nome). CNPJ/CPF apenas numericos.',
                'Informe servico.item_lista_servico (LC 116) e valores.aliquota_iss em percentual (ex.: 5).',
                'PDF: espelho auxiliar com link de consulta no portal (quando salvador.url_visualizacao estiver configurada).',
            ],
        ];
    }
}
