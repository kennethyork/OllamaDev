# Ollam-Cli

A terminal-based AI coding agent that uses local Ollama models. Privacy-first, fully offline.

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/kennethyork/Ollam-Cli/main/install | bash
```

Or manually from [releases](https://github.com/kennethyork/Ollam-Cli/releases).

## Requirements

- [Ollama](https://ollama.ai/) installed and running
- Local model installed (e.g., `ollama pull codellama`)

## Usage

```bash
ollamadev                    # Start interactive TUI
ollamadev -c /path/to/project  # Start with specific directory
ollamadev -p "explain this"   # Single prompt (coming soon)
```

## Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Ctrl+C` | Quit |
| `Ctrl+K` | Toggle help |
| `Ctrl+O` | Select model |
| `Ctrl+A` | Switch session |
| `Enter` | Send message |

## Features

- Interactive TUI built with Bubble Tea
- Local-only: your code never leaves your machine
- Tools: bash, view, write, edit, glob, grep, ls, fetch
- Session management with SQLite persistence
- Auto-compact for long conversations