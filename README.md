# OllamaDev

**Local AI coding agent** - runs entirely on your machine with Ollama. No cloud, no data leaving your computer. Single 145KB PHP binary.

## Quick Start

```bash
# Install (Linux/Mac)
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev && chmod +x /usr/local/bin/ollamadev

# Start chatting
ollamadev
```

## Why OllamaDev?

| | OllamaDev | Cursor | Copilot |
|--|--|--|--|
| Local-only (privacy) | ✅ | ❌ | ❌ |
| Terminal-native | ✅ | ❌ | ❌ |
| LSP for any IDE | ✅ | ❌ | ❌ |
| VS Code + CLI | ✅ | ✅ | ✅ |
| Single PHP binary | ✅ | ❌ (Electron) | ❌ |
| Git integration | ✅ | Partial | Partial |
| Web fetch | ✅ | ❌ | ❌ |
| Free & open source | ✅ | ❌ | ❌ |

## Install

### Linux / Mac
```bash
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev
chmod +x /usr/local/bin/ollamadev
```

### Windows
```powershell
irm https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -OutFile $env:LOCALAPPDATA\ollamadev\ollamadev
irm https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev.bat -OutFile $env:LOCALAPPDATA\ollamadev\ollamadev.bat
```

### Build from Source
```bash
git clone https://github.com/kennethyork/OllamaDev.git
cd OllamaDev
./build.sh
```

## Requirements

- **PHP 8.0+** (`php -v`)
- **Ollama** running (`ollama serve`)
- **Model** downloaded (`ollama pull llama3.2`)

## CLI Usage

```bash
ollamadev                     # Interactive chat
ollamadev "explain this"     # Single prompt
echo "fix bug" | ollamadev    # Pipe input

ollamadev git status          # Git commands
ollamadev git commit "fixes"  # AI-assisted commits

ollamadev lsp                 # Start LSP server for IDEs
ollamadev terminal create dev # Create terminal session
ollamadev terminal attach dev  # Chat with AI
```

## Terminal Multiplexer

Named AI terminals with different models - switch between them:

```bash
ollamadev terminal create dev --model llama3.2:latest
ollamadev terminal create review --model deepseek-r1:32b
ollamadev terminal attach dev      # Chat with dev terminal
ollamadev terminal list            # See all terminals
ollamadev terminal delete dev      # Clean up
```

## VS Code Extension

**Install:**
```bash
code --install-extension vscode-extension/ollamadev-lsp-1.1.0.vsix
```

**Start LSP server:**
```bash
ollamadev lsp --port 4389
```

**Keyboard shortcuts:**

| Shortcut | Action |
|----------|--------|
| `Ctrl+Shift+G` | Generate code |
| `Ctrl+Shift+R` | Review code |
| `Ctrl+Shift+A` | Ask AI |
| `Ctrl+Shift+L` | Open chat panel |
| `Ctrl+Space` | Toggle inline completion |
| `Ctrl+Shift+F` | Format document |
| `Ctrl+.` | Quick fix |

**Settings (`.vscode/settings.json`):**
```json
{
  "ollamadev.port": 4389,
  "ollamadev.model": "llama3.2:latest",
  "ollamadev.statusBarEnabled": true
}
```

## LSP Features

Works with VS Code, Neovim, Emacs, Sublime, IntelliJ - any LSP client:

| Feature | Description |
|---------|-------------|
| Hover | Get code info on hover |
| Goto Definition | Jump to definitions |
| Find References | Locate symbol usage |
| Completions | AI-powered suggestions |
| Code Actions | AI quick fixes |
| Formatting | Auto-format code |

## 66 Tools

**File:** view, cat, head, tail, read, write, edit, patch, touch, mkdir, rm, cp, mv, ls, cd, pwd, find, tree, glob, wc, stat, diff, sort, uniq

**Git:** status, diff, log, branch, checkout, commit, add, push, pull, stash, clone

**Code:** goto, goto_definition, find_refs, symbols, hover, diagnostics, format, lsp

**System:** bash, execute_command, editor, watch, fetch, bg, wait_bg, agent, mcp

## Architecture

```
ollamadev (145KB PHP binary)
├── build.sh → dist/release/ollamadev
├── 66 embedded tools
├── LSP server
├── Terminal multiplexer
└── AI agent with Ollama

vscode-extension/
└── ollamadev-lsp-1.1.0.vsix
```

## Configuration

`~/.ollamadev/config.json`:
```json
{
  "ollama": {
    "host": "http://localhost:11434",
    "defaultModel": "llama3.2:latest"
  },
  "mcpServers": {}
}
```

## Comparison

OllamaDev is the **terminal-first** alternative to Cursor/Copilot:

- **Cursor** - GUI-native, cloud-optional, Electron
- **Copilot** - Cloud-first, VS Code plugin only
- **OllamaDev** - Terminal-native, local-only, any LSP editor, 145KB binary

Same AI-powered coding assistance with full privacy and CLI flexibility.

## Changelog

See [CHANGELOG.md](CHANGELOG.md)

## License

MIT