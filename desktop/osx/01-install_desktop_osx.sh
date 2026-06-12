#!/bin/bash
# =============================================================
# NodePulse Desktop — install_desktop_osx.sh
# macOS adaptation of install_desktop_wsl2.sh.
#
# IMPORTANT DESIGN DIFFERENCE vs WSL2/Termux:
# Openbox is a Linux X11 window manager — it does not exist on
# macOS (no openbox/tint2/thunar in Homebrew, no apt). Instead of
# a virtual X11 desktop, this script exposes the NATIVE macOS
# desktop through the built-in Screen Sharing VNC server (:5900),
# bridged to the browser with websockify (:6080) + noVNC, behind
# the same nginx /desktop/ + auth_gate wiring used on WSL2.
#
# Authentication is double-layered:
#   1) nginx auth_request → cli-auth.php (gate_auth session cookie)
#   2) macOS account login inside noVNC — noVNC >= 1.1 speaks
#      Apple Remote Desktop auth (RFB security type 30), so the
#      user enters their macOS username/password in the noVNC
#      credentials dialog. No VNC legacy password needed.
#
# NOTE: Screen Sharing mirrors the PHYSICAL console — anyone in
# front of the Mac sees the remote session, and vice versa.
#
# Safe to re-run: every step checks before acting.
# Requires: base NodePulse setup (osx-setup.sh) already done.
# =============================================================

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*" >&2; exit 1; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

# macOS: single self-contained nginx conf written by osx-setup.sh
NGINX_CONF="$HOME/nodepulse-bin/nginx.conf"

# Homebrew is not on the default PATH on Apple Silicon (/opt/homebrew);
# resolve it here so the script works regardless of the caller's shell rc.
if ! command -v brew > /dev/null 2>&1; then
    if [ -x /opt/homebrew/bin/brew ]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [ -x /usr/local/bin/brew ]; then
        eval "$(/usr/local/bin/brew shellenv)"
    fi
fi
command -v brew > /dev/null 2>&1 || fail "Homebrew not found — run osx-setup.sh first"

echo -e "\n${BOLD}NodePulse Desktop — installer (macOS)${NC}"
echo "Working directory: $HOME"

# ── Prerequisites ─────────────────────────────────────────────
[ -f "$NGINX_CONF" ]            || fail "nginx config not found at $NGINX_CONF — run osx-setup.sh first"
[ -f "$HOME/bin/start-server" ] || fail "start-server not found — run osx-setup.sh first"
[ -f "$HOME/bin/stop-server" ]  || fail "stop-server not found — run osx-setup.sh first"
command -v python3 > /dev/null 2>&1 || fail "python3 not found — run osx-setup.sh first"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Screen Sharing (native macOS VNC server on :5900)
# ─────────────────────────────────────────────────────────────
step "1/8  Screen Sharing (com.apple.screensharing)"

if launchctl print system/com.apple.screensharing > /dev/null 2>&1; then
    skip "Screen Sharing already enabled"
else
    echo "    Enabling Screen Sharing (requires sudo)..."
    sudo launchctl enable system/com.apple.screensharing
    sudo launchctl bootstrap system /System/Library/LaunchDaemons/com.apple.screensharing.plist 2>/dev/null
    sleep 1
    if launchctl print system/com.apple.screensharing > /dev/null 2>&1; then
        ok "Screen Sharing enabled"
    else
        # launchctl can be refused when Remote Management (ARD) is already
        # active, or by MDM policy — the GUI toggle always works.
        warn "could not enable via launchctl — enable it manually:"
        warn "System Settings → General → Sharing → Screen Sharing (ON)"
    fi
fi

# ─────────────────────────────────────────────────────────────
# STEP 2 — websockify (pure-Python, no numpy)
# ─────────────────────────────────────────────────────────────
step "2/8  websockify"

if python3 -c "import websockify" 2>/dev/null; then
    skip "websockify already installed"
else
    # brew python is externally managed (PEP 668) → --break-system-packages.
    # --no-deps: websockify works in pure Python, numpy is optional.
    pip3 install --break-system-packages --no-deps websockify \
        || fail "websockify install failed"
    python3 -c "import websockify" 2>/dev/null \
        || fail "websockify installed but import failed"
    ok "websockify installed"
fi

