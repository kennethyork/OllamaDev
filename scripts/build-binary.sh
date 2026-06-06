#!/usr/bin/env bash
# Build standalone, PHP-free native binaries of the ollamadev CLI.
#
# The CLI is normally a single PHP script (needs `php` installed). This packs it
# — together with a static PHP runtime — into one self-contained executable per
# OS/arch using phpacker (https://phpacker.dev), which wraps static-php-cli's
# php-micro. phpacker is a BUILD-TIME tool only: it's fetched into a throwaway
# dir so the repo itself stays dependency-free.
#
# Usage:
#   scripts/build-binary.sh                 # build for the host platform
#   scripts/build-binary.sh all             # linux + mac + windows, x64 + arm64
#   scripts/build-binary.sh linux x64       # a specific target
#
# Output: dist/binaries/ollamadev-<os>-<arch>[.exe]
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

PLATFORM="${1:-host}"
ARCH="${2:-}"

# Resolve "host" to a concrete phpacker target (it requires an explicit platform).
if [ "$PLATFORM" = "host" ]; then
    case "$(uname -s)" in
        Linux)   PLATFORM="linux" ;;
        Darwin)  PLATFORM="mac" ;;
        MINGW*|MSYS*|CYGWIN*) PLATFORM="windows" ;;
        *) echo "✗ unknown host OS $(uname -s); pass a platform explicitly (linux|mac|windows)" >&2; exit 1 ;;
    esac
    case "$(uname -m)" in
        x86_64|amd64)  ARCH="x64" ;;
        arm64|aarch64) ARCH="arm64" ;;
        *) echo "✗ unknown host arch $(uname -m); pass an arch explicitly (x64|arm64)" >&2; exit 1 ;;
    esac
fi
PHP_VERSION="${PHPACKER_PHP:-8.4}"
BUILD_DIR="$ROOT/.build/phpacker"      # throwaway: phpacker + its composer deps
OUT_RAW="$ROOT/.build/binaries-raw"    # phpacker's per-os output tree
DIST="$ROOT/dist/binaries"             # final, stably-named artifacts

command -v php >/dev/null      || { echo "✗ php is required to build" >&2; exit 1; }
command -v composer >/dev/null || { echo "✗ composer is required to build the binary" >&2; exit 1; }

# 1. Build the plain single-file binary, then strip its shebang: php-micro runs
#    the appended script directly, so a leading `#!/usr/bin/env php` line would be
#    emitted as output.
echo "▸ building ollamadev (concatenation)…"
./build.sh >/dev/null
ENTRY="$ROOT/.build/ollamadev.entry.php"
mkdir -p "$ROOT/.build"
tail -n +2 "$ROOT/ollamadev" > "$ENTRY"

# 2. Fetch phpacker into an isolated build project (keeps the repo deps-free).
if [ ! -x "$BUILD_DIR/vendor/bin/phpacker" ]; then
    echo "▸ fetching phpacker (build-time only)…"
    mkdir -p "$BUILD_DIR"
    printf '{"name":"ollamadev/build","require-dev":{}}' > "$BUILD_DIR/composer.json"
    ( cd "$BUILD_DIR" && composer require --dev phpacker/phpacker --no-interaction --quiet )
fi
PHPACKER="$BUILD_DIR/vendor/bin/phpacker"

# 3. Pack. phpacker writes <out>/<os>/<os>-<arch>[.exe].
echo "▸ packing standalone binary (php $PHP_VERSION, target: $PLATFORM ${ARCH:-})…"
rm -rf "$OUT_RAW"; mkdir -p "$OUT_RAW"
# shellcheck disable=SC2086
"$PHPACKER" build "$PLATFORM" $ARCH --src="$ENTRY" --dest="$OUT_RAW" --php="$PHP_VERSION" --no-interaction

# 4. Collect into dist/ with stable, download-friendly names.
mkdir -p "$DIST"
found=0
while IFS= read -r -d '' f; do
    base="$(basename "$f")"            # e.g. linux-x64, mac-arm, windows-x64.exe
    base="${base/-arm/-arm64}"         # normalize phpacker's "arm" → canonical "arm64"
    cp "$f" "$DIST/ollamadev-$base"
    chmod +x "$DIST/ollamadev-$base" 2>/dev/null || true
    echo "  ✓ dist/binaries/ollamadev-$base ($(du -h "$f" | cut -f1))"
    found=$((found + 1))
done < <(find "$OUT_RAW" -type f -print0)

[ "$found" -gt 0 ] || { echo "✗ no binaries produced" >&2; exit 1; }
echo "✓ built $found standalone binar$([ "$found" -eq 1 ] && echo y || echo ies) in dist/binaries/"
