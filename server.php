#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Load .env before anything else
Dotenv\Dotenv::createImmutable(__DIR__)->load();

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

// Redirect all PHP errors/warnings to stderr so they never corrupt stdout
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting(E_ALL);

// Disable any output buffering — responses must reach Claude immediately
while (ob_get_level() > 0) {
    ob_end_clean();
}

$server = Server::builder()
    ->setServerInfo('MySQL MCP Server', '1.0.0')
    // Scan the src/ directory for classes with #[McpTool] attributes
    ->setDiscovery(__DIR__, ['src'])
    ->build();

fwrite(STDERR, "[mysql-mcp] Server starting...\n");

$server->run(new StdioTransport());