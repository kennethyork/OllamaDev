# Changelog

## v4.1.8 (2026-06-01)

### Added
- **Touch-friendly terminals** — on small screens each terminal gets a line-input (type a command, Enter/Send writes it to the PTY) plus a key bar for control keys (Tab, Esc, ↑, ↓, Ctrl-C, Ctrl-D), so the web terminal is usable on a phone/tablet. Hidden on desktop.

## v4.1.7 (2026-06-01)

### Added
- **Browser / web mode** — run the ADE in a browser (`composer serve` → `http://localhost:8080`), no native window or Boson needed. Same UI, same local models/tools/crew, shared `~/.ollamadev` data; a shared `src/Bindings.php` backs both the desktop and web. Localhost-only by default (`OLLAMADEV_SERVE_TOKEN` for remote). The desktop archive bundles an `OllamaDev-Web` launcher.
- **Responsive layout** — the ADE now works on phone/tablet (top bar wraps, sidebar becomes a drawer, panes stack) for using web mode when you're out.
- **Docs: self-hosting on a VPS** — install Ollama + run web mode + reach it over an SSH tunnel.

## v4.1.6 (2026-05-31)

### Added
- **Local voice input** — engine-agnostic speech-to-text. Configure a local engine (`stt.host` for an OpenAI-compatible server like whisper.cpp / faster-whisper / vosk-server, or `stt.command` for any local CLI) and dictate via the desktop Crew mic button or `ollamadev transcribe <file>`. 100% local, off by default.

## v4.1.5 (2026-05-31)

### Added
- **Self-populating memory** — the graph knowledge base now fills itself: a crew run (and resume) distills durable project facts into notes, and an interactive session captures a few on exit if it changed files. Deduped against existing notes; disable with `--no-memory` or `memory.autoRemember:false`.

## v4.1.4 (2026-05-31)

### Added
- **`ollamadev context`** — probes RAM/VRAM + the active model (weights, native max context) and recommends a safe `num_ctx`, with the command to set it. New **`--num-ctx N`** flag pins the window for a run.

### Changed
- **Smarter compaction** — keeps tool output the recent turns still reference (by file/path), instead of summarizing it away, so long sessions lose less context that's still in use.

## v4.1.3 (2026-05-31)

### Added
- **Crew auto-ideas** — every crew run (and resume) ends by proposing a short, ranked list of the most valuable next steps (improvements, likely bugs, missing tests, risks), printed, saved to `ideas.md`, and surfaced on the live board as 💡 To-do cards with one-click Run. Suggestions only — not auto-implemented. Disable with `--no-ideas`.

### Changed
- A build-time guard now enforces the vanilla constraint on OllamaDev's own code (CLI `src/`, `site/`, desktop `public/`): no frameworks, no `package.json`/`node_modules`, no CDN, desktop deps limited to PHP + Boson. Does not affect other projects.

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