#!/bin/bash
# =============================================================
# NodePulse Desktop — install_desktop_wsl2.sh
# WSL2 adaptation of install_desktop.sh (Termux original).
# Installs Openbox remote desktop via Xtigervnc + noVNC.
# Safe to re-run: every step checks before acting.
# =============================================================

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*" >&2; exit 1; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

# WSL2: nginx site config (sites-available, not nginx.conf monolith)
NGINX_CONF="/etc/nginx/sites-available/nodepulse"

echo -e "\n${BOLD}NodePulse Desktop — installer (WSL2)${NC}"
echo "Working directory: $HOME"

# ── Prerequisites ─────────────────────────────────────────────
[ -f "$NGINX_CONF" ]            || fail "nginx site config not found at $NGINX_CONF — run base NodePulse setup first"
[ -f "$HOME/bin/start-server" ] || fail "start-server not found — run base NodePulse setup first"
[ -f "$HOME/bin/stop-server" ]  || fail "stop-server not found — run base NodePulse setup first"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Packages
# ─────────────────────────────────────────────────────────────
step "1/9  Packages (tigervnc-standalone-server openbox tint2 xterm dbus-x11)"

VNC_BIN=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null || true)

if [ -n "$VNC_BIN" ] && command -v openbox &>/dev/null; then
    skip "packages already installed (VNC: $VNC_BIN)"
else
    apt-get install -y tigervnc-standalone-server openbox tint2 xterm dbus-x11 \
        || fail "apt-get install failed"
    ok "packages installed"
fi

VNC_BIN=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null || true)
[ -n "$VNC_BIN" ]              || fail "Xvnc/Xtigervnc not found after install"
command -v openbox &>/dev/null || fail "openbox not found after install"

# ─────────────────────────────────────────────────────────────
# STEP 2 — websockify (pure-Python, no numpy)
# ─────────────────────────────────────────────────────────────
step "2/9  websockify"

if python3 -c "import websockify" 2>/dev/null; then
    skip "websockify already installed"
else
    # Prefer system package; fall back to pip
    apt-get install -y python3-websockify 2>/dev/null \
        || pip3 install --no-deps websockify \
        || fail "websockify install failed"
    python3 -c "import websockify" 2>/dev/null \
        || fail "websockify installed but import failed"
    ok "websockify installed"
fi

# ─────────────────────────────────────────────────────────────
# STEP 3 — noVNC v1.5.0
# ─────────────────────────────────────────────────────────────
step "3/9  noVNC static client"

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
# STEP 4 — VNC passwd (empty, auth handled by auth_gate)
# ─────────────────────────────────────────────────────────────
step "4/9  VNC passwd (empty)"

mkdir -p "$HOME/.vnc"
if [ ! -f "$HOME/.vnc/passwd" ]; then
    touch "$HOME/.vnc/passwd"
    ok "~/.vnc/passwd created"
else
    skip "~/.vnc/passwd already exists"
fi
chmod 600 "$HOME/.vnc/passwd"

# ─────────────────────────────────────────────────────────────
# STEP 5 — xstartup
# ─────────────────────────────────────────────────────────────
step "5/9  ~/.vnc/xstartup"

cat > "$HOME/.vnc/xstartup" << 'XSTART'
#!/bin/bash
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
export LANG=en_US.UTF-8
xsetroot -solid "#0a0a0a"
tint2 &
xterm -geometry 100x30+50+50 -bg "#0a0a0a" -fg "#00ff88" &
exec openbox-session
XSTART

chmod +x "$HOME/.vnc/xstartup"

if python3 -c "open('$HOME/.vnc/xstartup','rb').read().find(b'\x00') == -1 or exit(1)" 2>/dev/null; then
    ok "xstartup written and verified (no null bytes)"
else
    fail "xstartup contains null bytes after write — filesystem issue?"
fi

# ─────────────────────────────────────────────────────────────
# STEP 6 — start-desktop / stop-desktop
# ─────────────────────────────────────────────────────────────
step "6/9  ~/bin/start-desktop  ~/bin/stop-desktop"

mkdir -p "$HOME/bin"

# Quoted heredoc: VNC_BIN is resolved at runtime inside the generated script
cat > "$HOME/bin/start-desktop" << 'STARTDESK'
#!/bin/bash
# NodePulse Desktop — Xvnc + openbox + websockify (also serves noVNC)

VNC_BIN=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null)
[ -z "$VNC_BIN" ] && { echo "[desktop] ERROR: Xvnc/Xtigervnc not found — install tigervnc-standalone-server"; exit 1; }

DISPLAY_NUM=1
VNC_PORT=5901
WS_PORT=6080
GEOMETRY="1280x720"
DEPTH=24
NOVNC_DIR="$HOME/services/novnc"
LOG_DIR="$HOME/tmp"
mkdir -p "$LOG_DIR"

# WSL2: ensure X11 socket directory exists with correct permissions
mkdir -p /tmp/.X11-unix
chmod 1777 /tmp/.X11-unix 2>/dev/null

