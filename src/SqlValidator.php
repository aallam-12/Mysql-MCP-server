<?php
declare(strict_types=1);

namespace App;

class SqlValidator
{
    // Numeric rank for each level — higher = more permissive
    private const LEVEL_RANK = [
        'read'  => 1,
        'write' => 2,
        'admin' => 3,
    ];

    // Map each SQL operation category to the minimum level required
    private const OPERATION_LEVELS = [
        'read'  => ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'USE'],
        'write' => ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'],
        'admin' => ['CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'RENAME',
                    'GRANT', 'REVOKE', 'SET', 'CALL', 'EXEC', 'EXECUTE',
                    'LOCK', 'UNLOCK', 'FLUSH', 'RESET', 'PURGE','LOAD'],
    ];

    /**
     * Check if a SQL statement is allowed under the given access level.
     *
     * @throws \InvalidArgumentException for unknown or empty SQL
     */
    public static function isAllowed(string $sql, string $currentLevel): bool
    {
        $required = self::getRequiredLevel($sql);

        $currentRank  = self::LEVEL_RANK[$currentLevel]  ?? 0;
        $requiredRank = self::LEVEL_RANK[$required] ?? 99;

        return $currentRank >= $requiredRank;
    }

    /**
     * Determine the minimum access level required to run this SQL.
     * Returns 'admin' for anything unrecognised (safest default).
     */
    public static function getRequiredLevel(string $sql): string
    {
        // Guard against multi-statement execution (e.g. "SELECT 1; DROP TABLE x")
        // Strip string literals first to avoid false positives on semicolons inside strings
        $withoutStrings = preg_replace("/'[^']*'|\"[^\"]*\"/", "''", $sql);
        if (substr_count($withoutStrings, ';') > 1 ||
            (substr_count($withoutStrings, ';') === 1 && !str_ends_with(trim($withoutStrings), ';'))) {
            return 'admin'; // treat multi-statement as highest risk
        }

        $clean = self::stripComments($sql);
        $keyword = self::extractFirstKeyword($clean);

        if ($keyword === null) {
            return 'admin'; // empty or unrecognisable → deny unless admin
        }

        foreach (self::OPERATION_LEVELS as $level => $keywords) {
            if (in_array($keyword, $keywords, true)) {
                return $level;
            }
        }

        return 'admin'; // unknown keyword → safest default
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * Remove SQL comments before keyword extraction.
     *
     * Order: block comments first, then line comments.
     * This prevents a line comment containing '*\/' from confusing block parsing.
     */
    public static function stripComments(string $sql): string
    {
        // Strip block comments: /* ... */  (non-greedy, dotall)
        $sql = preg_replace('!/\*.*?\*/!s', ' ', $sql);

        // Strip line comments: -- ... (to end of line)
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql);

        // Strip # comments (MySQL extension): # ... (to end of line)
        $sql = preg_replace('/#[^\r\n]*/', ' ', $sql);

        return trim($sql);
    }

    /**
     * Extract the first SQL keyword (alpha chars only) from a cleaned statement.
     * Returns it uppercased, or null if nothing found.
     */
    private static function extractFirstKeyword(string $sql): ?string
    {
        if (preg_match('/^\s*([A-Za-z]+)\b/', $sql, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}