# OllamaDev

**Local AI coding agent powered by Ollama**

OllamaDev is a privacy-first CLI tool that brings AI-powered coding assistance to your terminal. It connects to your local Ollama instance, offering 66+ tools for file operations, git management, code intelligence via LSP, and terminal multiplexing—all with zero data leaving your machine.

## Features

### AI-Powered Coding
- **Local-first**: All AI processing happens on your machine via Ollama
- **Multi-model support**: llama, mistral, codellama, qwen, phi, deepseek, wizardcoder, starcoder, gpt-oss, smollm
- **Auto-compaction**: Automatically summarizes long conversations to stay within context limits
- **Model switching**: Switch models at runtime with `/models`

### 66+ Built-in Tools

| Category | Tools |
|----------|-------|
| **File Operations** | view, cat, head, tail, read, write, edit, patch, touch, mkdir, rm, cp, mv |
| **Directory Operations** | ls, cd, pwd, find, tree |
| **Git Operations** | git_status, git_diff, git_log, git_branch, git_checkout, git_commit, git_add, git_push, git_pull, git_clone, git_merge, git_rebase, git_stash, git_remote |
| **Code Intelligence** | goto, find_refs, symbols, hover, diagnostics, format (via LSP) |
| **Web** | fetch |
| **Background** | bg, wait_bg, watch |
| **MCP** | mcp_servers, mcp (Model Context Protocol support) |

### Terminal Multiplexer
Named terminal sessions with:
- `terminal create/spawn/list/start/stop/pause/resume/delete/attach/log/send/broadcast`

### VS Code Extension
Full IDE integration with:
- AI inline completions
- Code generation, review, and explanation commands
- Chat panel webview
- Keyboard shortcuts (Ctrl+Shift+G/R/A/L, Ctrl+Space)

## Requirements

- **PHP 8.0+** (or standalone binary)
- **Ollama** running locally (`ollama serve`)
- One or more Ollama models downloaded (e.g., `ollama pull llama3.2`)

## Installation

### Option 1: Use the Binary (Recommended)

```bash
# Download the latest release from dist/release/ollamadev
chmod +x ollamadev
sudo mv ollamadev /usr/local/bin/

# Verify
ollamadev help
```

### Option 2: From Source

```bash
# Clone the repository
git clone https://github.com/yourusername/ollamadev.git
cd ollamadev

# Build the single-file binary
./build.sh

# The binary will be at dist/release/ollamadev
chmod +x dist/release/ollamadev
sudo mv dist/release/ollamadev /usr/local/bin/
```

### Option 3: PHP Source

```bash
# If you have PHP installed
php ollamadev.php help
```

### Option 4: VS Code Extension

```bash
cd vscode-extension
code --install-extension ollamadev-extension.vsix
```

## Configuration

Config file locations (in priority order):
1. `~/.ollamadev/config.json`
2. `~/.config/ollamadev/config.json`
3. `.ollamadev.json` (local)

### Example Config

```json
{
  "ollama": {
    "host": "http://localhost:11434",
    "defaultModel": "llama3.2:latest"
  },
  "agents": {
    "coder": {
      "temperature": 0.7,
      "maxTokens": 4096
    }
  },
  "lsp": {
    "php": {
      "command": "php",
      "args": ["-l"]
    }
  },
  "mcpServers": {
    "filesystem": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-filesystem", "/path/to/workspace"]
    }
  }
}
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `OLLAMA_HOST` | `http://localhost:11434` | Ollama API endpoint |
| `OLLAMA_MODEL` | `llama3.2:latest` | Default model |

## Usage

### Interactive Chat

```bash
ollamadev
# or
ollamadev chat
```

### Single Prompt

```bash
echo "Explain this function" | ollamadev
```

### Session Management

```bash
ollamadev new                    # Create new session
ollamadev list                   # List all sessions
ollamadev load <session_id>      # Load a session
```

### In-Chat Commands

| Command | Description |
|---------|-------------|
| `/exit`, `/quit`, `/q` | Exit |
| `/new` | Create new session |
| `/models` | List/switch models |
| `/clear` | Clear screen |
| `/verbose` | Toggle verbose output |
| `/help` | Show help |

### Terminal Multiplexer

```bash
ollamadev terminal create myapp llama3.2:latest
ollamadev terminal start myapp
ollamadev terminal attach myapp
ollamadev terminal pause myapp
ollamadev terminal resume myapp
ollamadev terminal list
ollamadev terminal log myapp 50
ollamadev terminal delete myapp
```

### LSP Server Mode

```bash
ollamadev lsp
```

## Data Storage

- Sessions: `~/.ollamadev/sessions/`
- Terminals: `~/.ollamadev/terminals/`
- Checkpoints: `~/.ollamadev/checkpoints/`
- Costs: `~/.ollamadev/costs/`

## Comparison

| Feature | OllamaDev | Claude Code | GitHub Copilot |
|---------|-----------|-------------|----------------|
| AI Provider | Local Ollama | Anthropic (cloud) | Multiple (cloud) |
| Privacy | 100% local | Data sent to cloud | Data sent to cloud |
| Cost | Free (local models) | Paid subscription | Paid subscription |
| MCP Support | Yes | Yes | Yes |
| LSP Diagnostics | Yes | Yes | Limited |
| Terminal Multiplexer | Yes | No | No |
| VS Code Extension | Yes | Yes | Yes |

## Troubleshooting

### "Cannot connect to Ollama"

```bash
# Make sure Ollama is running
ollama serve

# Or set custom host
export OLLAMA_HOST=http://localhost:11434
```

### "Model not found"

```bash
# Pull a model
ollama pull llama3.2:latest
ollama pull codellama:latest
ollama pull mistral:latest

# List available models
ollama list
```

### Permission Denied

```bash
chmod +x ollamadev
```

## License

MIT

## Version

v3.9.2