# Clean up previous sessions
pkill -f "Xvnc :$DISPLAY_NUM" 2>/dev/null
pkill -f "Xtigervnc :$DISPLAY_NUM" 2>/dev/null
pkill -f "websockify.*$WS_PORT" 2>/dev/null
rm -f /tmp/.X${DISPLAY_NUM}-lock /tmp/.X11-unix/X${DISPLAY_NUM} 2>/dev/null
sleep 1

# Xvnc (loopback only, no VNC auth — protected by auth_gate)
"$VNC_BIN" :$DISPLAY_NUM \
    -geometry "$GEOMETRY" \
    -depth $DEPTH \
    -rfbport $VNC_PORT \
    -localhost \
    -SecurityTypes None \
    -desktop "NodePulse" \
    > "$LOG_DIR/xvnc.log" 2>&1 &
sleep 2

# Openbox session
DISPLAY=:$DISPLAY_NUM $HOME/.vnc/xstartup > "$LOG_DIR/xstartup.log" 2>&1 &
sleep 1

# websockify + static noVNC
websockify --web="$NOVNC_DIR" $WS_PORT 127.0.0.1:$VNC_PORT \
    > "$LOG_DIR/websockify.log" 2>&1 &

echo "[desktop] $VNC_BIN :$DISPLAY_NUM  |  websockify :$WS_PORT  |  noVNC $NOVNC_DIR"
echo "[desktop] log: $LOG_DIR/{xvnc,xstartup,websockify}.log"
STARTDESK

cat > "$HOME/bin/stop-desktop" << 'STOPDESK'
#!/bin/bash
pkill -f "Xvnc :1" 2>/dev/null
pkill -f "Xtigervnc :1" 2>/dev/null
pkill -f "websockify.*6080" 2>/dev/null
pkill -f "openbox" 2>/dev/null
pkill -f "tint2" 2>/dev/null
rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null
echo "[desktop] stopped"
STOPDESK

chmod +x "$HOME/bin/start-desktop" "$HOME/bin/stop-desktop"
ok "start-desktop and stop-desktop written"

# ─────────────────────────────────────────────────────────────
# STEP 7 — nginx: /desktop/ locations + real-scheme fix
# ─────────────────────────────────────────────────────────────
step "7/9  nginx: location /desktop/ + real-scheme fix"

# ── 7a: desktop location blocks (first run only) ─────────────
if grep -q 'location = /desktop/' "$NGINX_CONF"; then
    skip "nginx desktop location blocks already present"
else
    cp "$NGINX_CONF" "$NGINX_CONF.bak.desktop"

    # Inject into sites-available/nodepulse (WSL2 uses sites-enabled pattern)
    python3 << 'PYEOF'
import re, sys

conf = '/etc/nginx/sites-available/nodepulse'

with open(conf) as f:
    lines = f.readlines()

# nginx variables ($http_upgrade etc.) are just string literals in Python.
# $real_scheme is set at server level (see EXACT_BLOCK); $host strips the port.
EXACT_BLOCK = '''\
        # Cloudflare Tunnel speaks plain HTTP to nginx; read real scheme from X-Forwarded-Proto.
        # $host never includes the port; $http_host may include :8080 on direct backend access.
        set $real_scheme $scheme;
        if ($http_x_forwarded_proto = "https") {
            set $real_scheme "https";
        }

        # NodePulse Desktop — Openbox via noVNC (WebSocket proxy)
        # Gated by the same auth_gate used by /cli/ (gate_auth cookie)

        # Exact match: redirect bare /desktop/ to noVNC with correct WS path+params.
        # IMPORTANT: noVNC default path='websockify' builds wss://host/websockify
        # (root-relative), but nginx only proxies under /desktop/ — must force
        # path=desktop/websockify so noVNC connects to wss://host/desktop/websockify.
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

PREFIX_ONLY = '''\
        location = /desktop/ {
            auth_request /cli-auth.php;
            error_page 401 = @desktop_login;
            return 302 $real_scheme://$host/desktop/vnc.html?path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1;
        }

