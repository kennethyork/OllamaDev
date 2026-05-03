# OllamaDev

A **local AI coding agent** that runs entirely on your machine using Ollama. No cloud, no data leaving your computer. Single PHP binary works on Linux, Mac, and Windows.

## Quick Start

```bash
# One-line install (Linux/Mac)
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev && chmod +x /usr/local/bin/ollamadev

# Windows: see install section below

# Start chatting
ollamadev
```

## Install

### Linux / Mac
```bash
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev
chmod +x /usr/local/bin/ollamadev
```

### Windows
```powershell
# Download both files
irm https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -OutFile $env:LOCALAPPDATA\ollamadev\ollamadev
irm https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev.bat -OutFile $env:LOCALAPPDATA\ollamadev\ollamadev.bat

# Add to PATH
[Environment]::SetEnvironmentVariable("PATH", $env:PATH + ";$env:LOCALAPPDATA\ollamadev", "User")
```

### Build from Source
```bash
git clone https://github.com/kennethyork/OllamaDev.git
cd OllamaDev
./build.sh    # Linux/Mac
php build.sh  # Windows
```

## Requirements

- **PHP 8.0+** (`php -v`)
- **Ollama** running (`ollama serve`)
- **Model** downloaded (`ollama pull llama3.2`)

## CLI Usage

```bash
ollamadev                    # Interactive chat
ollamadev chat               # Same as above
ollamadev new                # New session
ollamadev list               # List sessions
ollamadev load <id>         # Resume session
ollamadev git status         # Git commands
ollamadev terminal --help   # Terminal multiplexer
ollamadev lsp               # LSP server for IDEs

# Single prompt mode
ollamadev "explain this code"
echo "explain this" | ollamadev
```

## VS Code Extension

A VS Code extension that connects to OllamaDev's built-in LSP server for AI-assisted coding.

### Install

1. **Start the LSP server:**
   ```bash
   ollamadev lsp --port 4389
   ```

2. **Install the extension:**
   ```bash
   code --install-extension vscode-extension/ollamadev-lsp-1.1.0.vsix
   ```

   Or download `vscode-extension/ollamadev-lsp-1.1.0.vsix` from releases.

3. **Restart VS Code**

### Features

| Command | Shortcut | Description |
|---------|----------|-------------|
| `OllamaDev: Generate Code` | `Ctrl+Shift+G` | Generate/improve selected code |
| `OllamaDev: Review Code` | `Ctrl+Shift+R` | Get code review suggestions |
| `OllamaDev: Ask AI` | `Ctrl+Shift+A` | Ask about selected code |
| `OllamaDev: Open Chat Panel` | `Ctrl+Shift+L` | Open chat panel |
| `OllamaDev: Toggle Inline Completion` | `Ctrl+Space` | Enable/disable AI completions |

### Configuration

In `.vscode/settings.json`:

```json
{
  "ollamadev.port": 4389,
  "ollamadev.hostname": "127.0.0.1",
  "ollamadev.autoStart": true,
  "ollamadev.model": "llama3.2:latest",
  "ollamadev.inlineCompletionEnabled": false,
  "ollamadev.statusBarEnabled": true
}
```

### Status Bar

The extension shows connection status in the bottom-right status bar:
- `$(AI) OllamaDev: Connected` - LSP server connected
- `$(error) OllamaDev: Disconnected` - Not connected

## LSP Server

OllamaDev includes an LSP (Language Server Protocol) server for IDE integration:

```bash
# Start with defaults (127.0.0.1:4389)
ollamadev lsp

# Custom port/host
ollamadev lsp --port 9090 --hostname 0.0.0.0
```

Connect any LSP-compatible editor:
- **VS Code** - Use the OllamaDev extension (above)
- **IntelliJ** - Install LSP client plugin, connect to `localhost:4389`
- **Neovim** - Use `vim.lsp` or `nvim-lspconfig`

### LSP Features

| Feature | Description |
|---------|-------------|
| Hover | Get code information on hover |
| Goto Definition | Jump to symbol definitions |
| Completions | AI-powered code completions |
| Document Symbols | Navigate file structure |

## Terminal Multiplexer

Run **multiple AI terminals** simultaneously, each with a different model:

