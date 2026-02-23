<?php

namespace Pfme\Api\Core;

use PDO;
use PDOException;

/**
 * Database connection manager
 *
 * Supports multiple database platforms: MySQL, MariaDB, PostgreSQL, and SQLite.
 * The database type is determined by the POSTFIXADMIN_DB_TYPE environment variable.
 */
class Database
{
    private static ?PDO $connection = null;
    private static ?string $dbType = null;

    /**
     * Get a database connection
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = require __DIR__ . '/../../config/config.php';

            $dbType = $config['database']['type'] ?? 'mysql';
            self::$dbType = $dbType;

            try {
                match ($dbType) {
                    'mysql', 'mariadb' => self::connectMysql($config),
                    'postgresql' => self::connectPostgresql($config),
                    'sqlite' => self::connectSqlite($config),
                    default => throw new \RuntimeException("Unsupported database type: {$dbType}"),
                };
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new \RuntimeException('Database connection failed');
            }
        }

        return self::$connection;
    }

    /**
     * Get the current database type
     *
     * @return string
     */
    public static function getType(): string
    {
        if (self::$dbType === null) {
            self::getConnection();
        }

        return self::$dbType ?? 'mysql';
    }

    /**
     * Connect to MySQL/MariaDB
     */
    private static function connectMysql(array $config): void
    {
        $host = $config['database']['host'];
        $port = $config['database']['port'];
        $dbname = $config['database']['name'];

        // Read credentials from secret files
        $user = self::readSecretFile($config['database']['user_file']);
        $password = self::readSecretFile($config['database']['password_file']);

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        self::$connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Set session variables for MySQL
        self::$connection->exec("SET NAMES utf8mb4");
        self::$connection->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
    }

    /**
     * Connect to PostgreSQL
     */
    private static function connectPostgresql(array $config): void
    {
        $host = $config['database']['host'];
        $port = $config['database']['port'];
        $dbname = $config['database']['name'];

        // Read credentials from secret files
        $user = self::readSecretFile($config['database']['user_file']);
        $password = self::readSecretFile($config['database']['password_file']);

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};";

        self::$connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Set PostgreSQL session parameters
        self::$connection->exec("SET search_path TO public");
    }

    /**
     * Connect to SQLite
     */
    private static function connectSqlite(array $config): void
    {
        $path = $config['database']['path'];

        if (empty($path)) {
            throw new \RuntimeException('SQLite database path is not configured');
        }

        // Ensure the directory exists
        $dir = dirname($path);
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \RuntimeException("Failed to create SQLite directory: {$dir}");
            }
        }

        $dsn = "sqlite:{$path}";

        self::$connection = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enable WAL mode for better concurrency (SQLite 3.7.0+)
        self::$connection->exec('PRAGMA journal_mode=WAL');
        self::$connection->exec('PRAGMA synchronous=NORMAL');
        self::$connection->exec('PRAGMA busy_timeout=10000');
        self::$connection->exec('PRAGMA foreign_keys=ON');
    }

    /**
     * Read a secret file
     */
    public static function readSecretFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Secret file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read secret file: {$path}");
        }

        return trim($content);
    }
}
