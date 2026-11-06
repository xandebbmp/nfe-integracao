<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Xande\NfeIntegracao\Nfse\Dominio\Documentos;
use Xande\NfeIntegracao\Nfse\Repositorio\PortalRepositorio;
use Xande\NfeIntegracao\Nfse\Repositorio\RecorrenciaRepositorio;

/**
 * Notas recorrentes do portal (tela "Recorrencias", script 0011).
 *
 * Uma recorrencia guarda o que vai na nota (tomador, servico salvo,
 * descricao e valores) e o dia do mes. Quando o dia chega ela fica
 * PENDENTE ate a nota sair:
 *
 *   AUTOMATICA  o worker emite sozinho (executarAutomaticas). Se der erro,
 *               guarda o motivo e tenta de novo no dia seguinte.
 *   CONFIRMAR   a pessoa clica em "Emitir" (emitirMes) ou "Pular" (pular).
 *
 * A emissao usa o mesmo caminho do formulario (PortalEmissao), com a chave
 * rec-<id>-<AAAAMM>: cada mes so vira uma nota, mesmo com dois cliques ou
 * dois workers.
 */
final class PortalRecorrencias
{
    public const AUTOMATICA = 'AUTOMATICA';
    public const CONFIRMAR = 'CONFIRMAR';

    private const FUSO = 'America/Bahia';
    private const NOMES_MES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    private RecorrenciaRepositorio $repo;

    public function __construct(private PDO $pdo, private array $cfg, private ?string $hojeFixo = null)
    {
        $this->repo = new RecorrenciaRepositorio($pdo);
    }

    // =====================================================================
    // Tela
    // =====================================================================

    public function listar(string $cnpj): array
    {
        $hoje = $this->hoje();
        return array_map(function (array $r) use ($hoje): array {
            return [
                'id' => $r['id'],
                'nome' => $r['nome'],
                'tomador' => $r['tomador'],
                'servico_id' => $r['servico_id'],
                'servico_nome' => $r['servico_nome'],
                'descricao' => $r['descricao'],
                'valores' => $r['valores'],
                'dia' => $r['dia'],
                'competencia' => $r['competencia'],
                'modo' => $r['modo'],
                'proxima_em' => $r['proxima_em'],
                'fim' => $r['fim'] !== null ? substr((string)$r['fim'], 0, 7) : null,
                'situacao' => self::situacao($r, $hoje),
                'competencia_proxima' => self::mesPorExtenso($this->competenciaDe($r)),
                'emitidas' => $r['emitidas'],
                'ultima_mensagem' => $r['ultima_mensagem'],
                'ultimo_documento_id' => $r['ultimo_documento_id'],
                'ultimo_status' => $r['ultimo_status'],
            ];
        }, $this->repo->listar($cnpj));
    }

    /** PENDENTE (dia chegou) | AGENDADA | PAUSADA | ENCERRADA */
    private static function situacao(array $r, string $hoje): string
    {
        if (!$r['ativa']) {
            return $r['fim'] !== null && (string)$r['proxima_em'] > (string)$r['fim'] ? 'ENCERRADA' : 'PAUSADA';
        }
        return (string)$r['proxima_em'] <= $hoje ? 'PENDENTE' : 'AGENDADA';
    }

