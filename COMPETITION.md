# Competitive landscape & positioning

> Snapshot: mid-2026. Sourced from a deep-research pass (19 sources, 25 claims adversarially verified, 21 confirmed / 4 killed). This is a fast-moving space — re-check before relying on any single point. Competitors are described by category rather than name; the underlying analysis is unchanged.

## Bottom line

**No tool ships OllamaDev's exact combination.** The intersection is currently unoccupied:

> Ollama-only (local by default, optional Ollama cloud — no third-party cloud AI) **+** multi-agent Crew with git-worktree isolation **and a gated, AI-reviewed merge** **+** CLI / native desktop / web on **one zero-dependency vanilla-PHP engine**.

OllamaDev is differentiated by the **intersection**, not by any single feature — every individual piece exists somewhere else.

## Who overlaps, and how

| Category | Local-only? | Multi-agent? | Worktree + review gate | Form factor |
|---|---|---|---|---|
| Cloud-CLI orchestrators | ❌ wrap cloud CLIs | ✅ parallel | ✅ worktree + review-remediate (human/CI) | orchestrator |
| Cloud-routed multi-agent desktops | ❌ cloud-routed | ✅ many agents | ✅ worktree, **manual** merge | native desktop |
| Worktree parallel-task apps | ❌ (no local backend) | ✅ | ✅ worktree | app |
| Hybrid IDE-integrated agents | ❌ hybrid / cloud-routed | ✅ | ✅ worktree | IDE ext + CLI |
| Platform-launchable single-user agents | local via the platform | single-user parallel threads | ✅ built-in worktrees | native desktop |
| Local single-agent tools | ✅ local | ❌ single-agent | ❌ none | server / ext / IDE |

**The structural split:** every tool with worktree-isolated parallel agents orchestrates **cloud-backed** CLIs (none local-only); every **truly local** tool is **single-agent**. OllamaDev sits in the gap between those two groups.

## Genuinely differentiated

1. **Local-only AND multi-agent-with-gated-merge** — confirmed empty; nobody combines them.
2. **Tri-form-factor on one zero-dependency stack** (CLI + desktop + web, shared engine/data) — no comparable.
3. **AI Auditor with gated auto-merge** — the orchestrator category's gate is human/CI; the desktop category merges manually.
4. **Local by default with an opt-in cloud path through the *same* Ollama backend** — flexibility (frontier-scale when needed) without bolting on a second provider/integration.

## Where we're behind / threats

- **🚨 The model platform's own consolidation (primary threat).** Ollama's `launch` (Jan 2026) and its app-launch commands (v0.24.0, May 2026) auto-wire popular clients to local models with zero config — eroding the "launch a client locally" niche the CLI lives near. One launchable app already has git-worktree parallel threads (single-user), so it overlaps the worktree mechanic — but **not** the Director/Auditor gated Crew. (Ollama is the substrate OllamaDev builds on, not a like-for-like competitor.)
- **Model ceiling.** Tools that recommend frontier cloud models imply local models struggle on hard multi-step tasks. The Crew narrows this with the optional cloud path, but a purely-local run inherits that ceiling.
- **Adoption/ecosystem.** Incumbent local-AI coding tools hold the mindshare; OllamaDev is a solo project with an empty skill registry.

## Strategy — responding to the platform threat

The mistake would be competing on "launch a popular client against local models" — the platform now owns that. Plant the flag where a launcher and worktree-threads **structurally can't follow**:

1. **Make the Crew the headline, not the CLI.** A launcher gives you a single client; worktree-threads give one user parallel threads. Neither has a **Director → parallel Coders → AI Auditor → gated merge** loop. That orchestration + review gate is the product.
2. **Own "local + reviewed."** Lead with the gated Crew, fully local by default, with an opt-in cloud path for the hardest tasks — one backend, no second integration.
3. **Prove the local-model quality.** The model-ceiling doubt is the real objection. Benchmark `--amplify` (off vs 3 vs 5) on real tasks and publish the result — turn "local models struggle" into evidence either way.
4. **Seed the ecosystem.** The registry/crew-packs machinery exists but is empty; 15–20 strong skills + a few crew packs convert "we have a registry" into a reason to choose us.
5. **Interop, don't fight the launcher.** Be *reachable from* the platform's ecosystem (we already speak its API). Ride the local-model wave; differentiate on the layer above it (multi-agent + review), not on model serving.

Net: a real, currently-unoccupied niche — *the only local-first, multi-agent-with-review tool that runs everywhere on a zero-dep stack* — defended on orchestration + trust, not on being another local client.

## Caveats (verifier notes)

- The cloud-routed desktop category's worktrees are **not** Crew-comparable (no gated AI review).
- One tool assumed local-via-Ollama was **not** confirmed as such — don't assume every "local" tool runs on Ollama.
- Several reference tools are in flux (one IDE-extension agent's repo was archived ~2026-05-15; one IDE fork has been paused since ~Aug 2025) — re-check status before citing.
- No source evaluated OllamaDev itself — all OllamaDev-side claims are taken as given.

## Sources

- Ollama `launch` — https://ollama.com/blog/launch
- Ollama v0.24.0 release notes — https://github.com/ollama/ollama/releases/tag/v0.24.0
- Other competitor repos and write-ups reviewed during the research pass are intentionally omitted here; see the research transcript if you need the named list.