```bash
# Create named terminals with different models
ollamadev terminal create dev --model llama3.2:latest
ollamadev terminal create debug --model deepseek-r1:32b

# Spawn 4 terminals at once with same model
ollamadev terminal spawn 4 --model gemma4:26b --prefix dev

# List all terminals
ollamadev terminal list

# Attach to a terminal (Ctrl+C = detach, stays running)
ollamadev terminal attach dev

# Broadcast message to all running terminals
ollamadev terminal broadcast "deployment starting"

# Pause/resume a terminal
ollamadev terminal pause dev
ollamadev terminal resume dev

# Stop (saves state) or delete
ollamadev terminal stop dev
ollamadev terminal delete dev

# View logs
ollamadev terminal log dev 50
```

**Example output:**
```
Terminals: 4 | Running: 2 | Stopped: 2

🟢 dev-1    | gemma4:26b   | running | cwd: ~/project
🟢 dev-2    | gemma4:26b   | running | cwd: ~/project
⏸️ review   | qwen3.6:27b  | paused  | cwd: ~/review
⚫ debug     | deepseek-r1:32b | stopped | cwd: ~/debug
```

Each terminal has:
- Own working directory
- Own model
- Persistent session history
- Broadcast messaging
- Pause/resume support

## In-Chat Commands

| Command | Action |
|---------|--------|
| `model` | Show available models |
| `model <name>` | Switch model |
| `new` | New session |
| `clear` | Clear screen |
| `exit` / `quit` | Exit |

## Tools (66 total)

### File Operations
| Tool | Description |
|------|-------------|
| `view` | View file with line numbers |
| `cat` | Read file |
| `head` / `tail` | First/last n lines |
| `read` | Alias for view |

### File Editing
| Tool | Description |
|------|-------------|
| `write` | Create/overwrite file |
| `edit` | Replace text (old→new) |
| `patch` | Apply unified diff |
| `touch` | Create empty file |
| `mkdir` | Create directory |
| `rm` | Delete file/directory |
| `cp` | Copy file/directory |
| `mv` | Move/rename |

### Directory Operations
| Tool | Description |
|------|-------------|
| `ls` | List directory contents |
| `cd` / `pwd` | Change/show directory |
| `find` | Find files by name |
| `tree` | Directory tree |
| `glob` | Find by glob pattern |

### File Analysis
| Tool | Description |
|------|-------------|
| `grep` | Search with regex |
| `wc` | Count lines/words/chars |
| `stat` | File stats |
| `diff` | Compare two files |
| `sort` / `uniq` | Sort lines |

### Git
| Tool | Description |
|------|-------------|
| `git_status` | Working tree status |
| `git_diff` | Show changes |
| `git_log` | Commit history |
| `git_branch` | List branches |
| `git_checkout` | Switch branches |
| `git_commit` | Commit changes |
| `git_add` | Stage changes |
| `git_push` / `git_pull` | Remote operations |
| `git_clone` | Clone repository |
| `git_stash` | Manage stashes |

### Code Intelligence
| Tool | Description |
|------|-------------|
| `goto` | Go to symbol definition |
| `find_refs` | Find symbol references |
| `symbols` | List file symbols |
| `diagnostics` | Syntax/lint errors |
| `format` | Format code |
| `lsp` | Send LSP command |

### System
| Tool | Description |
|------|-------------|
| `bash` | Execute shell command |
| `fetch` | Download URL content |
| `bg` | Run in background |
| `watch` | Poll for file changes |
| `agent` | Run sub-agent task |
| `mcp` | Call MCP server tool |

## Features

- **Local-only** - Code never leaves your machine
- **Cross-platform** - Linux, Mac, Windows
- **66 tools** - Full filesystem, git, and code operations
- **Multi-terminal** - Multiple AI agents with different models
- **VS Code extension** - AI coding assistance directly in VS Code
- **LSP server** - Connect any LSP-compatible editor
- **Session persistence** - JSON-backed conversations
- **MCP support** - Connect to Model Context Protocol servers
- **Streaming** - Real-time response output

## Configuration

Config file: `~/.ollamadev/config.json`

```json
{
  "ollama": {
    "host": "http://localhost:11434",
    "defaultModel": "llama3.2:latest"
  },
  "mcpServers": {
    "filesystem": {
      "type": "sse",
      "url": "http://localhost:3000"
    }
  }
}
```

## Architecture

```
ollamadev          # Single-file binary
├── build.sh       # Builds the binary
├── Terminal.php    # Terminal multiplexer
└── Source classes embedded in binary:
    ├── Agent.php       # LLM agent & tool parsing
    ├── Config.php      # Configuration
    ├── OllamaClient.php
    ├── Session.php
    └── Tools.php      # Tool implementations

vscode-extension/  # VS Code extension
├── package.json   # Extension manifest
├── extension.ts   # Extension source
└── ollamadev-lsp-1.1.0.vsix  # Built package
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md)

## License

MIT