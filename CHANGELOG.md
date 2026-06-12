# Changelog

## v0.8.94 (2026-06-12) — watch the model think (chat / CLI / crew) and Ctrl-C to cancel

### Added
- **The model's reasoning now streams live so you can see where it's heading and cancel if it's wrong.**
  - **Chat:** a reasoning model (qwen3.5:9b) used to sit on a blank line through its whole think phase. It now streams its chain-of-thought *dimmed* before the answer, with a "thinking…" cue covering the gap before the first token. The reasoning is shown only — the saved reply stays answer-only. **Ctrl-C** cancels the in-flight reply (the turn is dropped from history).
  - **CLI (agent):** already streams content/reasoning live during the agentic loop (v0.8.89); Ctrl-C interrupts.
  - **Crew:** sequential coders now stream their reasoning (dimmed) and tool actions to the console instead of just heartbeat dots, so you can watch each coder work and Ctrl-C to stop a run that's going the wrong way. Forked/parallel coders keep writing to their per-coder logs (streaming many to one terminal would interleave into noise).
- New thinking-aware streaming hook on `OllamaClient::chatWithModel` (`$onThinking`) that surfaces reasoning deltas without folding them into the returned answer.

## v0.8.93 (2026-06-12) — qwen3.5:9b is the recommended default

### Changed
- **`qwen3.5:9b` is now the shipped default model.** It's the first-run `ollama.defaultModel`, the head of the tool-calling fallback chain (`Models::defaultChain`, so `bestInstalled` prefers it), and the top catalog preset marked "Recommended default" — reliable native tool-calling, fits a 24 GB / CPU-only host, and already backs every crew role. The `/models` recommendation hint and pull example now lead with it. `qwen2.5-coder:7b` remains in the catalog and chain as the next preference. (Existing configs/sessions are untouched — set yours with `config set ollama.defaultModel qwen3.5:9b` or `/model`.)

## v0.8.92 (2026-06-12) — crew coder no longer quits without editing (qwen3.5:9b)

### Fixed
- **Crew coders no longer end a subtask with "no changes" when the model describes the edit instead of calling a tool.** The coder loop broke the instant a turn produced no tool call — so a model like `qwen3.5:9b` that opened with "I'll create greet.py…" (prose, no `write` call) left the worktree empty and the subtask merged nothing (it only succeeded after escalating to a heavier model). The coder loop now carries the same one-shot "you described it but didn't act" nudge as the main agent loop (`Session::looksLikeUnactedAction`, now reusable), so the coder is told to actually call write/edit and retries instead of quitting. It also attaches `tool_calls` to the stored assistant turn in Ollama's wire shape so tool results correlate to the originating call during native function-calling. Verified end-to-end with the recommended crew model (`qwen3.5:9b`): Director → Coder → Auditor → merged.

## v0.8.91 (2026-06-12) — status badge driven by the prompt, not a timer

### Fixed
- **The desktop status badge no longer flips to "done" while the CLI is still working, and no longer gets stuck.** v0.8.90 still used a "output quiet for 2.5s ⇒ done" timer — but the agent is legitimately quiet for longer than that during a long `bash` run, a slow model loading, or tool execution, so the badge went "done" mid-turn (and could stay there). The badge is now derived from the actual output: it shows **running** from the moment you submit until the CLI reprints its prompt (`❯` on its own line), which is the only reliable "waiting for input" signal — then it returns to **idle**. No timer, so a long silent step keeps it correctly on running, and it always returns to idle when the turn ends. (Dropped the `done` state for the terminal badge; it's `idle` ↔ `running`.)

## v0.8.90 (2026-06-12) — desktop status badge tracks work correctly

### Fixed
- **The desktop (ADE) terminal status badge no longer sits on "idle" while the agent is working.** The badge only flipped to "running" when the app *programmatically* sent text (voice/task hand-off/Send button) — a prompt typed directly into the terminal and submitted with Enter never left "idle" the whole time the model worked. Submitting a line now marks the terminal busy (`Terminal.markBusy`), for both agent and chat panes. Output that resumes after the badge had gone "done" (e.g. a slow model finishing load and starting to stream) re-arms it to "running", so a cold-start think gap no longer strands it.

## v0.8.89 (2026-06-12) — live streaming + robust tool-calling across all models

### Fixed
- **The CLI no longer "stops mid answer" with a raw Ollama error.** When a model (notably gemma4) emitted a malformed native tool-call, Ollama's server rejected it with a Go-side `Value looks like object, but can't find closing '}' symbol` 500 — and the agent surfaced that raw error *as the answer* and ended the turn. The native path now retries once (the bad JSON is usually stochastic) and, if it still fails, falls through to the tolerant text parser + `repairJson` instead of dead-ending (`src/75-agent.php`).
- **Small but tool-capable models can finally use tools.** Sessions forced *every* small model (≤~3.5 GB / `3b`-ish names) into tools-off chat mode by size alone — so `llama3.2:3b`, which Ollama reports as tool-capable and calls natively just fine, could never read a file. Chat-mode default is now capability-aware (`Agent::shouldDefaultToChat`): tool-capable → agent mode regardless of size; only a *small AND non-tool* model defaults to pure chat.
- **Capable models no longer emit text pseudo-calls that silently no-op.** In native mode the system prompt was still showing the `<tool_code>{…}` text protocol and few-shot examples, luring models like mistral into writing `write{…}` / `view(…)` in prose that bypassed Ollama's structured `tool_calls`. The native prompt now tells the model to use its native function-calling and omits the text format; the `<tool_code>` few-shot block is restricted to actual text mode.
- **Prose function-call syntax is now recognized.** A new tolerant parser catches `toolname(arg="val", …)` syntax (constrained to known tool names so ordinary prose never false-fires) — recovering calls from weaker models that degrade to that form when the native tool catalog is large.
- **No more spinning on a stuck tool call.** A weak model that re-issued the *exact same* failing call (e.g. `edit` with a missing `old_string`) every iteration burned all `maxIterations` and looked hung. The loop now detects an all-repeat turn, nudges once to change approach, and stops cleanly if it repeats again.

### Added
- **Live token streaming in the agentic loop.** Native tool-calling ran with `stream:false`, so the screen sat blank while a slow model (e.g. gemma4:31b on CPU) generated a whole turn. `chatWithTools` now streams: content appears token-by-token as it's produced and reasoning shows dimmed as progress, while `tool_calls` are still collected structurally. The markdown restyle only buffers answer content (not thinking/tool lines), so it stays correct.

## v0.8.88 (2026-06-11) — agent persistence + cleaner thinking-model output

### Fixed
- **The agent no longer stops early when the model talks instead of acting.** The loop ended the moment a turn produced prose but no tool call, and the rescue nudge only covered unacted *edits* — so a model that said "I'll run `ls`…" or "let me check…" without emitting a tool call just quit. `looksLikeUnactedAction` now also catches first-person tool intent (run/read/search/…) and nudges once to actually call the tool. It's first-person only, so a finished answer's advice ("you can run `npm test`") doesn't false-trigger; it fires at most once per turn and touches no request params.
- **gemma4 (and other thinking models) no longer leak `<tool_call|>` markup into replies.** `stripToolMarkup` now strips the pipe-delimited delimiter variants (`<tool_call|>`, `<|tool_call|>`).
- **No more duplicated answer in the desktop ADE.** The post-stream markdown restyle repositioned the cursor by counting newlines, which miscounts wrapped lines and printed a second copy; it's now skipped in the embedded terminal (`OLLAMADEV_SIMPLE_INPUT`), where raw markdown streams cleanly. Real ttys keep the restyle.

### Changed
- Default `agents.maxIterations` headroom guidance raised to 20 for longer multi-step tasks (set per-machine via `config set agents.maxIterations`).

## v0.8.87 (2026-06-11) — VPS setup script: qwen3.5:9b only

### Changed
- **`scripts/setup-vps.sh` is now single-model.** Dropped the `--coder7b` / `--coder14b` (qwen2.5-coder) options — `qwen3.5:9b` is the one VPS crew model, and `--ctx` is the only remaining flag.

## v0.8.86 (2026-06-11) — VPS setup defaults to qwen3.5:9b

### Changed
- **`scripts/setup-vps.sh` now defaults to `qwen3.5:9b`** (≈6.6 GB) instead of `qwen2.5-coder:7b`. It reports a `tools` capability and fits in 24 GB with room to back *every* crew role on one model — so a small / CPU-only host (e.g. a 24 GB, 12-core VPS) runs the whole crew without swap thrash, matching the recommended local crew default. `qwen2.5-coder:7b` / `:14b` remain selectable via `--coder7b` / `--coder14b` (and `--qwen35` is explicit). The 22 GB+ models stay refused.

## v0.8.85 (2026-06-11) — detect tool support from the engine, not a hardcoded list

### Fixed
- **No more false "⚠ <model> is unreliable at tool use" warnings.** The check flagged any model absent from a hardcoded preset list — so capable models like `gemma4`, `glm`, and `mistral-nemo` were wrongly nagged (and pushed toward chat mode) even though they call tools fine. The CLI now asks the engine directly — Ollama's `/api/show` `capabilities` (`src/75-agent.php::modelSupportsTools`, mirroring the existing vision detection): a model that reports a `tools` capability is trusted, one whose capabilities omit `tools` still gets the nudge (e.g. completion-only `codellama`), and an unknown/unreachable engine errs toward no warning. No preset list is involved in the verdict.

## v0.8.84 (2026-06-11) — reliable tool calls on any model + desktop permission isolation

### Fixed
- **Tool calls now actually run on any tool-capable Ollama model.** `tools.mode=auto` led with brittle text/JSON parsing of the model's prose — so capable models (gemma4, qwen2.5-coder, qwen3.6) would *describe* a tool call, emit nothing parseable, and the turn dead-ended back at the prompt ("it goes to the tool and just stops"). `auto` now **prefers Ollama's native function-calling** (`src/75-agent.php::effectiveToolMode`), which has a per-model tool template and returns structured `tool_calls`. It keeps a graceful ladder — a model Ollama reports as tool-unsupported drops to a fallback model, then to structured (schema-constrained) decoding, then to the text protocol — so it never dead-ends. Verified 3/3 on write/read/bash across gemma4:31b, qwen2.5-coder:7b, and qwen3.6:latest (each checked by a real file on disk or an unguessable token, not the model's say-so).

### Changed
- **The desktop ADE's tool-approval choice no longer leaks into the standalone CLI.** It now rides a per-launch `OLLAMADEV_PERMISSION` env var + `localStorage` (`src/99-main.php` honors it without persisting), so picking *Auto* in the desktop governs only that terminal — your terminal CLI keeps its own `ask` default.

### Added
- **Tool-approval picker** in the desktop Session drawer (✋ Ask / ⚡ Auto / 🔒 Read-only), defaulting to Ask to match the CLI.
- **`scripts/setup-vps.sh`** — one command to wire a single shared coder model into every crew role and cap the context window for small / CPU-only hosts (e.g. a 24 GB, 12-core VPS). Refuses 22 GB+ models that can't fit there. `--coder7b` (default) or `--coder14b`.

## v0.8.83 (2026-06-11) — multiple Chat windows + an image-input cleanup

### Added
- **Open as many 💬 Chat windows as you want.** Chat is now a terminal *kind* (not a singleton pane), so each ＋ Chat is its own independent window — its own model, conversation, and resumable `--session`, with the model picker + 📎/🧠/⬇ in its header. Each window resumes its session on reopen, like terminals.

### Changed
- **Chat image input reuses the engine's `Vision` helper** (`src/77-vision.php`) instead of duplicating base64 logic — and gains `@<path>` mentions: type `look at @photo.png what's wrong?` inline, exactly like the agent.

### Fixed
- **A terminal could go dead when another pane was opened or closed.** In web mode with SSE streaming (`PHP_CLI_SERVER_WORKERS ≥ 2`), `render()` re-mounts every pane and the re-mount closed the SSE stream without re-opening it — so a terminal (or chat) stopped updating. Mount now re-opens the stream on re-mount (offset resets, so it re-streams from the top). Pre-existing; surfaced by the multi-window chat work.

## v0.8.82 (2026-06-11) — Chat: image input, custom persona, and export

Three additions to the 💬 Chat (all 100% local, vanilla — no new deps):

### Added
- **Image input (vision).** `/image <path>` in `ollamadev chat` attaches an image (base64) to your next message, sent to a vision-capable Ollama model via the `images` field — with a warning if the model isn't vision-capable. The Chat window has a 📎 button. Saved transcripts stay text-only (images aren't persisted).
- **Custom persona.** `/system <text>` sets a custom system prompt for the conversation, **persisted per session** (a 🧠 button in the window; `/system reset` returns to the default). Resuming a thread keeps its persona.
- **Export.** `ollamadev chat export <id>` renders a saved conversation as Markdown; the window's ⬇ button copies it to the clipboard **and** downloads a `.md`. Backed by a `chatExport` binding (desktop + web).

