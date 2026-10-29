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

    private static ?PDO $pdo = null;

    /**
     * Banco disponivel via config/database.local.php ou variaveis NFE_DB_* (Docker).
     */
    public static function configurado(): bool
    {
        return getenv('NFE_DB_HOST') !== false || is_file(self::configPath());
    }

    /**
     * Uma conexao por processo (os scripts sao de vida curta).
     */
    public static function make(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = self::loadConfig();

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

            return self::$pdo = $pdo;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Falha ao conectar no banco fiscal local.',
                0,
                $e
            );
        }
    }

    private static function configPath(): string
    {
        return dirname(__DIR__, 2) . '/config/database.local.php';
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        if (getenv('NFE_DB_HOST') !== false) {
            return [
                'driver' => 'pgsql',
                'host' => (string)getenv('NFE_DB_HOST'),
                'port' => (int)(getenv('NFE_DB_PORT') ?: 5432),
                'database' => (string)getenv('NFE_DB_NAME'),
                'username' => (string)getenv('NFE_DB_USER'),
                'password' => (string)getenv('NFE_DB_PASSWORD'),
                'charset' => 'utf8',
            ];
        }

        $configPath = self::configPath();

        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Configuração local de banco não encontrada. Crie config/database.local.php a partir de config/database.local.php.example.'
            );
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new RuntimeException('Configuração local de banco inválida.');
        }

        return $config;
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
