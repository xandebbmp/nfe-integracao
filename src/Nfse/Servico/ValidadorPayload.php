<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

/**
 * Validacao de ENTRADA do JSON de um documento.
 *
 * Cada erro sai no MESMO formato das mensagens do fisco, para o monitor e o
 * consumidor tratarem tudo igual:
 *
 *   [
 *     'codigo'   => 'CAMPO_OBRIGATORIO',          // tipo do problema
 *     'campo'    => 'servico.descricao',          // onde esta o problema
 *     'mensagem' => 'Descricao do servico ...',   // o que esta errado
 *     'correcao' => 'Informe o texto que ...',    // como corrigir
 *   ]
 *
 * Aqui so conferimos o obrigatorio e o formato basico. As regras fiscais
 * finas ficam por conta do XSD e do proprio fisco.
 *
 * Para adicionar uma regra: chame $this->erro(...) com uma correcao clara,
 * escrita para quem vai corrigir o cadastro no sistema de origem.
 */
final class ValidadorPayload
{
    public const CAMPO_OBRIGATORIO = 'CAMPO_OBRIGATORIO';
    public const FORMATO_INVALIDO = 'FORMATO_INVALIDO';
    public const VALOR_INVALIDO = 'VALOR_INVALIDO';
    public const NAO_SUPORTADO = 'NAO_SUPORTADO_PELO_PADRAO';

    /** @var list<array{codigo: string, campo: string, mensagem: string, correcao: string}> */
    private array $erros = [];

    private function __construct(private array $doc, private string $padrao)
    {
    }

    /**
     * @return list<array{codigo: string, campo: string, mensagem: string, correcao: string}>
     */
    public static function validar(array $doc, string $padrao): array
    {
        $validador = new self($doc, $padrao);
        $validador->referencia();
        $validador->competencia();
        $validador->servico();
        $validador->valores();
        $validador->tomador();
        $validador->substituicao();
        return $validador->erros;
    }

    /** Lista so com o texto das mensagens (util em logs e testes). */
    public static function textos(array $erros): array
    {
        return array_column($erros, 'mensagem');
    }

    // ---------------------------------------------------------------

    private function referencia(): void
    {
        $referencia = $this->doc['referencia'] ?? null;
        if (!is_string($referencia) || trim($referencia) === '') {
            $this->erro(self::CAMPO_OBRIGATORIO, 'referencia', 'Referencia do documento nao informada.',
                'Envie em "referencia" o identificador do documento no seu sistema (ex.: numero do pedido ou da OS). Ela evita nota duplicada.');
        } elseif (mb_strlen($referencia) > 60) {
            $this->erro(self::FORMATO_INVALIDO, 'referencia', 'Referencia com mais de 60 caracteres.',
                'Use um identificador com ate 60 caracteres.');
        }
    }

    private function competencia(): void
    {
        if (!isset($this->doc['competencia'])) {
            return;
        }
        $data = (string)$this->doc['competencia'];
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if ($d === false || $d->format('Y-m-d') !== $data) {
            $this->erro(self::FORMATO_INVALIDO, 'competencia', "Competencia '{$data}' invalida.",
                'Informe a data no formato AAAA-MM-DD (ex.: 2026-11-04) ou nao envie o campo para usar a data de hoje.');
        }
    }

