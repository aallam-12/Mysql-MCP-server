<?php
declare(strict_types=1);

namespace App;

class AccessControl
{
    private const LEVELS = ['read', 'write', 'admin'];
    private const STATE_FILE = __DIR__ . '/../storage/access_level.json';

    /**
     * Read the current access level from the sentinel file.
     * Returns 'read' as the safe default if the file is missing,
     * malformed, or has an invalid HMAC (tamper detected).
     */
    public static function getCurrentLevel(): string
    {
        if (!file_exists(self::STATE_FILE)) {
            return 'read';
        }

        $raw = file_get_contents(self::STATE_FILE);
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['level'], $data['changed_at'], $data['hmac'])) {
            return 'read';
        }

        if (!self::verifyHmac($data['level'], $data['changed_at'], $data['hmac'])) {
            return 'read'; // tamper detected — fail safe
        }

        if (!in_array($data['level'], self::LEVELS, true)) {
            return 'read';
        }

        return $data['level'];
    }

    /**
     * Change the access level. Verifies the PIN before writing.
     *
     * @throws \InvalidArgumentException if level is invalid
     * @throws \RuntimeException if PIN is wrong
     */
    public static function setLevel(string $level, string $pin): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(
                "Invalid level '{$level}'. Must be one of: " . implode(', ', self::LEVELS)
            );
        }

        $pinHash = Config::get('ACCESS_PIN_HASH');

        if (!password_verify($pin, $pinHash)) {
            throw new \RuntimeException('Incorrect PIN.');
        }

        $changedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $hmac = self::computeHmac($level, $changedAt);

        $payload = json_encode([
            'level'      => $level,
            'changed_at' => $changedAt,
            'hmac'       => $hmac,
        ], JSON_PRETTY_PRINT);

        $dir = dirname(self::STATE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents(self::STATE_FILE, $payload);
        fwrite(STDERR, "[mysql-mcp] Access level changed to: {$level} at {$changedAt}\n");
    }

    // ── private helpers ──────────────────────────────────────────────────────

    private static function computeHmac(string $level, string $changedAt): string
    {
        $secret = Config::get('HMAC_SECRET');
        return hash_hmac('sha256', $level . $changedAt, $secret);
    }

    private static function verifyHmac(string $level, string $changedAt, string $given): bool
    {
        $expected = self::computeHmac($level, $changedAt);
        // hash_equals prevents timing attacks
        return hash_equals($expected, $given);
    }
}