# OllamaDev

A terminal-based AI coding agent that uses local Ollama models. Privacy-first, fully offline.

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/kennethyork/OllamaDev/main/install | bash
```

## Requirements

- [Ollama](https://ollama.ai/) installed and running
- A local model (e.g., `ollama pull codellama`)

## Usage

```bash
ollamadev                    # Start interactive TUI
ollamadev -c /path/to/project  # Start with specific directory
```

## Features

- **Interactive TUI** - Built with Bubble Tea, same as OpenCode
- **Local-only** - Your code never leaves your machine
- **Tool permission system** - Approve/deny dangerous operations
- **Session persistence** - SQLite-backed conversations
- **Auto-compact** - Automatically summarizes long conversations
- **MCP support** - Connect to Model Context Protocol servers
- **LSP integration** - Get diagnostics while coding

## Tools

| Tool | Description | Permission |
|------|-------------|------------|
| `view` | View file with line numbers | No |
| `write` | Create/overwrite files | Yes |
| `edit` | Replace text in files | Yes |
| `glob` | Find files by pattern | No |
| `grep` | Search with regex | No |
| `ls` | List directory | No |
| `bash` | Execute commands | Yes |
| `fetch` | Fetch URL content | Yes |
| `diagnostics` | LSP diagnostics | No |
| `agent` | Run sub-agent | No |

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Ctrl+C` | Quit |
| `Ctrl+K` | Toggle help |
| `Ctrl+O` | Select model |
| `Ctrl+A` | Switch session |
| `Ctrl+L` | View logs |
| `Ctrl+N` | New session |
| `Ctrl+X` | Cancel operation |
| `Ctrl+E` | Open external editor |
| `Esc` | Close dialog |
| `↑/↓` | Navigate lists |
| `Enter` | Select/send |

## Configuration

Config is stored at `~/.ollamadev/config.json` or `./.ollamadev.json` (local takes precedence).

```json
{
  "ollama": {
    "host": "http://localhost:11434",
    "defaultModel": "codellama"
  },
  "agents": {
    "coder": {
      "model": "codellama",
      "temperature": 0.7,
      "maxTokens": 4096
    }
  },
  "shell": {
    "path": "/bin/bash",
    "args": ["-l"]
  },
  "autoCompact": true
}
```

## Architecture

```
ollamadev/
├── cmd/              # CLI commands (Cobra)
├── internal/
│   ├── agent/        # LLM agent & tool execution
│   ├── client/       # Ollama API client
│   ├── config/       # Viper-based configuration
│   ├── db/           # SQLite persistence
│   ├── llm/tools/    # Tool implementations
│   ├── lsp/          # Language Server Protocol
│   ├── mcp/          # Model Context Protocol
│   ├── permission/   # Permission system
│   └── tui/          # Bubble Tea TUI
```

## License

MIT