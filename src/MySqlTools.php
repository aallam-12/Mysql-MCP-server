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
     * List all tables in the connected database.
     */
    #[McpTool(name: 'list_tables')]
    public function listTables(): string
    {
        try {
            $pdo  = MySqlConnection::get();
            $stmt = $pdo->query('SHOW TABLES');
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($rows)) {
                return 'No tables found in the database.';
            }

            return implode("\n", $rows);
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

        try {
            $pdo  = MySqlConnection::get();
            // Backtick-quote the validated identifier
            $stmt = $pdo->query("DESCRIBE `{$table}`");
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                return "Table '{$table}' not found or has no columns.";
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