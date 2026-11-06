<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;

/**
 * Lista "Pronta para emitir" da ficha da empresa (painel admin).
 *
 * Confere o cadastro e diz, em portugues simples, o que ainda falta para a
 * empresa emitir cada tipo de nota contratado. Nao emite nada, nao abre o
 * certificado e nao chama a prefeitura: so olha o que ja esta gravado.
 *
 * Cada item tem um nivel:
 *   OK      - tudo certo
 *   ATENCAO - emite, mas merece cuidado (ex.: certificado vence logo)
 *   FALTA   - a nota vai ser recusada enquanto nao resolver
 *
 * Para incluir uma conferencia nova: acrescente um item no grupo certo
 * (geral(), nacional(), salvador() ou nfe()) e um teste em casos_clientes.php.
 */
final class ProntidaoEmissao
{
    public const OK = 'OK';
    public const ATENCAO = 'ATENCAO';
    public const FALTA = 'FALTA';

    /** Certificado com ate esta quantidade de dias aparece como ATENCAO. */
    public const DIAS_AVISO_CERTIFICADO = 30;
    /** Liberacao (vencimento do plano) com ate estes dias aparece como ATENCAO. */
    public const DIAS_AVISO_LIBERACAO = 7;

    public const CODIGO_SALVADOR = '2927408';

    private function __construct()
    {
    }

    /**
     * @param array $c          empresa como sai do AdministracaoClientes (com situacao e numeracao)
     * @param array $contexto   tp_amb, usuarios_portal (null = portal nao instalado),
     *                          certificado_no_arquivo (bool), hoje (AAAA-MM-DD, para os testes)
     * @return array{pronta: bool, faltam: int, atencao: int, ambiente: string, grupos: list<array{titulo: string, itens: list<array>}>}
     */
    public static function avaliar(array $c, array $contexto): array
    {
        $hoje = (string)($contexto['hoje'] ?? date('Y-m-d'));
        $padroes = $c['padroes_permitidos'] ?? [];

        $grupos = [['titulo' => 'Geral', 'itens' => self::geral($c, $contexto, $hoje)]];
        if (in_array(Padrao::NACIONAL, $padroes, true)) {
            $grupos[] = ['titulo' => 'NFS-e Nacional', 'itens' => self::nacional($c)];
        }
        if (in_array(Padrao::SALVADOR, $padroes, true)) {
            $grupos[] = ['titulo' => 'NFS-e Salvador', 'itens' => self::salvador($c)];
        }
        if (in_array(Padrao::NFE, $padroes, true)) {
            $grupos[] = ['titulo' => 'NF-e (produtos)', 'itens' => self::nfe($c)];
        }

        $faltam = 0;
        $atencao = 0;
        foreach ($grupos as $g) {
            foreach ($g['itens'] as $i) {
                $faltam += $i['nivel'] === self::FALTA ? 1 : 0;
                $atencao += $i['nivel'] === self::ATENCAO ? 1 : 0;
            }
        }

        return [
            'pronta' => $faltam === 0,
            'faltam' => $faltam,
            'atencao' => $atencao,
            'ambiente' => (int)($contexto['tp_amb'] ?? 2) === 1
                ? 'Produção: as notas emitidas têm valor fiscal.'
                : 'Homologação (teste): as notas emitidas não têm valor fiscal.',
            'grupos' => $grupos,
        ];
    }

    // ------------------------------------------------------------- grupos

