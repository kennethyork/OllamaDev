#!/usr/bin/env bash
# Package the OllamaDev ADE desktop app as a download-and-run archive per OS.
#
# Boson 0.19 is a RUNTIME (FFI + platform shared libs), not a compiler — there's
# no single-binary `boson compile`. So we ship the app + its vendored Boson libs
# + the CLI it drives + a launcher, as one archive. The user needs PHP 8.4 to run
# the Boson window; everything else is bundled (including the agent CLI, so they
# don't install it separately).
#
# Usage: scripts/build-desktop.sh        # build all OS archives into dist/binaries/
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
ADE="$ROOT/Desktop/ollamadev-ade"
DIST="$ROOT/dist/binaries"
STAGE="$ROOT/.build/ade-stage"

command -v composer >/dev/null || { echo "✗ composer is required" >&2; exit 1; }

# 1. Build the CLI script the desktop drives, and ensure the desktop's deps.
echo "▸ building CLI (bundled into the desktop archive)…"
./build.sh >/dev/null
echo "▸ composer install (desktop runtime)…"
( cd "$ADE" && composer install --no-interaction --no-progress --quiet )

# 2. Stage a clean payload: app + vendor (all platform Boson libs) + CLI + launcher.
echo "▸ staging payload…"
rm -rf "$STAGE"; mkdir -p "$STAGE/OllamaDev-ADE/bin"
APP="$STAGE/OllamaDev-ADE"
cp -R "$ADE/index.php" "$ADE/boson.config.php" "$ADE/composer.json" "$ADE/composer.lock" \
      "$ADE/src" "$ADE/public" "$ADE/vendor" "$APP/"
cp "$ROOT/ollamadev" "$APP/bin/ollamadev"; chmod +x "$APP/bin/ollamadev"   # bundled agent CLI (PHP script)

# Launchers: point the app at the bundled CLI, then run the Boson window.
cat > "$APP/OllamaDev-ADE" <<'SH'
#!/usr/bin/env sh
# Launch OllamaDev ADE. Requires PHP 8.4+ (for the Boson runtime). The agent CLI
# is bundled at bin/ollamadev, so nothing else needs installing.
here="$(cd "$(dirname "$0")" && pwd)"
command -v php >/dev/null 2>&1 || { echo "PHP 8.4+ is required to run the desktop app."; exit 1; }
export OLLAMADEV_BINARY="$here/bin/ollamadev"
exec php "$here/index.php" "$@"
SH
chmod +x "$APP/OllamaDev-ADE"
cat > "$APP/OllamaDev-ADE.bat" <<'BAT'
@echo off
REM Launch OllamaDev ADE. Requires PHP 8.4+ on PATH. Agent CLI is bundled.
set "OLLAMADEV_BINARY=%~dp0bin\ollamadev"
php "%~dp0index.php" %*
BAT
cat > "$APP/README.txt" <<'TXT'
OllamaDev ADE — desktop app

Requires: PHP 8.4+  and  Ollama running locally (https://ollama.com).
Run it:
  macOS / Linux :  ./OllamaDev-ADE
  Windows       :  OllamaDev-ADE.bat

The agent CLI is bundled in bin/ — you do not need to install it separately.
TXT

# 3. Emit one archive per platform label (identical portable payload — the right
#    Boson lib is chosen at runtime; zip for Windows, tar.gz elsewhere).
mkdir -p "$DIST"
emit() { # <label> <ext>
    local label="$1" ext="$2" out="$DIST/OllamaDev-ADE-$1.$2"
    rm -f "$out"
    if [ "$ext" = "zip" ]; then ( cd "$STAGE" && zip -qry "$out" OllamaDev-ADE );
    else ( cd "$STAGE" && tar -czf "$out" OllamaDev-ADE ); fi
    echo "  ✓ dist/binaries/OllamaDev-ADE-$label.$ext ($(du -h "$out" | cut -f1))"
}
emit linux-x64   tar.gz
emit mac-arm64   tar.gz
emit mac-x64     tar.gz
emit windows-x64 zip
echo "✓ desktop archives built in dist/binaries/"
