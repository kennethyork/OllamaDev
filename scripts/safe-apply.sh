#!/usr/bin/env bash
# Guarded apply: rebuild from src/, run the smoke suite, and only install +
# commit if everything is green. Otherwise roll back the working tree so a bad
# edit (whether made by you or by the CLI editing its own src/) never ships.
#
# Usage:
#   scripts/safe-apply.sh "commit message"      # build, test, install, commit
#   scripts/safe-apply.sh --no-commit           # build, test, install only
#
# Intended for the self-improvement loop: edit src/*.php, then run this.
set -uo pipefail
cd "$(dirname "$0")/.."

MSG="${1:-}"
TAG_BEFORE="$(git describe --tags --always 2>/dev/null || echo none)"

echo "▶ Building from src/ ..."
if ! ./build.sh; then
    echo "✗ build/lint failed — reverting src changes"
    git checkout -- src/ ollamadev 2>/dev/null || true
    exit 1
fi

echo "▶ Running smoke suite ..."
if ! php tests/smoke.php; then
    echo "✗ tests failed — reverting src + binary (previous build preserved)"
    git checkout -- src/ ollamadev 2>/dev/null || true
    ./build.sh >/dev/null 2>&1 || true
    exit 1
fi

echo "▶ Installing ..."
cp ollamadev "$HOME/.local/bin/ollamadev" && chmod +x "$HOME/.local/bin/ollamadev"
echo "✓ installed"

if [ "$MSG" != "" ] && [ "$MSG" != "--no-commit" ]; then
    git add src/ ollamadev tests/ 2>/dev/null || true
    git commit -q -m "$MSG" && echo "✓ committed: $MSG"
    echo "  rollback with: git revert HEAD   (was at $TAG_BEFORE)"
fi

echo "✓ safe-apply complete"
