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
      "$ADE/src" "$ADE/public" "$ADE/web" "$ADE/vendor" "$APP/"
cp "$ROOT/ollamadev" "$APP/bin/ollamadev"; chmod +x "$APP/bin/ollamadev"   # bundled agent CLI (PHP script)
cp "$ROOT/LICENSE" "$APP/LICENSE"   # GPL-3.0: the license travels with every distributed binary

# Launchers: point the app at the bundled CLI, then run the Boson window.
cat > "$APP/OllamaDev-ADE" <<'SH'
#!/usr/bin/env sh
# Launch OllamaDev ADE. Requires PHP 8.4+ (for the Boson runtime). The agent CLI
# is bundled at bin/ollamadev, so nothing else needs installing.
here="$(cd "$(dirname "$0")" && pwd)"
command -v php >/dev/null 2>&1 || { echo "PHP 8.4+ is required to run the desktop app."; exit 1; }
export OLLAMADEV_BINARY="$here/bin/ollamadev"

# First interactive run: offer to put the `ollamadev` command on your PATH too,
# so you get it in the terminal (not just inside the app). TTY-only — a
# double-click launch (no terminal) skips this silently. Asked at most once.
marker="${HOME:-/tmp}/.ollamadev/.desktop-cli-offered"
if [ -t 0 ] && [ -t 1 ] && [ ! -f "$marker" ] && ! command -v ollamadev >/dev/null 2>&1; then
    printf 'Also add the `ollamadev` command to your PATH (~/.local/bin)? [y/N] '
    read ans
    case "$ans" in
        y|Y|yes|YES)
            mkdir -p "$HOME/.local/bin"
            # Copy (not symlink): the CLI is one self-contained PHP file, so a copy
            # keeps working even if this app folder is later moved or deleted.
            if cp "$here/bin/ollamadev" "$HOME/.local/bin/ollamadev" 2>/dev/null; then
                chmod +x "$HOME/.local/bin/ollamadev" 2>/dev/null
                echo "✓ installed ~/.local/bin/ollamadev — ensure ~/.local/bin is on your PATH."
                echo "  (a self-contained copy; survives moving or removing this app folder)"
            else
                echo "✗ couldn't install it; add ~/.local/bin manually if you want the command."
            fi
            ;;
    esac
    mkdir -p "$(dirname "$marker")" 2>/dev/null && : > "$marker" 2>/dev/null || true
fi

exec php "$here/index.php" "$@"
SH
chmod +x "$APP/OllamaDev-ADE"
cat > "$APP/OllamaDev-ADE.bat" <<'BAT'
@echo off
REM Launch OllamaDev ADE. Requires PHP 8.4+ on PATH. Agent CLI is bundled.
set "OLLAMADEV_BINARY=%~dp0bin\ollamadev"
php "%~dp0index.php" %*
BAT

# Web mode: same ADE in your browser, no native window (and no Boson — so any
# PHP 8.x works). Localhost by default; for remote use, set OLLAMADEV_SERVE_HOST
# (e.g. 0.0.0.0) AND OLLAMADEV_SERVE_TOKEN, ideally behind an SSH/Tailscale tunnel.
cat > "$APP/OllamaDev-Web" <<'SH'
#!/usr/bin/env sh
here="$(cd "$(dirname "$0")" && pwd)"
command -v php >/dev/null 2>&1 || { echo "PHP is required (8.0+)."; exit 1; }
export OLLAMADEV_BINARY="$here/bin/ollamadev"
host="${OLLAMADEV_SERVE_HOST:-localhost}"; port="${OLLAMADEV_SERVE_PORT:-41434}"
echo "OllamaDev ADE (web) → http://$host:$port   (Ctrl-C to stop)"
[ -n "$OLLAMADEV_SERVE_TOKEN" ] && echo "  auth token required: append ?token=\$OLLAMADEV_SERVE_TOKEN"
[ "$host" != "localhost" ] && [ "$host" != "127.0.0.1" ] && echo "  ⚠ binding non-localhost — set OLLAMADEV_SERVE_TOKEN and prefer a tunnel/VPN."
exec php -S "$host:$port" "$here/web/server.php"
SH
chmod +x "$APP/OllamaDev-Web"
cat > "$APP/OllamaDev-Web.bat" <<'BAT'
@echo off
REM OllamaDev ADE in the browser. Requires PHP on PATH. Agent CLI is bundled.
set "OLLAMADEV_BINARY=%~dp0bin\ollamadev"
if "%OLLAMADEV_SERVE_HOST%"=="" set "OLLAMADEV_SERVE_HOST=localhost"
if "%OLLAMADEV_SERVE_PORT%"=="" set "OLLAMADEV_SERVE_PORT=41434"
echo OllamaDev ADE (web) -^> http://%OLLAMADEV_SERVE_HOST%:%OLLAMADEV_SERVE_PORT%
php -S %OLLAMADEV_SERVE_HOST%:%OLLAMADEV_SERVE_PORT% "%~dp0web\server.php"
BAT
cat > "$APP/README.txt" <<'TXT'
OllamaDev ADE — desktop app

Requires: PHP 8.4+  and  Ollama running locally (https://ollama.com).
Run it:
  Native window      :  ./OllamaDev-ADE        (Windows: OllamaDev-ADE.bat)  — needs PHP 8.4+
  In your browser    :  ./OllamaDev-Web         (Windows: OllamaDev-Web.bat)  — then open http://localhost:41434

Web mode needs no native window (and no Boson). Use it when you're away from the
desktop. It binds to localhost by default; for remote access set OLLAMADEV_SERVE_HOST
and OLLAMADEV_SERVE_TOKEN, and prefer an SSH/Tailscale tunnel over exposing it directly.

The agent CLI is bundled in bin/ — you do not need to install it separately.
On first run from a terminal it also offers to add the `ollamadev` command to
your PATH (~/.local/bin), so you get it in the shell too. (Optional, asked once.)

License: GNU General Public License v3.0 or later (GPL-3.0-or-later). See LICENSE.
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
