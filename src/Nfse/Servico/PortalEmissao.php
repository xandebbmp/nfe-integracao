<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Dominio\Padrao;
use Xande\NfeIntegracao\Nfse\NfseConfig;
use Xande\NfeIntegracao\Nfse\Repositorio\ClienteRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;

/**
 * Emissao pelo formulario do portal.
 *
 * O portal NAO tem uma emissao propria: ele monta o mesmo JSON que um
 * sistema integrado enviaria para /v1/nfse/emitir.php e entrega para o
 * RecebimentoLote. Fila, worker, numeracao, bloqueios, franquia, cobranca,
 * monitor e webhooks continuam iguais.
 *
 *   consumidor = CNPJ da empresa (o mesmo "dono" das notas enviadas com o
 *                token da API dessa empresa)
 *   referencia = "PORTAL-" + chave_envio (gerada pelo navegador ao abrir o
 *                formulario). Clique duplo ou reenvio nao duplicam a nota.
 *
 * Antes de enfileirar, os dados passam pelo ValidadorPayload: se houver erro,
 * volta 422 com o campo e a correcao, e NADA e gravado (o formulario mostra
 * o erro ao lado do campo).
 */
final class PortalEmissao
{
    public const PREFIXO_REFERENCIA = 'PORTAL-';
    private const FUSO = 'America/Bahia';

    private PortalRepositorio $portal;

    public function __construct(private PDO $pdo, private array $cfg)
    {
        $this->portal = new PortalRepositorio($pdo);
    }

    /**
     * Padrao usado pelo portal para a empresa: Nacional, a nao ser que a
     * empresa so tenha Salvador contratado. 'portal.padrao' na config forca.
     */
    public function padraoDaEmpresa(array $cliente): string
    {
        $forcado = strtoupper((string)($this->cfg['portal']['padrao'] ?? ''));
        if (in_array($forcado, [Padrao::NACIONAL, Padrao::SALVADOR], true)) {
            return $forcado;
        }
        $permitidos = $cliente['padroes_permitidos'] ?? [Padrao::NACIONAL];
        return !in_array(Padrao::NACIONAL, $permitidos, true) && in_array(Padrao::SALVADOR, $permitidos, true)
            ? Padrao::SALVADOR
            : Padrao::NACIONAL;
    }

    /**
     * @param array $usuario usuario da sessao (precisa de 'cnpj')
     * @param array $entrada corpo enviado pelo formulario (ver public/v1/portal/emitir.php)
     * @return array ok=true com o resultado do RecebimentoLote, ou ok=false com http/codigo/mensagem/erros
     */
    public function emitir(array $usuario, array $entrada): array
    {
        $cnpj = (string)$usuario['cnpj'];
        $cliente = (new ClienteRepositorio($this->pdo))->buscar($cnpj);
        if ($cliente === null) {
            return self::falha(404, 'EMPRESA_NAO_ENCONTRADA', 'Empresa não encontrada. Fale com o suporte.');
        }

        $chave = (string)($entrada['chave_envio'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,50}$/', $chave)) {
            return self::falha(400, 'CHAVE_ENVIO_INVALIDA', 'Recarregue a página e tente de novo.');
        }

        $padrao = $this->padraoDaEmpresa($cliente);
        $montado = $this->montarDocumento($cnpj, $padrao, $entrada);
        if (!$montado['ok']) {
            return $montado;
        }
        $documento = $montado['documento'];
        $documento['referencia'] = self::PREFIXO_REFERENCIA . $chave;

        // Mesmos erros que a API daria, mas sem gravar nada.
        $erros = ValidadorPayload::validar($documento, $padrao);
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Confira os campos destacados.', ['erros' => $erros]);
        }

        $resultado = (new RecebimentoLote($this->pdo, $this->comConsumidor($cnpj, $cliente)))
            ->receber($cnpj, ['padrao' => $padrao, 'emitente_cnpj' => $cnpj, 'documentos' => [$documento]]);
        if (!$resultado['ok']) {
            return self::falha(422, 'NAO_ENVIADO', $resultado['erro']);
        }

        $item = $resultado['documentos'][0];
        if ($item['situacao_recebimento'] === RecebimentoLote::RECUSADO) {
            $erro = $item['erros'][0] ?? ['mensagem' => 'Nota recusada.', 'codigo' => 'RECUSADO'];
            return self::falha(422, (string)($erro['codigo'] ?? 'RECUSADO'), (string)$erro['mensagem'], ['erros' => $item['erros'] ?? []]);
        }

