<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->load();

use App\AccessControl;

// ── validate argument ─────────────────────────────────────────────────────────
$level = $argv[1] ?? null;

if (!in_array($level, ['read', 'write', 'admin'], true)) {
    echo "Usage: php set-level.php <read|write|admin>" . PHP_EOL;
    exit(1);
}

// ── prompt for PIN (hidden input on Linux/Mac, best effort on Windows) ────────
echo "Enter PIN: ";

// On Unix: disable terminal echo so PIN is not displayed
$isUnix = DIRECTORY_SEPARATOR === '/';
if ($isUnix) {
    system('stty -echo');
}

$pin = trim(fgets(STDIN));

if ($isUnix) {
    system('stty echo');
}

echo PHP_EOL;

// ── attempt the level change ──────────────────────────────────────────────────
try {
    AccessControl::setLevel($level, $pin);
    echo "Access level changed to: {$level}" . PHP_EOL;
} catch (\RuntimeException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
} catch (\InvalidArgumentException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
