# Development workflow

This project ships as a single vanilla-PHP file (`ollamadev`), but you **edit
the modules in `src/`, not the built binary.** A build step concatenates them.

## Layout

```
src/                  PHP modules (edit these)
  00-header.php       shebang + <?php + globals   (only file with the <?php tag)
  10-config.php       Config
  20-mcp.php          MCPClient + MCP            (stdio JSON-RPC)
  30-permission.php   Permission                (auto / ask / readonly)
  40-lsp.php          LSPClient + LSP
  50-ollama-client.php
  60-tools.php        Tools + CmdError
  65-tools-register.php  all Tools::register(...) + global helpers
  70-system-prompts.php
  75-agent.php        Agent (tool loop, native + text-fallback tool calls)
  80-tui.php
  85-terminal.php     TerminalManager
  90-session.php      Session (chat loop, slash commands)
  99-main.php         CLI entry + flag parsing
ollamadev             BUILT artifact (do not hand-edit)
build.sh              src/*.php -> ollamadev (+ php -l)
Makefile              build / test / install
tests/smoke.php       runs the REAL binary + unit checks (no model needed)
scripts/safe-apply.sh guarded build->test->install->commit
```

The modules are contiguous slices of the program, so `build.sh` is a plain
concatenation in filename order. Only `00-header.php` carries the `<?php` tag;
the rest are raw PHP bodies (lint the built binary, not the fragments).

## Everyday loop

```bash
# edit src/*.php
make            # build + run smoke tests
make install    # build + test, install to ~/.local/bin ONLY if green
```

## Adding a feature without breaking the app

1. Branch: `git checkout -b feat/<name>` (never commit straight to `main`).
2. Edit the relevant `src/` module.
3. Add/extend an assertion in `tests/smoke.php` for the new behavior.
4. `make test` — the suite runs the real binary; if it's red, you broke
   something. Fix before installing.
5. `scripts/safe-apply.sh "feat: <message>"` — builds, tests, installs, and
   commits **only if green**; otherwise it reverts your working tree so a bad
   edit never ships.

## Self-modification (the CLI editing its own code)

The safe pattern is identical: have the agent edit `src/`, then run
`scripts/safe-apply.sh`. Because the install/commit is gated on `php -l` + the
smoke suite, a broken self-edit is caught and rolled back automatically. Verify
the guard yourself: introduce a syntax error in any `src/` file and run
`scripts/safe-apply.sh --no-commit` — it reverts and the live binary is
untouched.

Rollback a shipped change: `git revert <sha>` then `make install`.

## Desktop app (Desktop/ollamadev-ade)

The desktop ADE (Boson WebView) **shells out to the CLI binary** rather than
reimplementing the agent — see `src/PtyManager.php`, which runs `ollamadev` in
a PTY. Keep it that way: one source of agent logic (the CLI), the desktop is a
UI over it.

- Point it at the installed binary by defining `OLLAMADEV_BINARY` (defaults to
  a dev path today; prefer `~/.local/bin/ollamadev` or a PATH lookup).
- Anything the desktop needs from the agent should be reachable via the CLI
  (`ollamadev -p "..."`, `--port`, or the terminal daemon), so improving the
  CLI improves the desktop for free.

## Tests

`php tests/smoke.php` is offline and deterministic. Set `SMOKE_MODEL=<model>`
later if you add model-dependent agent-loop checks. The old
`tests/ollamadev_test.php` tests copied classes, not the binary — prefer
`smoke.php` for regression safety.