    private function servico(): void
    {
        $servico = $this->doc['servico'] ?? null;
        if (!is_array($servico)) {
            $this->erro(self::CAMPO_OBRIGATORIO, 'servico', 'Dados do servico nao informados.',
                'Envie o objeto "servico" com a descricao e o codigo do servico.');
            return;
        }

        $descricao = trim((string)($servico['descricao'] ?? ''));
        if ($descricao === '') {
            $this->erro(self::CAMPO_OBRIGATORIO, 'servico.descricao', 'Descricao do servico nao informada.',
                'Informe o que foi prestado (discriminacao do servico), ate 2000 caracteres.');
        } elseif (mb_strlen($descricao) > 2000) {
            $this->erro(self::FORMATO_INVALIDO, 'servico.descricao', 'Descricao do servico com mais de 2000 caracteres.',
                'Resuma a descricao para no maximo 2000 caracteres.');
        }

        if ($this->padrao === 'NACIONAL') {
            $codigo = (string)($servico['codigo_tributacao_nacional'] ?? '');
            if ($codigo === '') {
                $this->erro(self::CAMPO_OBRIGATORIO, 'servico.codigo_tributacao_nacional', 'Codigo de tributacao nacional nao informado.',
                    'Informe o codigo de 6 digitos da lista de servicos nacional (ex.: 010101 para analise e desenvolvimento de sistemas). Confira no cadastro do produto/servico.');
            } elseif (!preg_match('/^\d{6}$/', $codigo)) {
                $this->erro(self::FORMATO_INVALIDO, 'servico.codigo_tributacao_nacional', "Codigo de tributacao nacional '{$codigo}' invalido.",
                    'O codigo deve ter exatamente 6 digitos, sem pontos (ex.: 01.01.01 vira 010101).');
            }
        }

        if ($this->padrao === 'SALVADOR') {
            $item = preg_replace('/\D/', '', (string)($servico['item_lista_servico'] ?? '')) ?? '';
            if ($item === '') {
                $this->erro(self::CAMPO_OBRIGATORIO, 'servico.item_lista_servico', 'Item da lista de servicos nao informado.',
                    'Salvador exige o item da LC 116 (ex.: 01.01). Confira no cadastro do servico.');
            } elseif (strlen($item) > 5) {
                $this->erro(self::FORMATO_INVALIDO, 'servico.item_lista_servico', 'Item da lista de servicos invalido.',
                    'Use o item da LC 116 no formato 00.00 (ex.: 01.01 ou 17.01).');
            }
        }
    }

    private function valores(): void
    {
        $valores = $this->doc['valores'] ?? null;
        if (!is_array($valores)) {
            $this->erro(self::CAMPO_OBRIGATORIO, 'valores', 'Valores do servico nao informados.',
                'Envie o objeto "valores" com pelo menos "valor_servicos".');
            return;
        }

        $valor = $valores['valor_servicos'] ?? null;
        if (!is_numeric($valor) || (float)$valor <= 0) {
            $this->erro(self::VALOR_INVALIDO, 'valores.valor_servicos', 'Valor dos servicos ausente ou igual a zero.',
                'Informe o valor total do servico, maior que zero, com ponto decimal (ex.: 1500.00).');
        }

        $opcionais = ['aliquota_iss', 'deducoes', 'desconto_incondicionado', 'desconto_condicionado',
            'valor_pis', 'valor_cofins', 'valor_inss', 'valor_ir', 'valor_csll', 'outras_retencoes'];
        foreach ($opcionais as $campo) {
            if (isset($valores[$campo]) && (!is_numeric($valores[$campo]) || (float)$valores[$campo] < 0)) {
                $this->erro(self::VALOR_INVALIDO, "valores.{$campo}", "Valor de {$campo} invalido.",
                    'Use numero maior ou igual a zero, com ponto decimal (ex.: 12.50).');
            }
        }

        $aliquota = $valores['aliquota_iss'] ?? null;
        if (is_numeric($aliquota) && (float)$aliquota > 100) {
            $this->erro(self::VALOR_INVALIDO, 'valores.aliquota_iss', "Aliquota de ISS {$aliquota} fora do esperado.",
                'A aliquota e um percentual: envie 5 para 5% (nao 0.05 nem 500).');
        }
        if ($this->padrao === 'SALVADOR' && $aliquota === null) {
            $this->erro(self::CAMPO_OBRIGATORIO, 'valores.aliquota_iss', 'Aliquota de ISS nao informada.',
                'Salvador exige a aliquota em percentual (ex.: 5 para 5%). Confira a aliquota do servico no cadastro municipal.');
        }

        if (is_numeric($valor) && is_numeric($valores['desconto_incondicionado'] ?? null)
            && (float)$valores['desconto_incondicionado'] >= (float)$valor) {
            $this->erro(self::VALOR_INVALIDO, 'valores.desconto_incondicionado', 'Desconto maior ou igual ao valor do servico.',
                'O desconto deve ser menor que o valor dos servicos.');
        }
    }

