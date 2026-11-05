<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Infra;

use Throwable;

/**
 * Busca nome, e-mail e endereco de um CNPJ na BrasilAPI (gratuita, publica,
 * dados da Receita Federal): https://brasilapi.com.br/api/cnpj/v1/{cnpj}
 *
 * Usada pelo emissor simples para completar o tomador sozinho.
 * Se a consulta falhar (fora do ar, CNPJ inexistente, sem internet),
 * devolve null e a pessoa digita o nome. Nunca impede a emissao.
 *
 * CPF nao e consultado (nao existe base publica, e dado pessoal).
 */
final class ConsultaCnpj
{
    public const URL = 'https://brasilapi.com.br/api/cnpj/v1/';

    public function __construct(private TransporteHttp $http)
    {
    }

    /**
     * @return array{nome: string, email: ?string, telefone: ?string, endereco: ?array}|null
     */
    public function buscar(string $cnpj): ?array
    {
        if (!preg_match('/^\d{14}$/', $cnpj)) {
            return null; // CNPJ alfanumerico (2026+) ainda nao existe na base publica
        }
        try {
            $resposta = $this->http->enviar('GET', self::URL . $cnpj, ['Accept' => 'application/json']);
        } catch (Throwable) {
            return null;
        }
        if ($resposta->status !== 200) {
            return null;
        }
        $d = json_decode($resposta->corpo, true);
        if (!is_array($d) || trim((string)($d['razao_social'] ?? '')) === '') {
            return null;
        }

        $endereco = null;
        $municipio = (string)($d['codigo_municipio_ibge'] ?? '');
        if (preg_match('/^\d{7}$/', $municipio) && trim((string)($d['logradouro'] ?? '')) !== '') {
            $endereco = [
                'logradouro' => trim(trim((string)($d['descricao_tipo_de_logradouro'] ?? '')) . ' ' . trim((string)$d['logradouro'])),
                'numero' => trim((string)($d['numero'] ?? '')) ?: 'S/N',
                'complemento' => trim((string)($d['complemento'] ?? '')),
                'bairro' => trim((string)($d['bairro'] ?? '')) ?: 'Centro',
                'codigo_municipio' => $municipio,
                'uf' => strtoupper(trim((string)($d['uf'] ?? ''))),
                'cep' => preg_replace('/\D/', '', (string)($d['cep'] ?? '')),
                'municipio' => trim((string)($d['municipio'] ?? '')),
            ];
            if (strlen($endereco['cep']) !== 8) {
                $endereco = null;
            }
        }

        $telefone = preg_replace('/\D/', '', (string)($d['ddd_telefone_1'] ?? '')) ?: null;
        $email = trim((string)($d['email'] ?? ''));

        return [
            'nome' => mb_substr(trim((string)$d['razao_social']), 0, 150),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null,
            'telefone' => $telefone !== null ? substr($telefone, 0, 20) : null,
            'endereco' => $endereco,
        ];
    }
}