    private static function geral(array $c, array $contexto, string $hoje): array
    {
        $itens = [];

        // Emissao liberada (cobranca)
        if (($c['situacao'] ?? '') === ControleEmissao::LIBERADA) {
            $dias = !empty($c['liberado_ate']) ? self::dias($hoje, (string)$c['liberado_ate']) : null;
            $itens[] = $dias !== null && $dias <= self::DIAS_AVISO_LIBERACAO
                ? self::item(self::ATENCAO, 'Emissão liberada, mas a liberação vence em ' . self::data((string)$c['liberado_ate']) . self::emDias($dias) . '.',
                    'Se o cliente renovou, mude "Liberado até" em Controle de emissão.')
                : self::item(self::OK, 'Emissão liberada.');
        } else {
            $itens[] = self::item(self::FALTA, (string)($c['situacao_mensagem'] ?? 'Emissão bloqueada.'),
                'Ajuste em Controle de emissão (emissão liberada, vencimento ou limite do mês).');
        }

        if (($c['padroes_permitidos'] ?? []) === []) {
            $itens[] = self::item(self::FALTA, 'Nenhum tipo de nota contratado.', 'Marque os tipos de nota em Controle de emissão.');
        }

        // Certificado digital A1
        $itens[] = self::certificado($c, !empty($contexto['certificado_no_arquivo']));

        // Alguem consegue emitir? (sistema do cliente pela API ou pessoa no portal)
        $usuarios = $contexto['usuarios_portal'] ?? null;
        $formas = [];
        if (!empty($c['tem_token'])) {
            $formas[] = 'pelo sistema do cliente (token da API)';
        }
        if ($usuarios !== null && $usuarios > 0) {
            $formas[] = $usuarios === 1 ? 'por 1 pessoa no portal' : "por {$usuarios} pessoas no portal";
        }
        $itens[] = $formas !== []
            ? self::item(self::OK, 'Pode emitir ' . implode(' e ', $formas) . '.')
            : self::item(self::FALTA, 'Ninguém consegue emitir ainda.',
                $usuarios === null ? 'Gere o token da API logo abaixo.' : 'Cadastre uma pessoa em "Portal do cliente" ou gere o token da API.');

        if (trim((string)($c['email'] ?? '')) === '') {
            $itens[] = self::item(self::ATENCAO, 'Sem e-mail da empresa.', 'Preencha o e-mail em Dados da empresa: ele aparece nas notas.');
        }

        return $itens;
    }

    private static function nacional(array $c): array
    {
        $itens = [self::municipio($c)];

        $opcao = (int)($c['opcao_simples_nacional'] ?? 1);
        if ($opcao === 3) {
            $regime = $c['regime_apuracao_simples'] ?? null;
            $percentual = $c['percentual_tributos_simples'] ?? null;
            if (empty($regime)) {
                $itens[] = self::item(self::FALTA, 'Simples ME/EPP sem o regime de apuração.',
                    'Escolha o "Regime de apuração (ME/EPP)" em Dados da empresa. Quem informa é o contador.');
            }
            if ($percentual === null || $percentual === '') {
                $itens[] = self::item(self::FALTA, 'Simples ME/EPP sem o % de tributos do Simples.',
                    'Preencha "% tributos do Simples" em Dados da empresa. Sem ele a prefeitura recusa a nota. Quem informa é o contador.');
            }
            if (!empty($regime) && $percentual !== null && $percentual !== '') {
                $itens[] = self::item(self::OK, 'Simples ME/EPP: regime de apuração ' . (int)$regime . ' e '
                    . self::numero((float)$percentual) . '% de tributos.');
            }
        } else {
            $itens[] = self::item(self::OK, 'Regime: ' . ($opcao === 2 ? 'MEI.' : 'não optante do Simples.'));
        }

        $itens[] = self::numeracao($c, Padrao::NACIONAL);
        return $itens;
    }

    private static function salvador(array $c): array
    {
        $itens = [self::municipio($c)];
        $codigo = preg_replace('/\D/', '', (string)($c['codigo_municipio'] ?? ''));
        if ($codigo !== '' && $codigo !== self::CODIGO_SALVADOR) {
            $itens[] = self::item(self::ATENCAO, 'O município do cadastro não é Salvador.',
                'A NFS-e Salvador é para empresas de Salvador (código ' . self::CODIGO_SALVADOR . '). Confira o código ou o tipo contratado.');
        }
        $itens[] = trim((string)($c['inscricao_municipal'] ?? '')) === ''
            ? self::item(self::FALTA, 'Sem inscrição municipal.', 'Preencha a "Inscrição municipal (NFS-e)" em Dados da empresa. Salvador exige.')
            : self::item(self::OK, 'Inscrição municipal ' . $c['inscricao_municipal'] . '.');
        $itens[] = self::numeracao($c, Padrao::SALVADOR);
        return $itens;
    }