# ─────────────────────────────────────────────────────────────
# STEP 3 — noVNC v1.5.0
# ─────────────────────────────────────────────────────────────
step "3/8  noVNC static client"

NOVNC_DIR="$HOME/services/novnc"

if [ -f "$NOVNC_DIR/vnc.html" ]; then
    skip "noVNC already present at $NOVNC_DIR"
else
    mkdir -p "$HOME/services"
    echo "    Downloading noVNC v1.5.0..."
    curl -L -o "$HOME/services/novnc.tar.gz" \
        https://github.com/novnc/noVNC/archive/refs/tags/v1.5.0.tar.gz \
        || fail "download failed — check internet connection"
    tar xzf "$HOME/services/novnc.tar.gz" -C "$HOME/services/" \
        || fail "tar extraction failed"
    mv "$HOME/services/noVNC-1.5.0" "$NOVNC_DIR"
    rm "$HOME/services/novnc.tar.gz"
    ln -sf vnc.html "$NOVNC_DIR/index.html"
    ok "noVNC installed at $NOVNC_DIR"
fi

[ -L "$NOVNC_DIR/index.html" ] || ln -sf vnc.html "$NOVNC_DIR/index.html"

# ─────────────────────────────────────────────────────────────
# STEP 4 — start-desktop / stop-desktop
# ─────────────────────────────────────────────────────────────
step "4/8  ~/bin/start-desktop  ~/bin/stop-desktop"

mkdir -p "$HOME/bin"

# No Xvnc/openbox to launch on macOS: the desktop is the native one,
# served by Screen Sharing. start-desktop only runs the websockify
# bridge noVNC(:6080) → Screen Sharing(:5900).
cat > "$HOME/bin/start-desktop" << 'STARTDESK'
#!/bin/bash
# NodePulse Desktop (macOS) — websockify bridge: noVNC :6080 → Screen Sharing :5900

# Homebrew bootstrap (Apple Silicon: /opt/homebrew not on default PATH)
if ! command -v brew > /dev/null 2>&1; then
    if [ -x /opt/homebrew/bin/brew ]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [ -x /usr/local/bin/brew ]; then
        eval "$(/usr/local/bin/brew shellenv)"
    fi
fi

VNC_PORT=5900
WS_PORT=6080
NOVNC_DIR="$HOME/services/novnc"
LOG_DIR="$HOME/tmp"
mkdir -p "$LOG_DIR"

# Screen Sharing must be listening on :5900
if ! lsof -nP -iTCP:$VNC_PORT -sTCP:LISTEN > /dev/null 2>&1; then
    echo "[desktop] WARNING: nothing listening on :$VNC_PORT — Screen Sharing is off."
    echo "[desktop] Enable it: System Settings → General → Sharing → Screen Sharing"
fi

# Clean up previous bridge
pkill -f "websockify.*$WS_PORT" 2>/dev/null
sleep 1

# websockify + static noVNC (loopback target; auth = macOS login via noVNC ARD)
python3 -m websockify --web="$NOVNC_DIR" $WS_PORT 127.0.0.1:$VNC_PORT \
    > "$LOG_DIR/websockify.log" 2>&1 &

echo "[desktop] websockify :$WS_PORT → 127.0.0.1:$VNC_PORT  |  noVNC $NOVNC_DIR"
echo "[desktop] log: $LOG_DIR/websockify.log"
STARTDESK

cat > "$HOME/bin/stop-desktop" << 'STOPDESK'
#!/bin/bash
# macOS: only the websockify bridge is ours — Screen Sharing is a
# system service and stays up (it is the user's choice to disable it).
pkill -f "websockify.*6080" 2>/dev/null
echo "[desktop] stopped"
STOPDESK

chmod +x "$HOME/bin/start-desktop" "$HOME/bin/stop-desktop"
ok "start-desktop and stop-desktop written"

# ─────────────────────────────────────────────────────────────
# STEP 5 — nginx: /desktop/ locations + real-scheme fix
# ─────────────────────────────────────────────────────────────
step "5/8  nginx: location /desktop/ + real-scheme fix"

if grep -q 'location = /desktop/' "$NGINX_CONF"; then
    skip "nginx desktop location blocks already present"
