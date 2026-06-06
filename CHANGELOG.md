# Changelog

## v4.8.35 (2026-06-06)

### Changed
- **Desktop form controls consolidated into shared `.field` / `.select` component classes.** Inputs and dropdowns were styled individually by id, so a new control that missed its rule rendered at the wrong width (the clipped-box bug). Standalone modal/panel fields now share uniform component classes — consistent padding, borders, and focus rings, and correct by default so the bug can't recur. No behavior change; flex-row inputs (terminal, agent chat, browser bar) and layout-embedded selects (topbar, crew roster) keep their own styles.

## v4.8.34 (2026-06-06)

### Fixed
- **Several desktop inputs rendered too narrow and clipped their text.** The stylesheet sizes inputs individually by id, and a few were never given a width rule — so they fell back to the browser's ~150px default and truncated their placeholder/value: the Code-search box ("Find code by meaning…"), the crew **Ollama hosts** box, and the role-add modal (name / persona / description / pinned model). They're now full-width.

## v4.8.33 (2026-06-06)

### Fixed
- **Desktop app crashed with `Call to undefined function posix_kill()`** (and the crew/terminal could fail on `posix_isatty`). The AppImage's `AppRun` runs `php -n` (no php.ini), which skips the conf.d files that load Debian/Ubuntu's *shared* `posix.so` — so posix functions were undefined at runtime. The build now bundles `posix`/`pcntl` alongside `ffi`/`curl` and AppRun loads every bundled extension (globbing `usr/lib/php/*.so`, so the list can't drift). `PtyManager` also guards `posix_kill` with a `kill -TERM` fallback. The release smoke-gate now replicates AppRun and fails the build if `posix_kill`/`posix_isatty` don't resolve.

### Added
- **"Clear the board"** — dismiss the crew kanban (crew cards, ideas, and your manual cards). Available as `ollamadev crew clear`, as an agent tool (`clear_board`) the Director/coders can invoke when you ask, and reflected live in the desktop. It's explicit-only (the tool requires `confirm=true` and is told never to clear on its own initiative) and refused while a crew run is active.

## v4.8.32 (2026-06-06)

### Fixed
- **Linux AppImage failed to launch with an nghttp2 symbol error** (`libcurl-gnutls.so.4: undefined symbol: nghttp2_option_set_no_rfc9113_leading_and_trailing_ws_validation`). The AppImage bundled its build-runner's `libnghttp2` and forced it ahead of the host's via `LD_LIBRARY_PATH`. But Boson's WebView loads the **host's** `libcurl-gnutls`, which needs a recent nghttp2 symbol — and after the v4.8.30 glibc fix pinned builds to Ubuntu 22.04, the bundled nghttp2 (1.43) was too old to provide it, *shadowing* the host's matched copy. The build no longer bundles `libnghttp2`, so libboson uses the host's curl+nghttp2 pair (always matched). Keeps the glibc-2.35 / Linux Mint compatibility from v4.8.30.

## v4.8.31 (2026-06-06)

### Fixed
- **Changing a terminal's model from the desktop had no effect.** Bare `ollamadev` auto-resumes the folder's last session, and the resumed session's *saved* model overrode the `-m` flag the desktop passes — so a terminal launched with `-m qwen2.5-coder:14b` would actually run whatever the old session was saved on (e.g. qwen3-coder), and switching the model in the desktop did nothing. An explicit `-m` now overrides the resumed session's model (new `Session::useModel`).

## v4.8.30 (2026-06-06)

### Fixed
- **Desktop terminals now use your configured model.** The topbar model selector built its list with no selection, so it defaulted to whatever Ollama listed *first* — meaning new terminals launched with the wrong model regardless of your `ollama.defaultModel`. `models --json` now reports the configured default and the topbar selects it, so terminals start on your chosen model (e.g. qwen3-coder).

### Removed
- Reverted the per-terminal model dropdown (v4.8.29) — the real fix is the topbar honoring your default, above.

## v4.8.29 (2026-06-06)

### Added
- **Per-terminal model switcher (desktop/web).** Each terminal header now has a model dropdown — change it and it switches that terminal's **live** CLI session via the `/model` command (the topbar model only ever set the model at creation, with no way to change it after). Each terminal can run a different model, switchable on the fly; the choice persists across save/resume.

## v4.8.28 (2026-06-06)

### Fixed
- **Crew terminal relaunched as `-m crew` after resume.** The desktop created the crew terminal with the literal string `'crew'` as its *model* (it was meant as a label). The live run was fine, but when the workspace was saved and reopened, the terminal was recreated with `ollamadev -m crew` → "Model 'crew' is not installed." Crew terminals now use the real model (with a separate `kind: 'crew'` label), so resume relaunches correctly.

## v4.8.27 (2026-06-06)

### Added
- **GPU↔RAM offload + CPU thread controls.** New `ollama.gpuLayers` (→ Ollama's `num_gpu`: how many model layers stay on the GPU; the rest spill to system RAM) and `ollama.numThreads` (→ `num_thread`: cap CPU threads). Unset = Ollama decides (best when the model fits in VRAM); `gpuLayers: 0` runs fully on CPU/RAM. Lets you trade VRAM for RAM or leave cores free. Note: for *cooler/quieter* runs, a lighter model (e.g. the `qwen3-coder` MoE — only ~3B active params) reduces compute and heat far more effectively than RAM offload, which just shifts the load to the CPU.

## v4.8.26 (2026-06-05)

### Added
- **Built-in Browser tab** in the desktop/web ADE — a localhost **preview pane** next to Workspace/Board/Graph, for viewing the dev server you're building right beside your terminals. Address bar (accepts a bare port like `3000`, `localhost:3000`, or a full URL), back/forward/reload, quick-port chips (`:3000 :5173 :8080 :8000 :4321 :41434`), and an **↗ open-in-real-browser** button. Local URLs load **directly** at full fidelity (SPAs/APIs/websockets all work); air-gap mode (✈️) blocks external loads, matching the agent's network toggle.
- **Local strip-proxy for external sites.** A plain `<iframe>` can't load sites that send `X-Frame-Options: DENY` / CSP `frame-ancestors` (Google, GitHub, most docs) — the browser refuses and shows blank. New `proxyFetch` binding fetches the page server-side with `curl`, strips the frame-blocking `<meta>`, injects a `<base>` so the site's CSS/images/links resolve against the real origin, and renders it via `iframe.srcdoc` (inline content isn't subject to X-Frame-Options). In-page link clicks bubble back up and re-proxy so navigation stays in the pane; sites that genuinely can't embed get a clear ↗ "open in real browser" fallback. Works in **both** desktop and web (rides the same binding bridge — the desktop has no HTTP server, so a classic `/proxy?url=` route wouldn't work). Best-effort: a page's own cross-origin XHR/API calls fail from the frame, so logged-in dashboards and heavy SPAs (Gmail, GitHub app pages) may break — those get the fallback. New `openExternal` binding (xdg-open/open/start) backs the ↗ button on desktop where an in-app `window.open` isn't a real browser.

### Fixed
- **Version drift across the desktop app.** `boson.config.php` was stuck at `4.1.8` and `src/Config.php` at `3.9.2` while the CLI was `4.8.25`. All three `OLLAMADEV_VERSION` markers now track the real version.

## v4.8.25 (2026-06-04)

### Fixed
- **Switching projects "only worked once."** The save of the workspace you're leaving was *gating* the whole switch — and only switches after the first one trigger that save, so if the save binding stalled over the Boson bridge the switch chain never completed. The switch no longer waits on it: the window snapshot is still captured synchronously, and the persist happens in the background.
- **The desktop app didn't resume on reopen.** Workspace window state (terminals, editor tabs, layout) was only saved when you *switched away* from a project, so working in one project and just closing the app lost the session. Added an autosave that persists the active workspace whenever its state changes (debounced; writes only on change) plus a final flush on `beforeunload`/`pagehide`/hide — with a guard so the brief empty window mid-switch can't overwrite a project's saved state. The active project now reopens with its terminals restored.

## v4.8.24 (2026-06-04)

### Changed
- **Auto-compaction now also triggers on real context fill, not just message count.** Previously the session auto-summarized older messages only once it hit a fixed message-count threshold (30). It now *also* compacts when the conversation reaches ≥75% of the model's `num_ctx`, using **Ollama's actual prompt-token count** when available (else the char/4 estimate) — so a few very long messages get compacted before they overflow the window instead of slipping through the count check. Tunable with `agents.compactContextPct` (0 disables the token trigger); the compaction notice now shows the context % when that's what fired it. Message-count threshold and the smart tool-output preservation are unchanged.

## v4.8.23 (2026-06-04)

### Fixed
- **Switching projects in the desktop/web "Projects" list did nothing.** Two robustness fixes for the click→switch path: (1) the workspace tabs now use one **delegated** click listener on the strip container (attached once) instead of per-element handlers rebuilt on every render — so a click can never be dropped; (2) `openFolder` no longer aborts when the `setRoot` binding resolves to an empty/undefined value (which can happen over the Boson FFI bridge even on success) — it only bails on an explicit error and otherwise falls back to the requested path, so a switch never silently no-ops. The shared backend (add/list/setRoot) was already correct.

## v4.8.22 (2026-06-04)

### Fixed
- **Desktop/web terminal rendered typed text with a space between every character** (`hilo` showed as `h i l o`). A CSS class collision: the touch-input row and the terminal's output lines both use `.term-line`, and a bare `.term-line { display: flex; gap: 6px }` (intended only for the input row) turned every character `<span>` in the output into a gapped flex child. Scoped that rule to `.term-touch .term-line` so output lines are unaffected. Pairs with the v4.8.21 backspace fix — together the desktop terminal now types and erases cleanly.

## v4.8.21 (2026-06-04)

### Fixed
- **Backspace + stray glyphs in the desktop/web terminal.** The pty echoes a backspace erase as `\b \b` (backspace, space, backspace); the terminal renderer handled the first `\b` but its text scanner didn't stop on the trailing one, so that byte was drawn as a literal control character — which both broke the erase (line left dirty) and rendered as a tofu/□ box (read as a "font" problem). The scanner now stops on `\x08`/`\x7f`, and any leftover C0 control bytes (bell, NUL, …) are stripped before rendering so nothing shows up as tofu. Desktop/web only — the regular CLI (its own line editor) was unaffected.

## v4.8.20 (2026-06-04)

### Changed
- **Uniform topbar controls + a more mobile-friendly UI (additive only).** Pinned every topbar dropdown/button to one fixed height so the voice-model select no longer renders taller than the rest (the 🎙 emoji was growing its box). On phones/tablets: finger-sized 40px tap targets, the four selects share rows evenly instead of one hogging width, text inputs are ≥16px so iOS doesn't auto-zoom on focus, and touch niceties (no tap-flash, no 300ms tap delay, momentum scrolling). Palette, themes, layout, and controls unchanged.

## v4.8.19 (2026-06-04)

### Changed
- **Tidier desktop/web topbar (additive layout only).** Grouped all four dropdowns together (model · temperature · voice · theme) and moved the 🎙 History button into the action-button cluster, so the bar reads cleanly left-to-right (selects, then buttons) instead of a button wedged between dropdowns. The voice-model options are now parallel like the temperature ones (`tiny · fastest` … `large-v3 · best`). Palette, themes, and every control are unchanged.

## v4.8.18 (2026-06-04)

### Added
- **`/voice` now works with no separate install — the Whisper engine is baked in.** Speech-to-text previously needed you to install `whisper` yourself. Now OllamaDev provides a self-contained **whisper.cpp** engine (a tiny ~3 MB statically-linked CPU binary) two ways:
  - **Desktop (AppImage + Windows installer):** the engine **and** a model (`base` by default) are **bundled in**, so `/voice` and the mic button work **instantly and fully offline** out of the box (this grows those downloads by ~150 MB; the `.tar.gz`/`.zip` archives stay lean and auto-fetch instead).
  - **Standalone CLI:** the first time you use `/voice` with no engine, OllamaDev offers a **one-time auto-download** (whisper.cpp binary from the release + the ggml model from Hugging Face, into `~/.ollamadev/stt/`) — then it's fully local and offline forever. Air-gap mode blocks the download (bring your own engine into `~/.ollamadev/stt`). Non-interactive: `ollamadev voice install [<size>]`.
  - Engine resolution order: a **bundled** dir (`OLLAMADEV_STT_DIR`, set by the desktop launchers) → the **provisioned** `~/.ollamadev/stt` → any Whisper on `PATH`. The bundled/provisioned whisper.cpp is now preferred (faster on CPU than openai-whisper). Models still pick via `/voice model <tiny|base|small|medium|large-v3|turbo>`.
  - The whisper.cpp binaries are built per-platform in CI (`scripts/build-whisper.sh`, pinned to whisper.cpp v1.7.5) and attached to each release as `whisper-<os>-<arch>` — they are **not vendored in source**; the source stays vanilla PHP, and the engine is fetched at runtime exactly like an Ollama model pull. 100% local once present.

## v4.8.17 (2026-06-04)

### Added
- **`/voice` — speak your prompt in the CLI (local speech-to-text).** A new `/voice` (alias `/listen`) command records your mic, transcribes it locally, and submits the text as your prompt. Press **Enter** to stop (a max-seconds safety cap auto-stops). Zero-config: it **auto-detects an installed open-source Whisper engine** (`whisper` / `whisper.cpp` / `faster-whisper`) and runs **CPU-only** by design — no GPU/VRAM used, nothing leaves the machine. Recording uses whichever of `arecord` / `ffmpeg` / `parecord` is present. This also lights up STT everywhere: the **desktop and web mic button** now appear with zero config once a Whisper engine is installed (they share the same `transcribe` engine via the `sttEnabled`/`sttTranscribe` bindings), so STT works across **CLI, desktop, and web**. If no engine is found, `/voice` explains how to install one.
  - **Model picker:** `/voice model <size>` (or `config set stt.model …`) chooses accuracy — `tiny · base · small · medium · large-v3 · turbo` (default `base`; `small` is the best accuracy that stays snappy on CPU). The setting is shared by CLI, desktop and web. `/voice model` with no arg shows the current size; `/voice status` shows engine + model + recorder + availability.
  - **Voice history:** every transcription is saved to `.ollamadev/voice-history.jsonl` and reviewable with `/voice history [N]` (timestamp · model · text); `/voice history clear` wipes it.
  - **Desktop & web UI:** a voice-model dropdown (`🎙 tiny…turbo`) in the header and a **🎙 History** panel, both shown only when a local STT engine is present — additively, matching the existing toolbar (same pattern as the temperature dropdown). They read/write the same `stt.model` and the same history as the CLI via new `sttModel`/`setSttModel`/`sttHistory`/`sttClearHistory` bindings (a non-interactive `ollamadev voice …` subcommand backs them), so all three surfaces stay in lockstep. The web UI serves these over the existing web server on port **41434** — no new port. (Fixed along the way: `stt.model` now *persists* to `~/.ollamadev/config.json` instead of being set only in-memory, so the choice sticks across runs in CLI, desktop, and web.)
- **Docs: no-install desktop builds.** The Installation page now documents the self-contained **Linux AppImage** (`x86_64`/`aarch64` — `chmod +x` and run, PHP bundled) and the **Windows installer** (`OllamaDev-ADE-Setup.exe` — bundles PHP + VC++ runtime + Boson DLL + CLI, with a note on the unsigned-SmartScreen first-run prompt), alongside the existing portable archives.
- **Better image/vision support in the CLI.** Image analysis already worked (`/image <path>` or `@photo.png` in any prompt → base64-attached to Ollama's multimodal `images` array; verified end-to-end on a vision model). This release makes it work *better*: (1) **vision-model presets** — `llava`, `llama3.2-vision`, `moondream` are now in the catalog, so `ollamadev models presets` lists them and `ollamadev models pull llava` fetches one; (2) **`~` and relative image paths** now resolve (`@~/pic.png`, `@screenshot.png`); (3) **a warning when you attach an image to a model that can't see it** — OllamaDev checks the model's capabilities (`/api/show`) and, if it lacks vision, prints `⚠ <model> has no vision — the image will be ignored.` and points you at an installed vision model (or `models pull llava`). Stays quiet when the model *does* support vision or when capabilities are unknown. All vanilla PHP, local-only.

### Changed
- **Smarter model guidance so weak models aren't a silent footgun.** The interactive startup tip is now installed-aware: when the active model is weak at tool use (catalogued no-tools like `llama3.2`, or an uncatalogued non-recommended model), it points you straight at a **better model you already have installed** — e.g. `⚠ llama3.2:latest is weak at tools — started in chat mode. Better installed: qwen2.5-coder:7b` with ready-to-paste `/model …` and `config set ollama.defaultModel …` commands. Only suggests pulling when nothing capable is installed. It fires in chat mode too (small models auto-start there), since that's exactly when a user is parked on a model that can't drive tools. Silenceable with `config set model.nagWeakModel false`.
- **Default-model auto-pick tightened.** When none of the fallback chain is installed, the agent now prefers *any* catalogued tool-capable model (`Models::anyToolCapable`) over an arbitrary first-listed one (which may be chat-only) before falling back. Never overrides an explicit `ollama.defaultModel` — explicit choices are always honored.

## v4.8.16 (2026-06-04)

### Added
- **Windows desktop installer (`OllamaDev-ADE-Setup.exe`).** A self-contained Inno Setup installer that bundles a PHP 8.4 runtime (ffi + curl + the VC++ runtime) + the Boson Windows DLL + the app + the agent CLI — so it installs and runs with **no PHP install**, the Windows equivalent of the Linux AppImage. Start-menu + optional desktop shortcuts; a windowless `.vbs` GUI launcher (uses `php-win.exe`) plus a terminal `.bat`. Built on a `windows-latest` runner (`scripts/windows/build-installer.ps1` + `ollamadev-ade.iss`); the `.zip` archive (system PHP) stays as a fallback. **Note:** unsigned, so first-run SmartScreen shows a warning until dismissed; needs verification on a real Windows desktop (CI can build it but can't launch the GUI). No native macOS app is planned — macOS stays on the `.tar.gz` archive.

## v4.8.15 (2026-06-04)

### Added
- **arm64 Linux AppImage** (`OllamaDev-ADE-aarch64.AppImage`), alongside the x86_64 one. `scripts/build-appimage.sh` is now arch-aware (bundles the arch's PHP + the matching Boson lib, and slims to just that lib). Because an AppImage must bundle arch-specific binaries, the arm64 image is built on a native `ubuntu-24.04-arm` runner in the release workflow. Both are self-contained (no PHP install); the `.tar.gz`/`.zip` archives and all-platform CLI binaries remain.

## v4.8.14 (2026-06-04)

### Added
- **Self-contained Linux AppImage for the desktop app.** A new release asset, `OllamaDev-ADE-x86_64.AppImage`, **bundles a PHP 8.4 runtime (ffi + curl) + the Boson libs + the agent CLI** — so it runs by `chmod +x` and double-click with *no PHP install*. This is why a `.deb`/`.rpm`/`.exe`/AppImage didn't exist before: Boson is a runtime (not a compiler) and the app needs PHP 8.4, so a self-contained build has to bundle PHP — which the AppImage now does (`scripts/build-appimage.sh`). The existing **`.tar.gz`/`.zip` archives for Linux, macOS, and Windows stay** (they use your system PHP), and the **CLI standalone binaries remain for every platform**. Verified locally: the bundled PHP loads ffi+curl with no host ini and runs the app.

## v4.8.13 (2026-06-03)

### Changed
- **Default sampling temperature lowered 0.6 → 0.3.** Measured on the 20-task eval, a lower temperature sharply improves tool-calling reliability (the model commits to actions instead of rambling/describing): `qwen2.5-coder:14b` went **45% → 70%** at temp 0.2, and 7B improved too. 0.3 is a balanced agentic default; it's overridable per the dropdown below or `ollamadev config set ollama.temperature <v>`.
- **Temperature dropdown in the desktop/web header.** A new select (next to Model/Theme, matching the existing UI) — Exact 0.0 · Precise 0.2 · Balanced 0.3 · Standard 0.5 · Creative 0.7 · Wild 1.0 — reads/writes `ollama.temperature` live via the `temperature`/`setTemperature` bindings. Verified headlessly (loads the stored value, persists on change, zero JS errors). No other UI changed.

## v4.8.12 (2026-06-03)

### Fixed / Added — tool reliability
- **Strip stray markdown fences from written files.** Local models habitually wrap a whole file's content in a ```` ```lang … ``` ```` block; written verbatim the file started with ```` ``` ```` instead of `<?php` and wouldn't run (the eval's `fix-loop` had *correct* logic but the file echoed its source). `write` and `notebook_edit` now unwrap a single fully-enclosing fence — and **only** that case, so a real multi-block markdown file is left intact (verified by tests).
- **Prompt nudge to act via tools.** The structured/text protocols now explicitly tell the model to create/change files by calling `write`/`edit` with the contents as the argument — not by pasting code into its message or wrapping it in fences.
- **Measured:** `qwen2.5-coder:7b` on the 20-task eval went 7/20 → 8/20, and the fence class of failure (file written but not executable) is gone. The remaining misses are model behavior (not calling `write`, or wrong logic), not file handling. For reference, `qwen2.5-coder:14b` scores 9/20 (45%) on the same suite.

## v4.8.11 (2026-06-03)

### Fixed
- **Director no longer emits duplicate/overlapping subtasks.** When it over-decomposed a task (the same work under two titles, or a near-identical prompt), a second coder would spin up a worktree only to find nothing to do (`#2 skipped (empty)`). The plan now de-duplicates by normalized title and prompt before coders run — fewer wasted coders, cleaner boards. Applies to single and self-consistency (`--amplify`) planning.

## v4.8.10 (2026-06-03)

### Added
- **Two new team skills: `pwa` and `observability`** (library now 29 → 31). They fill real gaps — the PWA project-type template had no matching skill, and microservice/serverless/devops focuses lacked production-observability guidance. Verified live: a `pwa`-focused crew now seeds `pwa` + `responsive-design`, and a `microservice … with logging` focus seeds `observability` + `rest-api-design` + `auth-security`. (Team skills auto-match a template's *focus* and are materialized into each coder's worktree.)

## v4.8.9 (2026-06-03)

### Fixed
- **`find` with wildcards never matched.** The glob→regex translation ran on the already-escaped pattern (`preg_quote` had turned `*` into `\*`), producing a regex like `/^\.*\.php$/` — so `find name="*.php"` always returned "No matches" (only exact filenames worked). Now translates the escaped wildcards correctly.

### Added
- **Expanded tool-layer tests (`tests/tools.sh`): 55 → 60 checks, now behavioral.** They assert actual side effects (file written/edited/moved/deleted, commit created, branch switched, stash cleaned, notebook cell changed, todo persisted), **multi_edit atomicity** (a missing `old_string` applies *none* of the edits), and **error paths** (edit reports a missing string; reading a missing file doesn't crash) — not just "didn't crash." Caught the `find` bug above. Runs in CI on every push.

## v4.8.8 (2026-06-03)

### Added
- **Eval suite expanded from 6 to 20 app-shaped tasks** — turning the harness from a smoke signal into a real benchmark. Covers algorithms (fizzbuzz, factorial, palindrome, dedup, word-count, celsius), seeded bug-fixes (off-by-one loop, syntax error, wrong operator), multi-file work (a `lib/` module + entry point), parsing/data (env parser, LIFO stack class), and files/config (.gitignore, README). Every check is deterministic and verified to pass with a correct solution (so failures reflect the model, not the harness). The CI eval gate is now rate-based (fails under 25%, catching catastrophic regressions while tolerating model variance). Baseline: `qwen2.5-coder:7b` ≈ 7/20 (35%) in structured mode — an honest, discriminating number that a stronger model will climb.

## v4.8.7 (2026-06-03)

### Added
- **Default multi-model crew via config.** Each crew role's model now falls back to a configured default when no `--*-model` flag is given: `crew.directorModel`, `crew.researcherModel`, `crew.coderModel`, `crew.auditorModel` (resolution: CLI flag → config → base model). Set them once in `~/.ollamadev/config.json` (e.g. `ollamadev config set crew.directorModel codestral`) and a plain `ollamadev crew "task"` runs a multi-model team — a strong director, a fast coder, a separate auditor — with no flags. Applies to both fresh runs and resume.

## v4.8.6 (2026-06-03)

### Changed
- **`tools.mode=auto` (the default) now prefers schema-constrained decoding.** Auto resolves to `structured` whenever the backend supports constrained output (Ollama/LM Studio both do), falling back to the text protocol otherwise. Native function-calling is now **opt-in only** (`tools.mode=native`) — it was the fragile, version-dependent path. So every install gets the most reliable tool-calling out of the box, no config needed. Measured: default `auto` scores 5/6 with `qwen2.5-coder:7b`.
- **Structured mode is now self-consistent end-to-end.** The model is always prompted for the `{message, tool_calls}` envelope, so even when a backend can't truly constrain output, the same reply is parsed correctly (no prompt/parser mismatch). Backend capability is cached per session, and the JSON scanner now accepts both `name` and `tool` keys.

## v4.8.5 (2026-06-03)

### Added
- **`tools.mode=structured` — schema-constrained tool-calling.** Uses the backend's structured-output support (Ollama's `format` with a JSON schema; LM Studio's `json_schema` response format) to force every reply to match a `{message, tool_calls:[{tool, arguments}]}` schema — so the model **cannot** emit malformed tool JSON or hallucinate a tool name (tool names are an enum of the real registered tools). This is the closest local equivalent to a frontier API's constrained decoding, and the most reliable mode for capable models. Falls back to the text protocol automatically if a backend can't do constrained output. Works for **both the regular CLI and the Crew** (shared `chatTurn`). Measured: `qwen2.5-coder:7b` scored **5/6** with `fix-bug` *and* `new-function` passing. `tools.mode` now accepts `auto` (default) · `native` · `text` · `structured`.

## v4.8.4 (2026-06-03)

### Added
- **`ollamadev tool <name> '<json>'`** — directly invoke any registered tool, bypassing the model, for testing/debugging the tool layer (`ollamadev tool list` shows all 94). 
- **Tool-layer functional test (`tests/tools.sh`), wired into CI.** Exercises every offline tool (~55: files, shell, background jobs, git, code-intelligence, memory, todo, notebook, and the parity tools) in a real workspace and asserts each executes without crashing. Runs on every push/PR — so a broken tool is caught automatically, not just at runtime. (Tools needing a model/network/index are covered by the eval workflow instead.)

## v4.8.3 (2026-06-03)

### Added
- **Complete Claude Code tool parity.** Added the last missing pieces: tracked **background shells** — `bg` now returns a job id, `bash_output` reads new output by id, `kill_bash` stops it, and `wait_bg` can wait on a specific job (Claude Code's Bash-background + BashOutput + KillShell). Plus **`ask_user`** (AskUserQuestion) — the agent can ask you a question mid-task in interactive runs (it proceeds with a default in one-shot/crew runs). With v4.8.2's `multi_edit`/`todo_write`/`notebook_edit`, OllamaDev now matches Claude Code's full tool set, all running on your local Ollama/LM Studio. ExitPlanMode maps to the existing `--plan`/readonly permission mode (and the `permission` tool); Read's image support is via the existing vision/`@file` path.

## v4.8.2 (2026-06-03)

### Added
- **Full tool catalog in text mode.** Text-protocol tool-calling now advertises the *complete* tool set to the model — files, directories, code-intelligence (diagnostics/hover/symbols/find_refs/format), and the entire git suite — not just the 15 native-schema tools. The model can now reach ~50 tools by name with correct parameters (the executor always could; now discovery matches). Aliases and internal helpers (`print`/`echo`/`ok`/…) are intentionally excluded; `bash` covers the long tail.
- **Claude Code tool parity: `multi_edit`, `todo_write`, `notebook_edit`.** `multi_edit` applies several edits to one file atomically (all apply or none, single diff preview). `todo_write` maintains a structured session todo list so the agent can plan and track multi-step work. `notebook_edit` edits Jupyter `.ipynb` cells (replace/insert/delete). All other Claude Code tools (Bash, Read/Write/Edit, Glob, Grep, Task, WebFetch, WebSearch, background bash, skills) were already present.
- **Bigger eval suite.** Added `edit-two` (multi-value edit) and `make-app` (multi-file static page with a linked stylesheet) so `ollamadev eval` measures app-shaped work, not just single-file tasks. `qwen2.5-coder:7b` scores ~4–6/6 (text mode) depending on run.

## v4.8.1 (2026-06-03)

### Added
- **Text-protocol tool-calling (`tools.mode`).** You can now opt out of relying on Ollama's native function-calling — which varies by model and breaks across versions — and have OllamaDev own the protocol instead: the model is prompted to emit tool calls as JSON and our own balanced-JSON parser extracts them. `ollamadev config set tools.mode text` forces it; `auto` (default) tries native and falls back to text; `native` prefers native. In text mode the system prompt gains an explicit format spec + a compact tool catalog (from the same schemas), so any model knows what's callable without the native schema. **Measured:** on the built-in eval suite, `qwen2.5-coder:7b` scored **3/4 (75%) in text mode vs 2/4 (50%) native** — more portable *and* more reliable. `ODV_EVAL_VERBOSE=1` shows an eval run's agent output for debugging.

## v4.8.0 (2026-06-03)

### Added
- **Eval harness — turn "it works" into a number.** `ollamadev eval` runs a fixed suite of small, well-defined coding tasks against the current model, each in an isolated temp dir with auto-permission, then scores it with a deterministic check (file content or a command's exit/output) and reports a **pass rate**. Built-in tasks (create-file, edit-json, fix-bug, new-function) plus your own JSON dropped in `./evals/` or `~/.ollamadev/evals/`. `eval list`, `--only <name>`, `--model <m>`, `--keep`, `--json`. 100% local — it just drives the same agent the CLI uses.
- **Curated model presets + graceful fallback chain.** `ollamadev models presets` lists models known to work for agentic coding (qwen2.5-coder, llama3.1, mistral, codestral, …) with size/tool-support/installed flags; `ollamadev models pull <alias>` pulls one by short name; `ollamadev models chain` shows the tool-calling fallback order and which entries are installed. When the active model can't do native tool-calling, OllamaDev now **falls back once to a capable installed model** (config `model.fallback` / `model.autoFallback`) instead of silently dropping to brittle text parsing. A sane default model is also chosen from the chain when none is configured.
- **Inline diff-review panel (desktop & web).** A new **⇄ Review** button opens a read-only, colorized diff of the working tree — changed *and* new files, with per-file chips and +/- counts. Backed by a `reviewDiff` binding over the new local `ollamadev diff [--json]` command (just `git`, read-only). Git stays **agent-driven**: the panel only shows changes; you tell the agent to commit. Verified headlessly (0 JS errors, files rendered).
- **"Why OllamaDev" comparison page** (`docs/compare.html`) leading with the things local-first wins on — privacy, $0 cost, air-gap, auditability — and honest about where hosted tools lead.

### Fixed
- **Native tool-calling was silently broken on Ollama ≥ 0.23 (regression since v4.6.0).** A no-argument tool schema (`run_tests`) serialized its `properties` as a JSON array `[]` instead of an object `{}`, so Ollama rejected the *entire* request with HTTP 400 (`"Value looks like object, but can't find closing '}' symbol"`). Every model fell back to the brittle text-format parser, which is why agentic tasks were unreliable. Tool-schema `properties` are now always emitted as objects. **The new eval harness caught this** — `qwen2.5-coder:7b` went from 0/4 to 2/4 on the built-in suite after the fix.
- **Global flags before the subcommand** (e.g. `ollamadev --offline search "x"`, `-m model eval …`) reported "Unknown command"; argv is now re-rooted at the first positional so leading globals route correctly.
- **`ollamadev eval --model <m>`** now actually uses the requested model (it set a local config array the Agent never read; now applied via `Config::set`).

## v4.7.0 (2026-06-03)

### Added
- **Semantic code search in the desktop & browser.** A new 🔎 sidebar panel runs the local code index: type a query, get ranked file:line matches with snippets, click one to open it in the editor. If the project has no index yet, a one-click **Build it** embeds the repo. Backed by `codeSearch`/`codeIndexStatus`/`codeIndexBuild` bindings (shared by desktop + web), which run the CLI in the open project folder (`code-search`/`index` gained `--json`).
- **Landing page** now surfaces the new local capabilities (semantic code search on the "Code intelligence" card, the `verify` test-fix loop on "Safe by default") — without adding new cards.

## v4.6.0 (2026-06-03)

### Added
- **Semantic code search (local embeddings).** A new on-device index lets the agent find code by *meaning*, not just keywords — `ollamadev index build` embeds the repo with a local Ollama model (default `nomic-embed-text`, set `embed.model`), and `ollamadev code-search "<query>"` (plus a `code_search` agent tool) ranks by cosine similarity. 100% local — only your own Ollama is touched, nothing leaves the machine. `index status` / `index clear` manage it; build/dep dirs (`.git`, `node_modules`, `vendor`, `.build`, …) are skipped, and failed chunks are skipped rather than aborting.
- **Test-aware agent (verify loop).** `ollamadev test` auto-detects and runs the project's suite (npm / phpunit / composer / go / cargo / pytest / make, or `test.command`); `ollamadev verify [--max N]` runs the tests and, on failure, lets the agent fix and re-run until green. A `run_tests` agent tool lets the model verify its own changes mid-session.
- **Git/PR workflow.** `ollamadev commit` writes a Conventional Commit message from the staged diff (`-a` to stage all, `-m` to override; confirms in a TTY). `ollamadev pr create [--base main]` pushes the branch and opens a PR with an AI-drafted title/body, and `ollamadev pr review <n> [--comment]` reviews a PR with the local model. PR commands use the GitHub CLI (`gh`) and are blocked in air-gap mode; `commit` is local and always available.

## v4.5.3 (2026-06-02)

### Fixed
- **Focusing (⤢) a terminal now actually pops it out and enlarges it.** Before, focus only rearranged the terminals strip, so in split layout the terminal stayed the same size (it just hid sibling terminals within the ~40% strip). Focus now pops the terminal out to fill the **whole** code view — hiding the editor and the other terminals — and ⤡ restores the previous layout. Verified headlessly: split + 2 terminals → focus shows 1 pane filling the area with the editor hidden, restore brings both back.

## v4.5.2 (2026-06-02)

### Fixed
- **Desktop/web: focusing (zooming) a terminal threw `TypeError: undefined is not an object (evaluating 'this.zoomed')`.** The terminal `render()` used a `.filter()` callback that read `this.zoomed` without a `thisArg`, so under `'use strict'` `this` was `undefined` inside the callback — it only triggered once a terminal was zoomed. `render()` now hoists the value into a local. Verified headlessly (no exceptions when zooming/cycling layout).

## v4.5.1 (2026-06-02)

### Added
- **Search-only kill switch** — `search.enabled` (default true) turns off **just web search** while leaving `fetch` and remote git working. Distinct from full air-gap (`offline`), which still hard-blocks everything. Toggle it with `ollamadev config set search.enabled false`, or the new **🔍 Search** button in the desktop/web top bar (greys out when the 🌐 Web air-gap is off, since search is moot then). Shared across CLI, desktop, and web via `searchEnabled`/`setSearchEnabled` bindings.

## v4.5.0 (2026-06-02)

### Added
- **`ollamadev search "<query>"`** — run a web search straight from the CLI (not just as an agent tool). `--limit N`, `--provider <backend>`. Honors air-gap mode: blocked under `--offline` / `OLLAMADEV_OFFLINE` / config `offline:true`.
- **Pluggable search backends** — the `search` tool now supports **DuckDuckGo** (default, no key), **SearXNG** (self-hosted, most local-first — set `search.host`), and **Brave Search** (opt-in API — set `search.key` or `BRAVE_API_KEY`). Pick with `search.provider` in config or `--provider` per run. Only the query ever leaves the machine; the AI stays local.
- **🌐 Web toggle in the desktop & browser** — a top-bar button flips web access (search / fetch / remote git) on and off; off = ✈️ air-gapped. Backed by the shared `offline` config through new `webAccess`/`setWebAccess` bindings, so the terminal, desktop, and web all agree. Applies to new agent runs.
- **`ollamadev config get|set <key> [value]`** — inspect or persist settings in `~/.ollamadev/config.json` from the command line (new `Config::persist()` writes the user's config file, preserving other keys).

## v4.4.1 (2026-06-02)

### Fixed
- **Desktop/web (run from source) now use the repo's freshly built CLI** instead of an older `ollamadev` that happens to be installed on `PATH`. After `./build.sh`, the app picks up new CLI features (like the role catalog) without needing a reinstall. Shipped archives are unaffected — their launchers set `OLLAMADEV_BINARY` explicitly, which still takes priority; you can also set it yourself to point at any binary.

## v4.4.0 (2026-06-02)

### Added
- **Manage Crew roles from the desktop & browser**, not just the CLI. The Crew setup screen now has a **🎭 Manage roles** panel: see every role (built-ins + your own) with its model/permission badges, add a new role (name, persona, description, optional pinned model, read-only toggle), and remove custom ones. Backed by the shared `Bindings` (`crewRoleList`/`crewRoleAdd`/`crewRoleRemove`) over the one global catalog in `~/.ollamadev/crew-roles/`, so a role added anywhere shows up everywhere.
- **`crew role list --json`** — structured catalog output (used by the desktop/web role manager; handy for scripting too).

## v4.3.0 (2026-06-02)

### Added
- **Crew roles — the Director assigns a role to each subtask.** Instead of every coder being identical, the Director now picks the most fitting **role** for each subtask from a catalog, and each coder runs with that role's persona, optional pinned model, and permission mode.
  - **Built-in roles:** `coder` (default), `tester`, `docs`, `refactor`, `security`.
  - **Your own roles:** `ollamadev crew role add <name> "<persona>" [--desc "…"] [--model <m>] [--readonly]`, plus `crew role list | show <name> | remove <name>`. Roles are plain JSON in `~/.ollamadev/crew-roles/` — one global catalog **shared across CLI, desktop, and web** (the desktop/web run crew through the same CLI engine).
  - A role can pin its own **model** (e.g. a bigger model for `security`) and run **read-only** (survey/advise without editing).
  - The assigned role is persisted in the resumable plan and shown on the live **Crew board** (a role badge on each card in the desktop & browser).
  - Unknown role names the Director invents fall back to `coder`.

## v4.2.2 (2026-06-01)

### Changed
- **Default web-mode port is now `41434`** (was `8080`). 8080 collides with countless other dev servers and proxies; 41434 mirrors Ollama's `11434` so it's recognizably "the OllamaDev port" while sitting clear of common dev ports and the OS ephemeral range. Override anytime with `OLLAMADEV_SERVE_PORT` (or `PORT=` for the VPS script).

## v4.2.1 (2026-06-01)

### Changed
- **Workspaces moved to a vertical list at the top of the left pane** (under a "Projects" header), instead of a horizontal strip under the top bar — click a project to switch, ＋ Open project adds another.

### Fixed
- **Workspaces: adding a second project via ＋ / 📂 Open could appear to do nothing.** The folder modal pre-filled the *current* project's path, so clicking Open re-opened the same workspace (a no-op). The ＋ now opens with an empty path and a "new tab" hint, so you add a *different* project.
- **Workspace window state now marshals reliably in the desktop (Boson) app.** `wsSaveState` passes the saved state as a JSON string instead of a nested object — the Boson FFI bridge is stricter about complex arguments than web mode. Opening/switching is also now fully best-effort: if any workspace-bookkeeping binding hiccups, the folder still opens and the tab strip still refreshes (a workspace add never silently fails).

## v4.2.0 (2026-06-01)

### Added
- **Workspaces** — a named, persistent list of project folders you switch between, shared across the CLI, desktop, and web (one global store at `~/.ollamadev/workspaces.json`).
  - **Desktop/web:** always-visible **project tabs** under the top bar (one per workspace, stable order). Clicking a tab opens its folder and **restores its full window state** — its terminals (re-attached to their still-running PTYs when switching within a session, respawned fresh after a restart), open editor tabs, layout, and active view. `＋` (or `📂 Open`) opens a folder as a new tab; `×` closes one. The app reopens your active workspace on launch instead of prompting. (Tabs are a switcher, not split panes — one project on screen at a time; open the app twice to see two at once.)
  - **CLI:** `ollamadev workspace list | add [path] [name] | remove <name> | open <name>` (alias `ws`). `open` prints the path so `cd $(ollamadev workspace open <name>)` jumps there.
  - Sessions, memory, and Crew were already keyed by working directory, so opening a workspace auto-resumes them — workspaces add the named switcher and saved window state on top.

### Changed
- The static site moved from `site/` to `docs/` and is now published to GitHub Pages automatically (`.github/workflows/pages.yml`) on every push to `main` that touches it.
- Landing page gained three feature cards: air-gap/attestation, per-repo resume, and voice input.

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