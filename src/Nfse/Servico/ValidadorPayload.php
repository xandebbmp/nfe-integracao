<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use Xande\NfeIntegracao\Nfse\Dominio\Padrao;

/**
 * Validacao de ENTRADA do JSON de um documento (antes de gravar).
 *
 * Aqui so conferimos o que e obrigatorio e o formato basico, com mensagens
 * claras para o sistema consumidor. As regras fiscais finas ficam por conta
 * do XSD e do proprio fisco.
 */
final class ValidadorPayload
{
    /**
     * @return list<string> erros encontrados (vazio = ok)
     */
    public static function validar(array $doc, string $padrao): array
    {
        $erros = [];

        if (!in_array($padrao, Padrao::TODOS, true)) {
            return ['padrao invalido. Use: ' . implode(', ', Padrao::TODOS) . '.'];
        }

        $referencia = $doc['referencia'] ?? null;
        if (!is_string($referencia) || trim($referencia) === '' || mb_strlen($referencia) > 60) {
            $erros[] = 'referencia e obrigatoria (texto de 1 a 60 caracteres, unica por documento no seu sistema).';
        }

        if (isset($doc['competencia']) && !self::dataValida((string)$doc['competencia'])) {
            $erros[] = 'competencia deve estar no formato AAAA-MM-DD.';
        }

        // --- Servico ---
        $servico = $doc['servico'] ?? null;
        if (!is_array($servico)) {
            $erros[] = 'servico e obrigatorio.';
            $servico = [];
        }
        $descricao = (string)($servico['descricao'] ?? '');
        if (trim($descricao) === '' || mb_strlen($descricao) > 2000) {
            $erros[] = 'servico.descricao e obrigatoria (ate 2000 caracteres).';
        }

        if ($padrao === Padrao::NACIONAL
            && !preg_match('/^\d{6}$/', (string)($servico['codigo_tributacao_nacional'] ?? ''))) {
            $erros[] = 'servico.codigo_tributacao_nacional e obrigatorio no Padrao Nacional (6 digitos, ex.: 010101).';
        }

        if ($padrao === Padrao::SALVADOR) {
            $item = preg_replace('/\D/', '', (string)($servico['item_lista_servico'] ?? '')) ?? '';
            if ($item === '' || strlen($item) > 5) {
                $erros[] = 'servico.item_lista_servico e obrigatorio em Salvador (ex.: 01.01).';
            }
        }

        // --- Valores ---
        $valores = $doc['valores'] ?? null;
        if (!is_array($valores)) {
            $erros[] = 'valores e obrigatorio.';
            $valores = [];
        }
        if (!self::numeroPositivo($valores['valor_servicos'] ?? null)) {
            $erros[] = 'valores.valor_servicos e obrigatorio e deve ser maior que zero.';
        }
        foreach (['aliquota_iss', 'deducoes', 'desconto_incondicionado', 'desconto_condicionado',
                  'valor_pis', 'valor_cofins', 'valor_inss', 'valor_ir', 'valor_csll', 'outras_retencoes'] as $campo) {
            if (isset($valores[$campo]) && (!is_numeric($valores[$campo]) || (float)$valores[$campo] < 0)) {
                $erros[] = "valores.{$campo} deve ser numero maior ou igual a zero.";
            }
        }
        if (isset($valores['aliquota_iss']) && is_numeric($valores['aliquota_iss']) && (float)$valores['aliquota_iss'] > 100) {
            $erros[] = 'valores.aliquota_iss e um percentual (ex.: 5 para 5%).';
        }
        if ($padrao === Padrao::SALVADOR && !isset($valores['aliquota_iss'])) {
            $erros[] = 'valores.aliquota_iss e obrigatoria em Salvador (percentual, ex.: 5).';
        }

        // --- Tomador ---
        $tomador = $doc['tomador'] ?? null;
        if ($padrao === Padrao::SALVADOR && !is_array($tomador)) {
            $erros[] = 'tomador e obrigatorio em Salvador.';
        }
        if (is_array($tomador)) {
            if (trim((string)($tomador['nome'] ?? '')) === '') {
                $erros[] = 'tomador.nome e obrigatorio quando o tomador e informado.';
            }
            $documento = preg_replace('/[^A-Za-z0-9]/', '', (string)($tomador['cpf_cnpj'] ?? '')) ?? '';
            if ($documento !== '' && !in_array(strlen($documento), [11, 14], true)) {
                $erros[] = 'tomador.cpf_cnpj deve ter 11 (CPF) ou 14 (CNPJ) caracteres.';
            }
            if ($padrao === Padrao::SALVADOR && $documento !== '' && !ctype_digit($documento)) {
                $erros[] = 'tomador.cpf_cnpj: Salvador ainda nao aceita CNPJ alfanumerico.';
            }
            $endereco = $tomador['endereco'] ?? null;
            if (is_array($endereco) && $padrao === Padrao::NACIONAL) {
                foreach (['logradouro', 'bairro', 'codigo_municipio', 'cep'] as $campo) {
                    if (trim((string)($endereco[$campo] ?? '')) === '') {
                        $erros[] = "tomador.endereco.{$campo} e obrigatorio quando o endereco e informado.";
                    }
                }
            }
        }

        // --- Substituicao (somente Nacional) ---
        if (isset($doc['substituicao'])) {
            if ($padrao !== Padrao::NACIONAL) {
                $erros[] = 'substituicao so existe no Padrao Nacional.';
            } elseif (!preg_match('/^\d{50}$/', (string)($doc['substituicao']['chave_substituida'] ?? ''))) {
                $erros[] = 'substituicao.chave_substituida deve ter 50 digitos.';
            } elseif (!in_array((string)($doc['substituicao']['codigo_motivo'] ?? ''), ['01', '02', '03', '04', '05', '99', '1', '2', '3', '4', '5'], true)) {
                $erros[] = 'substituicao.codigo_motivo invalido (01, 02, 03, 04, 05 ou 99).';
            }
        }

        return $erros;
    }

    private static function numeroPositivo(mixed $valor): bool
    {
        return is_numeric($valor) && (float)$valor > 0;
    }

    private static function dataValida(string $data): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $d !== false && $d->format('Y-m-d') === $data;
    }
}