    /**
     * Cria ou altera. Os dados da nota sao conferidos na hora com as mesmas
     * regras do "Emitir nota" (o erro nao fica escondido ate o dia).
     *
     * @param array $usuario usuario da sessao (cnpj, id)
     * @param array $e { id?, nome, tomador{...}, servico_id, descricao, valores{...},
     *                   primeira_em (AAAA-MM-DD), competencia ATUAL|ANTERIOR, modo, fim (AAAA-MM)? }
     */
    public function salvar(array $usuario, array $e): array
    {
        $cnpj = (string)$usuario['cnpj'];
        $id = isset($e['id']) && (int)$e['id'] > 0 ? (int)$e['id'] : null;
        $atual = $id !== null ? $this->repo->buscar($cnpj, $id) : null;
        if ($id !== null && $atual === null) {
            return self::falha(404, 'RECORRENCIA_NAO_ENCONTRADA', 'Nota recorrente não encontrada.');
        }

        $hoje = $this->hoje();
        $nome = trim((string)($e['nome'] ?? ''));
        $primeira = trim((string)($e['primeira_em'] ?? ''));
        $fim = trim((string)($e['fim'] ?? ''));
        $modo = (string)($e['modo'] ?? self::CONFIRMAR);
        $competencia = (string)($e['competencia'] ?? 'ATUAL');
        $tomador = is_array($e['tomador'] ?? null) ? $e['tomador'] : [];
        $tomador['cpf_cnpj'] = preg_replace('/\D/', '', (string)($tomador['cpf_cnpj'] ?? '')) ?? '';
        $valores = is_array($e['valores'] ?? null) ? $e['valores'] : [];

        $erros = [];
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros[] = ['campo' => 'nome', 'mensagem' => 'Dê um nome para achar na lista (ex.: Mensalidade Clínica Vida).'];
        }
        if (!Documentos::valido($tomador['cpf_cnpj'])) {
            $erros[] = ['campo' => 'tomador.cpf_cnpj', 'mensagem' => 'Escolha um tomador com CPF ou CNPJ válido.'];
        }
        if ((new PortalRepositorio($this->pdo))->servico($cnpj, (int)($e['servico_id'] ?? 0)) === null) {
            $erros[] = ['campo' => 'servico_id', 'mensagem' => 'Escolha um dos seus serviços salvos.'];
        }
        if (!in_array($modo, [self::AUTOMATICA, self::CONFIRMAR], true)) {
            $erros[] = ['campo' => 'modo', 'mensagem' => 'Escolha se a nota sai sozinha ou espera a sua confirmação.'];
        }
        if ((float)($e['valores']['valor_servicos'] ?? 0) <= 0) {
            $erros[] = ['campo' => 'valores.valor_servicos', 'mensagem' => 'Informe o valor do serviço (maior que zero).'];
        }
        if (!in_array($competencia, ['ATUAL', 'ANTERIOR'], true)) {
            $erros[] = ['campo' => 'competencia', 'mensagem' => 'Escolha o mês de competência.'];
        }

