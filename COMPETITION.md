# Competitive landscape & positioning

> Snapshot: mid-2026. Sourced from a deep-research pass (19 sources, 25 claims adversarially verified, 21 confirmed / 4 killed). This is a fast-moving space — re-check before relying on any single point.

## Bottom line

**No tool ships OllamaDev's exact combination.** The intersection is currently unoccupied:

> Ollama-only (local by default, optional Ollama cloud — no third-party cloud AI) **+** multi-agent Crew with git-worktree isolation **and a gated, AI-reviewed merge** **+** CLI / native desktop / web on **one zero-dependency vanilla-PHP engine**.

OllamaDev is differentiated by the **intersection**, not by any single feature — every individual piece exists somewhere else.

## Who overlaps, and how

| Tool | Local-only? | Multi-agent? | Worktree + review gate | Form factor |
|---|---|---|---|---|
| Composio Agent Orchestrator | ❌ wraps cloud CLIs | ✅ parallel | ✅ worktree + review-remediate (human/CI) | orchestrator |
| emdash (YC W26) | ❌ cloud-routed | ✅ ~27 agents | ✅ worktree, **manual** merge | native desktop |
| johannesjo/parallel-code | ❌ ("no Ollama/LM Studio") | ✅ | ✅ worktree | app |
| Kilo Code (Roo+Cline fork) | ❌ hybrid/OpenRouter | ✅ | ✅ worktree | VS Code + JetBrains + CLI |
| OpenAI Codex App (`ollama launch codex-app`) | runs on local via Ollama | single-user parallel threads | ✅ built-in worktrees | native desktop |
| Roo Code (*repo archived ~May 2026*) | ❌ hybrid | ✅ modes | ❌ context-isolation, sequential | VS Code ext |
| Tabby / Continue+Ollama / Void (*paused*) | ✅ local | ❌ single-agent | ❌ none | server / ext / IDE fork |

**The structural split:** every tool with worktree-isolated parallel agents orchestrates **cloud-backed** CLIs (none local-only); every **truly local** tool is **single-agent**. OllamaDev sits in the gap between those two groups.

## Genuinely differentiated

1. **Local-only AND multi-agent-with-gated-merge** — confirmed empty; nobody combines them.
2. **Tri-form-factor on one zero-dependency stack** (CLI + desktop + web, shared engine/data) — no comparable.
3. **AI Auditor with gated auto-merge** — Composio's gate is human/CI; emdash is manual.
4. **Air-gap + attestation** — no comparable ships it.

## Where we're behind / threats

- **🚨 Ollama's own consolidation (primary threat).** `ollama launch` (Jan 2026) and `ollama launch codex-app` (v0.24.0, May 2026) auto-wire popular clients to local models with zero config — eroding the "launch a client locally" niche the CLI lives near. The launched Codex App already has git-worktree parallel threads (single-user), so it overlaps the worktree mechanic — but **not** the Director/Auditor gated Crew.
- **Model ceiling.** Tools that recommend frontier cloud models (Goose, Aider, Cline) imply local models struggle on hard multi-step tasks. The purely-local Crew inherits that ceiling.
- **Adoption/ecosystem.** Incumbents (Cline, Continue, opencode) hold the mindshare; OllamaDev is a solo project with an empty skill registry.

## Strategy — responding to the Ollama threat

The mistake would be competing on "launch a popular client against local models" — Ollama now owns that. Plant the flag where `ollama launch` and Codex worktree-threads **structurally can't follow**:

1. **Make the Crew the headline, not the CLI.** `ollama launch` gives you a single client; Codex gives one user parallel threads. Neither has a **Director → parallel Coders → AI Auditor → gated merge** loop. That orchestration + review gate is the product.
2. **Own "local + reviewed + air-gapped."** Lead with air-gap mode + attestation + the gated Crew for the buyer who *cannot* use cloud (regulated/offline). `ollama launch` doesn't serve them; we do, end-to-end.
3. **Prove the local-model quality.** The model-ceiling doubt is the real objection. Benchmark `--amplify` (off vs 3 vs 5) on real tasks and publish the result — turn "local models struggle" into evidence either way.
4. **Seed the ecosystem.** The registry/crew-packs machinery exists but is empty; 15–20 strong skills + a few crew packs convert "we have a registry" into a reason to choose us.
5. **Interop, don't fight the launcher.** Consider being *reachable from* the Ollama ecosystem (we already speak its API). Ride the local-model wave Ollama is driving; differentiate on the layer above it (multi-agent + review + air-gap), not on model serving.

Net: a real, currently-unoccupied niche — *the only local-first, air-gappable, multi-agent-with-review tool that runs everywhere on a zero-dep stack* — defended on orchestration + trust, not on being another local client.

## Caveats (verifier notes)

- emdash's worktrees are **not** Crew-comparable (no gated AI review) — refuted 1-2.
- **opencode** was **not** confirmed as local-via-Ollama (killed 0-3) — don't assume it's a local competitor.
- **Tabby** was **not** confirmed as the clearest air-gap pick over OllamaDev (killed 1-2).
- Roo Code repo archived ~2026-05-15; Void paused since ~Aug 2025; emdash shipped v1.1.27 the report day.
- No source evaluated OllamaDev itself — all OllamaDev-side claims are taken as given.

## Sources

- Ollama `launch` — https://ollama.com/blog/launch
- Ollama v0.24.0 / codex-app — https://github.com/ollama/ollama/releases/tag/v0.24.0
- Composio Agent Orchestrator — https://github.com/ComposioHQ/agent-orchestrator
- emdash — https://github.com/generalaction/emdash
- parallel-code — https://github.com/johannesjo/parallel-code
- Roo Code — https://github.com/RooCodeInc/Roo-Code
- Tabby — https://github.com/TabbyML/tabby
- Continue — https://docs.continue.dev
- Codex worktrees — https://developers.openai.com/codex/app/worktrees
