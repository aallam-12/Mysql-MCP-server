# MySQL MCP Server (PHP)

A [Model Context Protocol](https://modelcontextprotocol.io) server written in PHP that gives your AI assistant controlled access to MySQL databases, with three enforced access levels that only the human operator can change. Supports multiple databases — list, switch, and query across all databases on the server.

## Architecture

```
AI assistant (MCP Host)
  └─ stdio pipe ─── server.php (PHP process)
                         ├─ AccessControl  ← reads HMAC-signed sentinel file
                         ├─ SqlValidator   ← classifies SQL before execution
                         └─ MySqlConnection (PDO singleton) → MySQL
                              ├─ tracks current database in-memory
                              └─ transparent reconnect with database restore
```

The AI assistant has **no tool to change the access level**. Level changes happen exclusively via `set-level.php`, run by the operator in a separate terminal.

## Access Levels

| Level   | Allowed SQL                                              |
|---------|----------------------------------------------------------|
| `read`  | SELECT, SHOW, DESCRIBE, EXPLAIN, USE                     |
| `write` | read + INSERT, UPDATE, DELETE, REPLACE                   |
| `admin` | write + CREATE, DROP, ALTER, TRUNCATE, GRANT, REVOKE ... |

The server defaults to `read` on startup if no sentinel file exists or if the file fails HMAC validation.

## Requirements

- PHP 8.1+
- Composer
- MySQL / MariaDB
- Node.js (optional, for MCP Inspector testing)

## Installation

```bash
# 1. Clone / navigate to the project directory
cd /path/to/mysql-mcp-server

# 2. Install dependencies
composer install

# 3. Copy and fill in the environment file
cp .env.example .env   # Linux/Mac
copy .env.example .env  # Windows

# 4. Generate a bcrypt hash for your PIN
php -r "echo password_hash('your_pin', PASSWORD_BCRYPT) . PHP_EOL;"
# Paste the output into ACCESS_PIN_HASH in .env

# 5. Generate an HMAC secret
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
# Paste the output into HMAC_SECRET in .env

# 6. Set the initial access level
php set-level.php read
```

## Project Structure

```
mysql-mcp-server/
├── .env                        # Secrets — never commit this
├── .env.example                # Safe template — commit this
├── .gitignore
├── composer.json
├── server.php                  # MCP server entry point (stdio)
├── set-level.php               # Operator CLI to change access level
├── src/
│   ├── Config.php              # Env variable wrapper
│   ├── AccessControl.php       # HMAC-signed level read/write + PIN verify
│   ├── SqlValidator.php        # SQL statement type detection
│   ├── MySqlConnection.php     # PDO singleton with database tracking
│   └── MySqlTools.php          # MCP tool definitions (#[McpTool])
└── storage/
    └── access_level.json       # Runtime level state (auto-created)
```

## Changing the Access Level

Run in a terminal:

```bash
php set-level.php read
php set-level.php write
php set-level.php admin
```

You will be prompted for your PIN. The server picks up the change on the next tool call — no restart needed.

## Connecting to Claude

Run the following command from inside the project directory.

**Claude Code (CLI):**
```bash
claude mcp add mysql php /absolute/path/to/mysql-mcp-server/server.php
```

**Claude Desktop:**
```bash
claude mcp add mysql php /absolute/path/to/mysql-mcp-server/server.php --client claude
```
Replace `/absolute/path/to/mysql-mcp-server` with the actual path on your machine:
- Linux/Mac example: `/home/youruser/projects/mysql-mcp-server`
- Windows example: `C:/Users/youruser/projects/mysql-mcp-server`

Verify the server is registered:
```bash
claude mcp list
```

**Cursor:**

Open (or create) `~/.cursor/mcp.json` and add:

```json
{
  "mcpServers": {
    "mysql": {
      "command": "php",
      "args": ["/absolute/path/to/mysql-mcp-server/server.php"],
      "cwd": "/absolute/path/to/mysql-mcp-server"
    }
  }
}
```

- `cwd` is required so the server can locate `.env` in its own directory.
- Restart Cursor after saving the file. The `mysql` server will appear in Cursor's MCP tool list.

**OpenCode:**

Open (or create) `~/.config/opencode/config.json` and add:

```json
{
  "mcpServers": {
    "mysql": {
      "command": "php",
      "args": ["/absolute/path/to/mysql-mcp-server/server.php"],
      "cwd": "/absolute/path/to/mysql-mcp-server"
    }
  }
}
```

Restart OpenCode after saving.

**OpenAI Codex CLI:**

Open (or create) `~/.codex/config.json` and add:

```json
{
  "mcpServers": {
    "mysql": {
      "command": "php",
      "args": ["/absolute/path/to/mysql-mcp-server/server.php"],
      "cwd": "/absolute/path/to/mysql-mcp-server"
    }
  }
}
```

Restart Codex after saving.

## Available Tools

| Tool               | Description                                                  | Min Level |
|--------------------|--------------------------------------------------------------|-----------|
| `execute_sql`      | Execute a SQL statement (level-enforced)                     | dynamic   |
| `list_tables`      | List all tables in the current database                      | read      |
| `describe_table`   | Describe columns of a specific table                         | read      |
| `get_access_level` | Return the current access level (read-only info)             | read      |
| `list_databases`   | List all databases on the server (current one marked with *) | read      |
| `use_database`     | Switch the active database for all subsequent queries        | read      |

### Multi-Database Workflow

The server supports working across multiple databases on the same MySQL server:

1. **List databases** — call `list_databases` to see all available databases. The currently active one is marked with `*`.
2. **Switch database** — call `use_database` with the target database name to switch. All subsequent queries (`execute_sql`, `list_tables`, `describe_table`) will operate on the new database.
3. **Initial database** — if `DB_NAME` is set in `.env`, it becomes the default database on startup. If `DB_NAME` is empty or omitted, no database is selected until you call `use_database`.

The database switch uses MySQL's `USE` statement internally — it is a lightweight session command that does not reconnect or re-authenticate. If the connection drops, the server automatically restores the selected database on reconnect.

## Testing with MCP Inspector

```bash
npx @modelcontextprotocol/inspector php /absolute/path/to/mysql-mcp-server/server.php
```

Opens a browser UI where you can call tools manually and inspect the JSON-RPC messages.

## Security Model

| Threat | Mitigation |
|---|---|
| The AI escalating its own privileges | No level-change tool exists — zero API surface |
| Forging `access_level.json` | HMAC-SHA256 signed with a secret key the AI never sees |
| SQL injection via tool arguments | PDO real prepared statements (`ATTR_EMULATE_PREPARES = false`) |
| Identifier injection in `describe_table` / `use_database` | Strict regex whitelist (`[a-zA-Z0-9_]`) + backtick escaping (defense-in-depth) |
| Comment-wrapped statement bypass | Comments stripped before keyword classification and USE interception |
| Multi-statement execution (`SELECT 1; DROP TABLE x`) | Semicolon count guard → classified as `admin` |
| Database switch via raw SQL (`USE db` in `execute_sql`) | Intercepted and redirected to `use_database` tool to prevent state desync |
| Secrets in the AI config | Credentials live only in `.env`, loaded by the server itself |
