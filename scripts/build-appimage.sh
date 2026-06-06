#!/usr/bin/env bash
# Build a SELF-CONTAINED Linux AppImage of the OllamaDev ADE desktop app.
#
# Bundles a PHP 8.4 runtime (with the ffi + curl extensions), the Boson libs,
# the app, and the agent CLI — so it runs by double-click with NO system PHP
# install. x86_64 only: the desktop is Linux-only by design, while the CLI ships
# as standalone binaries for every platform (see scripts/build-binary.sh).
#
# Note: built against the host's glibc — build on the OLDEST glibc you want to
# support (CI uses ubuntu-latest). Usage: scripts/build-appimage.sh
set -euo pipefail
export LC_ALL=C
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
DIST="$ROOT/dist/binaries"
STAGE="$ROOT/.build/ade-stage/OllamaDev-ADE"   # produced by build-desktop.sh
APPDIR="$ROOT/.build/AppDir"

command -v php >/dev/null || { echo "✗ php is required" >&2; exit 1; }
php -m | grep -qi '^ffi$'  || { echo "✗ this PHP lacks the ffi extension (Boson needs it)" >&2; exit 1; }

# Architecture is whatever this runner is — the AppImage bundles arch-specific
# PHP + the matching Boson lib, so build x86_64 on an x86_64 box, aarch64 on arm.
case "$(uname -m)" in
  x86_64)        AIARCH=x86_64 ;;
  aarch64|arm64) AIARCH=aarch64 ;;
  *) echo "✗ unsupported arch: $(uname -m)" >&2; exit 1 ;;
esac
echo "▸ target arch: $AIARCH"

# 1. Build the desktop payload FRESH (rebuilds the bundled CLI + stages + emits
#    the per-OS archives). Always run it so the AppImage never bundles a stale CLI.
echo "▸ building desktop payload (build-desktop.sh)…"
bash scripts/build-desktop.sh >/dev/null

# 2. Assemble the AppDir.
echo "▸ assembling AppDir…"
rm -rf "$APPDIR"
mkdir -p "$APPDIR/usr/bin" "$APPDIR/usr/lib/php" "$APPDIR/usr/share/ollamadev-ade"
cp -R "$STAGE/." "$APPDIR/usr/share/ollamadev-ade/"
# Keep only THIS arch's Linux Boson lib (drop the other arch + mac/windows).
find "$APPDIR/usr/share/ollamadev-ade/vendor/boson-php/saucer/bin" -type f \
  ! -name "libboson-linux-${AIARCH}.so" -delete 2>/dev/null || true

# Bundle the PHP binary + the ffi/curl extensions.
PHP_BIN="$(readlink -f "$(command -v php)")"
cp "$PHP_BIN" "$APPDIR/usr/bin/php"
EXTDIR="$(php -r 'echo ini_get("extension_dir");')"
cp "$EXTDIR/ffi.so" "$APPDIR/usr/lib/php/" 2>/dev/null || true
cp "$EXTDIR/curl.so" "$APPDIR/usr/lib/php/" 2>/dev/null || true

# Bundle the shared libs php + those extensions need — EXCEPT the glibc core,
# which must come from the host to match its dynamic loader.
CORE='libc\.so|libm\.so|libpthread|libdl\.so|librt\.so|ld-linux|libresolv|libgcc_s|libstdc\+\+'
# ALSO never bundle libnghttp2: Boson's WebView (libboson) loads the HOST's
# libcurl-gnutls at runtime, and that curl<->nghttp2 pair is matched on the host.
# Bundling our build-runner's (older, ubuntu-22.04) libnghttp2 shadows the host's
# via LD_LIBRARY_PATH and hides newer symbols the host curl needs — libboson then
# fails with "undefined symbol: nghttp2_option_set_no_rfc9113...". PHP's bundled
# libcurl still works fine against the host's (>=) nghttp2. Keep this excluded.
SHARED='libnghttp2'
bundle_libs() { # <binary-or-.so>
  ldd "$1" 2>/dev/null | awk '/=>/ {print $3}' | grep -E '^/' | while read -r lib; do
    base="$(basename "$lib")"
    echo "$base" | grep -Eq "$CORE|$SHARED" && continue
    [ -f "$APPDIR/usr/lib/$base" ] || cp -L "$lib" "$APPDIR/usr/lib/" 2>/dev/null || true
  done
}
bundle_libs "$APPDIR/usr/bin/php"
bundle_libs "$APPDIR/usr/lib/php/ffi.so"
bundle_libs "$APPDIR/usr/lib/php/curl.so"

