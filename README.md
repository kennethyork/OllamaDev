# OllamaDev

**Local AI coding agent** - 151KB single PHP binary, runs entirely on your machine with Ollama. No cloud, no data leaves your computer.

## Install (30 seconds)

```bash
# Linux/Mac
curl -fsSL https://github.com/kennethyork/OllamaDev/releases/latest/download/ollamadev -o /usr/local/bin/ollamadev && chmod +x /usr/local/bin/ollamadev

# Start
ollamadev
```

**Requirements:** PHP 8.0+ and Ollama running (`ollama serve`)

## Quick Start

```bash
ollamadev                     # Interactive chat
ollamadev "fix this bug"      # Single prompt
ollamadev git status           # Git commands
ollamadev terminal create dev  # Named sessions
ollamadev update              # Check for updates
```

## Why OllamaDev?

| | OllamaDev | Claude Code | Copilot |
|--|--|--|--|
| Local-only (privacy) | ✅ | ❌ | ❌ |
| Terminal-native | ✅ | ✅ | ❌ |
| Named sessions | ✅ | ❌ | ❌ |
| LSP for any IDE | ✅ | ❌ | ❌ |
| Free | ✅ | ❌ ($20/mo) | ❌ |
| 151KB binary | ✅ | ~100MB | ~100MB |
| Sequential orchestration | ✅ | ✅ | ❌ |

## Features

### Git (full workflow)
```bash
ollamadev git status
ollamadev git commit "fix: resolve issue"
ollamadev git_cherry_pick ref=<hash>       # Cherry-pick commit
ollamadev git_revert ref=<hash>            # Revert commit
ollamadev git_merge branch=main no_ff=true  # No fast-forward merge
ollamadev git_merge branch=main squash=true # Squash merge
```

### Auto-update
```bash
ollamadev update           # Check version
ollamadev update --install # Download and install
```

### Environment variables
```bash
OLLAMA_HOST=http://localhost:11434 ollamadev chat
OLLAMA_MODEL=deepseek-r1:32b ollamadev chat
```

### Project memory
Create `OLLAMADEV.md` in project root for persistent context:
```markdown
# Project Context
- Framework: Laravel 10
- Code style: PSR-12
- Testing: PHPUnit
- Main model: User
```

AI reads this on every prompt - like Claude Code's CLAUDE.md.

### Terminal Sessions
```bash
ollamadev terminal create backend --model llama3.2:latest
ollamadev terminal create frontend --model deepseek-r1:32b
ollamadev terminal attach backend   # Switch to session
ollamadev terminal list              # See all sessions
```

### LSP for any IDE
Connect VS Code, Neovim, Emacs, Sublime, IntelliJ:
```bash
ollamadev lsp --port 4389
```
Auto-starts when VS Code extension is loaded.

## 68 Tools

**File:** view, cat, head, tail, write, edit, patch, touch, mkdir, rm, cp, mv, ls, cd, pwd, find, tree, glob, wc, stat, diff, sort, uniq

**Git:** status, diff, log, branch, checkout, commit, add, push, pull, stash, clone, merge, rebase, fetch, remote, show, cherry_pick, revert

**Code:** goto, find_refs, symbols, hover, diagnostics, format, lsp

**System:** bash, execute_command, fetch, watch, bg, agent, mcp, update

**Agent:** Sequential orchestration for multi-step tasks.

## VS Code Extension

```bash
code --install-extension vscode-extension/ollamadev-lsp-1.1.0.vsix
```

Auto-starts LSP on load (no manual server needed).

| Shortcut | Action |
|----------|--------|
| `Ctrl+Shift+G` | Generate code |
| `Ctrl+Shift+R` | Review code |
| `Ctrl+Shift+A` | Ask AI |
| `Ctrl+Shift+L` | Open chat panel |
| `Ctrl+Space` | Toggle completion |
| `Ctrl+Shift+F` | Format document |
| `Ctrl+.` | Quick fix |

## VSCode Settings

```json
{
  "ollamadev.port": 4389,
  "ollamadev.model": "llama3.2:latest",
  "ollamadev.autoStart": true,
  "ollamadev.statusBarEnabled": true
}
```

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
ollamadev (151KB PHP binary)
├── 68 embedded tools
├── AI agent (Ollama)
├── LSP server
├── Terminal sessions
├── Git integration
├── Auto-updater
└── Sequential orchestration

vscode-extension/ollamadev-lsp-1.1.0.vsix
```

## License

MIT