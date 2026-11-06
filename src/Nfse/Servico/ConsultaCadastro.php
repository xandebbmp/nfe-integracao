<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Infra\CurlTransporte;
use Xande\NfeIntegracao\Nfse\Infra\TransporteHttp;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Consultas publicas usadas para preencher o formulario do portal sozinho:
 *
 *   cnpj()  razao social, e-mail e endereco (BrasilAPI, dados da Receita)
 *   cep()   endereco + codigo IBGE do municipio (ViaCEP)
 *
 * A consulta e feita pelo SERVIDOR (nao pelo navegador) e guardada em
 * portal_consultas: CNPJ por 30 dias, CEP por 180 dias. Assim o portal nao
 * depende da API publica a cada digitacao e nao estoura o limite dela.
 *
 * Se a API publica estiver fora do ar, devolve ok=false com mensagem
 * amigavel: a pessoa preenche na mao e a emissao continua funcionando.
 */
final class ConsultaCadastro
{
    private const DIAS_CNPJ = 30;
    private const DIAS_CEP = 180;

    private PortalRepositorio $portal;
    private TransporteHttp $http;
    private string $urlCnpj;
    private string $urlCep;

    public function __construct(PDO $pdo, array $cfg, ?TransporteHttp $http = null)
    {
        $this->portal = new PortalRepositorio($pdo);
        $this->http = $http ?? new CurlTransporte(null, 8);
        $this->urlCnpj = (string)($cfg['portal']['url_cnpj'] ?? 'https://brasilapi.com.br/api/cnpj/v1/{cnpj}');
        $this->urlCep = (string)($cfg['portal']['url_cep'] ?? 'https://viacep.com.br/ws/{cep}/json/');
    }

    public function cnpj(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj) ?? '';
        if (!Documentos::cnpjValido($cnpj)) {
            return self::falha(422, 'CNPJ_INVALIDO', 'CNPJ inválido. Confira os 14 números.');
        }

        $dados = $this->portal->consultaEmCache('CNPJ', $cnpj, self::DIAS_CNPJ);
        if ($dados === null) {
            $resposta = $this->http->enviar('GET', str_replace('{cnpj}', $cnpj, $this->urlCnpj), ['Accept' => 'application/json']);
            if ($resposta->status === 404) {
                return self::falha(404, 'CNPJ_NAO_ENCONTRADO', 'CNPJ não encontrado na Receita Federal. Preencha os dados à mão.');
            }
            $json = $resposta->json();
            if (!$resposta->sucesso() || $json === null) {
                return self::falha(503, 'CONSULTA_INDISPONIVEL', 'A consulta de CNPJ está fora do ar agora. Preencha os dados à mão.');
            }
            $dados = self::dadosCnpj($json);
            $this->portal->guardarConsulta('CNPJ', $cnpj, $dados);
        }

        return ['ok' => true, 'tipo' => 'CNPJ'] + $dados;
    }

    public function cep(string $cep): array
    {
        $cep = preg_replace('/\D/', '', $cep) ?? '';
        if (strlen($cep) !== 8) {
            return self::falha(422, 'CEP_INVALIDO', 'O CEP tem 8 números.');
        }

        $dados = $this->portal->consultaEmCache('CEP', $cep, self::DIAS_CEP);
        if ($dados === null) {
            $resposta = $this->http->enviar('GET', str_replace('{cep}', $cep, $this->urlCep), ['Accept' => 'application/json']);
            $json = $resposta->json();
            if (!$resposta->sucesso() || $json === null) {
                return self::falha(503, 'CONSULTA_INDISPONIVEL', 'A consulta de CEP está fora do ar agora. Preencha o endereço à mão.');
            }
            // ViaCEP responde 200 com {"erro": true} quando o CEP nao existe.
            if (!empty($json['erro'])) {
                return self::falha(404, 'CEP_NAO_ENCONTRADO', 'CEP não encontrado. Confira os números.');
            }
            $dados = [
                'cep' => $cep,
                'logradouro' => (string)($json['logradouro'] ?? ''),
                'bairro' => (string)($json['bairro'] ?? ''),
                'cidade' => (string)($json['localidade'] ?? ''),
                'uf' => (string)($json['uf'] ?? ''),
                'codigo_municipio' => (string)($json['ibge'] ?? ''),
            ];
            $this->portal->guardarConsulta('CEP', $cep, $dados);
        }

        return ['ok' => true, 'tipo' => 'CEP'] + $dados;
    }

    /** Converte a resposta da BrasilAPI no formato do formulario. */
    private static function dadosCnpj(array $j): array
    {
        $logradouro = trim(((string)($j['descricao_tipo_de_logradouro'] ?? '')) . ' ' . ((string)($j['logradouro'] ?? '')));
        return [
            'cpf_cnpj' => preg_replace('/\D/', '', (string)($j['cnpj'] ?? '')),
            'nome' => (string)($j['razao_social'] ?? ''),
            'nome_fantasia' => (string)($j['nome_fantasia'] ?? ''),
            'email' => mb_strtolower((string)($j['email'] ?? '')),
            'telefone' => preg_replace('/\D/', '', (string)($j['ddd_telefone_1'] ?? '')),
            'situacao' => (string)($j['descricao_situacao_cadastral'] ?? ''),
            'cep' => preg_replace('/\D/', '', (string)($j['cep'] ?? '')),
            'logradouro' => $logradouro,
            'numero' => (string)($j['numero'] ?? ''),
            'complemento' => (string)($j['complemento'] ?? ''),
            'bairro' => (string)($j['bairro'] ?? ''),
            'cidade' => (string)($j['municipio'] ?? ''),
            'uf' => (string)($j['uf'] ?? ''),
            'codigo_municipio' => (string)($j['codigo_municipio_ibge'] ?? ''),
        ];
    }

    private static function falha(int $http, string $codigo, string $mensagem): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
    }
}