All three are covered by hermetic smoke tests (a mock Ollama server flags image messages, asserts persona persistence + Markdown export). 613 tests passing.

## v0.8.81 (2026-06-11) — the Chat window fits small windows

### Fixed
- **The Chat window's toolbar no longer overflows when the window is narrow.** The "chat · no tools" hint drops first, then the model picker shrinks (with an ellipsis); the 💬 label and ↻ New-chat button stay usable all the way down to the minimum window width. A terminal now also stays **pinned to the bottom on resize**, so the live prompt stays in view when you shrink the window instead of leaving you scrolled up.

## v0.8.80 (2026-06-11) — chat sessions persist + a desktop-bindings drift fix

### Added
- **`ollamadev chat` conversations now persist as resumable sessions.** Each chat runs under a `--session <id>` and is saved to `~/.ollamadev/chats/<id>.json`; resuming an id replays the conversation. New **`ollamadev chat list [--json]`** and **`ollamadev chat delete <id>`** subcommands. The ADE Chat window uses this for its **↻ New chat** button (a clean break) and to resume on reopen — the window stays minimal (model picker + New chat), no thread sidebar.

### Fixed
- **The desktop app (Boson) was silently missing 12 bindings.** `index.php` is a hand-maintained `$b->bind()` list that had drifted from `Bindings::PUBLIC`, so on the desktop **Skills, Hooks, Director-steering, terminal-resize, save-crew-models, and chat list/delete were all dead** (the web build had them). All 12 are now registered. A new smoke **parity test** asserts `index.php` registers every `PUBLIC` binding, so it can't drift again — the web bridge already had such a check, but there was no desktop one.

## v0.8.79 (2026-06-11) — chat shows the answer, not the chain-of-thought

### Fixed
- **`ollamadev chat` no longer prints a reasoning model's chain-of-thought** — it streams only the final answer. Reasoning models (gpt-oss, qwen3, …) put their internal reasoning in Ollama's `thinking` field, which was leaking into the conversation; a plain chat should show the reply, not the reasoning. `OllamaClient::chatWithModel` gained an `$includeThinking` flag (default **on**; the `chat` command turns it **off**) — the agent path is unchanged.

### Changed
- The website's Chat demo now features **gpt-oss:20b**.

## v0.8.78 (2026-06-11) — a plain Chat window (ollamadev chat)

