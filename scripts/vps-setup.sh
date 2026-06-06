#!/usr/bin/env bash
# One-shot VPS setup for OllamaDev web mode — 100% local, nothing exposed.
# Installs Ollama + a model, fetches the ADE, and runs the browser app on
# localhost. Reach it from anywhere over an SSH tunnel (see the end).
#
#   curl -fsSL https://raw.githubusercontent.com/kennethyork/OllamaDev/main/scripts/vps-setup.sh | bash
#
# Security: binds to localhost ONLY and requires an auth token, so the web app is
# not reachable from the internet and even other users on the box can't use it
# without the token. Reach it from your devices over an SSH tunnel (or Tailscale).
#
# Override with env vars:
#   MODEL=llama3.2:3b              # smaller = better on a CPU-only VPS (default qwen2.5-coder:7b)
#   PORT=41434                      # web app port
#   DIR=~/ollamadev-ade            # where the ADE is installed
#   CLI=0                          # skip installing the `ollamadev` shell command
#   OLLAMADEV_SERVE_TOKEN=secret   # use a specific token (default: a fresh random one)
#   FIREWALL=1                     # also enable ufw (allows SSH, keeps the app port private)
set -euo pipefail

REPO="kennethyork/OllamaDev"
MODEL="${MODEL:-qwen2.5-coder:7b}"
PORT="${PORT:-41434}"
DIR="${DIR:-$HOME/ollamadev-ade}"
WANT_CLI="${CLI:-1}"
TOKEN="${OLLAMADEV_SERVE_TOKEN:-}"
WANT_FIREWALL="${FIREWALL:-0}"

say()  { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
warn() { printf '\033[33m  ⚠ %s\033[0m\n' "$*"; }

# A random auth token gates every API call (shell, file, agent). Without it the
# app would be open to anyone who can reach the port — so we always set one.
gen_token() {
    if command -v openssl >/dev/null; then openssl rand -hex 32
    elif [ -r /dev/urandom ]; then head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
    else date +%s%N | sha256sum | cut -c1-64; fi
}
[ -z "$TOKEN" ] && TOKEN="$(gen_token)"

# --- prerequisites ---------------------------------------------------------
command -v curl >/dev/null || { echo "curl is required."; exit 1; }
command -v tar  >/dev/null || { echo "tar is required."; exit 1; }
if ! command -v php >/dev/null; then
    echo "✗ PHP 8.0+ is required for web mode. Install it first, e.g.:"
    echo "    sudo apt install -y php-cli     # Debian/Ubuntu"
    echo "    sudo dnf install -y php-cli     # Fedora/RHEL"
    exit 1
fi
case "$(uname -m)" in
    x86_64|amd64)  ARCH=x64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) echo "✗ unsupported arch $(uname -m) — build from source instead."; exit 1 ;;
esac

# --- 1. Ollama -------------------------------------------------------------
if ! command -v ollama >/dev/null; then
    say "Installing Ollama"
    curl -fsSL https://ollama.com/install.sh | sh
fi
# Make sure the server is up (systemd service if present, else background).
if command -v systemctl >/dev/null && systemctl list-unit-files 2>/dev/null | grep -q '^ollama\.service'; then
    sudo systemctl enable --now ollama 2>/dev/null || true
elif ! pgrep -x ollama >/dev/null 2>&1; then
    nohup ollama serve >/tmp/ollama.log 2>&1 & sleep 2
fi

say "Pulling model: $MODEL"
warn "On a CPU-only VPS this is slow and capped by RAM — prefer a small model (e.g. MODEL=llama3.2:3b)."
ollama pull "$MODEL"

# --- 2. The ADE (web app) --------------------------------------------------
say "Fetching OllamaDev ADE (linux-$ARCH) → $DIR"
mkdir -p "$DIR"
curl -fsSL "https://github.com/$REPO/releases/latest/download/OllamaDev-ADE-linux-$ARCH.tar.gz" \
    | tar -xz -C "$DIR" --strip-components=1
chmod +x "$DIR/OllamaDev-ADE" "$DIR/OllamaDev-Web" "$DIR/bin/ollamadev" 2>/dev/null || true