    private static function nfe(array $c): array
    {
        $itens = [];
        $uf = strtoupper(trim((string)($c['uf'] ?? '')));
        $itens[] = preg_match('/^[A-Z]{2}$/', $uf)
            ? self::item(self::OK, "UF {$uf}.")
            : self::item(self::FALTA, 'Sem UF.', 'Preencha a "UF (NF-e)" em Dados da empresa.');
        $itens[] = trim((string)($c['ie'] ?? '')) === ''
            ? self::item(self::FALTA, 'Sem inscrição estadual.', 'Preencha a "Inscrição estadual (NF-e)" em Dados da empresa.')
            : self::item(self::OK, 'Inscrição estadual ' . $c['ie'] . '.');
        $itens[] = self::numeracao($c, Padrao::NFE);
        return $itens;
    }

    // ------------------------------------------------------------- itens

    private static function certificado(array $c, bool $noArquivo): array
    {
        if (empty($c['tem_certificado'])) {
            return $noArquivo
                ? self::item(self::OK, 'Certificado no arquivo de configuração do servidor.',
                    'A validade não aparece aqui. Para acompanhar, envie o .pfx pelo painel.')
                : self::item(self::FALTA, 'Sem certificado digital.', 'Envie o certificado A1 (.pfx) da empresa em "Certificado digital".');
        }
        $dias = $c['certificado_dias_restantes'] ?? null;
        $validade = !empty($c['certificado_validade']) ? self::data((string)$c['certificado_validade']) : '';
        if ($dias === null) {
            return self::item(self::ATENCAO, 'Certificado enviado, mas sem data de validade.', 'Envie o .pfx de novo para conferir a validade.');
        }
        if ($dias < 0) {
            return self::item(self::FALTA, "Certificado venceu em {$validade}.", 'Peça o certificado novo à empresa e envie em "Certificado digital".');
        }
        if ($dias <= self::DIAS_AVISO_CERTIFICADO) {
            return self::item(self::ATENCAO, "Certificado vence em {$validade}" . self::emDias($dias) . '.',
                'Avise a empresa para renovar antes de vencer.');
        }
        return self::item(self::OK, "Certificado válido até {$validade}.");
    }

    private static function municipio(array $c): array
    {
        return preg_match('/^\d{7}$/', (string)($c['codigo_municipio'] ?? ''))
            ? self::item(self::OK, 'Código do município ' . $c['codigo_municipio'] . '.')
            : self::item(self::FALTA, 'Código IBGE do município inválido.', 'Preencha os 7 números em Dados da empresa. Salvador = ' . self::CODIGO_SALVADOR . '.');
    }

    /**
     * Numeracao: se a serie ainda nao foi usada aqui, a primeira nota sai com
     * o numero 1. Se a empresa ja emitia em outro sistema, isso repete numero.
     */
    private static function numeracao(array $c, string $padrao): array
    {
        $serie = (string)($c['series_padrao'][$padrao] ?? '1');
        foreach ($c['numeracao'] ?? [] as $n) {
            if ($n['padrao'] === $padrao && (string)$n['serie'] === $serie) {
                return self::item(self::OK, "Série {$serie}: a próxima nota sai com o nº " . ((int)$n['ultimo_numero'] + 1) . '.');
            }
        }
        return self::item(self::ATENCAO, "Série {$serie} ainda não usada: a primeira nota sai com o nº 1.",
            'Se a empresa já emitia em outro sistema, informe o último número usado em "Numeração", mais abaixo.');
    }

    // ------------------------------------------------------------- apoio

    private static function item(string $nivel, string $texto, ?string $resolver = null): array
    {
        return ['nivel' => $nivel, 'texto' => $texto, 'resolver' => $nivel === self::OK && $resolver === null ? null : $resolver];
    }

    private static function dias(string $de, string $ate): int
    {
        return (int)(new DateTimeImmutable(substr($de, 0, 10)))->diff(new DateTimeImmutable(substr($ate, 0, 10)))->format('%r%a');
    }

    private static function emDias(int $dias): string
    {
        return match (true) {
            $dias <= 0 => ' (hoje)',
            $dias === 1 => ' (amanhã)',
            default => " (daqui a {$dias} dias)",
        };
    }

    private static function data(string $iso): string
    {
        return (new DateTimeImmutable(substr($iso, 0, 10)))->format('d/m/Y');
    }

    private static function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
