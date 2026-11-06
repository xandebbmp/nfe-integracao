<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Dominio;

/**
 * Configuracoes que podem ser mudadas pelo painel admin (tela Sistema).
 *
 * Para liberar uma configuracao nova no painel, acrescente uma linha em
 * CAMPOS. Nada mais precisa mudar: a tela, a validacao e a leitura usam
 * esta lista.
 *
 * Ficam FORA de proposito (so no arquivo config/nfse.local.php):
 *   - senhas e tokens (admin_token, monitor_token, chave_certificados, S3);
 *   - tpAmb (homologacao/producao): trocar por engano emitiria notas com
 *     valor fiscal.
 *
 * Tipos: 'inteiro' (com min e max), 'opcao' (lista fechada), 'url' (https
 * ou vazio).
 */
final class AjustesPainel
{
    public const CAMPOS = [
        'portal.sessao_minutos' => ['grupo' => 'Portal do cliente', 'rotulo' => 'Tempo de sessão (minutos)', 'tipo' => 'inteiro', 'min' => 30, 'max' => 1440,
            'ajuda' => 'Depois desse tempo sem "lembrar de mim", a pessoa entra de novo.'],
        'portal.sessao_lembrar_dias' => ['grupo' => 'Portal do cliente', 'rotulo' => '"Lembrar de mim" (dias)', 'tipo' => 'inteiro', 'min' => 1, 'max' => 90,
            'ajuda' => 'Quanto tempo o login fica salvo no navegador.'],
        'portal.tentativas_login' => ['grupo' => 'Portal do cliente', 'rotulo' => 'Senhas erradas antes de bloquear', 'tipo' => 'inteiro', 'min' => 3, 'max' => 20,
            'ajuda' => 'Protege contra quem tenta adivinhar a senha.'],
        'portal.bloqueio_minutos' => ['grupo' => 'Portal do cliente', 'rotulo' => 'Tempo de bloqueio (minutos)', 'tipo' => 'inteiro', 'min' => 5, 'max' => 240,
            'ajuda' => 'Quanto tempo o login fica travado depois das senhas erradas.'],
        'portal.padrao' => ['grupo' => 'Portal do cliente', 'rotulo' => 'Tipo de NFS-e emitida', 'tipo' => 'opcao',
            'opcoes' => ['' => 'Automático (Nacional, ou Salvador se for o único contratado)', 'NACIONAL' => 'Sempre Nacional', 'SALVADOR' => 'Sempre Salvador'],
            'ajuda' => 'Vale para todas as empresas que emitem pelo portal.'],

        'fila.max_tentativas' => ['grupo' => 'Envio das notas', 'rotulo' => 'Tentativas quando a prefeitura falha', 'tipo' => 'inteiro', 'min' => 1, 'max' => 10,
            'ajuda' => 'Se a prefeitura estiver fora do ar, o worker tenta de novo até esse número.'],
        'fila.espera_base_segundos' => ['grupo' => 'Envio das notas', 'rotulo' => 'Espera antes de tentar de novo (segundos)', 'tipo' => 'inteiro', 'min' => 10, 'max' => 600,
            'ajuda' => 'A espera dobra a cada tentativa (30 s, 60 s, 120 s...).'],
        'fila.job_travado_minutos' => ['grupo' => 'Envio das notas', 'rotulo' => 'Devolver à fila após (minutos)', 'tipo' => 'inteiro', 'min' => 5, 'max' => 60,
            'ajuda' => 'Se um worker cair no meio de uma nota, ela volta para a fila depois desse tempo.'],

        'api.max_aguardar_segundos' => ['grupo' => 'API', 'rotulo' => 'Espera máxima pela resposta (segundos)', 'tipo' => 'inteiro', 'min' => 0, 'max' => 120,
            'ajuda' => 'Quanto tempo a API pode segurar a resposta esperando a prefeitura (parâmetro aguardar).'],
        'api.max_documentos_por_lote' => ['grupo' => 'API', 'rotulo' => 'Máximo de notas por envio', 'tipo' => 'inteiro', 'min' => 1, 'max' => 200,
            'ajuda' => 'Limite de documentos num único lote enviado à API.'],

        'salvador.url_visualizacao' => ['grupo' => 'Salvador', 'rotulo' => 'Link para ver a nota no site da prefeitura', 'tipo' => 'url',
            'ajuda' => 'Use {numero}, {inscricao} e {verificacao} no endereço. Vazio = sem link.'],
    ];

    /**
     * Confere um valor vindo da tela.
     *
     * @return array{0: mixed, 1: ?string} [valor convertido, mensagem de erro ou null]
     */
    public static function validar(string $chave, mixed $valor): array
    {
        $campo = self::CAMPOS[$chave] ?? null;
        if ($campo === null) {
            return [null, 'Esta configuração não pode ser alterada pelo painel.'];
        }
        switch ($campo['tipo']) {
            case 'inteiro':
                $texto = trim((string)$valor);
                if (!preg_match('/^\d+$/', $texto) || (int)$texto < $campo['min'] || (int)$texto > $campo['max']) {
                    return [null, "Use um número de {$campo['min']} a {$campo['max']}."];
                }
                return [(int)$texto, null];
            case 'opcao':
                $texto = (string)$valor;
                return array_key_exists($texto, $campo['opcoes']) ? [$texto, null] : [null, 'Escolha uma das opções da lista.'];
            case 'url':
                $texto = trim((string)$valor);
                if ($texto !== '' && (!str_starts_with($texto, 'https://') || filter_var(str_replace(['{', '}'], '', $texto), FILTER_VALIDATE_URL) === false)) {
                    return [null, 'Use um endereço que comece com https:// (ou deixe vazio).'];
                }
                return [$texto, null];
        }
        return [null, 'Tipo desconhecido.'];
    }

    /** Valor atual de uma chave "grupo.nome" dentro da config. */
    public static function ler(array $cfg, string $chave): mixed
    {
        [$grupo, $nome] = explode('.', $chave, 2);
        return $cfg[$grupo][$nome] ?? null;
    }

    /**
     * Aplica na config os valores salvos pelo painel. Chave fora da lista
     * ou valor invalido e ignorado (vale o arquivo).
     */
    public static function aplicar(array $cfg, array $ajustes): array
    {
        foreach ($ajustes as $chave => $valor) {
            [$convertido, $erro] = self::validar((string)$chave, $valor);
            if ($erro === null) {
                [$grupo, $nome] = explode('.', (string)$chave, 2);
                $cfg[$grupo][$nome] = $convertido;
            }
        }
        return $cfg;
    }
}
