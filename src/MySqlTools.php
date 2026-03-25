<?php
declare(strict_types=1);

namespace App;

use Mcp\Capability\Attribute\McpTool;
use PDO;

class MySqlTools
{
    /**
     * Execute a SQL statement against the database.
     * The statement will be validated against the current access level before execution.
     * Read level allows SELECT/SHOW/DESCRIBE/EXPLAIN.
     * Write level additionally allows INSERT/UPDATE/DELETE/REPLACE.
     * Admin level allows all statements including DDL (CREATE/DROP/ALTER etc).
     *
     * @param string $sql  The SQL statement to execute
     * @param int    $limit Maximum rows to return for SELECT queries (default 100, max 500)
     */
    #[McpTool(name: 'execute_sql')]
    public function executeSql(string $sql, int $limit = 100): string
    {
        if (strlen($sql) > 10000) {
          return 'Error: SQL statement exceeds maximum allowed length (10,000 characters).';
        }

        // Intercept USE statements — redirect to the dedicated tool to avoid
        // a split-brain scenario where PDO switches databases but the
        // in-memory $currentDatabase tracker is not updated.
        // Strip comments first so that "/* */ USE db" cannot bypass detection.
        if (preg_match('/^\s*USE\s+/i', SqlValidator::stripComments($sql))) {
            return 'To switch databases, please use the use_database tool instead of writing USE statements directly. '
                 . 'This ensures the server correctly tracks which database is active.';
        }

        $level = AccessControl::getCurrentLevel();

        if (!SqlValidator::isAllowed($sql, $level)) {
            $required = SqlValidator::getRequiredLevel($sql);
            return "Access denied: this statement requires '{$required}' level, "
                . "but current level is '{$level}'. "
                . "Ask the user to run: php set-level.php {$required}";
        }

        try {
            $pdo  = MySqlConnection::get();
            $type = SqlValidator::getRequiredLevel($sql);

            if ($type === 'read') {
                return $this->runSelect($pdo, $sql, min($limit, 500));
            }

            return $this->runMutation($pdo, $sql);

        } catch (\PDOException $e) {
            return 'Database error: ' . $e->getMessage();
        } catch (\RuntimeException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * List all tables in the currently active database.
     * Shows the database name for context when working with multiple databases.
     */
    #[McpTool(name: 'list_tables')]
    public function listTables(): string
    {
        try {
            $pdo     = MySqlConnection::get();
            $current = MySqlConnection::getCurrentDatabase();

            if ($current === null) {
                return 'No database selected. Use the use_database tool to select one first.';
            }

            $stmt = $pdo->query('SHOW TABLES');
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($rows)) {
                return "No tables found in database '{$current}'.";
            }

            return "Tables in '{$current}':\n" . implode("\n", $rows);
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Describe the columns of a specific table, including data types,
     * nullability, default values, and key information.
     *
     * @param string $table The name of the table to describe
     */
    #[McpTool(name: 'describe_table')]
    public function describeTable(string $table): string
    {
        // Validate table name: only allow alphanumeric + underscore
        // We cannot use a prepared statement for identifiers (table/column names),
        // only for values — so we whitelist the characters manually.
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return 'Error: invalid table name. Only letters, numbers, and underscores are allowed.';
        }

        $current = MySqlConnection::getCurrentDatabase();
        if ($current === null) {
            return 'No database selected. Use the use_database tool to select one first.';
        }

        try {
            $pdo  = MySqlConnection::get();
            // Backtick-quote the validated identifier
            $stmt = $pdo->query("DESCRIBE `{$table}`");
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return "Table '{$table}' not found in database '{$current}'.";
            }

            return $this->formatTable($rows);
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Return the current access level (read, write, or admin).
     * This is informational only — the level can only be changed by the user
     * via the set-level.php CLI script, never by Claude.
     */
    #[McpTool(name: 'get_access_level')]
    public function getAccessLevel(): string
    {
        $level = AccessControl::getCurrentLevel();
        $map = [
            'read'  => 'read — SELECT, SHOW, DESCRIBE, EXPLAIN only',
            'write' => 'write — read operations + INSERT, UPDATE, DELETE, REPLACE',
            'admin' => 'admin — all operations including DDL (CREATE, DROP, ALTER, etc.)',
        ];

        return 'Current access level: ' . ($map[$level] ?? $level);
    }

    /**
     * List all databases available on the MySQL server.
     * Returns only databases the connected user has permission to see.
     * The currently active database is marked with an asterisk (*).
     */
    #[McpTool(name: 'list_databases')]
    public function listDatabases(): string
    {
        try {
            $pdo  = MySqlConnection::get();
            $stmt = $pdo->query('SHOW DATABASES');
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($rows)) {
                return 'No databases found (or insufficient privileges).';
            }

            $current = MySqlConnection::getCurrentDatabase();
            $lines   = array_map(
                fn(string $db) => ($db === $current ? '* ' : '  ') . $db,
                $rows
            );

            $header = $current !== null
                ? "Databases (current: {$current}):"
                : 'Databases (no database selected):';

            return $header . "\n" . implode("\n", $lines);
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Switch the active database for all subsequent queries.
     * This does not require reconnecting — it uses MySQL's USE statement internally.
     *
     * @param string $database The name of the database to switch to
     */
    #[McpTool(name: 'use_database')]
    public function useDatabase(string $database): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            return 'Error: invalid database name. Only letters, numbers, and underscores are allowed.';
        }

        $level = AccessControl::getCurrentLevel();
        if (!in_array($level, ['read', 'write', 'admin'], true)) {
            return 'Access denied: unable to verify access level.';
        }

        try {
            MySqlConnection::useDatabase($database);
            return "Switched to database: {$database}";
        } catch (\PDOException $e) {
            return 'Error: ' . $e->getMessage();
        } catch (\RuntimeException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * Run a SELECT-class query and return formatted results.
     * Appends LIMIT automatically if not already present.
     */
    private function runSelect(PDO $pdo, string $sql, int $limit): string
    {
        // Append LIMIT only if not already in the query
        if (!preg_match('/\bLIMIT\b/i', $sql)) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            return 'Query returned no rows.';
        }

        return $this->formatTable($rows) . "\n(" . count($rows) . " row(s))";
    }

    /**
     * Run an INSERT/UPDATE/DELETE/DDL statement and report affected rows.
     */
    private function runMutation(PDO $pdo, string $sql): string
    {
        $affected = $pdo->exec($sql);
        return "OK. Rows affected: {$affected}";
    }

    /**
     * Format an array of associative arrays as a plain-text table.
     */
    private function formatTable(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $widths  = [];

        // Calculate column widths from headers
        foreach ($headers as $h) {
            $widths[$h] = strlen((string) $h);
        }

        // Expand column widths based on data
        foreach ($rows as $row) {
            foreach ($headers as $h) {
                $len = strlen((string) ($row[$h] ?? 'NULL'));
                if ($len > $widths[$h]) {
                    $widths[$h] = $len;
                }
            }
        }

        // Build header row
        $line = $this->buildRow($headers, $widths, $headers);
        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        $output = $separator . "\n" . $line . "\n" . $separator . "\n";

        // Build data rows
        foreach ($rows as $row) {
            $output .= $this->buildRow($headers, $widths, $row) . "\n";
        }

        return $output . $separator;
    }

    private function buildRow(array $headers, array $widths, array $row): string
    {
        $cells = array_map(
            fn($h) => ' ' . str_pad((string) ($row[$h] ?? 'NULL'), $widths[$h]) . ' ',
            $headers
        );
        return '|' . implode('|', $cells) . '|';
    }
}