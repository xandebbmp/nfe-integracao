<?php

declare(strict_types=1);

namespace Xande\NfeIntegracao\Database;

use PDO;
use RuntimeException;
use Throwable;

final class ConnectionFactory
{
    private function __construct()
    {
    }

    public static function make(): PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/database.local.php';

        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Configuração local de banco não encontrada. Crie config/database.local.php a partir de config/database.local.php.example.'
            );
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new RuntimeException('Configuração local de banco inválida.');
        }

        self::assertRequiredConfig($config);

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Driver de banco não suportado. Esperado: pgsql.');
        }

        $host = (string) $config['host'];
        $port = (int) $config['port'];
        $database = (string) $config['database'];
        $username = (string) $config['username'];
        $password = (string) $config['password'];

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database
        );

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec("SET client_encoding TO 'UTF8'");

            return $pdo;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Falha ao conectar no banco fiscal local.',
                0,
                $e
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function assertRequiredConfig(array $config): void
    {
        $required = [
            'driver',
            'host',
            'port',
            'database',
            'username',
            'password',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("Configuração local de banco incompleta. Campo ausente: {$key}.");
            }
        }
    }
}