### Added
- **A dedicated 💬 Chat window in the ADE — a plain, tool-free chatbot, like ChatGPT but 100% local.** Pick any of your local Ollama models from the in-window picker and just talk. It runs the new `ollamadev chat` command — **no agent, no tools, no file access, no permissions** — streams replies, and renders clean in the embedded terminal (it's line-based like the CLI). It's a window on the infinite canvas like everything else (drag / resize / persist per project), and it **remembers its own last-used model** across restarts, independent of the session Model dropdown.
- **CLI: `ollamadev chat [-m <model>]`** — a plain conversational REPL (`/model`, `/clear`, `/exit`) plus a `--json` one-shot mode. Shares the one engine, so your model / host (`--host` / `--lmstudio`) / temperature / context settings all apply.
- **Website**: a Chat showcase section with a real demo screenshot, a nav link, and docs updates (window tour + CLI command table).

### Fixed
- Batch the terminal's `ResizeObserver` into `requestAnimationFrame` so resizing a renderer inside the callback can't retrigger it — silences the benign "ResizeObserver loop completed with undelivered notifications" warning.

### Notes
- Purely **additive** — the agentic Crew and the ollamadev CLI agent terminals are untouched. The Chat window is conversation only.

## v0.8.77 (2026-06-10) — picking a crew team auto-loads its team skill

### Added
- **Choosing a by-project-type crew team now auto-loads that team’s skill into the run.** Pick **🛒 E-commerce** and the crew loads the `ecommerce` skill; **🔌 REST API / Backend** loads `rest-api`; and so on for all 34 teams. The team selection is the opt-in — a plain crew run (no team picked) behaves exactly as before. The Crew window shows which skill a team will load when you select it.
- Engine: a forced skill name now resolves against the per-team starters too (`CrewSkills::byNames` → `allBuiltins`), so a team can materialize its own skill into each coder’s worktree. Focus-matching of the capability skills is unchanged.

## v0.8.76 (2026-06-10) — a skill for every crew team + full Skills CRUD in the UI

### Added
- **Every crew team now has a matching, editable skill in the Skills window.** Alongside the 31 capability skills (responsive-design, payments-money, …), there are now **34 per-team starter skills** — one for each by-project-type team in the crew picker (`website`, `landing-page`, `saas`, `ecommerce`, `admin-dashboard`, `rest-api`, `graphql`, `microservice`, `game`, `cli`, `library`, `vscode-ext`, `ci-cd`, `web3`, …). They’re **listed, readable, and editable** in the UI (⎘ to view / customize a copy), shared across CLI, desktop, and web. They surface in the Skills manager only — crew focus-matching is unchanged, so existing crews behave exactly as before.
- **Full Skills CRUD in the window**: a **＋ New skill** button, clear **create vs. edit** mode (the name locks while editing so an update can’t silently fork a new skill, with a status line and a 💾 Update / Save-my-copy button), plus **＋ Add crew-template skills** to copy the template-injected built-ins into your own editable skills.

## v0.8.75 (2026-06-09) — Roles / Skills / Hooks are direct ＋ Add menu entries

### Added
- **🎭 Roles, 🧩 Skills, and 🪝 Hooks now open directly from the ＋ Add button and the right-click canvas menu**, instead of only through the Crew window’s “Manage…” links. The Skills window lists your custom skills **and all 31 built-in team-skills** (the ones crew templates inject — `testing-discipline`, `refactor-safety`, `docs-writing`, `security-hardening`, …) under a “Built-in team-skills” section; ⎘ views or customizes a copy of any. (No new wiring — `addPane` already routed these; they just lacked a menu entry.)

## v0.8.74 (2026-06-09) — parallel local coders on one box (opt-in) + cockpit verified live

### Added
- **Run crew coders in parallel on a single machine** — opt-in via **`--parallel [N]`** (CLI), **`crew.parallel`** / **`crew.parallelMax`** (config), or the new **“‖ Run coders in parallel on this machine”** checkbox in the Crew window. A **bounded pool** forks up to N coders at once (default 2) so a 6-coder run doesn’t open 6 inference streams against one GPU. For true concurrent inference set `OLLAMA_NUM_PARALLEL`; otherwise requests queue but tool/git/IO work still overlaps. The topology window shows the lane count (`‖ N parallel`).
- The **default stays sequential** and multi-host parallelism stays automatic — existing crew behavior is unchanged.

### Verified
- The v0.8.73 **crew cockpit was checked against a real crew run**: the live board is enriched exactly as the topology + activity feed expect — per-role models, each coder’s branch, the files it changed, and the Auditor’s verdict (clean/held) — and the per-coder logs carry the `→ tool` lines the activity parser reads (👁 reading / ✎ editing / ✓ done). The Director → worktrees → coders → Auditor → land pipeline ran intact.

## v0.8.73 (2026-06-09) — Crew cockpit: live topology map, voice-driven crew, real-time activity (compete with cnvs/BridgeSpace/Forge)

Four additive surfaces over the **existing** crew engine — the Director, isolated git worktrees, the Auditor gate, and `crew steer`/`crew director` are all **unchanged**. This just *shows* and *drives* what the crew already does, matching the headline features of cnvs.dev, BridgeSpace, and Plyrium Forge while staying 100% local + vanilla.

### Added
- **🛰 Live crew topology window** (＋ Add → Crew topology, right-click canvas, or say “open topology”). A read-only map of a running crew: the **Director** + Researcher + Auditor (with each role’s model), and one card per **coder** showing the **branch it owns**, its model, the **files it’s touching**, the **Auditor’s verdict** (clean / flagged / held), and its **live activity**. Updates live off the same board the kanban uses.
- **Real-time agent activity** — each coder’s pane badge (and its topology card) now shows what it’s doing *right now* — ✎ editing, 👁 reading, ⚡ running, 🔎 searching, 💭 thinking — parsed live from the per-coder logs, instead of a static “working”.
- **Voice commands the crew** (in the 🎙 Voice window). “**start a crew to add tests to the signup form**” launches a run; “**tell coder 2 to focus on edge cases**” or “**tell the crew to add error handling**” steers a running coder (or the whole crew). Drives the existing `runCrew` / `crewSteer` — no new orchestration.
- **💾 Save these models as my default crew** — the Crew modal’s per-role model picks (Director / Researcher / Coder / Auditor) can now be saved as your global defaults (`crew.*Model`), so every future run — terminal, desktop, or web — starts with them.

### Changed
- The live crew board JSON gains additive display fields (per-role models, each subtask’s branch, files, and audit verdict). Purely for the new views — orchestration is byte-for-byte the same.

## v0.8.72 (2026-06-08) — cohesive ☰ drawer (rail + Projects + Session slide as one panel)

### Changed
- The rail and the Projects/Session sidebar now **slide in together as one seamless panel** (same offset + speed, no divider line between them) instead of two pieces. The **Session** pickers (model / temperature / voice / theme) are unchanged and fully wired — they still drive the app exactly as the top-bar versions did.

## v0.8.71 (2026-06-08) — session pickers (model / temp / voice / theme) move into the ☰ drawer

### Changed
- **The model, temperature, voice-model, and theme dropdowns moved into the ☰ drawer** (a new “Session” section under Projects), so the top bar is now just ☰ · brand · connection · ＋ Add · current folder. The ＋ Add button and the folder path stay where they were. Applies to both the desktop app and web (they share one frontend).

## v0.8.70 (2026-06-08) — canvas zoom + the top-bar actions move into the ＋ Add / right-click menu

### Added
- **Canvas zoom.** Zoom with **Ctrl/⌘ + scroll** or a **trackpad pinch** (zooms toward the cursor), or the floating **− 100% +** control bottom-right of the canvas. Voice too: “zoom in / zoom out / reset zoom”. Pane dragging/resizing and drop positions are all zoom-aware; the zoom level persists per project.

### Changed
- **The top-bar action buttons now live in the ＋ Add / right-click menu** (Open project, Web access, Web search, Tiled/Free, Renderer DOM/Canvas, Center, Voice history) plus the window types (Terminal, Editor, Files, Search, Tasks, Board, Graph, Browser, Crew, Review, Voice). The toggles show their current state. The top bar is now a **slim strip**: ☰, the model/temperature/STT/theme pickers, connection status, and **＋ Add**.

## v0.8.69 (2026-06-08) — full-bleed canvas: the rail + Projects become a ☰ drawer

### Changed
- **The canvas now fills the entire window.** The left rail and Projects sidebar are an overlay **drawer** toggled by a **☰** button (top-left) instead of permanently occupying the left edge — so the canvas spans full width.
- Opening a window from the rail, picking a file or project, clicking the canvas, or pressing Esc closes the drawer again to reveal the full canvas.
- Works the same on **desktop and mobile** (web), and the canvas now **pans with one-finger touch**, not just mouse drag.

## v0.8.68 (2026-06-08) — Voice control window: press-to-talk to open windows or drive the CLI

### Added
- **A 🎙 Voice control window** (right-click canvas / ＋ Add → Voice control). Press-to-talk, and your speech either **runs as a command** or is **piped to a CLI terminal**:
  - **Open / control windows by voice** — “new terminal” (opens a fresh ollamadev CLI), “open board”, “show graph”, “review”, “open files / search / tasks / browser / editor / crew / roles / skills / hooks”, “close graph”, “center”, “tile”, “free”.
  - **Dictate to the CLI** — say “type add input validation to the signup form”, then “enter” to run it. “control c” interrupts, “clear” clears. It targets the terminal you last focused.
  - **Three modes** — *Auto* (a command if it matches one, otherwise sent to the CLI), *Commands* (only window/canvas actions), *To CLI* (always send to the terminal).
  - Uses the same local STT engine as dictation — nothing leaves the machine. Shows what it heard and a short action log.
- Pure vanilla — reuses the existing `MediaRecorder` + `sttTranscribe` plumbing and the canvas-window machinery.

## v0.8.67 (2026-06-08) — tool dialogs are canvas windows too (Crew / Review / Roles / Skills / Hooks)

### Changed
- **The Crew, Review (diff), Roles, Skills, and Hooks dialogs are now canvas windows** instead of modal overlays — drag, resize, focus-zoom, and keep them open alongside everything else. Their buttons (👥 Crew, ⇄ Review, and the Manage… links) open them on the canvas; Cancel/Done or the window × closes them. They're transient (not persisted across restart) so they don't clutter a reopened project.
- The real dialog content is *moved* into the window (not duplicated), so all the existing form wiring works unchanged. Folder-open and Voice-history stay as quick modals.
- Still 100% vanilla.

## v0.8.66 (2026-06-08) — every window lives on the canvas (Files / Search / Tasks too)

### Changed
- **Files, Code search, and Tasks are now canvas windows too** — they left the fixed left sidebar and join the editor / board / graph / browser / terminals as draggable, resizable panes on the infinite canvas. The sidebar now holds only the **Projects** switcher.
- The **rail buttons** (▤ 🔎 ▦) and **＋ Add** / right-click menu open or focus each window on the canvas; an open window's rail button shows active.
- A **Files** window opens automatically the first time a project lands on the canvas (so the tree is right there). Each window's open state + position travels per-project, as before.
- Still 100% vanilla — the panels are moved (not duplicated) between a hidden store and their pane, so state is preserved.

## v0.8.65 (2026-06-08) — canvas layout is per-project (multiple workspaces, like before)

### Changed
- **Each project (workspace) now remembers its own canvas** — which view panes are open, their positions/sizes, and the pan offset travel with the workspace, exactly like the terminals and editor tabs already did. Switching projects in the **Projects** sidebar restores that project's canvas; closing/reopening the app resumes it.
- Older saved workspaces (from before the canvas) migrate cleanly: their single active view opens as a pane.

## v0.8.64 (2026-06-08) — the Workspace is now one infinite, pannable canvas

### Changed
- **The Workspace is now a single infinite canvas — everything is a draggable pane on it: terminals, the editor, the board, the graph, the browser.** Replaces the old Workspace/Board/Graph/Browser tab nav (removed) and the editor-above-terminals split.
  - **Pan** by dragging empty canvas space (or middle-drag anywhere); the canvas extends infinitely in every direction. A faint dot grid shows you're moving across it.
  - **Add panes continuously** — **right-click empty canvas** (or the **＋ Add** toolbar button) to drop a Terminal, Editor, Board, Graph, or Browser pane where you clicked. Each non-terminal view is a singleton (add it once; ＋ Add focuses it if it's already open).
  - **The editor is a pane too** — opening a file drops (or focuses) an editor pane on the canvas.
  - **⊕ Center** re-centers the canvas to bring every pane back into view.
  - Each pane drags by its header, resizes from the corner, and **Focus (⤢)** zooms it full-screen — same as before. Closing a view pane (**×**) parks it (state preserved — graph layout, browser page, editor tabs); re-add any time.
  - Which panes are open, their geometry, and the pan offset **persist** across restarts.
- 100% vanilla — one CSS `transform` pans the whole canvas; views are moved (not re-rendered) between a hidden store and their pane, so state is never lost. No dependency, engine untouched.

## v0.8.63 (2026-06-08) — fix: popped-out pane now actually appears in the Workspace

### Fixed
- **Popping a tab out now shows the pane in the main area with the terminals.** v0.8.62 set the view but didn't re-render `#terminals`, so the pane was never mounted (and popping a non-current tab left you on a view where the terminals aren't shown). Pop-out now switches to the Workspace and re-renders, so the floating pane appears immediately among the terminals.

## v0.8.62 (2026-06-08) — pop any workspace tab out into a pane with the terminals

### Added
- **Any workspace tab — Board, Graph, or Browser — can now be popped out into a pane right where your terminals are, and popped back into its tab.** Generalizes v0.8.61 (which was board-only) to every view, each independently: pop out one, several, or none.
  - **⤴ Pop out** button on the tab row pops the current tab into a floating/tiled pane among the terminals. Its old tab shows a **⤵ Pop it back here** placeholder; the pane header has a **⤵** pop-back and **×** (both return it to its tab).
  - The pane drags by its header, resizes from the corner, and **Focus (⤢)** zooms it full-area — identical to a terminal pane (it reuses the same free/tiled layout code).
  - The view's **real element is moved** into the pane (not re-rendered), so its live state is preserved — the graph keeps its layout, the browser keeps its page, the board stays live.
  - Which tabs are popped + their geometry **persist** across restarts.
- The crew poll keeps the board live while it's popped out, even when you're on another tab.
- Pure vanilla — no dependency; the panes reuse the existing terminal pane machinery and a DOM move/park.

## v0.8.61 (2026-06-08) — dock the task board as a pane among your terminals

### Added
- **The task board can now be docked as a pane right where your terminals are** — a new **📋 Board pane** toolbar toggle. It's the same live kanban as the Board tab (Director's crew cards, 💡 idea cards with ▶ Run, and your manual tasks), but it lives alongside the terminals instead of on a separate view.
  - **Free layout:** a floating window you drag by the header and resize from the corner, overlapping terminals freely.
  - **Tiled layout:** it takes a grid cell next to the terminals.
  - **Focus (⤢)** and **double-click the header** zoom it full-area; **×** undocks it — identical to a terminal pane (it reuses the same free-layout drag/zoom code).
  - Its on/off state and geometry **persist** across restarts.