        // Data da proxima nota: obrigatoria ao criar; ao editar, vazia = mantem.
        $proxima = $atual['proxima_em'] ?? null;
        $dia = $atual['dia'] ?? null;
        if ($primeira !== '' || $atual === null) {
            $data = DateTimeImmutable::createFromFormat('!Y-m-d', $primeira);
            if ($data === false || $data->format('Y-m-d') !== $primeira) {
                $erros[] = ['campo' => 'primeira_em', 'mensagem' => 'Escolha a data da próxima nota.'];
            } elseif ($primeira < $hoje) {
                $erros[] = ['campo' => 'primeira_em', 'mensagem' => 'A data não pode ser no passado. Escolha hoje ou um dia à frente.'];
            } elseif ((int)$data->format('j') > 28) {
                $erros[] = ['campo' => 'primeira_em', 'mensagem' => 'Escolha um dia até 28 (todo mês tem).'];
            } else {
                $proxima = $primeira;
                $dia = (int)$data->format('j');
            }
        }
        $fimData = null;
        if ($fim !== '') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fim)) {
                $erros[] = ['campo' => 'fim', 'mensagem' => 'Informe o mês da última nota ou deixe vazio.'];
            } else {
                $fimData = (new DateTimeImmutable($fim . '-01'))->format('Y-m-t');
                if ($proxima !== null && $fimData < (string)$proxima) {
                    $erros[] = ['campo' => 'fim', 'mensagem' => 'O mês da última nota é antes da próxima.'];
                }
            }
        }
        if ($erros !== []) {
            return self::falha(422, 'DADOS_INVALIDOS', 'Confira os campos destacados.', $erros);
        }

        // Mesmas regras do "Emitir nota", com a competencia de hoje (a do dia
        // ainda esta no futuro e seria recusada so por isso).
        $descricao = trim((string)($e['descricao'] ?? ''));
        $conferido = (new PortalEmissao($this->pdo, $this->cfg))->conferir($cnpj, [
            'tomador' => $tomador,
            'servico' => ['id' => (int)$e['servico_id']],
            'descricao' => $descricao,
            'competencia' => substr($hoje, 0, 7),
            'valores' => $valores,
        ]);
        if (!$conferido['ok']) {
            return $conferido;
        }

        $id = $this->repo->salvar($cnpj, [
            'nome' => $nome,
            'tomador' => $tomador,
            'servico_id' => (int)$e['servico_id'],
            'descricao' => $descricao,
            'valores' => $valores,
            'dia' => $dia,
            'competencia' => $competencia,
            'modo' => $modo,
            'proxima_em' => $proxima,
            'fim' => $fimData,
        ], $id, isset($usuario['id']) ? (int)$usuario['id'] : null);

        return ['ok' => true, 'id' => $id, 'mensagem' => $atual === null ? 'Nota recorrente criada.' : 'Nota recorrente atualizada.'];
    }

    /** "Emitir" de uma recorrencia pendente (o dia ja chegou). */
    public function emitirMes(array $usuario, int $id): array
    {
        $r = $this->repo->buscar((string)$usuario['cnpj'], $id);
        if ($r === null) {
            return self::falha(404, 'RECORRENCIA_NAO_ENCONTRADA', 'Nota recorrente não encontrada.');
        }
        if (!$r['ativa'] || (string)$r['proxima_em'] > $this->hoje()) {
            return self::falha(409, 'NAO_PENDENTE', 'Esta nota ainda não chegou no dia. Para emitir antes, use "Emitir nota".');
        }
        return $this->emitirOcorrencia($r);
    }

    /** "Pular este mes": segue para a proxima data sem emitir. */
    public function pular(array $usuario, int $id): array
    {
        $r = $this->repo->buscar((string)$usuario['cnpj'], $id);
        if ($r === null) {
            return self::falha(404, 'RECORRENCIA_NAO_ENCONTRADA', 'Nota recorrente não encontrada.');
        }
        if (!$r['ativa'] || (string)$r['proxima_em'] > $this->hoje()) {
            return self::falha(409, 'NAO_PENDENTE', 'Só dá para pular o mês quando o dia chegou.');
        }
        $proxima = self::mesSeguinte((string)$r['proxima_em'], $r['dia']);
        $this->repo->avancar($r['id'], $proxima, null, self::antesDoFim($proxima, $r['fim']));
        return ['ok' => true, 'mensagem' => 'Mês pulado. Próxima nota em ' . self::dataBr($proxima) . '.'];
    }

    public function pausar(array $usuario, int $id): array
    {
        $r = $this->repo->buscar((string)$usuario['cnpj'], $id);
        if ($r === null) {
            return self::falha(404, 'RECORRENCIA_NAO_ENCONTRADA', 'Nota recorrente não encontrada.');
        }
        $this->repo->definirAtiva($r['cnpj'], $id, false);
        return ['ok' => true, 'mensagem' => 'Pausada. Nenhuma nota sai até você retomar.'];
    }

    /** Retoma a partir do proximo dia marcado (meses pausados nao viram nota atrasada). */
    public function retomar(array $usuario, int $id): array
    {
        $r = $this->repo->buscar((string)$usuario['cnpj'], $id);
        if ($r === null) {
            return self::falha(404, 'RECORRENCIA_NAO_ENCONTRADA', 'Nota recorrente não encontrada.');
        }
        // Se a data marcada ainda esta a frente, mantem; senao, o proximo dia do mes.
        $proxima = (string)$r['proxima_em'] >= $this->hoje()
            ? (string)$r['proxima_em']
            : self::proximoDia($this->hoje(), $r['dia']);
        if (!self::antesDoFim($proxima, $r['fim'])) {
            return self::falha(409, 'RECORRENCIA_ENCERRADA', 'O período desta nota já acabou. Edite e mude o mês da última nota.');
        }
        $this->repo->definirAtiva($r['cnpj'], $id, true, $proxima);
        return ['ok' => true, 'mensagem' => 'Retomada. Próxima nota em ' . self::dataBr($proxima) . '.'];
    }

    public function remover(array $usuario, int $id): array
    {
        return $this->repo->remover((string)$usuario['cnpj'], $id)
            ? ['ok' => true, 'mensagem' => 'Nota recorrente apagada. As notas já emitidas continuam guardadas.']
            : self::falha(404, 'RECORRENCIA_NAO_ENCONTRADA', 'Nota recorrente não encontrada.');
    }

    /** Quantas estao esperando (aviso da tela inicial). */
    public function pendentes(string $cnpj): int
    {
        return $this->repo->existe() ? $this->repo->pendentes($cnpj, $this->hoje()) : 0;
    }

    // =====================================================================
    // Worker
    // =====================================================================

    /**
     * Emite as recorrencias AUTOMATICAS que chegaram no dia.
     * Uma tentativa por dia: se falhar (empresa bloqueada, certificado...),
     * o motivo aparece para o cliente e o worker tenta de novo amanha.
     *
     * @return array{emitidas: int, falhas: int}
     */
    public function executarAutomaticas(int $limite = 20): array
    {
        $saida = ['emitidas' => 0, 'falhas' => 0];
        if (!$this->repo->existe()) {
            return $saida;
        }
        foreach ($this->repo->automaticasDoDia($this->hoje(), $limite) as $r) {
            $resultado = $this->emitirOcorrencia($r);
            if ($resultado['ok']) {
                $saida['emitidas']++;
            } elseif (($resultado['codigo'] ?? '') !== 'EM_ANDAMENTO') {
                $this->repo->registrarFalha($r['id'], (string)$resultado['mensagem'], $this->hoje());
                $saida['falhas']++;
            }
        }
        return $saida;
    }

    // =====================================================================

    /** Emite o mes de proxima_em e segue para o proximo. */
    private function emitirOcorrencia(array $r): array
    {
        if (!$this->repo->travar($r['id'])) {
            return self::falha(409, 'EM_ANDAMENTO', 'Esta nota já está sendo emitida. Aguarde um instante.');
        }
        try {
            // Rele depois da trava: outro worker pode ter acabado de emitir.
            $r = $this->repo->buscar($r['cnpj'], $r['id']);
            if ($r === null || !$r['ativa'] || (string)$r['proxima_em'] > $this->hoje()) {
                return self::falha(409, 'EM_ANDAMENTO', 'Esta nota já foi emitida.');
            }
            $competencia = $this->competenciaDe($r);
            $resultado = (new PortalEmissao($this->pdo, $this->cfg))->emitir(
                ['cnpj' => $r['cnpj'], 'id' => $r['criado_por']],
                [
                    'chave_envio' => 'rec-' . $r['id'] . '-' . str_replace('-', '', $competencia),
                    'tomador' => $r['tomador'],
                    'servico' => ['id' => $r['servico_id']],
                    'descricao' => $r['descricao'],
                    'competencia' => $competencia,
                    'valores' => $r['valores'],
                ]
            );
            if (!$resultado['ok']) {
                $detalhe = $resultado['erros'][0]['mensagem'] ?? '';
                $resultado['mensagem'] = trim($resultado['mensagem'] . ($detalhe !== '' && $detalhe !== $resultado['mensagem'] ? ' ' . $detalhe : ''));
                return $resultado;
            }
            $proxima = self::mesSeguinte((string)$r['proxima_em'], $r['dia']);
            $this->repo->avancar($r['id'], $proxima, $resultado['id'], self::antesDoFim($proxima, $r['fim']));
            return ['ok' => true, 'id' => $resultado['id'], 'mensagem' => 'Nota de ' . self::mesPorExtenso($competencia) . ' enviada para a prefeitura.'];
        } catch (Throwable $erro) {
            return self::falha(500, 'ERRO_INTERNO', 'Não foi possível emitir agora: ' . $erro->getMessage());
        } finally {
            $this->repo->liberar($r['id'] ?? 0);
        }
    }

    /** Mes de competencia (AAAA-MM) da proxima nota. */
    private function competenciaDe(array $r): string
    {
        $data = new DateTimeImmutable(substr((string)$r['proxima_em'], 0, 10));
        return $r['competencia'] === 'ANTERIOR' ? $data->modify('first day of last month')->format('Y-m') : $data->format('Y-m');
    }

    private function hoje(): string
    {
        return $this->hojeFixo ?? (new DateTimeImmutable('now', new DateTimeZone(self::FUSO)))->format('Y-m-d');
    }

    /** Mesmo dia no mes seguinte (o dia vai ate 28, entao sempre existe). */
    public static function mesSeguinte(string $data, int $dia): string
    {
        return (new DateTimeImmutable(substr($data, 0, 10)))->modify('first day of next month')->modify('+' . ($dia - 1) . ' days')->format('Y-m-d');
    }

    /** Primeira data com esse dia a partir de hoje (inclusive). */
    public static function proximoDia(string $hoje, int $dia): string
    {
        $esteMes = (new DateTimeImmutable($hoje))->modify('first day of this month')->modify('+' . ($dia - 1) . ' days')->format('Y-m-d');
        return $esteMes >= $hoje ? $esteMes : self::mesSeguinte($hoje, $dia);
    }

    private static function antesDoFim(string $data, ?string $fim): bool
    {
        return $fim === null || $data <= substr($fim, 0, 10);
    }

    private static function mesPorExtenso(string $anoMes): string
    {
        [$ano, $mes] = explode('-', $anoMes);
        return self::NOMES_MES[(int)$mes - 1] . '/' . $ano;
    }

    private static function dataBr(string $data): string
    {
        return implode('/', array_reverse(explode('-', substr($data, 0, 10))));
    }

    private static function falha(int $http, string $codigo, string $mensagem, array $erros = []): array
    {
        $r = ['ok' => false, 'http' => $http, 'codigo' => $codigo, 'mensagem' => $mensagem];
        if ($erros !== []) {
            $r['erros'] = $erros;
        }
        return $r;
    }
}
