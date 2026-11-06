<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use PDO;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Servicos salvos da empresa no portal (nome, descricao, codigos fiscais,
 * aliquota). Escolhidos no formulario para nao redigitar codigos.
 */
final class PortalServicos
{
    private PortalRepositorio $portal;

    public function __construct(PDO $pdo)
    {
        $this->portal = new PortalRepositorio($pdo);
    }

    public function listar(string $cnpj): array
    {
        return $this->portal->servicos($cnpj);
    }

    public function salvar(string $cnpj, array $e): array
    {
        $s = [
            'nome' => trim((string)($e['nome'] ?? '')),
            'descricao' => trim((string)($e['descricao'] ?? '')),
            'codigo_tributacao_nacional' => (preg_replace('/\D/', '', (string)($e['codigo_tributacao_nacional'] ?? '')) ?? '') ?: null,
            'item_lista_servico' => trim((string)($e['item_lista_servico'] ?? '')) ?: null,
            'codigo_tributacao_municipal' => trim((string)($e['codigo_tributacao_municipal'] ?? '')) ?: null,
            'codigo_cnae' => (preg_replace('/\D/', '', (string)($e['codigo_cnae'] ?? '')) ?? '') ?: null,
            'aliquota_iss' => isset($e['aliquota_iss']) && $e['aliquota_iss'] !== '' ? round((float)$e['aliquota_iss'], 2) : null,
            'valor_padrao' => isset($e['valor_padrao']) && $e['valor_padrao'] !== '' ? round((float)$e['valor_padrao'], 2) : null,
        ];

        $erros = [];
        if (mb_strlen($s['nome']) < 2 || mb_strlen($s['nome']) > 120) {
            $erros[] = ['campo' => 'nome', 'mensagem' => 'Dê um nome curto para achar o serviço na lista (até 120 caracteres).'];
        }
        if (mb_strlen($s['descricao']) < 5 || mb_strlen($s['descricao']) > 2000) {
            $erros[] = ['campo' => 'descricao', 'mensagem' => 'A descrição precisa ter entre 5 e 2000 caracteres.'];
        }
        if ($s['codigo_tributacao_nacional'] === null && $s['item_lista_servico'] === null) {
            $erros[] = ['campo' => 'codigo_tributacao_nacional', 'mensagem' => 'Informe o código de tributação nacional (6 números) ou o item da LC 116.'];
        }
        if ($s['codigo_tributacao_nacional'] !== null && strlen($s['codigo_tributacao_nacional']) !== 6) {
            $erros[] = ['campo' => 'codigo_tributacao_nacional', 'mensagem' => 'O código de tributação nacional tem 6 números (ex.: 010101).'];
        }
        if ($s['item_lista_servico'] !== null && !preg_match('/^\d{1,2}\.\d{2}$/', $s['item_lista_servico'])) {
            $erros[] = ['campo' => 'item_lista_servico', 'mensagem' => 'Item da LC 116 no formato 01.01.'];
        }
        if ($s['aliquota_iss'] !== null && ($s['aliquota_iss'] < 0 || $s['aliquota_iss'] > 5)) {
            $erros[] = ['campo' => 'aliquota_iss', 'mensagem' => 'A alíquota do ISS vai de 0% a 5%.'];
        }
        if ($erros !== []) {
            return ['ok' => false, 'http' => 422, 'codigo' => 'DADOS_INVALIDOS', 'mensagem' => 'Confira os campos do serviço.', 'erros' => $erros];
        }

        $id = isset($e['id']) && (int)$e['id'] > 0 ? (int)$e['id'] : null;
        if ($id !== null && $this->portal->servico($cnpj, $id) === null) {
            return ['ok' => false, 'http' => 404, 'codigo' => 'SERVICO_NAO_ENCONTRADO', 'mensagem' => 'Serviço não encontrado.'];
        }
        $id = $this->portal->salvarServico($cnpj, $s, $id);
        return ['ok' => true, 'servico' => $this->portal->servico($cnpj, $id)];
    }

    public function remover(string $cnpj, int $id): array
    {
        return $this->portal->desativarServico($cnpj, $id)
            ? ['ok' => true, 'mensagem' => 'Serviço removido da lista.']
            : ['ok' => false, 'http' => 404, 'codigo' => 'SERVICO_NAO_ENCONTRADO', 'mensagem' => 'Serviço não encontrado.'];
    }
}