- The crew poll now refreshes the docked board even outside the Board view, and stays alive while the pane is open so a freshly-started crew run appears there live.
- Pure vanilla — the board pane reuses `Tasks.renderInto()` and the existing pane machinery; no new dependency, no duplicated board code.

## v0.8.60 (2026-06-07) — canvas terminal: fill the pane + emoji

### Fixed
- **The canvas renderer now fills the whole pane.** It was sized from a stale `clientWidth` (measured before a free-floating pane reached its real size), so it rendered into a small box in the corner with text clipped off the right. It now fills the container, derives cols/rows from the live pixel size, and re-fits via a **ResizeObserver** whenever the pane actually changes size (mount, drag-resize, zoom, window resize) — no timing guesswork. The ResizeObserver also keeps the DOM renderer's pty cols/rows correct.
- **Emoji no longer render as `□□` tofu** — the canvas font stack now includes an emoji fallback (Noto/Apple/Segoe emoji), so glyphs like 🧭 render.

## v0.8.59 (2026-06-07) — free-mode focus + canvas/DOM terminal parity

### Fixed
- **Focus (full-screen one terminal) now works in Free layout too**, not just Tiled. The ⤢ focus button pops a pane out to fill the whole work area and ⤡ restores it — and the saved free-layout geometry is preserved. (Focus was previously a tiled-only concept and was silently cleared when you switched to Free.)
- **The canvas (GPU) renderer now matches the DOM terminal.** It was using hardcoded colors and a guessed font size; it now reads the live theme from the screen's CSS — foreground/background colors, font family, and font size — and zeros the screen padding so cells align edge-to-edge. So toggling 🖼 Canvas keeps your theme and font instead of changing the terminal's look.

## v0.8.58 (2026-06-07) — opt-in GPU/canvas terminal renderer

### Added
- **GPU-composited canvas terminal renderer** (desktop + web), opt-in via the **🖼 DOM / 🖼 Canvas** toggle. Instead of thousands of `<div>`/`<span>` nodes, it keeps a scrollback cell buffer and paints the visible viewport into a single `<canvas>` — one hardware-composited layer, constant memory, **dirty-cell repaint** — so it stays fast under firehose output where the DOM renderer slows down. Includes HiDPI scaling, wheel scrollback, and drag-to-select + copy. Alt-screen TUIs reuse the `TermGrid` model. Pure vanilla Canvas 2D — no xterm.js, no WebGL, no dependency.
- **The DOM renderer remains the default** — the canvas path is a clean, separate opt-in, so it can't regress the working terminal.

_The renderer's pure logic (cell buffer, scrollback, alt-screen, selection, dirty-diff) is unit-tested headlessly via node; the visual result (crispness, selection feel) is best spot-checked in a browser — hence the opt-in toggle. A WebGL renderer (true single-draw-call GPU) remains a future option for extreme throughput._

## v0.8.57 (2026-06-07) — small-model tool-calling reliability

The biggest real-world limiter for a local agent isn't the harness — it's whether a small model's tool calls actually land. Two fixes for the two most common failure modes, **measured on the eval suite**:

- **Recover malformed tool calls instead of dropping them.** Small models often emit *almost*-valid JSON (trailing commas, single quotes, Python `True`/`None`, unquoted keys, smart quotes). Previously `json_decode` failed and the call vanished — which reads as "the model described it but did nothing." A conservative `repairJson` now fixes those and re-parses; it only runs *after* a strict parse fails and only accepts a result that actually parses, so it can recover a broken call but never corrupt a valid one.
- **Nudge "described-but-didn't-call".** When a model writes a code block / says it'll make a change but issues no tool call, the agent loop now nudges it once to actually call write/edit, then lets it retry — instead of ending the turn with nothing written.

**Measured impact** (`ollamadev eval --model qwen2.5-coder:7b`): pass rate went from a **45%** baseline to **55–65%** across runs (the suite is non-deterministic on a small model, so the magnitude varies; both post-change runs beat the baseline). The repair/nudge mechanisms themselves are covered by deterministic unit tests.

## v0.8.56 (2026-06-07) — full-screen TUI support (vim/htop) in the terminal

### Added
- **Real alt-screen terminal emulator** (desktop + web). Full-screen TUIs — **vim, htop, less, top, lazygit** — now render correctly. When a program enters the alternate screen, the terminal switches from the line renderer to a **cell grid** with an absolute cursor, scroll region, insert/delete line & char, erase, and full styling; on exit it restores your scrollback. Pure vanilla JS (a hand-written `TermGrid`), no xterm.js.
- **PTY auto-sizing.** The terminal measures its character cell, computes cols×rows, and tells the PTY (`termResize` → the daemon sets the pts window size, delivering SIGWINCH) — so a TUI's layout matches the pane and **reflows on window resize**. The backend resize path already existed; this wires the frontend to it.

_The grid emulator is unit-tested headlessly (cursor positioning, erase, scroll, color, resize) via node; final visual polish is best spot-checked in a real terminal. 100% vanilla php/js/css/html._

## v0.8.55 (2026-06-07) — desktop & web terminal polish

### Added
- **Full terminal color & attributes** (desktop + web). The terminal renderer went from foreground-color + bold only to the **full SGR set**: background colors, 256-color, 24-bit truecolor, and italic / underline / dim / reverse. Build tools, test runners, and colorized agent output now render the way they do in a real terminal.
- **Low-latency terminal streaming in web mode** via Server-Sent Events. The browser stops round-tripping `termRead` every 80ms; output is pushed as it arrives. It's **safe by design** — the server only streams when it has spare workers (the `serve` script now sets `PHP_CLI_SERVER_WORKERS=4`), and if it can't, the endpoint returns 503 and the client transparently falls back to the old polling. Native `EventSource`, no library.

_Still 100% vanilla — native `EventSource`, hand-written SGR parsing, PHP's built-in server; no xterm.js, no dependencies._

_Not included (deliberately): full alt-screen TUI emulation (vim/htop) needs a grid model + pty-resize wiring and real browser validation — scoped as a follow-up rather than shipped blind._

## v0.8.54 (2026-06-07) — fixes from the multi-agent cloud review

A `/code-review ultra` pass (7 finder angles) over v0.8.43→v0.8.53 found real issues — including three regressions the previous "hardening" commit (v0.8.53) had introduced. All fixed and regression-tested (555 tests):

