<?php
declare(strict_types=1);

namespace Xande\NfeIntegracao\Nfse\Servico;

/**
 * Liga o worker (bin/nfse_worker.php) em segundo plano, a partir do painel.
 *
 * Roda o mesmo PHP do site, com o mesmo usuario do site, e solta o processo:
 * ele continua ligado depois que a pagina responde. A saida vai para
 * storage/logs/worker.log.
 *
 * O worker ligado assim recebe --pelo-painel: no "Reiniciar" ele mesmo liga
 * um novo antes de sair (nao ha systemd/pm2 para religar).
 */
final class IniciadorWorker
{
    /** @return string|null null = ligou; texto = motivo de nao ter ligado */
    public static function iniciar(): ?string
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return 'O PHP deste servidor não deixa o site ligar programas (função exec desligada). Peça para quem cuida do servidor instalar o worker como serviço.';
        }
        $php = self::php();
        if ($php === null) {
            return 'Não achei o programa do PHP neste servidor para ligar o worker.';
        }
        $raiz = dirname(__DIR__, 3);
        $log = $raiz . '/storage/logs/worker.log';
        if (!is_dir(dirname($log))) {
            @mkdir(dirname($log), 0775, true);
        }
        // As chaves { ... & } > /dev/null soltam o processo de verdade: sem elas,
        // o sh do macOS fica esperando o worker e o exec() nunca volta (o
        // worker que pediu o reinicio ficava travado, vivo, sem dar sinal).
        // 3>&- ... 9>&- fecham o que veio do site (porta do servidor e a
        // conexao do clique): o worker nao pode segurar a porta 8099.
        $comando = 'cd ' . escapeshellarg($raiz) . ' && { nohup ' . escapeshellarg($php) . ' bin/nfse_worker.php --pelo-painel'
            . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- & } > /dev/null 2>&1';
        exec($comando, $saida, $codigo);
        return $codigo === 0 ? null : 'Não consegui ligar o worker (código ' . $codigo . '). Veja storage/logs/worker.log.';
    }

    /** O PHP de linha de comando (o site pode estar rodando no php-fpm). */
    private static function php(): ?string
    {
        $candidatos = [PHP_BINARY, PHP_BINDIR . '/php', '/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'];
        foreach ($candidatos as $c) {
            if ($c !== '' && !preg_match('/fpm|cgi/i', basename($c)) && is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return null;
    }
}
