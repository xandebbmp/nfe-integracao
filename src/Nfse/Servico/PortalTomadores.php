<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Tela "Tomadores" do portal: os clientes da empresa (quem recebe a nota).
 * Eles ja sao salvos sozinhos a cada emissao; aqui a pessoa confere,
 * corrige o cadastro ou tira da lista.
 */
final class PortalTomadores
{
    private PortalRepositorio $portal;

    public function __construct(PDO $pdo)
    {
        $this->portal = new PortalRepositorio($pdo);
    }

    public function listar(string $cnpj, string $busca): array
    {
        return $this->portal->tomadores($cnpj, mb_substr(trim($busca), 0, 60), 200);
    }

    public function salvar(string $cnpj, array $e): array
    {
        $t = [
            'cpf_cnpj' => preg_replace('/\D/', '', (string)($e['cpf_cnpj'] ?? '')) ?? '',
            'nome' => trim((string)($e['nome'] ?? '')),
            'email' => mb_strtolower(trim((string)($e['email'] ?? ''))),
            'telefone' => preg_replace('/\D/', '', (string)($e['telefone'] ?? '')) ?? '',
            'cep' => preg_replace('/\D/', '', (string)($e['cep'] ?? '')) ?? '',
            'logradouro' => trim((string)($e['logradouro'] ?? '')),
            'numero' => trim((string)($e['numero'] ?? '')),
            'complemento' => trim((string)($e['complemento'] ?? '')),
            'bairro' => trim((string)($e['bairro'] ?? '')),
            'codigo_municipio' => preg_replace('/\D/', '', (string)($e['codigo_municipio'] ?? '')) ?? '',
            'cidade' => trim((string)($e['cidade'] ?? '')),
            'uf' => strtoupper(trim((string)($e['uf'] ?? ''))),
        ];

        $erros = [];
        if (!Documentos::valido($t['cpf_cnpj'])) {
            $erros[] = ['campo' => 'cpf_cnpj', 'mensagem' => 'CPF ou CNPJ inválido. Confira os números.'];
        }
        if (mb_strlen($t['nome']) < 2 || mb_strlen($t['nome']) > 150) {
            $erros[] = ['campo' => 'nome', 'mensagem' => 'Informe o nome ou a razão social (até 150 caracteres).'];
        }
        if ($t['email'] !== '' && !filter_var($t['email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = ['campo' => 'email', 'mensagem' => 'E-mail inválido. Corrija ou deixe em branco.'];
        }
        if ($t['cep'] !== '' && strlen($t['cep']) !== 8) {
            $erros[] = ['campo' => 'cep', 'mensagem' => 'O CEP tem 8 números.'];
        }
        if ($t['uf'] !== '' && !preg_match('/^[A-Z]{2}$/', $t['uf'])) {
            $erros[] = ['campo' => 'uf', 'mensagem' => 'Use a sigla do estado (ex.: BA).'];
        }
        if ($t['codigo_municipio'] !== '' && strlen($t['codigo_municipio']) !== 7) {
            $t['codigo_municipio'] = '';
        }
        if ($erros !== []) {
            return ['ok' => false, 'http' => 422, 'codigo' => 'DADOS_INVALIDOS', 'mensagem' => 'Confira os campos do tomador.', 'erros' => $erros];
        }

        // Campo vazio vira null no banco.
        $t = array_map(static fn(string $v): ?string => $v === '' ? null : $v, $t);
        $this->portal->salvarTomador($cnpj, $t, false);
        return ['ok' => true, 'mensagem' => 'Tomador salvo.', 'tomador' => $this->portal->tomador($cnpj, (string)$t['cpf_cnpj'])];
    }

    public function remover(string $cnpj, string $cpfCnpj): array
    {
        $doc = preg_replace('/\D/', '', $cpfCnpj) ?? '';
        return $this->portal->removerTomador($cnpj, $doc)
            ? ['ok' => true, 'mensagem' => 'Tomador removido da lista. As notas dele continuam guardadas.']
            : ['ok' => false, 'http' => 404, 'codigo' => 'TOMADOR_NAO_ENCONTRADO', 'mensagem' => 'Tomador não encontrado.'];
    }
}
