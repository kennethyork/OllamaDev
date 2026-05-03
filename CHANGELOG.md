# Changelog

## v3.9.2 (2026-05-03)

### Added
- **VS Code Extension** - Full IDE integration with AI-powered features
  - Generate code, review code, ask AI commands
  - Inline completion with Ollama AI
  - Chat panel webview
  - Status bar indicator showing connection status
  - Keyboard shortcuts (Ctrl+Shift+G/R/A/L, Ctrl+Space)
- **LSP Server** - All AI features now route through the PHP CLI
  - `textDocument/completion` → real AI completions via Ollama
  - `ollamadev/chat`, `ollamadev/review`, `ollamadev/generate` RPC methods
- **Terminal Multiplexer Improvements**
  - `terminal pause/resume` - Pause/resume running terminals (SIGSTOP/SIGCONT)
  - `terminal broadcast <msg>` - Send message to all running terminals
  - `terminal detach` - Ctrl+C detaches without stopping (stays running)
  - Proper stop with state preservation (SIGTERM then SIGKILL)
  - Spawn now auto-starts terminals
- **CLI Improvements**
  - `ollamadev git status|diff|log|branch|commit|push|pull|stash` - Working git command
  - Tab completion in interactive mode (help, exit, quit, clear, model, session, tools, cd)
  - Config file support (`~/.ollamadev/config.json`)

### Changed
- VS Code extension now routes ALL AI requests through `ollamadev lsp` server
- Inline completion uses `codeComplete()` method instead of direct Ollama API

### Fixed
- Terminal stop now properly kills process and saves state
- LSP server uses correct OllamaClient methods for completions

## v3.9.1 (2026-05-03)

### Added
- Terminal multiplexer with create/spawn/list/start/stop/delete/attach/log

## v3.9.0 (2026-05-03)

### Added
- Terminal multiplexer prototype
- LSP server prototype for IDE integration

## v3.8.2 (2026-05-03)

### Fixed
- tool_call parsing for `arguments: {json}` format
- param aliases: file, file_path, path all accepted by view, cat, head, tail, wc, stat, diff
- stripos typo (was str_stripos)

## v3.8.1 (2026-05-03)

### Fixed
- cp tool using PHP native copy() for cross-platform
- tilde expansion (~) support in cp tool

## v3.8.0 (2026-05-03)

### Added
- 66 tools documented in system prompt
- Session management (create, list, load, save)
- MCP integration
- GitHub PR fetching
- Web interface mode

---

## Older Versions

See git history for versions before v3.8.0