'''

has_prefix = any(re.search(r'location\s+/desktop/', l) and '=' not in l for l in lines)

if has_prefix:
    for i, line in enumerate(lines):
        if re.search(r'location\s+/desktop/', line) and '=' not in line:
            lines.insert(i, PREFIX_ONLY)
            break
    print("added exact-match block before existing prefix block")
else:
    inserted = False
    for i, line in enumerate(lines):
        if re.match(r'\s+location\s+/\s*\{', line):
            lines.insert(i, EXACT_BLOCK)
            inserted = True
            break
    if not inserted:
        print("ERROR: cannot find 'location / {' catch-all in nginx site config", file=sys.stderr)
        sys.exit(1)
    print("added full desktop block before location /")

with open(conf, 'w') as f:
    f.writelines(lines)
PYEOF

    nginx -t 2>/dev/null || {
        cp "$NGINX_CONF.bak.desktop" "$NGINX_CONF"
        fail "nginx config test failed — backup restored ($NGINX_CONF.bak.desktop)"
    }
    ok "nginx desktop location blocks added"
fi

# ── 7b: real-scheme + $host fix (idempotent — runs on old installs too) ──────
if grep -q 'real_scheme' "$NGINX_CONF"; then
    skip "nginx real-scheme fix already applied"
else
    python3 << 'PYEOF'
import re, sys

conf = '/etc/nginx/sites-available/nodepulse'

with open(conf) as f:
    content = f.read()

SCHEME_BLOCK = (
    '\n'
    '    # Cloudflare Tunnel speaks plain HTTP to nginx; read real scheme from X-Forwarded-Proto.\n'
    '    # $host never includes the port; $http_host may include :8080 on direct backend access.\n'
    '    set $real_scheme $scheme;\n'
    '    if ($http_x_forwarded_proto = "https") {\n'
    '        set $real_scheme "https";\n'
    '    }\n'
)

# Inject after client_max_body_size line
content = re.sub(
    r'(    client_max_body_size[^\n]*\n)',
    r'\1' + SCHEME_BLOCK,
    content,
    count=1
)
if 'real_scheme' not in content:
    print("ERROR: could not inject real_scheme block", file=sys.stderr)
    sys.exit(1)

# Fix $scheme://$http_host -> $real_scheme://$host in all return directives
content = re.sub(r'\$scheme://\$http_host', r'$real_scheme://$host', content)

# Fix root-relative /desktop/ redirect -> absolute with real scheme
content = re.sub(
    r'return 302 /desktop/vnc\.html\?',
    r'return 302 $real_scheme://$host/desktop/vnc.html?',
    content
)

with open(conf, 'w') as f:
    f.write(content)

print("applied real-scheme + $host fix")
PYEOF

    nginx -t 2>/dev/null || {
        fail "nginx config test failed after real-scheme fix — check $NGINX_CONF"
    }
    nginx -s reload 2>/dev/null || warn "nginx reload failed (not running yet?)"
    ok "nginx real-scheme fix applied"
fi

# ─────────────────────────────────────────────────────────────
# STEP 8 — Integrate with start-server / stop-server
# ─────────────────────────────────────────────────────────────
step "8/9  start-server / stop-server integration"

# ── start-server: call start-desktop BEFORE the nodepulse block ──
if grep -q 'start-desktop' "$HOME/bin/start-server"; then
    skip "start-desktop already integrated in start-server"
else
    # Pass path as argument to avoid hardcoding; $HOME is expanded by the shell here
    python3 - "$HOME/bin/start-server" << 'PYEOF'
import sys

path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()

INSERT = (
    '# Start desktop (Xvnc + openbox + websockify) — must run before nodepulse blocks\n'
    'bash $HOME/bin/start-desktop\n'
    '\n'
)

markers = [
    '# Start cloudflared with NodePulse',
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
    ok "start-desktop integrated in start-server"
fi

# ── stop-server: call stop-desktop ──
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
    # Fallback: insert before final echo
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
    ok "stop-desktop integrated in stop-server"
fi

# ─────────────────────────────────────────────────────────────
# STEP 9 — Dashboard card (~/www/desktop/)
# ─────────────────────────────────────────────────────────────
step "9/9  Dashboard card ~/www/desktop/"

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
# Final verification
# ─────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Verification ──${NC}"

ERRORS=0
check() {
    local label="$1"; shift
    if "$@" &>/dev/null; then
        ok "$label"
    else
        warn "MISSING: $label"
        ERRORS=$((ERRORS + 1))
    fi
}

VNC_BIN_CHECK=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null || true)

check "Xvnc/Xtigervnc binary"    test -n "$VNC_BIN_CHECK"
check "openbox binary"           command -v openbox
check "websockify importable"    python3 -c "import websockify"
check "noVNC vnc.html"           test -f "$NOVNC_DIR/vnc.html"
check "noVNC index.html link"    test -L "$NOVNC_DIR/index.html"
check "~/.vnc/passwd"            test -f "$HOME/.vnc/passwd"
check "~/.vnc/xstartup (+x)"    test -x "$HOME/.vnc/xstartup"
check "start-desktop (+x)"       test -x "$HOME/bin/start-desktop"
check "stop-desktop (+x)"        test -x "$HOME/bin/stop-desktop"
check "nginx exact-match"        grep -q 'location = /desktop/' "$NGINX_CONF"
check "nginx prefix-match"       grep -q 'location /desktop/' "$NGINX_CONF"
check "nginx config valid"       nginx -t
check "start-server integrated"  grep -q 'start-desktop' "$HOME/bin/start-server"
check "stop-server integrated"   grep -q 'stop-desktop' "$HOME/bin/stop-server"
check "www/desktop/ dir"         test -d "$HOME/www/desktop"

echo ""
if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed.${NC}"
    echo ""
    echo "Next step:  start-server"
    echo "Then open:  https://<tunnel>.trycloudflare.com/desktop/"
    echo "Logs:       ~/tmp/{xvnc,xstartup,websockify}.log"
    echo "***NOTICE*** TO APPLY CHANGES RESTART THE SERVICE WITH stop-server AND start-server"
    echo "To set up a basic desktop configuration, execute the configure_desktop_wsl2.sh file."
else
    echo -e "${RED}${BOLD}$ERRORS check(s) failed — review output above.${NC}"
    exit 1
fi
