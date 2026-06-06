#!/usr/bin/env bash
# Build the single-file ollamadev binary from src/*.php modules.
#
# The modules are contiguous slices of the program concatenated in filename
# order (00-, 10-, 20- ...). Only src/00-header.php carries the shebang and the
# opening <?php tag; the rest are raw PHP bodies. Edit the modules, not the
# built binary.
set -euo pipefail
cd "$(dirname "$0")"

# Deterministic, locale-independent file ordering. Without this the glob sorts
# by the ambient LC_COLLATE, which orders punctuation differently across
# machines (e.g. 83-crew.php vs 83a-crew-roles.php) — producing byte-different
# binaries from identical source. Class declarations are early-bound, so order
# never affects runtime; this just makes the build reproducible (CI checks it).
export LC_ALL=C

OUT="ollamadev"
TMP="$(mktemp)"

# Concatenate modules in byte-order (C locale, set above).
for f in src/[0-9]*.php; do
    cat "$f" >> "$TMP"
done

# Validate before replacing the live binary.
if ! php -l "$TMP" >/dev/null; then
    echo "✗ build failed: syntax error" >&2
    rm -f "$TMP"
    exit 1
fi

mv "$TMP" "$OUT"
chmod +x "$OUT"
echo "✓ built $OUT ($(wc -l < "$OUT") lines)"
