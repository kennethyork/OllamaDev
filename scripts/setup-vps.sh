#!/usr/bin/env bash
# setup-vps.sh — configure OllamaDev crew for a small / CPU-only host
# (e.g. a 24 GB, 12-core VPS with no GPU).
#
# It pulls ONE tool-capable coder model and points every crew role at it, so only
# one model is ever loaded (no swap thrash), and caps the context window so the KV
# cache stays small on limited RAM. Run it ON the VPS.
#
# Usage:
#   scripts/setup-vps.sh                 # qwen3.5:9b       (~6.6 GB, tool-capable, default — matches local crew)
#   scripts/setup-vps.sh --coder7b       # qwen2.5-coder:7b (~4.7 GB, leanest / fastest on CPU)
#   scripts/setup-vps.sh --coder14b      # qwen2.5-coder:14b (~9 GB, stronger but slower)
#   scripts/setup-vps.sh --ctx 4096      # override the context cap (default 8192)
#
# Why these choices: qwen3.5:9b reports a `tools` capability and fits in 24 GB with
# room to spare, so one model can back every crew role (no swap thrash). The 22 GB+
# models (qwen3.6, *:32b, *:35b) do NOT fit alongside anything on 24 GB and are
# painfully slow on CPU, so this script refuses to use them.
set -euo pipefail
cd "$(dirname "$0")/.."

MODEL="qwen3.5:9b"
CTX=8192

while [ $# -gt 0 ]; do
  case "$1" in
    --coder14b) MODEL="qwen2.5-coder:14b" ;;
    --coder7b)  MODEL="qwen2.5-coder:7b" ;;
    --qwen35)   MODEL="qwen3.5:9b" ;;
    --ctx)      CTX="${2:-8192}"; shift ;;
    -h|--help)  grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1 (try --help)" >&2; exit 1 ;;
  esac
  shift
done

# Resolve the ollamadev CLI: prefer this repo's freshly built binary, else PATH.
if [ -x "./ollamadev" ]; then ODV="php ./ollamadev"
elif command -v ollamadev >/dev/null 2>&1; then ODV="ollamadev"
else echo "✗ ollamadev binary not found (run ./build.sh first, or install it)" >&2; exit 1; fi

# Ollama must be reachable.
HOST="${OLLAMA_HOST:-http://localhost:11434}"
if ! curl -fsS "$HOST/api/tags" >/dev/null 2>&1; then
  echo "✗ Ollama not reachable at $HOST — start it with 'ollama serve' (or set OLLAMA_HOST)." >&2
  exit 1
fi

echo "▸ Host looks small/CPU-only — using a single shared coder model for all crew roles."
echo "▸ Model:   $MODEL"
echo "▸ Context: $CTX tokens (KV cache stays small on limited RAM)"
echo

echo "▸ Pulling $MODEL …"
ollama pull "$MODEL"

echo "▸ Pointing every crew role at $MODEL …"
$ODV config set crew.directorModel   "$MODEL" >/dev/null
$ODV config set crew.coderModel      "$MODEL" >/dev/null
$ODV config set crew.auditorModel    "$MODEL" >/dev/null
$ODV config set crew.researcherModel "$MODEL" >/dev/null

echo "▸ Capping the context window for limited RAM …"
$ODV config set ollama.contextWindow    "$CTX" >/dev/null
$ODV config set ollama.maxContextWindow "$CTX" >/dev/null

# A sensible default model for plain (non-crew) use, too.
$ODV config set ollama.defaultModel "$MODEL" >/dev/null

echo
echo "✓ Crew configured for this host:"
for k in directorModel coderModel auditorModel researcherModel; do
  printf '   crew.%-16s %s\n' "$k" "$($ODV config get "crew.$k" 2>/dev/null | tail -1)"
done
printf '   ollama.contextWindow   %s\n' "$($ODV config get ollama.contextWindow 2>/dev/null | tail -1)"
echo
echo "Notes:"
echo "  • Keep concurrent crew coders low (1–2) — on CPU they share cores, so more ≠ faster."
echo "  • No desktop GUI on a headless VPS: use the ADE web server mode (Desktop/ollamadev-ade/web/server.php) over a browser."
echo "  • Want more reasoning and can wait? Re-run with --coder14b."