else
    cp "$NGINX_CONF" "$NGINX_CONF.bak.desktop"

    # The conf on disk has literal nginx variables ($host etc.) — Python
    # writes them verbatim. The block is inserted INSIDE the server block,
    # right before the catch-all "location / {": `set` is only valid at
    # server/location level (NOT in the http block where the macOS conf
    # keeps client_max_body_size).
    python3 << 'PYEOF'
import os, re, sys

conf = os.path.expanduser('~/nodepulse-bin/nginx.conf')

with open(conf) as f:
    lines = f.readlines()

EXACT_BLOCK = '''\
        # Cloudflare Tunnel speaks plain HTTP to nginx; read real scheme from X-Forwarded-Proto.
        # $host never includes the port; $http_host may include :8080 on direct backend access.
        set $real_scheme $scheme;
        if ($http_x_forwarded_proto = "https") {
            set $real_scheme "https";
        }

        # NodePulse Desktop — native macOS desktop via Screen Sharing + noVNC
        # Gated by the same auth_gate used by /cli/ (gate_auth cookie).

        # Exact match: redirect bare /desktop/ to noVNC with correct WS path+params.
        # noVNC default path='websockify' builds wss://host/websockify (root-relative),
        # but nginx only proxies under /desktop/ — force path=desktop/websockify.
        location = /desktop/ {
            auth_request /cli-auth.php;
            error_page 401 = @desktop_login;
            return 302 $real_scheme://$host/desktop/vnc.html?path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1;
        }

        # Prefix match: static noVNC files + WebSocket upgrade to websockify
        location /desktop/ {
            auth_request /cli-auth.php;
            error_page 401 = @desktop_login;

            proxy_pass http://127.0.0.1:6080/;
            proxy_http_version 1.1;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
            proxy_buffering off;
        }

        location @desktop_login {
            return 302 $real_scheme://$host/cli-login.php;
        }

'''

inserted = False
for i, line in enumerate(lines):
    if re.match(r'\s+location\s+/\s*\{', line):
        lines.insert(i, EXACT_BLOCK)
        inserted = True
        break
if not inserted:
    print("ERROR: cannot find 'location / {' catch-all in nginx conf", file=sys.stderr)
    sys.exit(1)

content = ''.join(lines)

# Fix $scheme://$http_host -> $real_scheme://$host in all return directives
# (the @cli_login redirect written by osx-setup.sh): behind the Cloudflare
# tunnel $scheme is "http" and $http_host may carry :8080.
content = re.sub(r'\$scheme://\$http_host', r'$real_scheme://$host', content)

with open(conf, 'w') as f:
    f.write(content)

print("added desktop block + real-scheme fix")
PYEOF
    [ $? -eq 0 ] || { cp "$NGINX_CONF.bak.desktop" "$NGINX_CONF"; fail "nginx conf injection failed — backup restored"; }

    nginx -t -c "$NGINX_CONF" > /dev/null 2>&1 || {
        cp "$NGINX_CONF.bak.desktop" "$NGINX_CONF"
        fail "nginx config test failed — backup restored ($NGINX_CONF.bak.desktop)"
    }
    nginx -c "$NGINX_CONF" -s reload > /dev/null 2>&1 || true   # not running yet is fine
    ok "nginx desktop location blocks added"
fi

# ─────────────────────────────────────────────────────────────
# STEP 6 — Integrate with start-server / stop-server
# ─────────────────────────────────────────────────────────────
step "6/8  start-server / stop-server integration"

if grep -q 'start-desktop' "$HOME/bin/start-server"; then
    skip "start-desktop already integrated in start-server"
else
    python3 - "$HOME/bin/start-server" << 'PYEOF'
import sys

path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()

INSERT = (
    '# Start desktop (websockify bridge to Screen Sharing) — before the tunnel block\n'
    'bash $HOME/bin/start-desktop\n'
    '\n'
)

markers = [
    '# Cloudflare tunnel + NodePulse',
    'if [ -f "$HOME/bin/nodepulse" ]',
    'cloudflared tunnel',
]
insert_at = None
for i, line in enumerate(lines):
    if any(m in line for m in markers):
        insert_at = i
        break

if insert_at is None:
    print("ERROR: cannot find nodepulse/cloudflared block in start-server", file=sys.stderr)
    sys.exit(1)

lines.insert(insert_at, INSERT)
with open(path, 'w') as f:
    f.writelines(lines)
