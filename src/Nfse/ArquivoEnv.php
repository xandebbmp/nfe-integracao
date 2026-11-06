<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse;

/**
 * Le o arquivo .env da raiz do projeto (senha do e-mail e outros segredos).
 *
 * Formato, uma linha por valor:
 *   SMTP_PASSWORD=minha-senha
 *   SMTP_FROM="contato@empresa.com.br"   # aspas e comentario no fim sao opcionais
 *
 * Regras:
 *   - O .env NUNCA vai para o Git (esta no .gitignore). O modelo e o
 *     .env.example, sem senhas.
 *   - Variavel ja definida no servidor (systemd, pm2, Apache) vale mais que o
 *     .env: o arquivo so preenche o que falta.
 *   - Fica na raiz do projeto, fora de public/: o navegador nao alcanca.
 */
final class ArquivoEnv
{
    private function __construct()
    {
    }

    /** Carrega o .env (se existir). Devolve as chaves que vieram do arquivo. */
    public static function carregar(?string $arquivo = null): array
    {
        $arquivo ??= dirname(__DIR__, 2) . '/.env';
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            return [];
        }
        $carregadas = [];
        foreach (self::ler((string)file_get_contents($arquivo)) as $chave => $valor) {
            if (getenv($chave) === false) {
                putenv("{$chave}={$valor}");
                $_ENV[$chave] = $valor;
                $carregadas[] = $chave;
            }
        }
        return $carregadas;
    }

    /**
     * Separado para os testes: transforma o texto do .env em [CHAVE => valor].
     *
     * @return array<string, string>
     */
    public static function ler(string $conteudo): array
    {
        $saida = [];
        foreach (preg_split('/\r\n|\r|\n/', $conteudo) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#') {
                continue;
            }
            if (str_starts_with($linha, 'export ')) {
                $linha = trim(substr($linha, 7));
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $linha, $m)) {
                continue; // linha estranha: ignora em vez de quebrar o sistema
            }
            $valor = $m[2];
            if ($valor !== '' && ($valor[0] === '"' || $valor[0] === "'")) {
                // Entre aspas: pega ate a aspa que fecha (pode ter # e espacos dentro).
                $aspa = $valor[0];
                $fim = strpos($valor, $aspa, 1);
                $valor = $fim === false ? substr($valor, 1) : substr($valor, 1, $fim - 1);
                if ($aspa === '"') {
                    $valor = str_replace(['\\n', '\\"'], ["\n", '"'], $valor);
                }
            } else {
                // Sem aspas: " #" comeca um comentario.
                $valor = trim((string)preg_replace('/\s+#.*$/', '', $valor));
            }
            $saida[$m[1]] = $valor;
        }
        return $saida;
    }
}
