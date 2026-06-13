# Eval results

Pass rate of the built-in agentic suite (`ollamadev eval`) — small, deterministic
coding tasks the agent must complete end-to-end (write/edit a real file, then a
deterministic check decides pass/fail). Reproduce with `ollamadev eval -m <model>`.

## 26-task suite — 2026-06-13 (v0.9.4)

| Model | Type | Pass rate |
|---|---|---|
| `qwen3.5:9b` | local | **26/26 (100%)** |
| `kimi-k2.7-code:cloud` | Ollama cloud | **26/26 (100%)** |
| `minimax-m3:cloud` | Ollama cloud | **26/26 (100%)** |

All 26 tasks passed on every model, including the harder additions: `binary-search`,
`bank-class` (stateful class), `csv-parse`, `fib-memo`, `refactor-extract` (multi-file),
and `fix-two-bugs`.

### Earlier (20-task suite), before/after the edit-tool fixes (v0.9.2)

The pre-fix variance traced to edits silently not applying on a near-miss `old_string`
(every failure was an edit-existing-file task); v0.9.2's whitespace-tolerant + unique
matching fixed it:

| Model | Before (v0.9.1) | After (v0.9.2) |
|---|---|---|
| `qwen3.5:9b` (local) | 50% | **100%** |
| `kimi-k2.7-code:cloud` | 70% | **100%** |
| `minimax-m3:cloud` | 80% | **100%** |

## How to read this

100% here means the **harness reliably completes well-specified tasks** — local and
cloud alike, and it scales with whatever model you give it. It does **not** mean
frontier-model parity: these tasks are deterministic and tightly specified by design
(that's what makes them checkable). The real model-tier gap shows up on work that
*isn't* clean — ambiguous intent, large multi-file context, subtle cross-codebase bugs —
which resists a deterministic check and so isn't in the suite. Use these numbers as a
harness-reliability signal, not a capability ceiling.
