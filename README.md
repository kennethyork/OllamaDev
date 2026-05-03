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
ollamadev terminal --help    # Terminal multiplexer

# Single prompt mode
ollamadev "explain this code"
echo "explain this" | ollamadev
```

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

# Attach to a terminal
ollamadev terminal attach dev

# View logs
ollamadev terminal log dev 50

# Delete
ollamadev terminal delete dev
```

**Example output:**
```
Terminals: 4 | Running: 0 | Stopped: 4

⚫ dev-1 | gemma4:26b | stopped | cwd: ~/project
⚫ dev-2 | gemma4:26b | stopped | cwd: ~/project
⚫ debug  | deepseek-r1:32b | stopped | cwd: ~/debug
⚫ review | qwen3.6:27b | stopped | cwd: ~/review
```

Each terminal has:
- Own working directory
- Own model
- Persistent session history

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
├── Agent.php       # LLM agent & tool parsing
├── Terminal.php    # Terminal multiplexer
├── Config.php      # Configuration
├── OllamaClient.php
├── Session.php
└── Tools.php      # Tool implementations
```

## License

MIT