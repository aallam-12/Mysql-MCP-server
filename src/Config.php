<?php
declare(strict_types=1);

namespace App;

class Config
{
    public static function get(string $key, ?string $default = null): string
    {
        // array_key_exists checks presence, not truthiness — works for empty strings
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        throw new \RuntimeException("Missing required environment variable: {$key}");
    }
}
