<?php

namespace Pfme\Api\Core;

use PDO;
use PDOException;

/**
 * Database connection manager
 */
class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = require __DIR__ . '/../../config/config.php';

            $host = $config['database']['host'];
            $port = $config['database']['port'];
            $dbname = $config['database']['name'];

            // Read credentials from secret files
            $user = trim(file_get_contents($config['database']['user_file']));
            $password = trim(file_get_contents($config['database']['password_file']));

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                self::$connection = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new \RuntimeException('Database connection failed');
            }
        }

        return self::$connection;
    }

    public static function readSecretFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Secret file not found: {$path}");
        }

        return trim(file_get_contents($path));
    }
}
