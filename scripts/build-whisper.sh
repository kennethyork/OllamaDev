#!/usr/bin/env bash
# Build a portable, self-contained whisper.cpp CLI binary for the CURRENT
# os/arch, named to match OllamaDev's release assets (whisper-<os>-<arch>).
#
# Why: OllamaDev's /voice does speech-to-text locally, but PHP can't BE an STT
# engine. So we ship a tiny self-contained whisper.cpp binary — built here in CI
# per platform, hosted as a release asset, and either bundled into the desktop
# builds or auto-fetched by the CLI on first use (see SttClient::provision()).
# Models (ggml-*.bin) are pulled separately from Hugging Face at runtime.
#
# Native build only (no cross-compile): each CI runner builds its own target.
#   linux  x86_64/aarch64   →  whisper-linux-x64 / whisper-linux-arm64
#   macOS  arm64/x86_64     →  whisper-mac-arm64 / whisper-mac-x64
# Windows is built by the release workflow (cmake on a windows runner).
#
# Output: dist/whisper/whisper-<os>-<arch>
set -euo pipefail

WHISPER_REF="${WHISPER_REF:-v1.7.5}"          # pinned upstream tag
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$ROOT/.whisper-build}"
DIST="$ROOT/dist/whisper"

case "$(uname -s)" in
  Linux)  OS=linux ;;
  Darwin) OS=mac ;;
  *) echo "✗ unsupported OS for this script: $(uname -s) (Windows builds run in CI)" >&2; exit 1 ;;
esac
case "$(uname -m)" in
  x86_64|amd64)  ARCH=x64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) echo "✗ unsupported arch: $(uname -m)" >&2; exit 1 ;;
esac
TARGET="whisper-$OS-$ARCH"

echo "▸ building $TARGET from whisper.cpp $WHISPER_REF"
command -v cmake >/dev/null || { echo "✗ cmake required" >&2; exit 1; }

# 1. Fetch source (shallow, pinned).
if [ ! -d "$WORK/whisper.cpp" ]; then
  mkdir -p "$WORK"
  git clone --depth 1 --branch "$WHISPER_REF" https://github.com/ggerganov/whisper.cpp "$WORK/whisper.cpp"
fi
cd "$WORK/whisper.cpp"

# 2. Configure a portable CPU build:
#    - BUILD_SHARED_LIBS=OFF  → static-link ggml/whisper into the one CLI binary
#    - GGML_OPENMP=OFF        → drop the libgomp runtime dependency (more portable)
#    - static libstdc++/libgcc on Linux so it runs on older glibc userlands
LDFLAGS=""
[ "$OS" = linux ] && LDFLAGS="-static-libstdc++ -static-libgcc"
cmake -B build -DCMAKE_BUILD_TYPE=Release \
  -DBUILD_SHARED_LIBS=OFF \
  -DGGML_OPENMP=OFF \
  -DGGML_NATIVE=OFF \
  -DWHISPER_BUILD_TESTS=OFF \
  -DWHISPER_BUILD_SERVER=OFF \
  -DWHISPER_BUILD_EXAMPLES=ON \
  ${LDFLAGS:+-DCMAKE_EXE_LINKER_FLAGS="$LDFLAGS"} >/dev/null
cmake --build build -j --config Release --target whisper-cli >/dev/null

# 3. Locate the CLI binary (path varies a little across versions).
BIN=""
for p in build/bin/whisper-cli build/bin/Release/whisper-cli build/bin/main; do
  [ -f "$p" ] && BIN="$p" && break
done
[ -n "$BIN" ] || { echo "✗ whisper-cli binary not found after build" >&2; exit 1; }

# 4. Stage it under the canonical name.
mkdir -p "$DIST"
cp "$BIN" "$DIST/$TARGET"
chmod +x "$DIST/$TARGET"
strip "$DIST/$TARGET" 2>/dev/null || true
echo "✓ $DIST/$TARGET ($(du -h "$DIST/$TARGET" | cut -f1))"
"$DIST/$TARGET" --help >/dev/null 2>&1 && echo "  smoke: --help OK" || echo "  ⚠ binary did not run --help cleanly"
