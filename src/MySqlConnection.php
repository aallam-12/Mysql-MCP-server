<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class MySqlConnection
{
    private static ?PDO $pdo = null;

    /**
     * Return the shared PDO instance, creating it on first call.
     *
     * @throws \RuntimeException if the connection fails
     */
    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            try {
                self::$pdo->query('SELECT 1'); // ping
            } catch (\PDOException) {
                self::$pdo = null; // stale connection — reconnect below
            }
        }

        if (self::$pdo === null) {
            self::$pdo = self::createConnection();
        }

        return self::$pdo;
    }

    private static function createConnection(): PDO
    {
        $host    = Config::get('DB_HOST', '127.0.0.1');
        $port    = Config::get('DB_PORT', '3306');
        $dbname  = Config::get('DB_NAME');
        $user    = Config::get('DB_USER');
        $pass    = Config::get('DB_PASS');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                // Throw exceptions on error instead of returning false
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

                // Use real MySQL prepared statements, not PHP-emulated ones
                PDO::ATTR_EMULATE_PREPARES   => false,

                // Return rows as associative arrays by default
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Don't convert numeric strings to PHP integers automatically
                // (keeps data types predictable and avoids surprises with large IDs)
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Wrap in RuntimeException — don't expose raw PDO internals to MCP callers
            throw new \RuntimeException(
                'Database connection failed: ' . $e->getMessage()
            );
        }

        return $pdo;
    }

    // Prevent instantiation — this class is used statically only
    private function __construct() {}
}