# --- 3. Optional: the ollamadev CLI on PATH (for crew/watch over SSH) ------
if [ "$WANT_CLI" = "1" ]; then
    say "Installing the ollamadev CLI"
    curl -fsSL "https://raw.githubusercontent.com/$REPO/main/install.sh" | sh || warn "CLI install skipped/failed (optional)."
fi

# --- 3b. Firewall: keep the app port private, never lock out SSH -----------
# The app binds to localhost so it isn't internet-exposed regardless. ufw adds a
# safety net for a multi-tenant box or accidental re-binding. We ALWAYS allow SSH
# before touching ufw so this can't drop your session; enabling is opt-in.
if command -v ufw >/dev/null && { [ "$(id -u)" -eq 0 ] || sudo -n true 2>/dev/null; }; then
    FW=""; [ "$(id -u)" -eq 0 ] || FW="sudo"
    $FW ufw allow OpenSSH >/dev/null 2>&1 || $FW ufw allow 22/tcp >/dev/null 2>&1 || true
    $FW ufw delete allow "$PORT" >/dev/null 2>&1 || true   # ensure the app port is NOT publicly open
    $FW ufw delete allow "$PORT/tcp" >/dev/null 2>&1 || true
    if [ "$WANT_FIREWALL" = "1" ]; then
        say "Enabling ufw (SSH allowed, app port $PORT kept private)"
        $FW ufw --force enable >/dev/null 2>&1 || warn "ufw enable failed (continuing)."
    else
        warn "ufw present but not enabled. To lock the box down: sudo ufw allow OpenSSH && sudo ufw enable  (re-run with FIREWALL=1 to do it now)."
    fi
fi

# --- 4. Keep the web app running ------------------------------------------
UNIT=/etc/systemd/system/ollamadev-web.service
if command -v systemctl >/dev/null && { [ "$(id -u)" -eq 0 ] || sudo -n true 2>/dev/null; }; then
    say "Installing a systemd service (always-on, localhost:$PORT)"
    SUDO=""; [ "$(id -u)" -eq 0 ] || SUDO="sudo"
    $SUDO tee "$UNIT" >/dev/null <<EOF
[Unit]
Description=OllamaDev ADE (web)
After=network.target ollama.service
[Service]
WorkingDirectory=$DIR
ExecStart=$DIR/OllamaDev-Web
Environment=OLLAMADEV_SERVE_PORT=$PORT
Environment=OLLAMADEV_SERVE_TOKEN=$TOKEN
Environment=OLLAMA_MODEL=$MODEL
Restart=on-failure
User=$(id -un)
[Install]
WantedBy=multi-user.target
EOF
    $SUDO systemctl daemon-reload
    $SUDO systemctl enable --now ollamadev-web
    RUNNING="systemd service 'ollamadev-web' (always-on)"
else
    warn "No root/systemd — starting in the background with nohup (won't survive a reboot)."
    ( cd "$DIR" && OLLAMADEV_SERVE_PORT=$PORT OLLAMADEV_SERVE_TOKEN=$TOKEN nohup ./OllamaDev-Web >/tmp/ollamadev-web.log 2>&1 & )
    sleep 1
    RUNNING="background process (logs: /tmp/ollamadev-web.log)"
fi

# --- done ------------------------------------------------------------------
cat <<EOF

$(printf '\033[32m\033[1m✓ OllamaDev is up on this VPS — private to you\033[0m')
  Web app : http://localhost:$PORT   ($RUNNING)
  Engine  : Ollama + $MODEL (localhost:11434)
  Binding : localhost only (not reachable from the internet)
  Auth    : token required on every request

$(printf '\033[1mYour access token (keep it secret — treat it as the password):\033[0m')
  $TOKEN

Reach it from your laptop/phone (encrypted, nothing public):
  1. Tunnel:  ssh -L $PORT:localhost:$PORT $(id -un)@<this-vps>
  2. Open  :  http://localhost:$PORT/?token=$TOKEN

The ?token= is needed even over the tunnel (it also stops other users on
this box). Bookmark that URL on your phone. To rotate the token later:
  sudo systemctl edit ollamadev-web   # change Environment=OLLAMADEV_SERVE_TOKEN=...
  sudo systemctl restart ollamadev-web

(Voice/mic needs a secure context: localhost works; to open it WITHOUT a
 tunnel, put it behind HTTPS + keep this token — see the Self-hosting docs.)
EOF
