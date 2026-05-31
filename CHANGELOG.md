# Changelog

## v4.1.2 (2026-05-31)

### Added
- **Crew resume from disk** — an interrupted crew run (closed app, crash, reboot) can be resumed: `ollamadev crew resume [runId]`, and opening interactive `crew` in a repo with an unfinished run offers to continue it. Already-built branches are kept; only unfinished subtasks re-run, then audit + land. The plan is persisted to `~/.ollamadev/crew/<runId>/run.json`.
- **Per-repo session resume** — bare `ollamadev` (and the desktop, which launches in the project folder) resumes that directory's most recent session. `ollamadev new` / `--new` starts fresh; disable with `session.autoResume:false`.
- **Desktop Focus/Restore** — a ⤢ button on every terminal *and* every live crew pane enlarges it to fill the area, ⤡ puts it back in the grid (double-click works too).

### Fixed
- `terminal list` no longer warns on desktop-app session records (normalized across schemas). *(also in v4.1.1)*

## v4.1.1 (2026-05-31)

### Fixed
- `ollamadev terminal list` no longer warns (`Undefined array key "name"`) when the desktop app's PTY sessions share `~/.ollamadev/terminals/`. Records are normalized across both schemas, so desktop sessions also list cleanly.

## v4.1.0 (2026-05-31)

### Added
- **Standalone binaries + downloads** — true PHP-free CLI binaries (Linux/macOS/Windows, x64/arm64) built with phpacker; `install.sh` one-liner; an OS-detecting Downloads page; a `release.yml` workflow that builds and attaches everything on a version tag.
- **Desktop app archive** — per-OS download-and-run archive (app + Boson runtime libs + bundled CLI + launcher). The launcher offers, on first run, to also add `ollamadev` to your PATH.
- **Crew `--amplify N`** — trade free local compute for quality: N-sample plan self-consistency + an N-reviewer adversarial audit panel (strict majority).
- **Air-gapped mode + attestation** — `--offline` / `OLLAMADEV_OFFLINE` hard-blocks every network tool (unwaivable); `ollamadev attest` prints a fingerprinted proof of the air-gap posture.
- **Watch (background agent)** — `ollamadev watch "<task>"` re-runs a task whenever files change.
- **Skill registry + crew packs** — `skills browse/search/add` over configurable registries; `crew pack save/list` and `crew --pack <name>` to reuse and share tuned teams.

### Changed
- Provider-aware startup onboarding for Ollama/LM Studio; environment variables now correctly override a config file.
- Removed all PHP 8.4 compile-time deprecations so the standalone binary runs clean.

### Fixed
- Desktop `composer build` referenced a nonexistent `boson compile`; it now packages a portable app archive instead.

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