    private function tomador(): void
    {
        $tomador = $this->doc['tomador'] ?? null;

        if ($tomador === null) {
            if ($this->padrao === 'SALVADOR') {
                $this->erro(self::CAMPO_OBRIGATORIO, 'tomador', 'Tomador nao informado.',
                    'Salvador exige o tomador: envie ao menos "tomador.nome" e, se houver, "tomador.cpf_cnpj".');
            }
            return;
        }
        if (!is_array($tomador)) {
            $this->erro(self::FORMATO_INVALIDO, 'tomador', 'Tomador em formato invalido.', 'Envie "tomador" como objeto.');
            return;
        }

        if (trim((string)($tomador['nome'] ?? '')) === '') {
            $this->erro(self::CAMPO_OBRIGATORIO, 'tomador.nome', 'Nome/razao social do tomador nao informado.',
                'Preencha o nome ou a razao social do cliente no cadastro.');
        }

        $documento = preg_replace('/[^A-Za-z0-9]/', '', (string)($tomador['cpf_cnpj'] ?? '')) ?? '';
        if ($documento !== '' && !in_array(strlen($documento), [11, 14], true)) {
            $this->erro(self::FORMATO_INVALIDO, 'tomador.cpf_cnpj', "CPF/CNPJ do tomador '{$tomador['cpf_cnpj']}' com tamanho invalido.",
                'CPF tem 11 digitos e CNPJ tem 14 caracteres. Confira o cadastro do cliente.');
        }
        if ($this->padrao === 'SALVADOR' && $documento !== '' && !ctype_digit($documento)) {
            $this->erro(self::NAO_SUPORTADO, 'tomador.cpf_cnpj', 'Salvador ainda nao aceita CNPJ alfanumerico.',
                'Emita esta nota pelo Padrao Nacional ("padrao": "NACIONAL").');
        }

        $endereco = $tomador['endereco'] ?? null;
        if (is_array($endereco) && $this->padrao === 'NACIONAL') {
            $nomes = ['logradouro' => 'logradouro', 'bairro' => 'bairro', 'codigo_municipio' => 'codigo IBGE do municipio', 'cep' => 'CEP'];
            foreach ($nomes as $campo => $nome) {
                if (trim((string)($endereco[$campo] ?? '')) === '') {
                    $this->erro(self::CAMPO_OBRIGATORIO, "tomador.endereco.{$campo}", "Endereco do tomador sem {$nome}.",
                        "Complete o {$nome} no cadastro do cliente, ou nao envie o endereco (e opcional no Padrao Nacional).");
                }
            }
            $municipio = (string)($endereco['codigo_municipio'] ?? '');
            if ($municipio !== '' && !preg_match('/^\d{7}$/', $municipio)) {
                $this->erro(self::FORMATO_INVALIDO, 'tomador.endereco.codigo_municipio', "Codigo de municipio '{$municipio}' invalido.",
                    'Use o codigo IBGE de 7 digitos (ex.: Salvador = 2927408).');
            }
        }
    }

    private function substituicao(): void
    {
        $sub = $this->doc['substituicao'] ?? null;
        if ($sub === null) {
            return;
        }
        if ($this->padrao !== 'NACIONAL') {
            $this->erro(self::NAO_SUPORTADO, 'substituicao', 'Substituicao de NFS-e so existe no Padrao Nacional.',
                'Remova o grupo "substituicao" ou emita pelo Padrao Nacional.');
            return;
        }
        if (!preg_match('/^\d{50}$/', (string)($sub['chave_substituida'] ?? ''))) {
            $this->erro(self::FORMATO_INVALIDO, 'substituicao.chave_substituida', 'Chave da NFS-e substituida invalida.',
                'Informe a chave de acesso de 50 digitos da nota que sera substituida.');
        }
        if (!in_array(str_pad((string)($sub['codigo_motivo'] ?? ''), 2, '0', STR_PAD_LEFT), ['01', '02', '03', '04', '05', '99'], true)) {
            $this->erro(self::VALOR_INVALIDO, 'substituicao.codigo_motivo', 'Motivo da substituicao invalido.',
                'Use 01, 02, 03, 04, 05 ou 99 (outros), conforme a tabela do Padrao Nacional.');
        }
    }

    private function erro(string $codigo, string $campo, string $mensagem, string $correcao): void
    {
        $this->erros[] = compact('codigo', 'campo', 'mensagem', 'correcao');
    }
}