### Fixed (security)
- **RCE from a cloned repo's config.** `Config::load()` reads a project-local `.ollamadev.json`, and the `statusline` command-form + hooks ran via `shell_exec` — so a checked-out repo could execute code just by being opened. Executable config (statusline, hooks) now reads **only** from trusted home/global config (`Config::trustedGet`).
- **MCP server was wide open.** `mcp serve` set `auto` + auto-allow, letting any connected client run `bash`/`write`/`rm`. It now defaults to **read-only**; mutations require `mcp serve --allow-writes` (or `mcp.allowWrites: true`). Tool failures are now reported as `isError: true`.
- **Plan mode now propagates into delegated subagents** (a custom agent with `permission: auto` can no longer mutate while you're still planning).

### Fixed (correctness — incl. v0.8.53 regressions)
- **Tool allowlist** no longer blocks the read-only/`exit_plan_mode` tools an agent needs — it confines only *mutating* tools, so a `tools: edit` agent can still inspect files and leave plan mode. *(v0.8.53 regression.)*
- **Steering watermark** no longer reset per coder attempt — the reset re-applied a stale `model <name>` swap and clobbered auto-escalation. *(v0.8.53 regression.)*
- **Escalation ladder** selects the next rung alias-aware, so a ladder written with base names (`qwen2.5-coder`) actually finds the bigger installed tag. *(v0.8.53 regression.)*
- **Approving a plan** (`exit_plan_mode`) now re-syncs the system prompt, so the model stops being told it's still in plan mode and will actually implement.
- **`paramSize`** infers `:latest`/untagged model sizes from the preset catalog, so escalation works on the common `:latest` pulls.
- **`/plan off`** restores the mode that preceded plan (auto/ask/readonly) instead of hardcoding `ask`.

## v0.8.53 (2026-06-07) — security & correctness hardening

An adversarial review of the permission/hooks/steering/escalation code surfaced several real issues, now fixed and regression-tested:

### Fixed (security)
- **Permission-gate bypass via the `agent` tool.** The legacy `agent` tool invoked tool closures directly, skipping `Tools::run` — and therefore the permission gate, hooks, plan mode, and the tool allowlist entirely. It now routes every nested call through `Tools::run`.
- **Plan mode could be self-exited.** In non-interactive runs (crew/subagent/one-shot) `exit_plan_mode` unconditionally left plan mode, so a model could escape it without approval; a bare Enter/EOF also counted as approval. Now leaving plan mode requires an **explicit interactive “yes”** — non-interactive runs stay read-only.
- **Hooks failed open.** A PreToolUse hook that couldn’t start (proc_open failure) returned exit 0 (= allow), and an **invalid matcher regex** silently skipped the hook. Both now **fail closed** (block / apply), and a reentrancy depth-guard prevents a hook that re-invokes the CLI from recursing.

### Fixed (correctness)
- **Live model swap** now only targets a **confirmed-installed** model, so a swap while Ollama is unreachable can’t point a coder at a dead model and kill it mid-run.
- **Steering watermark** is cleared per coder attempt, so a retry/fix-back coder no longer ignores steering (including a rescue model-swap) the Director sent during the earlier attempt.
- **Crew auto-escalation** now climbs across retries (tier 1 → 2 → 3) instead of jumping to the same next-up model every time.
- **MoE model sizing** — `8x7b`/`8x22b` tags are now sized as experts × per-expert (≈56/176B), not 7/22B, so escalation ranks them correctly.
- **Resume overrides** are key-whitelisted so a stray flag can’t pollute the persisted run; the **escalation ladder** matches by alias/base-name, so a ladder written with `qwen2.5-coder` still matches a resolved `qwen2.5-coder:7b`.

## v0.8.52 (2026-06-07)

### Added
- **Auto-escalate on failure.** When a crew coder stalls (produces no changes on a retry), it now **automatically hands off to the next-bigger installed model** instead of just re-nudging the same one — so a weak coder that can't finish a task is replaced by a stronger one mid-run. On by default; disable with `crew --no-escalate` or `crew.escalate: false`. Backed by `Models::escalate()`, which climbs a configured ladder (`models.escalation`) or infers the next size up from the tag (`:7b` → `:14b` → `:32b`).
- **Behavioral eval gains escalation + a CI threshold.** `ollamadev eval --escalate` retries a failed task on bigger models until one passes (reporting "via <model>"), and `eval --min <pct>` sets the exit code to gate on a pass-rate floor. The scheduled model-backed CI eval now gates via `--min 25` (cleaner than the old shell parse) — catching catastrophic regressions (e.g. tool-calling broken) while tolerating a small model's normal task variance.

_Verified live: `llama3.2:latest` failed the fizzbuzz eval, auto-escalated to `qwen2.5-coder:14b`, and passed._

## v0.8.51 (2026-06-07)

### Added
- **Resume an interrupted crew on different models.** `crew resume [runId] --coder-model <m>` (also `--director-model`, `--auditor-model`, `--researcher-model`, or `-m` for the base) continues the *same plan and progress* on the model(s) you pick — flag overrides win over the models the run started with, and the choice persists so a second resume keeps it. The interactive "resume this run?" offer honors any model flags you launched `crew` with too. This completes model-switching across the board: mid-chat, on session resume, live mid-crew (via the Director), and now on crew resume.

## v0.8.50 (2026-06-07)

### Added
- **Swap a coder's model live, through the Director.** Send `2: model llama3.3:70b` from the Director console (`crew director`), the desktop Director box, or `crew steer 2 "model <name>"`, and that coder **hot-swaps its model mid-run** — same worktree, same subtask, same message history; the next iteration just runs on the new model. `all: model <name>` switches the whole crew. If the model isn't installed, the coder is told and stays on its current one. (So when a 7B coder stalls, bump it to a 70B without losing its progress.)

## v0.8.49 (2026-06-07)

### Added
- **Desktop Hooks panel.** A new **🪝 Manage hooks…** button opens a panel (desktop + web) to view, add, and remove shell hooks without touching JSON — pick an event, type a command, optionally add a tool-name matcher. Backed by a new `ollamadev hooks list --json` surface and the `hooksList/hooksAdd/hooksRemove` bindings (wired into the web bridge too, with a parity test). Same `~/.ollamadev/config.json` the CLI's `/hooks` editor uses, so hooks added anywhere apply everywhere.

## v0.8.48 (2026-06-07)

### Changed
- **A custom agent's `tools:` list is now a HARD gate.** Previously it was advertised to the model as a soft constraint; now `Permission` enforces it — when a subagent runs with a `tools:` allowlist, any tool outside the list is blocked at the gate (and the parent's confinement is restored when it returns). The air-gap and plan-mode gates still compose on top.

### Added
- **Hooks editor — `/hooks` (and `ollamadev hooks`).** View, add, and remove shell hooks without hand-editing JSON: `/hooks add PreToolUse "command" --match bash`, `/hooks list`, `/hooks remove PreToolUse 0`. Changes persist to `~/.ollamadev/config.json` (normalizing into the `{command, matcher}` list form), and an added PreToolUse hook takes effect immediately.

## v0.8.47 (2026-06-07)

### Added — Claude-Code-parity harness (six features)
- **Plan mode.** `ollamadev --plan` or `/plan` — the agent researches with read-only tools only (all mutations blocked at the permission layer), proposes a plan via the new `exit_plan_mode` tool, and only after you approve does editing unlock. `/permission` gains a `plan` mode.
- **Full hook coverage with blocking.** Hooks expanded from 2 events to the complete set: **PreToolUse** (a hook that exits non-zero **blocks** the tool; its output is the reason returned to the model), **PostToolUse**, **UserPromptSubmit**, **SessionStart**, **Stop**, **PreCompact**, **SubagentStop**, **Notification** — plus the original `beforePrompt`/`afterEdit`. Each event accepts a bare command or a list of `{command, matcher}` entries (matcher = regex on the tool name). Configured in `~/.ollamadev/config.json` under `hooks`.
- **Output styles.** `default · concise · explanatory · formal · bullets` — adjust the agent's tone/verbosity. `/output-style <name>` or `config set outputStyle <name>`; appended to the system prompt.
- **Configurable status line.** `/statusline` — a template with `{model} {cwd} {branch} {mode}` tokens, or a shell command whose output is shown above the prompt.
- **File-defined custom agent types.** Drop `.ollamadev/agents/<name>.md` (frontmatter: `name, description, model, permission, tools`; body = its system prompt) and delegate to it: `task(prompt, agent_type="name")`. New `ollamadev agents` command (and `/agents`) lists them.
- **MCP server mode.** `ollamadev mcp serve` exposes this CLI's tool registry to any MCP client over stdio (JSON-RPC 2.0: initialize / tools/list / tools/call / ping) — OllamaDev was already an MCP *client*; now it's a server too.

All six are 100% vanilla PHP, zero new dependencies, and covered by 23 new smoke tests (517 total).

## v0.8.46 (2026-06-07)

### Changed
- **The Director, Researcher, and Auditor are now skill-aware too** — previously only the coders knew about the team-skills. Each of the three now receives the loaded skills (name + description) in its prompt: the **Director** names the relevant skill in each subtask it hands out (and tells the coder to load it first), the **Researcher** flags which skill applies to each area it surveys, and the **Auditor** holds every diff to the standards of any applicable skill (e.g. a payments change must respect `payments-money`). Shared via one `CrewSkills`-fed brief, so skill-awareness stays consistent across all roles and `crew resume`.

## v0.8.45 (2026-06-07)

### Added
- **Crew templates now carry their own skills.** Picking a template forces the matching built-in team-skill into the run regardless of focus: **Tests / Feature / Bug fix → `testing-discipline`**, **Refactor → `refactor-safety`**, **Docs → `docs-writing`**, **Audit → `security-hardening`**. New CLI flag `crew --skill <name>` (repeatable, or `--skills a,b,c`) does the same from the terminal; domain teams still add more by focus on top.
- **The 31 built-in team-skills are now browsable in the Skills manager** (desktop + web), not just materialized into coder worktrees during a crew run. Each shows a **built-in** badge; "customize" loads it into the editor and **Save creates your own copy that overrides it**. `skills list --json` and `skills show <name> --json` now include the built-ins (with a `builtin` flag), and `skills show` can display any starter's full body.

### Changed
- Crew skill selection moved from focus-only matching to `CrewSkills::resolve(focus, forced)` — forced-by-name skills are always kept (never dropped by the 5-skill cap), then focus matches fill the rest, deduped. Forced skills persist across `crew resume`.

