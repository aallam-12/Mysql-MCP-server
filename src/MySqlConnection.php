<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class MySqlConnection
{
    private static ?PDO $pdo = null;

    /** The database currently selected on the connection (runtime state). */
    private static ?string $currentDatabase = null;

    /** The initial database from .env DB_NAME (null if omitted). */
    private static ?string $defaultDatabase = null;

    /**
     * Return the shared PDO instance, creating it on first call.
     *
     * If the connection was dropped (stale ping), it is transparently
     * recreated and the runtime-selected database is restored so that
     * callers never notice the reconnect.
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
            // Save the desired database BEFORE createConnection() overwrites
            // $currentDatabase with $defaultDatabase (from .env DB_NAME).
            $desiredDatabase = self::$currentDatabase;

            self::$pdo = self::createConnection();

            // Restore runtime database selection after reconnect.
            // createConnection() resets $currentDatabase to $defaultDatabase,
            // but the user may have switched to a different database before
            // the connection dropped — restore that choice.
            if ($desiredDatabase !== null
                && $desiredDatabase !== self::$defaultDatabase) {
                try {
                    self::$pdo->exec('USE `' . self::escapeIdentifier($desiredDatabase) . '`');
                    self::$currentDatabase = $desiredDatabase;
                } catch (\PDOException) {
                    // Restore failed (database dropped, privileges revoked, etc.).
                    // $currentDatabase stays at whatever createConnection() set
                    // (the .env default), which matches the actual PDO state.
                }
            }
        }

        return self::$pdo;
    }

    /**
     * Switch the active database on the existing connection.
     *
     * Uses MySQL's USE statement internally — this is a lightweight
     * session-scoped command that does not reconnect or re-authenticate.
     * The $currentDatabase tracker is updated only after the statement
     * succeeds, so a failed switch (unknown database, access denied)
     * leaves the state unchanged.
     *
     * @throws \PDOException if MySQL rejects the USE statement
     */
    public static function useDatabase(string $database): void
    {
        $pdo = self::get();
        $pdo->exec('USE `' . self::escapeIdentifier($database) . '`');
        self::$currentDatabase = $database;
    }

    /**
     * Return the name of the currently active database, or null if none selected.
     */
    public static function getCurrentDatabase(): ?string
    {
        return self::$currentDatabase;
    }

    private static function createConnection(): PDO
    {
        $host    = Config::get('DB_HOST', '127.0.0.1');
        $port    = Config::get('DB_PORT', '3306');
        $dbname  = Config::get('DB_NAME', '');
        $user    = Config::get('DB_USER');
        $pass    = Config::get('DB_PASS');

        // Build the DSN. If DB_NAME is empty/omitted, connect to the server
        // without selecting a database — the user can pick one at runtime
        // via the use_database tool.
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($dbname !== '') {
            $dsn .= ";dbname={$dbname}";
            self::$defaultDatabase = $dbname;
            self::$currentDatabase = $dbname;
        }

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

    /**
     * Escape a SQL identifier by doubling internal backticks.
     *
     * SQL identifiers (database/table/column names) cannot use prepared-
     * statement parameter binding — only values can be bound. The standard
     * defense is backtick-quoting with backtick-doubling for any backtick
     * already present in the name. Combined with regex validation at the
     * tool layer, this provides defense-in-depth.
     */
    private static function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    // Prevent instantiation — this class is used statically only
    private function __construct() {}
}