# OllamaDev

A terminal-based AI coding agent powered by local Ollama models. Privacy-first, fully offline, single PHP file.

## Install

```bash
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev
chmod +x /usr/local/bin/ollamadev
```

**Or build from source:**
```bash
git clone https://github.com/kennethyork/OllamaDev.git
cd OllamaDev
./build.sh
```

## Requirements

- PHP 8.0+ (`php -v`)
- [Ollama](https://ollama.ai/) running (`ollama serve`)
- Local models downloaded (`ollama pull llama3.2`)

## Usage

```bash
ollamadev                    # Start interactive chat
ollamadev chat               # Same as above
ollamadev new                # Create new session
ollamadev list               # List sessions
ollamadev load <id>          # Resume session
ollamadev help               # Show help
```

## In-chat Commands

| Command | Action |
|---------|--------|
| `model` | Show available models |
| `model <name>` | Switch to model |
| `exit` / `quit` | Exit |
| `new` | New session |
| `clear` | Clear screen |
| `help` | Show banner |

## Features

- **Agentic loop** - Iterative tool use until task complete
- **Local-only** - Code never leaves your machine
- **Auto-select model** - Uses latest installed model by modified date
- **Session persistence** - JSON-backed conversations
- **MCP support** - Connect to Model Context Protocol servers
- **LSP diagnostics** - Get lint errors for multiple languages
- **Streaming** - Real-time response streaming

## Tools

| Tool | Description |
|------|-------------|
| `view` | View file with line numbers |
| `write` | Create/overwrite files |
| `edit` | Replace text in files (old/new) |
| `glob` | Find files by glob pattern |
| `grep` | Search with regex |
| `ls` | List directory contents |
| `bash` | Execute read-only commands |
| `fetch` | Fetch URL content |
| `patch` | Apply unified diff |
| `diagnostics` | Get lint/syntax errors |
| `mcp_servers` | List MCP servers |
| `mcp` | Call MCP tool |

## Configuration

Config at `~/.ollamadev/config.json` or `.ollamadev.json`:

```json
{
  "ollama": {
    "host": "http://localhost:11434"
  },
  "data": {
    "directory": ".ollamadev"
  },
  "mcpServers": {
    "myserver": {
      "type": "sse",
      "url": "http://localhost:3000"
    }
  },
  "lsp": {
    "php": { "command": "phpactor" }
  }
}
```

## System Prompt

Optimized for local Ollama models with explicit instructions:
- Numbered tool list with clear parameter formats
- Step-by-step agentic workflow
- No assumed knowledge - state everything explicitly
- Must use tools, no "I would need" responses

## Architecture

```
ollamadev.php    # Entry point (single-file CLI)
Agent.php        # LLM agent & tool parsing
Config.php       # Configuration loading
OllamaClient.php # Ollama API client
Session.php      # Session management
Tools.php        # Tool implementations
build.sh         # Builds single-file binary
dist/release/     # Built binary
```

## License

MIT