### Changed
- **Free is now the default terminal layout.** New users (and anyone who hasn't picked a mode) open in Free — draggable, resizable, overlapping windows. Tiled is still one click away via the **⊞ Tiled / ⮻ Free** toggle, and an explicit Tiled choice is remembered as before.

## v0.8.43 (2026-06-07)

### Fixed
- **Help text had drifted from the code.** `help` advertised "66 tools" while 95 were registered; the `help tools` page omitted ~30 tools (including `clear_board`); and `ollamadev crew` didn't mention the `steer` / `director` / `clear` subcommands. The tools page is now generated from the live registry — accurate count, with an "Other" catch-all so a new tool can never be silently undocumented — and the crew usage block lists every subcommand. A new guard test fails the build if either ever drifts again.

## v0.8.42 (2026-06-06)

### Fixed
- **Web mode was missing the Director box and Skills manager.** The web bridge wraps desktop bindings from a name list that hadn't been updated for `crewSteer` + `skillsList/Get/Save/Remove`, so those panels were inert in a browser (CLI/desktop were fine). Added them — web is back at full parity — plus a test that checks every `Bindings::PUBLIC` entry is exposed by the bridge so it can't drift again.

### Changed
- **Free/Tiled layout now reopens in whichever mode you last used** (remembered globally), so if you work in Free mode it comes back in Free.

## v0.8.41 (2026-06-06)

### Added
- **Free-floating window layout (desktop).** A new **⊞ Tiled / ⮻ Free** toggle in the top bar. In Free mode, terminals become draggable, resizable windows that can overlap — drag a header to move, drag the bottom-right corner to resize, click to bring to front. Positions and sizes are saved per workspace; Tiled (the auto-grid) is still there.
- **Director answer mode.** In the interactive crew, type `ask` (or a one-off `?<question>`) and the Director just *answers* — a bounded read-only run that may read the codebase but never writes or starts a task. Type `task` to go back to building.

### Fixed
- **Crew and Director terminals didn't come back after closing the app.** Restore was respawning them as an invalid `-m Director` / a plain agent. Restore is now terminal-kind-aware: the Director terminal returns running `crew director`, and the crew terminal returns as the interactive `crew` prompt. (Per-terminal kind and working folder are now persisted too.)

## v0.8.40 (2026-06-06)

### Changed
- **Plain shell is now the top entry of the model dropdown** ("🐚 shell — plain terminal") instead of a separate button. Pick it and hit **+ Terminal** for a bare shell (no ollamadev) in the project folder; pick any model for the agent as usual. Agent-only paths (run-task, crew) ignore the shell entry.

## v0.8.39 (2026-06-06)

### Added
- **Desktop Skills manager.** A 🧩 Manage skills panel (in the Crew modal) to list, view, create, edit, and remove skills from the GUI — backed by the CLI engine, so it's the same `~/.ollamadev/skills/` catalog the terminal, web, crews (`--focus`), and the agent's `skill` tool all use. New CLI surfaces back it: `skills list --json`, `skills show <name> --json`, `skills save <name>`.
- **Plain shell terminal.** An always-available **+ Shell** button opens a bare shell (your real `bash`, no ollamadev launched) in the project folder, alongside the default **+ Terminal** (the agent). Each terminal's kind and working folder now persist across workspace switches and restarts.

## v0.8.38 (2026-06-06)

### Added
- **The Director in its own terminal.** `ollamadev crew director` opens a live steering console — any terminal running it becomes the Director. It auto-refreshes your crew's board as coders change state (reading the same `current.json` as the desktop kanban), and lets you redirect one coder (`2: focus on tests`) or the **whole crew** (`all: stop using deprecated APIs`). On the desktop, launching a crew auto-opens a dedicated **Director** terminal tab.
- **Talk to the whole crew at once.** Broadcast steering via `all:` (target 0) reaches every coder, not just one — available in the Director console, `crew steer all "…"`, and the desktop Director box.
- **Per-terminal working folders (desktop).** Each terminal can now run in its own directory. A folder chip in the terminal header edits the path inline and respawns that terminal there (`~` expansion supported), so you can have one terminal in each of several projects side by side.

## v0.8.37 (2026-06-06)

### Added
- **Live mid-run crew steering via a separate Director.** Redirect a coder *while it's working*, without typing into the coder's output stream: run `ollamadev crew steer <coder#> "instruction"` from another pane, or use the new **Director box** on the desktop board view. The message is routed to that coder through the run's `steer.jsonl` and applied on its next step (queued if the coder hasn't started yet). Pure vanilla PHP/JS — file-based, so it works for sequential *and* forked/parallel runs.

## v0.8.36 (2026-06-06)

### Fixed
- **Typing "clear board" to the interactive Director spun up a whole crew run instead of clearing the board** (Researcher → Director → Coder), and the coder couldn't actually clear it — local models don't reliably set the `clear_board` tool's `confirm` flag, so it was refused and the model reported a bogus "permissions" error. The interactive `Director ▸` prompt now recognizes "clear/reset/wipe/empty (the) board" and clears it directly — no crew run, no model in the loop. Typing it yourself is the explicit signal, so it's both reliable and can't fire unprompted. (`crew clear` and the `clear_board` agent tool remain.)

## v0.8.35 (2026-06-06)

### Changed
- **Desktop form controls consolidated into shared `.field` / `.select` component classes.** Inputs and dropdowns were styled individually by id, so a new control that missed its rule rendered at the wrong width (the clipped-box bug). Standalone modal/panel fields now share uniform component classes — consistent padding, borders, and focus rings, and correct by default so the bug can't recur. No behavior change; flex-row inputs (terminal, agent chat, browser bar) and layout-embedded selects (topbar, crew roster) keep their own styles.

## v0.8.34 (2026-06-06)

### Fixed
- **Several desktop inputs rendered too narrow and clipped their text.** The stylesheet sizes inputs individually by id, and a few were never given a width rule — so they fell back to the browser's ~150px default and truncated their placeholder/value: the Code-search box ("Find code by meaning…"), the crew **Ollama hosts** box, and the role-add modal (name / persona / description / pinned model). They're now full-width.

## v0.8.33 (2026-06-06)