print(f"inserted start-desktop at line {insert_at + 1}")
PYEOF
    [ $? -eq 0 ] || fail "start-server integration failed"
    ok "start-desktop integrated in start-server"
fi

if grep -q 'stop-desktop' "$HOME/bin/stop-server"; then
    skip "stop-desktop already integrated in stop-server"
else
    python3 - "$HOME/bin/stop-server" << 'PYEOF'
import sys

path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()

INSERT = 'bash $HOME/bin/stop-desktop\n\n'

markers = ['pm2 stop', 'pkill cloudflared', 'pkill -f nodepulse']
insert_at = None
for i, line in enumerate(lines):
    if any(m in line for m in markers):
        insert_at = i
        break

if insert_at is None:
    for i, line in enumerate(lines):
        if 'echo' in line and 'stopped' in line.lower():
            insert_at = i
            break

if insert_at is None:
    print("ERROR: cannot find insertion point in stop-server", file=sys.stderr)
    sys.exit(1)

lines.insert(insert_at, INSERT)
with open(path, 'w') as f:
    f.writelines(lines)
print(f"inserted stop-desktop at line {insert_at + 1}")
PYEOF
    [ $? -eq 0 ] || fail "stop-server integration failed"
    ok "stop-desktop integrated in stop-server"
fi

# ─────────────────────────────────────────────────────────────
# STEP 7 — Dashboard card (~/www/desktop/)
# ─────────────────────────────────────────────────────────────
step "7/8  Dashboard card ~/www/desktop/"

mkdir -p "$HOME/www/desktop"

cat > "$HOME/www/desktop/index.php" << 'PHPEOF'
<?php
// NodePulse Desktop — redirect to noVNC.
// Uses an absolute Location header (PHP only rewrites relative URLs to http://host:8080/...).
// X-Forwarded-Proto from Cloudflare Tunnel gives the real scheme (https).
// HTTP_HOST is the public hostname without port; strtok strips it if present.
$proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https';
$host  = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$host  = strtok($host, ':'); // strip :8080 (nginx backend port)
$qs    = 'path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1';
header("Location: {$proto}://{$host}/desktop/vnc.html?{$qs}", true, 302);
exit;
PHPEOF
ok "index.php written (absolute PHP redirect, preserves https and strips :8080)"

# ─────────────────────────────────────────────────────────────
# STEP 8 — Final verification
# ─────────────────────────────────────────────────────────────
step "8/8  Verification"

ERRORS=0
check() {
    local label="$1"; shift
    if "$@" > /dev/null 2>&1; then
        ok "$label"
    else
        warn "MISSING: $label"
        ERRORS=$((ERRORS + 1))
    fi
}

check "Screen Sharing enabled"   launchctl print system/com.apple.screensharing
check "websockify importable"    python3 -c "import websockify"
check "noVNC vnc.html"           test -f "$NOVNC_DIR/vnc.html"
check "noVNC index.html link"    test -L "$NOVNC_DIR/index.html"
check "start-desktop (+x)"       test -x "$HOME/bin/start-desktop"
check "stop-desktop (+x)"        test -x "$HOME/bin/stop-desktop"
check "nginx exact-match"        grep -q 'location = /desktop/' "$NGINX_CONF"
check "nginx prefix-match"       grep -q 'location /desktop/' "$NGINX_CONF"
check "nginx config valid"       nginx -t -c "$NGINX_CONF"
check "start-server integrated"  grep -q 'start-desktop' "$HOME/bin/start-server"
check "stop-server integrated"   grep -q 'stop-desktop' "$HOME/bin/stop-server"
check "www/desktop/ dir"         test -d "$HOME/www/desktop"

echo ""
if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed.${NC}"
    echo ""
    echo "Next step:  start-server"
    echo "Then open:  https://<tunnel>.trycloudflare.com/desktop/"
    echo "Login:      1) auth_gate password  2) macOS username+password in the noVNC dialog"
    echo "Logs:       ~/tmp/websockify.log"
    echo "***NOTICE*** TO APPLY CHANGES RESTART THE SERVICE WITH stop-server AND start-server"
    echo "To prepare the desktop for remote use, run 02-configure_desktop_osx.sh."
else
    echo -e "${RED}${BOLD}$ERRORS check(s) failed — review output above.${NC}"
    exit 1
fi
