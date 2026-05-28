#!/usr/bin/env bash
# Build the single-file ollamadev binary from src/*.php modules.
#
# The modules are contiguous slices of the program concatenated in filename
# order (00-, 10-, 20- ...). Only src/00-header.php carries the shebang and the
# opening <?php tag; the rest are raw PHP bodies. Edit the modules, not the
# built binary.
set -euo pipefail
cd "$(dirname "$0")"

OUT="ollamadev"
TMP="$(mktemp)"

# Concatenate modules in lexical order.
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
