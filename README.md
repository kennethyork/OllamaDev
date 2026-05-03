# OllamaDev

**Local AI coding agent** - 145KB single PHP binary, runs entirely on your machine with Ollama. No cloud, no data leaves your computer.

## Install (30 seconds)

```bash
# Linux/Mac
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev && chmod +x /usr/local/bin/ollamadev

# Windows PowerShell
irm https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -OutFile $env:LOCALAPPDATA\ollamadev\ollamadev

# Start
ollamadev
```

**Requirements:** PHP 8.0+ and Ollama running (`ollama serve`)

## What makes it different?

| | OllamaDev | Cursor | Copilot |
|--|--|--|--|
| Local-only (privacy) | ✅ | ❌ | ❌ |
| Terminal-native | ✅ | ❌ | ❌ |
| Single 145KB binary | ✅ | ❌ | ❌ |
| LSP for any IDE | ✅ | ❌ | ❌ |
| Git + AI integrated | ✅ | Partial | Partial |
| Web fetch tool | ✅ | ❌ | ❌ |
| Free & open source | ✅ | ❌ | ❌ |

## Examples

```bash
# Chat with AI
ollamadev
ollamadev "explain this function"
echo "fix the bug" | ollamadev

# Git workflows
ollamadev git status
ollamadev git commit "fix: resolve issue"
ollamadev git diff

# Named terminals (sessions)
ollamadev terminal create dev --model llama3.2:latest
ollamadev terminal attach dev    # Your AI session
ollamadev terminal list         # See all sessions
ollamadev terminal log dev 50   # View history

# LSP for IDE
ollamadev lsp                    # Start server (127.0.0.1:4389)
```

## Features

### 66 Tools
- **File:** view, cat, head, tail, write, edit, patch, touch, mkdir, rm, cp, mv, ls, cd, pwd, find, tree, glob, wc, stat, diff, sort, uniq
- **Git:** status, diff, log, branch, checkout, commit, add, push, pull, stash, clone
- **Code:** goto, find_refs, symbols, hover, diagnostics, format, lsp
- **System:** bash, execute_command, fetch, watch, bg, agent, mcp

### Terminal Sessions
Create named sessions with different models. Switch between them:

```bash
ollamadev terminal create dev --model llama3.2:latest
ollamadev terminal create review --model deepseek-r1:32b
ollamadev terminal attach dev    # Switch to dev session
ollamadev terminal list          # See all sessions
ollamadev terminal delete review # Clean up
```

### LSP Server
Connect any editor - VS Code, Neovim, Emacs, Sublime, IntelliJ:

```bash
ollamadev lsp --port 4389
```

Features: hover, goto definition, find references, completions, code actions, formatting

### VS Code Extension

```bash
code --install-extension vscode-extension/ollamadev-lsp-1.1.0.vsix
```

| Shortcut | Action |
|----------|--------|
| `Ctrl+Shift+G` | Generate code |
| `Ctrl+Shift+R` | Review code |
| `Ctrl+Shift+A` | Ask AI |
| `Ctrl+Shift+L` | Open chat panel |
| `Ctrl+Space` | Toggle completion |
| `Ctrl+Shift+F` | Format document |
| `Ctrl+.` | Quick fix |

## Configuration

`~/.ollamadev/config.json`:
```json
{
  "ollama": {
    "host": "http://localhost:11434",
    "defaultModel": "llama3.2:latest"
  }
}
```

## Build from Source

```bash
git clone https://github.com/kennethyork/OllamaDev.git
cd OllamaDev
./build.sh
```

## Architecture

```
ollamadev (145KB PHP binary)
├── 66 embedded tools
├── AI agent (Ollama)
├── LSP server
├── Terminal sessions
└── Git integration

vscode-extension/ollamadev-lsp-1.1.0.vsix
```

## License

MIT