### Fixed
- **Desktop app crashed with `Call to undefined function posix_kill()`** (and the crew/terminal could fail on `posix_isatty`). The AppImage's `AppRun` runs `php -n` (no php.ini), which skips the conf.d files that load Debian/Ubuntu's *shared* `posix.so` — so posix functions were undefined at runtime. The build now bundles `posix`/`pcntl` alongside `ffi`/`curl` and AppRun loads every bundled extension (globbing `usr/lib/php/*.so`, so the list can't drift). `PtyManager` also guards `posix_kill` with a `kill -TERM` fallback. The release smoke-gate now replicates AppRun and fails the build if `posix_kill`/`posix_isatty` don't resolve.

### Added
- **"Clear the board"** — dismiss the crew kanban (crew cards, ideas, and your manual cards). Available as `ollamadev crew clear`, as an agent tool (`clear_board`) the Director/coders can invoke when you ask, and reflected live in the desktop. It's explicit-only (the tool requires `confirm=true` and is told never to clear on its own initiative) and refused while a crew run is active.

## v0.8.32 (2026-06-06)

### Fixed
- **Linux AppImage failed to launch with an nghttp2 symbol error** (`libcurl-gnutls.so.4: undefined symbol: nghttp2_option_set_no_rfc9113_leading_and_trailing_ws_validation`). The AppImage bundled its build-runner's `libnghttp2` and forced it ahead of the host's via `LD_LIBRARY_PATH`. But Boson's WebView loads the **host's** `libcurl-gnutls`, which needs a recent nghttp2 symbol — and after the v0.8.30 glibc fix pinned builds to Ubuntu 22.04, the bundled nghttp2 (1.43) was too old to provide it, *shadowing* the host's matched copy. The build no longer bundles `libnghttp2`, so libboson uses the host's curl+nghttp2 pair (always matched). Keeps the glibc-2.35 / Linux Mint compatibility from v0.8.30.

## v0.8.31 (2026-06-06)

### Fixed
- **Changing a terminal's model from the desktop had no effect.** Bare `ollamadev` auto-resumes the folder's last session, and the resumed session's *saved* model overrode the `-m` flag the desktop passes — so a terminal launched with `-m qwen2.5-coder:14b` would actually run whatever the old session was saved on (e.g. qwen3-coder), and switching the model in the desktop did nothing. An explicit `-m` now overrides the resumed session's model (new `Session::useModel`).

## v0.8.30 (2026-06-06)

### Fixed
- **Desktop terminals now use your configured model.** The topbar model selector built its list with no selection, so it defaulted to whatever Ollama listed *first* — meaning new terminals launched with the wrong model regardless of your `ollama.defaultModel`. `models --json` now reports the configured default and the topbar selects it, so terminals start on your chosen model (e.g. qwen3-coder).

### Removed
- Reverted the per-terminal model dropdown (v0.8.29) — the real fix is the topbar honoring your default, above.

## v0.8.29 (2026-06-06)

### Added
- **Per-terminal model switcher (desktop/web).** Each terminal header now has a model dropdown — change it and it switches that terminal's **live** CLI session via the `/model` command (the topbar model only ever set the model at creation, with no way to change it after). Each terminal can run a different model, switchable on the fly; the choice persists across save/resume.

## v0.8.28 (2026-06-06)

### Fixed
- **Crew terminal relaunched as `-m crew` after resume.** The desktop created the crew terminal with the literal string `'crew'` as its *model* (it was meant as a label). The live run was fine, but when the workspace was saved and reopened, the terminal was recreated with `ollamadev -m crew` → "Model 'crew' is not installed." Crew terminals now use the real model (with a separate `kind: 'crew'` label), so resume relaunches correctly.

## v0.8.27 (2026-06-06)

### Added
- **GPU↔RAM offload + CPU thread controls.** New `ollama.gpuLayers` (→ Ollama's `num_gpu`: how many model layers stay on the GPU; the rest spill to system RAM) and `ollama.numThreads` (→ `num_thread`: cap CPU threads). Unset = Ollama decides (best when the model fits in VRAM); `gpuLayers: 0` runs fully on CPU/RAM. Lets you trade VRAM for RAM or leave cores free. Note: for *cooler/quieter* runs, a lighter model (e.g. the `qwen3-coder` MoE — only ~3B active params) reduces compute and heat far more effectively than RAM offload, which just shifts the load to the CPU.

## v0.8.26 (2026-06-05)

### Added
- **Built-in Browser tab** in the desktop/web ADE — a localhost **preview pane** next to Workspace/Board/Graph, for viewing the dev server you're building right beside your terminals. Address bar (accepts a bare port like `3000`, `localhost:3000`, or a full URL), back/forward/reload, quick-port chips (`:3000 :5173 :8080 :8000 :4321 :41434`), and an **↗ open-in-real-browser** button. Local URLs load **directly** at full fidelity (SPAs/APIs/websockets all work); air-gap mode (✈️) blocks external loads, matching the agent's network toggle.
- **Local strip-proxy for external sites.** A plain `<iframe>` can't load sites that send `X-Frame-Options: DENY` / CSP `frame-ancestors` (Google, GitHub, most docs) — the browser refuses and shows blank. New `proxyFetch` binding fetches the page server-side with `curl`, strips the frame-blocking `<meta>`, injects a `<base>` so the site's CSS/images/links resolve against the real origin, and renders it via `iframe.srcdoc` (inline content isn't subject to X-Frame-Options). In-page link clicks bubble back up and re-proxy so navigation stays in the pane; sites that genuinely can't embed get a clear ↗ "open in real browser" fallback. Works in **both** desktop and web (rides the same binding bridge — the desktop has no HTTP server, so a classic `/proxy?url=` route wouldn't work). Best-effort: a page's own cross-origin XHR/API calls fail from the frame, so logged-in dashboards and heavy SPAs (Gmail, GitHub app pages) may break — those get the fallback. New `openExternal` binding (xdg-open/open/start) backs the ↗ button on desktop where an in-app `window.open` isn't a real browser.

### Fixed
- **Version drift across the desktop app.** `boson.config.php` was stuck at `4.1.8` and `src/Config.php` at `3.9.2` while the CLI was `4.8.25`. All three `OLLAMADEV_VERSION` markers now track the real version.

## v0.8.25 (2026-06-04)

### Fixed
- **Switching projects "only worked once."** The save of the workspace you're leaving was *gating* the whole switch — and only switches after the first one trigger that save, so if the save binding stalled over the Boson bridge the switch chain never completed. The switch no longer waits on it: the window snapshot is still captured synchronously, and the persist happens in the background.
- **The desktop app didn't resume on reopen.** Workspace window state (terminals, editor tabs, layout) was only saved when you *switched away* from a project, so working in one project and just closing the app lost the session. Added an autosave that persists the active workspace whenever its state changes (debounced; writes only on change) plus a final flush on `beforeunload`/`pagehide`/hide — with a guard so the brief empty window mid-switch can't overwrite a project's saved state. The active project now reopens with its terminals restored.

## v0.8.24 (2026-06-04)

### Changed
- **Auto-compaction now also triggers on real context fill, not just message count.** Previously the session auto-summarized older messages only once it hit a fixed message-count threshold (30). It now *also* compacts when the conversation reaches ≥75% of the model's `num_ctx`, using **Ollama's actual prompt-token count** when available (else the char/4 estimate) — so a few very long messages get compacted before they overflow the window instead of slipping through the count check. Tunable with `agents.compactContextPct` (0 disables the token trigger); the compaction notice now shows the context % when that's what fired it. Message-count threshold and the smart tool-output preservation are unchanged.

## v0.8.23 (2026-06-04)

### Fixed
- **Switching projects in the desktop/web "Projects" list did nothing.** Two robustness fixes for the click→switch path: (1) the workspace tabs now use one **delegated** click listener on the strip container (attached once) instead of per-element handlers rebuilt on every render — so a click can never be dropped; (2) `openFolder` no longer aborts when the `setRoot` binding resolves to an empty/undefined value (which can happen over the Boson FFI bridge even on success) — it only bails on an explicit error and otherwise falls back to the requested path, so a switch never silently no-ops. The shared backend (add/list/setRoot) was already correct.

## v0.8.22 (2026-06-04)

### Fixed
- **Desktop/web terminal rendered typed text with a space between every character** (`hilo` showed as `h i l o`). A CSS class collision: the touch-input row and the terminal's output lines both use `.term-line`, and a bare `.term-line { display: flex; gap: 6px }` (intended only for the input row) turned every character `<span>` in the output into a gapped flex child. Scoped that rule to `.term-touch .term-line` so output lines are unaffected. Pairs with the v0.8.21 backspace fix — together the desktop terminal now types and erases cleanly.

## v0.8.21 (2026-06-04)

### Fixed
- **Backspace + stray glyphs in the desktop/web terminal.** The pty echoes a backspace erase as `\b \b` (backspace, space, backspace); the terminal renderer handled the first `\b` but its text scanner didn't stop on the trailing one, so that byte was drawn as a literal control character — which both broke the erase (line left dirty) and rendered as a tofu/□ box (read as a "font" problem). The scanner now stops on `\x08`/`\x7f`, and any leftover C0 control bytes (bell, NUL, …) are stripped before rendering so nothing shows up as tofu. Desktop/web only — the regular CLI (its own line editor) was unaffected.

## v0.8.20 (2026-06-04)

### Changed
- **Uniform topbar controls + a more mobile-friendly UI (additive only).** Pinned every topbar dropdown/button to one fixed height so the voice-model select no longer renders taller than the rest (the 🎙 emoji was growing its box). On phones/tablets: finger-sized 40px tap targets, the four selects share rows evenly instead of one hogging width, text inputs are ≥16px so iOS doesn't auto-zoom on focus, and touch niceties (no tap-flash, no 300ms tap delay, momentum scrolling). Palette, themes, layout, and controls unchanged.

## v0.8.19 (2026-06-04)

### Changed
- **Tidier desktop/web topbar (additive layout only).** Grouped all four dropdowns together (model · temperature · voice · theme) and moved the 🎙 History button into the action-button cluster, so the bar reads cleanly left-to-right (selects, then buttons) instead of a button wedged between dropdowns. The voice-model options are now parallel like the temperature ones (`tiny · fastest` … `large-v3 · best`). Palette, themes, and every control are unchanged.

## v0.8.18 (2026-06-04)

### Added
- **`/voice` now works with no separate install — the Whisper engine is baked in.** Speech-to-text previously needed you to install `whisper` yourself. Now OllamaDev provides a self-contained **whisper.cpp** engine (a tiny ~3 MB statically-linked CPU binary) two ways:
  - **Desktop (AppImage + Windows installer):** the engine **and** a model (`base` by default) are **bundled in**, so `/voice` and the mic button work **instantly and fully offline** out of the box (this grows those downloads by ~150 MB; the `.tar.gz`/`.zip` archives stay lean and auto-fetch instead).
  - **Standalone CLI:** the first time you use `/voice` with no engine, OllamaDev offers a **one-time auto-download** (whisper.cpp binary from the release + the ggml model from Hugging Face, into `~/.ollamadev/stt/`) — then it's fully local and offline forever. Air-gap mode blocks the download (bring your own engine into `~/.ollamadev/stt`). Non-interactive: `ollamadev voice install [<size>]`.
  - Engine resolution order: a **bundled** dir (`OLLAMADEV_STT_DIR`, set by the desktop launchers) → the **provisioned** `~/.ollamadev/stt` → any Whisper on `PATH`. The bundled/provisioned whisper.cpp is now preferred (faster on CPU than openai-whisper). Models still pick via `/voice model <tiny|base|small|medium|large-v3|turbo>`.
  - The whisper.cpp binaries are built per-platform in CI (`scripts/build-whisper.sh`, pinned to whisper.cpp v1.7.5) and attached to each release as `whisper-<os>-<arch>` — they are **not vendored in source**; the source stays vanilla PHP, and the engine is fetched at runtime exactly like an Ollama model pull. 100% local once present.

## v0.8.17 (2026-06-04)

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

## v0.8.16 (2026-06-04)

### Added
- **Windows desktop installer (`OllamaDev-ADE-Setup.exe`).** A self-contained Inno Setup installer that bundles a PHP 8.4 runtime (ffi + curl + the VC++ runtime) + the Boson Windows DLL + the app + the agent CLI — so it installs and runs with **no PHP install**, the Windows equivalent of the Linux AppImage. Start-menu + optional desktop shortcuts; a windowless `.vbs` GUI launcher (uses `php-win.exe`) plus a terminal `.bat`. Built on a `windows-latest` runner (`scripts/windows/build-installer.ps1` + `ollamadev-ade.iss`); the `.zip` archive (system PHP) stays as a fallback. **Note:** unsigned, so first-run SmartScreen shows a warning until dismissed; needs verification on a real Windows desktop (CI can build it but can't launch the GUI). No native macOS app is planned — macOS stays on the `.tar.gz` archive.

## v0.8.15 (2026-06-04)

### Added
- **arm64 Linux AppImage** (`OllamaDev-ADE-aarch64.AppImage`), alongside the x86_64 one. `scripts/build-appimage.sh` is now arch-aware (bundles the arch's PHP + the matching Boson lib, and slims to just that lib). Because an AppImage must bundle arch-specific binaries, the arm64 image is built on a native `ubuntu-24.04-arm` runner in the release workflow. Both are self-contained (no PHP install); the `.tar.gz`/`.zip` archives and all-platform CLI binaries remain.

## v0.8.14 (2026-06-04)

### Added
- **Self-contained Linux AppImage for the desktop app.** A new release asset, `OllamaDev-ADE-x86_64.AppImage`, **bundles a PHP 8.4 runtime (ffi + curl) + the Boson libs + the agent CLI** — so it runs by `chmod +x` and double-click with *no PHP install*. This is why a `.deb`/`.rpm`/`.exe`/AppImage didn't exist before: Boson is a runtime (not a compiler) and the app needs PHP 8.4, so a self-contained build has to bundle PHP — which the AppImage now does (`scripts/build-appimage.sh`). The existing **`.tar.gz`/`.zip` archives for Linux, macOS, and Windows stay** (they use your system PHP), and the **CLI standalone binaries remain for every platform**. Verified locally: the bundled PHP loads ffi+curl with no host ini and runs the app.

## v0.8.13 (2026-06-03)

### Changed
- **Default sampling temperature lowered 0.6 → 0.3.** Measured on the 20-task eval, a lower temperature sharply improves tool-calling reliability (the model commits to actions instead of rambling/describing): `qwen2.5-coder:14b` went **45% → 70%** at temp 0.2, and 7B improved too. 0.3 is a balanced agentic default; it's overridable per the dropdown below or `ollamadev config set ollama.temperature <v>`.
- **Temperature dropdown in the desktop/web header.** A new select (next to Model/Theme, matching the existing UI) — Exact 0.0 · Precise 0.2 · Balanced 0.3 · Standard 0.5 · Creative 0.7 · Wild 1.0 — reads/writes `ollama.temperature` live via the `temperature`/`setTemperature` bindings. Verified headlessly (loads the stored value, persists on change, zero JS errors). No other UI changed.

## v0.8.12 (2026-06-03)

### Fixed / Added — tool reliability
- **Strip stray markdown fences from written files.** Local models habitually wrap a whole file's content in a ```` ```lang … ``` ```` block; written verbatim the file started with ```` ``` ```` instead of `<?php` and wouldn't run (the eval's `fix-loop` had *correct* logic but the file echoed its source). `write` and `notebook_edit` now unwrap a single fully-enclosing fence — and **only** that case, so a real multi-block markdown file is left intact (verified by tests).
- **Prompt nudge to act via tools.** The structured/text protocols now explicitly tell the model to create/change files by calling `write`/`edit` with the contents as the argument — not by pasting code into its message or wrapping it in fences.
- **Measured:** `qwen2.5-coder:7b` on the 20-task eval went 7/20 → 8/20, and the fence class of failure (file written but not executable) is gone. The remaining misses are model behavior (not calling `write`, or wrong logic), not file handling. For reference, `qwen2.5-coder:14b` scores 9/20 (45%) on the same suite.

## v0.8.11 (2026-06-03)

### Fixed
- **Director no longer emits duplicate/overlapping subtasks.** When it over-decomposed a task (the same work under two titles, or a near-identical prompt), a second coder would spin up a worktree only to find nothing to do (`#2 skipped (empty)`). The plan now de-duplicates by normalized title and prompt before coders run — fewer wasted coders, cleaner boards. Applies to single and self-consistency (`--amplify`) planning.

## v0.8.10 (2026-06-03)

### Added
- **Two new team skills: `pwa` and `observability`** (library now 29 → 31). They fill real gaps — the PWA project-type template had no matching skill, and microservice/serverless/devops focuses lacked production-observability guidance. Verified live: a `pwa`-focused crew now seeds `pwa` + `responsive-design`, and a `microservice … with logging` focus seeds `observability` + `rest-api-design` + `auth-security`. (Team skills auto-match a template's *focus* and are materialized into each coder's worktree.)

## v0.8.9 (2026-06-03)

### Fixed
- **`find` with wildcards never matched.** The glob→regex translation ran on the already-escaped pattern (`preg_quote` had turned `*` into `\*`), producing a regex like `/^\.*\.php$/` — so `find name="*.php"` always returned "No matches" (only exact filenames worked). Now translates the escaped wildcards correctly.

### Added
- **Expanded tool-layer tests (`tests/tools.sh`): 55 → 60 checks, now behavioral.** They assert actual side effects (file written/edited/moved/deleted, commit created, branch switched, stash cleaned, notebook cell changed, todo persisted), **multi_edit atomicity** (a missing `old_string` applies *none* of the edits), and **error paths** (edit reports a missing string; reading a missing file doesn't crash) — not just "didn't crash." Caught the `find` bug above. Runs in CI on every push.

## v0.8.8 (2026-06-03)

### Added
- **Eval suite expanded from 6 to 20 app-shaped tasks** — turning the harness from a smoke signal into a real benchmark. Covers algorithms (fizzbuzz, factorial, palindrome, dedup, word-count, celsius), seeded bug-fixes (off-by-one loop, syntax error, wrong operator), multi-file work (a `lib/` module + entry point), parsing/data (env parser, LIFO stack class), and files/config (.gitignore, README). Every check is deterministic and verified to pass with a correct solution (so failures reflect the model, not the harness). The CI eval gate is now rate-based (fails under 25%, catching catastrophic regressions while tolerating model variance). Baseline: `qwen2.5-coder:7b` ≈ 7/20 (35%) in structured mode — an honest, discriminating number that a stronger model will climb.

## v0.8.7 (2026-06-03)

### Added
- **Default multi-model crew via config.** Each crew role's model now falls back to a configured default when no `--*-model` flag is given: `crew.directorModel`, `crew.researcherModel`, `crew.coderModel`, `crew.auditorModel` (resolution: CLI flag → config → base model). Set them once in `~/.ollamadev/config.json` (e.g. `ollamadev config set crew.directorModel codestral`) and a plain `ollamadev crew "task"` runs a multi-model team — a strong director, a fast coder, a separate auditor — with no flags. Applies to both fresh runs and resume.

## v0.8.6 (2026-06-03)

### Changed
- **`tools.mode=auto` (the default) now prefers schema-constrained decoding.** Auto resolves to `structured` whenever the backend supports constrained output (Ollama/LM Studio both do), falling back to the text protocol otherwise. Native function-calling is now **opt-in only** (`tools.mode=native`) — it was the fragile, version-dependent path. So every install gets the most reliable tool-calling out of the box, no config needed. Measured: default `auto` scores 5/6 with `qwen2.5-coder:7b`.
- **Structured mode is now self-consistent end-to-end.** The model is always prompted for the `{message, tool_calls}` envelope, so even when a backend can't truly constrain output, the same reply is parsed correctly (no prompt/parser mismatch). Backend capability is cached per session, and the JSON scanner now accepts both `name` and `tool` keys.

## v0.8.5 (2026-06-03)

### Added
- **`tools.mode=structured` — schema-constrained tool-calling.** Uses the backend's structured-output support (Ollama's `format` with a JSON schema; LM Studio's `json_schema` response format) to force every reply to match a `{message, tool_calls:[{tool, arguments}]}` schema — so the model **cannot** emit malformed tool JSON or hallucinate a tool name (tool names are an enum of the real registered tools). This is the closest local equivalent to a frontier API's constrained decoding, and the most reliable mode for capable models. Falls back to the text protocol automatically if a backend can't do constrained output. Works for **both the regular CLI and the Crew** (shared `chatTurn`). Measured: `qwen2.5-coder:7b` scored **5/6** with `fix-bug` *and* `new-function` passing. `tools.mode` now accepts `auto` (default) · `native` · `text` · `structured`.

## v0.8.4 (2026-06-03)

### Added
- **`ollamadev tool <name> '<json>'`** — directly invoke any registered tool, bypassing the model, for testing/debugging the tool layer (`ollamadev tool list` shows all 94). 
- **Tool-layer functional test (`tests/tools.sh`), wired into CI.** Exercises every offline tool (~55: files, shell, background jobs, git, code-intelligence, memory, todo, notebook, and the parity tools) in a real workspace and asserts each executes without crashing. Runs on every push/PR — so a broken tool is caught automatically, not just at runtime. (Tools needing a model/network/index are covered by the eval workflow instead.)

## v0.8.3 (2026-06-03)

### Added
- **Complete Claude Code tool parity.** Added the last missing pieces: tracked **background shells** — `bg` now returns a job id, `bash_output` reads new output by id, `kill_bash` stops it, and `wait_bg` can wait on a specific job (Claude Code's Bash-background + BashOutput + KillShell). Plus **`ask_user`** (AskUserQuestion) — the agent can ask you a question mid-task in interactive runs (it proceeds with a default in one-shot/crew runs). With v0.8.2's `multi_edit`/`todo_write`/`notebook_edit`, OllamaDev now matches Claude Code's full tool set, all running on your local Ollama/LM Studio. ExitPlanMode maps to the existing `--plan`/readonly permission mode (and the `permission` tool); Read's image support is via the existing vision/`@file` path.

## v0.8.2 (2026-06-03)

### Added
- **Full tool catalog in text mode.** Text-protocol tool-calling now advertises the *complete* tool set to the model — files, directories, code-intelligence (diagnostics/hover/symbols/find_refs/format), and the entire git suite — not just the 15 native-schema tools. The model can now reach ~50 tools by name with correct parameters (the executor always could; now discovery matches). Aliases and internal helpers (`print`/`echo`/`ok`/…) are intentionally excluded; `bash` covers the long tail.
- **Claude Code tool parity: `multi_edit`, `todo_write`, `notebook_edit`.** `multi_edit` applies several edits to one file atomically (all apply or none, single diff preview). `todo_write` maintains a structured session todo list so the agent can plan and track multi-step work. `notebook_edit` edits Jupyter `.ipynb` cells (replace/insert/delete). All other Claude Code tools (Bash, Read/Write/Edit, Glob, Grep, Task, WebFetch, WebSearch, background bash, skills) were already present.
- **Bigger eval suite.** Added `edit-two` (multi-value edit) and `make-app` (multi-file static page with a linked stylesheet) so `ollamadev eval` measures app-shaped work, not just single-file tasks. `qwen2.5-coder:7b` scores ~4–6/6 (text mode) depending on run.

## v0.8.1 (2026-06-03)

### Added
- **Text-protocol tool-calling (`tools.mode`).** You can now opt out of relying on Ollama's native function-calling — which varies by model and breaks across versions — and have OllamaDev own the protocol instead: the model is prompted to emit tool calls as JSON and our own balanced-JSON parser extracts them. `ollamadev config set tools.mode text` forces it; `auto` (default) tries native and falls back to text; `native` prefers native. In text mode the system prompt gains an explicit format spec + a compact tool catalog (from the same schemas), so any model knows what's callable without the native schema. **Measured:** on the built-in eval suite, `qwen2.5-coder:7b` scored **3/4 (75%) in text mode vs 2/4 (50%) native** — more portable *and* more reliable. `ODV_EVAL_VERBOSE=1` shows an eval run's agent output for debugging.

## v0.8.0 (2026-06-03)

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