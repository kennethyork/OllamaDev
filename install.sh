#!/usr/bin/env sh
# OllamaDev CLI installer — downloads the standalone binary for your platform.
#
#   curl -fsSL https://raw.githubusercontent.com/kennethyork/OllamaDev/main/install.sh | sh
#
# The binary is fully self-contained (PHP is bundled) — nothing else to install.
# Override the install dir with OLLAMADEV_BIN_DIR=/usr/local/bin, or pin a
# version with OLLAMADEV_VERSION=v4.0.0 (default: latest release).
set -eu

REPO="kennethyork/OllamaDev"
VERSION="${OLLAMADEV_VERSION:-latest}"
BIN_DIR="${OLLAMADEV_BIN_DIR:-$HOME/.local/bin}"

# --- detect platform → release asset name --------------------------------
os=$(uname -s)
arch=$(uname -m)
case "$os" in
    Linux)  pos="linux" ;;
    Darwin) pos="mac" ;;
    MINGW*|MSYS*|CYGWIN*) pos="windows" ;;
    *) echo "Unsupported OS: $os. Build from source: https://github.com/$REPO" >&2; exit 1 ;;
esac
case "$arch" in
    x86_64|amd64)  parch="x64" ;;
    arm64|aarch64) parch="arm64" ;;
    *) echo "Unsupported arch: $arch. Build from source: https://github.com/$REPO" >&2; exit 1 ;;
esac
asset="ollamadev-${pos}-${parch}"
[ "$pos" = "windows" ] && asset="${asset}.exe"

if [ "$VERSION" = "latest" ]; then
    url="https://github.com/$REPO/releases/latest/download/$asset"
else
    url="https://github.com/$REPO/releases/download/$VERSION/$asset"
fi

echo "▸ Installing OllamaDev ($pos-$parch, $VERSION)"
echo "  from $url"

# --- download ------------------------------------------------------------
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
if command -v curl >/dev/null 2>&1; then
    curl -fSL --progress-bar "$url" -o "$tmp"
elif command -v wget >/dev/null 2>&1; then
    wget -q --show-progress "$url" -O "$tmp"
else
    echo "Need curl or wget to download." >&2; exit 1
fi
[ -s "$tmp" ] || { echo "Download failed or empty (no release asset '$asset' yet?)." >&2; exit 1; }

# --- install -------------------------------------------------------------
mkdir -p "$BIN_DIR"
dest="$BIN_DIR/ollamadev"
[ "$pos" = "windows" ] && dest="$dest.exe"
mv "$tmp" "$dest"
chmod +x "$dest"
trap - EXIT

echo "✓ Installed to $dest"
case ":$PATH:" in
    *":$BIN_DIR:"*) ;;
    *) echo "  ⚠ $BIN_DIR is not on your PATH. Add it:"
       echo "      echo 'export PATH=\"$BIN_DIR:\$PATH\"' >> ~/.bashrc  # or ~/.zshrc" ;;
esac
echo
echo "Next: start Ollama (ollama serve), pull a model (ollama pull qwen2.5-coder), then run: ollamadev"