        // Deu certo: lembra do tomador e, se pedido, do servico.
        if (in_array($item['situacao_recebimento'], [RecebimentoLote::NOVO, RecebimentoLote::REENVIADO], true)) {
            $this->portal->salvarTomador($cnpj, $montado['tomador']);
            if (!empty($entrada['servico']['salvar']) && $montado['servico_novo'] !== null) {
                $this->portal->salvarServico($cnpj, $montado['servico_novo']);
            }
        }

        return ['ok' => true, 'id' => $item['id'], 'situacao_recebimento' => $item['situacao_recebimento'], 'padrao' => $padrao];
    }

    // =====================================================================

    /**
     * Transforma o formulario no documento da API.
     *
     * @return array{ok: true, documento: array, tomador: array, servico_novo: ?array}|array{ok: false}
     */
    private function montarDocumento(string $cnpj, string $padrao, array $e): array
    {
        $erros = [];

        // ---- Tomador ----
        $t = is_array($e['tomador'] ?? null) ? $e['tomador'] : [];
        $doc = preg_replace('/\D/', '', (string)($t['cpf_cnpj'] ?? '')) ?? '';
        if (!Documentos::valido($doc)) {
            $erros[] = self::erroCampo('tomador.cpf_cnpj', 'CPF ou CNPJ do tomador inválido.', 'Confira os números digitados.');
        }
        $tomador = [
            'cpf_cnpj' => $doc,
            'nome' => trim((string)($t['nome'] ?? '')),
            'email' => mb_strtolower(trim((string)($t['email'] ?? ''))),
            'telefone' => preg_replace('/\D/', '', (string)($t['telefone'] ?? '')) ?? '',
            'cep' => preg_replace('/\D/', '', (string)($t['cep'] ?? '')) ?? '',
            'logradouro' => trim((string)($t['logradouro'] ?? '')),
            'numero' => trim((string)($t['numero'] ?? '')),
            'complemento' => trim((string)($t['complemento'] ?? '')),
            'bairro' => trim((string)($t['bairro'] ?? '')),
            'codigo_municipio' => preg_replace('/\D/', '', (string)($t['codigo_municipio'] ?? '')) ?? '',
            'cidade' => trim((string)($t['cidade'] ?? '')),
            'uf' => strtoupper(trim((string)($t['uf'] ?? ''))),
        ];
        if ($tomador['email'] !== '' && !filter_var($tomador['email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = self::erroCampo('tomador.email', 'E-mail do tomador inválido.', 'Corrija ou deixe em branco.');
        }

        // ---- Servico (salvo ou digitado) ----
        $servicoNovo = null;
        $s = is_array($e['servico'] ?? null) ? $e['servico'] : [];
        if (!empty($s['id'])) {
            $salvo = $this->portal->servico($cnpj, (int)$s['id']);
            if ($salvo === null) {
                return self::falha(422, 'SERVICO_NAO_ENCONTRADO', 'O serviço escolhido não existe mais. Escolha outro.');
            }
            $codigos = $salvo;
        } else {
            $codigos = [
                'codigo_tributacao_nacional' => preg_replace('/\D/', '', (string)($s['codigo_tributacao_nacional'] ?? '')) ?: null,
                'item_lista_servico' => trim((string)($s['item_lista_servico'] ?? '')) ?: null,
                'codigo_tributacao_municipal' => trim((string)($s['codigo_tributacao_municipal'] ?? '')) ?: null,
                'codigo_cnae' => preg_replace('/\D/', '', (string)($s['codigo_cnae'] ?? '')) ?: null,
                'aliquota_iss' => isset($s['aliquota_iss']) && $s['aliquota_iss'] !== '' ? (float)$s['aliquota_iss'] : null,
            ];
            $servicoNovo = $codigos + [
                'nome' => trim((string)($s['nome'] ?? '')) ?: mb_substr(trim((string)($e['descricao'] ?? 'Serviço')), 0, 60),
                'descricao' => trim((string)($e['descricao'] ?? '')),
                'valor_padrao' => null,
            ];
        }

        $competencia = $this->competencia((string)($e['competencia'] ?? ''));
        if ($competencia === null) {
            $erros[] = self::erroCampo('competencia', 'Competência inválida ou no futuro.', 'Escolha o mês em que o serviço foi prestado.');
        }

        $descricao = str_replace('{mes}', self::mesPorExtenso($competencia ?? date('Y-m-d')), trim((string)($e['descricao'] ?? '')));

        // ---- Valores ----
        $v = is_array($e['valores'] ?? null) ? $e['valores'] : [];
        $valores = [
            'valor_servicos' => round((float)($v['valor_servicos'] ?? 0), 2),
            'iss_retido' => !empty($v['iss_retido']),
            'desconto_incondicionado' => round((float)($v['desconto_incondicionado'] ?? 0), 2),
            'valor_ir' => round((float)($v['valor_ir'] ?? 0), 2),
            'valor_pis' => round((float)($v['valor_pis'] ?? 0), 2),
            'valor_cofins' => round((float)($v['valor_cofins'] ?? 0), 2),
            'valor_csll' => round((float)($v['valor_csll'] ?? 0), 2),
            'valor_inss' => round((float)($v['valor_inss'] ?? 0), 2),
        ];
        if ($codigos['aliquota_iss'] !== null) {
            $valores['aliquota_iss'] = (float)$codigos['aliquota_iss'];
        }

        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Confira os campos destacados.', ['erros' => $erros]);
        }

        // ---- Documento no formato da API (docs/nfse-guia-api.md) ----
        $tomadorApi = ['cpf_cnpj' => $tomador['cpf_cnpj'], 'nome' => $tomador['nome']];
        if ($tomador['email'] !== '') {
            $tomadorApi['email'] = $tomador['email'];
        }
        if ($tomador['telefone'] !== '') {
            $tomadorApi['telefone'] = $tomador['telefone'];
        }
        // Endereco so vai completo (o Nacional recusa endereco pela metade).
        if ($tomador['cep'] !== '' && $tomador['logradouro'] !== '' && $tomador['codigo_municipio'] !== '') {
            $tomadorApi['endereco'] = array_filter([
                'logradouro' => $tomador['logradouro'],
                'numero' => $tomador['numero'] !== '' ? $tomador['numero'] : 'S/N',
                'complemento' => $tomador['complemento'],
                'bairro' => $tomador['bairro'],
                'codigo_municipio' => $tomador['codigo_municipio'],
                'uf' => $tomador['uf'],
                'cep' => $tomador['cep'],
            ], static fn($valor) => $valor !== '');
        }

        $servicoApi = array_filter([
            'descricao' => $descricao,
            'codigo_tributacao_nacional' => $padrao === Padrao::NACIONAL ? $codigos['codigo_tributacao_nacional'] : null,
            'item_lista_servico' => $codigos['item_lista_servico'],
            'codigo_tributacao_municipal' => $codigos['codigo_tributacao_municipal'],
            'codigo_cnae' => $padrao === Padrao::SALVADOR ? $codigos['codigo_cnae'] : null,
        ], static fn($valor) => $valor !== null && $valor !== '');

        $documento = [
            'tomador' => $tomadorApi,
            'servico' => $servicoApi,
            'valores' => array_filter($valores, static fn($valor) => $valor !== 0.0),
        ];
        if ($padrao === Padrao::NACIONAL) {
            $documento['competencia'] = $competencia;
        }

        return ['ok' => true, 'documento' => $documento, 'tomador' => $tomador, 'servico_novo' => $servicoNovo];
    }

    /** Config com a empresa como "consumidor" (mesmo sem token da API gerado). */
    private function comConsumidor(string $cnpj, array $cliente): array
    {
        $cfg = $this->cfg;
        $cfg['consumidores'][$cnpj] = ($cfg['consumidores'][$cnpj] ?? []) + [
            'cnpjs' => [$cnpj],
            'webhook_url' => (string)($cliente['webhook_url'] ?? ''),
            'webhook_segredo' => (string)($cliente['webhook_segredo'] ?? ''),
            'cliente' => true,
        ];
        if (!isset($cfg['emitentes'][$cnpj])) {
            // Empresa cadastrada depois que a config foi montada: recarrega.
            $cfg = NfseConfig::comClientes($cfg, $this->pdo);
        }
        return $cfg;
    }

    /**
     * "2026-11" -> data da competencia. Mes atual: hoje. Mes passado: dia 1.
     * Mes futuro: invalido.
     */
    private function competencia(string $mes): ?string
    {
        $hoje = new DateTimeImmutable('now', new DateTimeZone(self::FUSO));
        if ($mes === '') {
            return $hoje->format('Y-m-d');
        }
        if (!preg_match('/^(\d{4})-(\d{2})$/', $mes, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) {
            return null;
        }
        if ($mes === $hoje->format('Y-m')) {
            return $hoje->format('Y-m-d');
        }
        return $mes < $hoje->format('Y-m') ? "{$mes}-01" : null;
    }

    private static function mesPorExtenso(string $data): string
    {
        $nomes = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $nomes[(int)substr($data, 5, 2) - 1] . '/' . substr($data, 0, 4);
    }

    private static function erroCampo(string $campo, string $mensagem, string $correcao): array
    {
        return ['codigo' => 'CAMPO_INVALIDO', 'campo' => $campo, 'mensagem' => $mensagem, 'correcao' => $correcao];
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $extra = []): array
    {
        return ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem] + $extra;
    }
}
