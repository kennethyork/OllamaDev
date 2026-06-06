# OllamaDev LSP VS Code Extension

Connects VS Code to OllamaDev's built-in LSP server for local AI code assistance.

## Setup

### 1. Install OllamaDev

```bash
# Already have ollamadev? Skip to step 2
git clone https://github.com/yourrepo/ollamadev
cd ollamadev && ./build.sh
sudo cp dist/release/ollamadev /usr/local/bin/
```

### 2. Build the extension

```bash
cd vscode-extension
npm install
npm run compile
```

### 3. Package and install

```bash
npm install -g @vscode/vsce
vsce package
code --install-extension ollamadev-lsp-1.0.0.vsix
```

### 4. Start the LSP server

In a terminal:
```bash
ollamadev lsp
```

Or let the extension auto-start it (enabled by default).

## Configuration

In `.vscode/settings.json`:

```json
{
  "ollamadev-lsp.port": 4389,
  "ollamadev-lsp.hostname": "127.0.0.1",
  "ollamadev-lsp.autoStart": true
}
```

## Commands

- `OllamaDev: Start LSP Server` - Connect to LSP
- `OllamaDev: Stop LSP Server` - Disconnect
- `OllamaDev: Restart LSP Server` - Reconnect
- `OllamaDev: LSP Status` - Show connection status

## Features

- Hover for code documentation
- Go-to definition
- Document symbols
- Real-time diagnostics via Ollama AI

## Requirements

- OllamaDev v3.9.2+ running locally
- Ollama installed with at least one model