# 2b. Bundle the Whisper STT engine + model so /voice works fully offline out of
#     the box (the "bake-in"). Skip with STT_BUNDLE=0; choose the model with
#     STT_MODEL (default base ~142MB). AppRun points OLLAMADEV_STT_DIR here.
if [ "${STT_BUNDLE:-1}" != "0" ]; then
  STT_MODEL="${STT_MODEL:-base}"
  echo "▸ bundling whisper.cpp engine + ggml-$STT_MODEL model…"
  bash scripts/build-whisper.sh >/dev/null || echo "  ⚠ whisper build failed — AppImage will auto-fetch on first /voice"
  STTDIR="$APPDIR/usr/share/ollamadev-ade/stt"
  mkdir -p "$STTDIR"
  cp dist/whisper/whisper-linux-* "$STTDIR/" 2>/dev/null || true
  case "$STT_MODEL" in turbo) MODELNAME=ggml-large-v3-turbo.bin ;; *) MODELNAME="ggml-$STT_MODEL.bin" ;; esac
  if [ ! -f "$STTDIR/$MODELNAME" ]; then
    echo "▸ fetching $MODELNAME…"
    curl -fsSL -o "$STTDIR/$MODELNAME" "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/$MODELNAME?download=true" \
      || { echo "  ⚠ model fetch failed — will auto-fetch on first /voice"; rm -f "$STTDIR/$MODELNAME"; }
  fi
fi

# 3. AppRun — run the bundled php (ignoring any host php.ini) on the app.
cat > "$APPDIR/AppRun" <<'SH'
#!/bin/sh
HERE="$(dirname "$(readlink -f "$0")")"
export LD_LIBRARY_PATH="$HERE/usr/lib:${LD_LIBRARY_PATH:-}"
export OLLAMADEV_BINARY="$HERE/usr/share/ollamadev-ade/bin/ollamadev"
# Bundled Whisper STT engine + model (if packed) — /voice uses it with no download.
[ -d "$HERE/usr/share/ollamadev-ade/stt" ] && export OLLAMADEV_STT_DIR="$HERE/usr/share/ollamadev-ade/stt"
exec "$HERE/usr/bin/php" -n \
  -d extension_dir="$HERE/usr/lib/php" -d extension=ffi -d extension=curl \
  "$HERE/usr/share/ollamadev-ade/index.php" "$@"
SH
chmod +x "$APPDIR/AppRun"

# 4. Desktop entry + icon (icon generated with GD — no binary committed).
cat > "$APPDIR/ollamadev-ade.desktop" <<'DESK'
[Desktop Entry]
Type=Application
Name=OllamaDev ADE
Comment=Local AI coding agent (Ollama / LM Studio)
Exec=ollamadev-ade
Icon=ollamadev-ade
Categories=Development;IDE;
Terminal=false
DESK
APPDIR="$APPDIR" php -r '
$d=getenv("APPDIR"); $im=imagecreatetruecolor(256,256);
imagefilledrectangle($im,0,0,256,256,imagecolorallocate($im,13,17,23));
$fg=imagecolorallocate($im,88,166,255); imagesetthickness($im,16);
imageline($im,72,84,124,128,$fg); imageline($im,124,128,72,172,$fg);  // ">"
imageline($im,140,172,196,172,$fg);                                    // "_"
imagepng($im,"$d/ollamadev-ade.png");' || echo "  (icon generation skipped — no GD)"
cp "$APPDIR/ollamadev-ade.png" "$APPDIR/.DirIcon" 2>/dev/null || true

# 5. Pack with appimagetool (extract-and-run so no FUSE is required in CI).
mkdir -p "$DIST" "$ROOT/.build/tools"
TOOL="$ROOT/.build/tools/appimagetool-$AIARCH"
if [ ! -x "$TOOL" ]; then
  echo "▸ fetching appimagetool ($AIARCH)…"
  curl -fsSL -o "$TOOL" "https://github.com/AppImage/appimagetool/releases/download/continuous/appimagetool-$AIARCH.AppImage" || { echo "✗ could not fetch appimagetool" >&2; exit 1; }
  chmod +x "$TOOL"
fi
OUT="$DIST/OllamaDev-ADE-$AIARCH.AppImage"
rm -f "$OUT"
( ARCH=$AIARCH "$TOOL" --appimage-extract-and-run "$APPDIR" "$OUT" ) || ( ARCH=$AIARCH "$TOOL" "$APPDIR" "$OUT" )
echo "✓ $OUT ($(du -h "$OUT" | cut -f1))"
