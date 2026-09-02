#!/bin/bash
# =============================================================
# NodePulse Desktop — 01-install_desktop_raspberry.sh
# Raspberry Pi OS adaptation of 01-install_desktop_wsl2.sh.
# Installs the Openbox remote desktop via Xtigervnc + noVNC,
# published through the existing cloudflared tunnel at /desktop/.
#
# DESIGN CHOICE — virtual display, not the native Pi desktop:
# The Pi node is meant to run headless (Raspberry Pi OS Lite, no
# monitor, supervised by systemd). Exposing the native desktop the
# way the macOS port does (Screen Sharing → websockify) would need
# a logged-in graphical session and wayvnc/RealVNC, neither of
# which exists on Lite. Xvnc ships its own framebuffer, so this
# stack works on Lite AND on the Desktop image, with no monitor,
# no GPU and no autologin. Same Debian packages as the WSL2 port.
#
# Differences from the WSL2 script:
#   - runs as a NORMAL user with sudo (never root)
#   - nginx is the unprivileged instance in ~/nginx/ written by
#     rpi-setup.sh (no /etc/nginx/sites-available, -c/-p needed)
#   - loginctl enable-linger so /run/user/<uid> exists when the
#     desktop is started by the nodepulse systemd unit (no login)
#   - every pkill is scoped to the current user (shared machine)
#   - explicit xfonts/x11-xserver-utils: Lite has no X11 at all
#
# Safe to re-run: every step checks before acting.
# Requires: base NodePulse setup (rpi-setup.sh) already done.
# =============================================================

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*" >&2; exit 1; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

# Raspberry Pi: single self-contained nginx conf with its own prefix
NGINX_PREFIX="$HOME/nginx"
NGINX_CONF="$NGINX_PREFIX/nginx.conf"

# nginx lives in /usr/sbin, which is off a normal user's PATH on Debian
NGINX_BIN="$(cat "$HOME/.nginx-bin" 2>/dev/null)"
[ -x "$NGINX_BIN" ] || NGINX_BIN="$(command -v nginx 2>/dev/null)"
[ -x "$NGINX_BIN" ] || NGINX_BIN="/usr/sbin/nginx"

nginx_test()   { "$NGINX_BIN" -t -c "$NGINX_CONF" -p "$NGINX_PREFIX/"; }
nginx_reload() { "$NGINX_BIN" -s reload -c "$NGINX_CONF" -p "$NGINX_PREFIX/"; }

echo -e "\n${BOLD}NodePulse Desktop — installer (Raspberry Pi)${NC}"
echo "Working directory: $HOME"

# ── Prerequisites ─────────────────────────────────────────────
if [ "$(id -u)" -eq 0 ]; then
    fail "Do not run this as root — run it as the same user that ran rpi-setup.sh"
fi
command -v sudo >/dev/null 2>&1 || fail "sudo not found"
sudo -v || fail "sudo authentication failed"

[ -f "$NGINX_CONF" ]            || fail "nginx config not found at $NGINX_CONF — run rpi-setup.sh first"
[ -x "$NGINX_BIN" ]             || fail "nginx binary not found — run rpi-setup.sh first"
[ -f "$HOME/bin/start-server" ] || fail "start-server not found — run rpi-setup.sh first"
[ -f "$HOME/bin/stop-server" ]  || fail "stop-server not found — run rpi-setup.sh first"
command -v python3 >/dev/null 2>&1 || fail "python3 not found — run rpi-setup.sh first"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Packages
# ─────────────────────────────────────────────────────────────
step "1/10 Packages (tigervnc-standalone-server openbox tint2 xterm dbus-x11 + X11 basics)"

# x11-xserver-utils: xsetroot used by xstartup.
# xfonts-base / fonts-dejavu-core: Raspberry Pi OS Lite ships no X11
# fonts at all; without them xterm aborts with "unable to load font".
PKGS=(
    tigervnc-standalone-server openbox tint2 xterm dbus-x11
    x11-xserver-utils xfonts-base fonts-dejavu-core
)

VNC_BIN=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null || true)

MISSING=()
for p in "${PKGS[@]}"; do
    dpkg -s "$p" >/dev/null 2>&1 || MISSING+=("$p")
done

if [ -n "$VNC_BIN" ] && command -v openbox >/dev/null 2>&1 && [ ${#MISSING[@]} -eq 0 ]; then
    skip "packages already installed (VNC: $VNC_BIN)"
else
    echo "    Installing: ${MISSING[*]}"
    sudo apt-get update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING[@]}" \
        || fail "apt-get install failed"
    ok "packages installed"
fi

VNC_BIN=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null || true)
[ -n "$VNC_BIN" ]                     || fail "Xvnc/Xtigervnc not found after install"
command -v openbox >/dev/null 2>&1    || fail "openbox not found after install"

# ─────────────────────────────────────────────────────────────
# STEP 2 — websockify (pure-Python, no numpy)
# ─────────────────────────────────────────────────────────────
step "2/10 websockify"

if python3 -c "import websockify" 2>/dev/null; then
    skip "websockify already installed"
else
    # Prefer the Debian package (arm64 wheel, no compilation). Fallback to
    # pip: --break-system-packages for PEP 668, --no-deps so pip does not
    # try to build numpy on the Pi (hours of native compilation).
    sudo apt-get install -y python3-websockify 2>/dev/null \
        || pip3 install --break-system-packages --no-deps websockify \
        || fail "websockify install failed"
    python3 -c "import websockify" 2>/dev/null \
        || fail "websockify installed but import failed"
    ok "websockify installed"
fi

# ─────────────────────────────────────────────────────────────
# STEP 3 — noVNC v1.5.0
# ─────────────────────────────────────────────────────────────
step "3/10 noVNC static client"

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
step "4/10 VNC passwd (empty)"

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
step "5/10 ~/.vnc/xstartup"

cat > "$HOME/.vnc/xstartup" << 'XSTART'
#!/bin/bash
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
export LANG=C.UTF-8
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
# STEP 6 — systemd linger (runtime dir for the headless session)
# ─────────────────────────────────────────────────────────────
step "6/10 loginctl enable-linger"

# The nodepulse systemd unit runs start-server as User=<you> WITHOUT a
# login session, so pam_systemd never creates /run/user/<uid>. dbus,
# gvfs and xfconf (installed by 02/03) want XDG_RUNTIME_DIR there.
# Lingering makes systemd keep that directory (and a user manager) for
# this account from boot, login or not. The autostart written by 02/03
# also falls back to ~/tmp/xdg-runtime in case this step was refused.
if loginctl show-user "$(id -un)" 2>/dev/null | grep -q '^Linger=yes'; then
    skip "linger already enabled for $(id -un)"
else
    if sudo loginctl enable-linger "$(id -un)" 2>/dev/null; then
        ok "linger enabled for $(id -un) (/run/user/$(id -u) persists across boots)"
    else
        warn "could not enable linger — the desktop falls back to ~/tmp/xdg-runtime"
    fi
fi

# ─────────────────────────────────────────────────────────────
# STEP 7 — start-desktop / stop-desktop
# ─────────────────────────────────────────────────────────────
step "7/10 ~/bin/start-desktop  ~/bin/stop-desktop"

mkdir -p "$HOME/bin"

# Quoted heredoc: everything is resolved at runtime inside the generated script
cat > "$HOME/bin/start-desktop" << 'STARTDESK'
#!/bin/bash
# NodePulse Desktop (Raspberry Pi) — Xvnc + openbox + websockify (also serves noVNC)

UID_SELF="$(id -u)"

VNC_BIN=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null)
[ -z "$VNC_BIN" ] && { echo "[desktop] ERROR: Xvnc/Xtigervnc not found — install tigervnc-standalone-server"; exit 1; }

DISPLAY_NUM=1
VNC_PORT=5901
WS_PORT=6080
# 1280x720@24 is a good compromise for a Pi: a bigger framebuffer costs
# CPU on every screen update (Xvnc renders in software, the VideoCore GPU
# is not involved) and bandwidth on the tunnel.
GEOMETRY="${DESKTOP_GEOMETRY:-1280x720}"
DEPTH="${DESKTOP_DEPTH:-24}"
NOVNC_DIR="$HOME/services/novnc"
LOG_DIR="$HOME/tmp"
mkdir -p "$LOG_DIR"

# X11 socket directory: normally created by systemd-tmpfiles at boot.
# A normal user cannot chmod it, so just make sure it exists.
mkdir -p /tmp/.X11-unix 2>/dev/null

# Clean up previous sessions (only our own processes — shared machine)
pkill -u "$UID_SELF" -f "Xvnc :$DISPLAY_NUM" 2>/dev/null
pkill -u "$UID_SELF" -f "Xtigervnc :$DISPLAY_NUM" 2>/dev/null
pkill -u "$UID_SELF" -f "websockify.*$WS_PORT" 2>/dev/null
rm -f /tmp/.X${DISPLAY_NUM}-lock /tmp/.X11-unix/X${DISPLAY_NUM} 2>/dev/null
sleep 1

# Xvnc (loopback only, no VNC auth — protected by auth_gate)
"$VNC_BIN" :$DISPLAY_NUM \
    -geometry "$GEOMETRY" \
    -depth "$DEPTH" \
    -rfbport $VNC_PORT \
    -localhost \
    -SecurityTypes None \
    -desktop "NodePulse" \
    > "$LOG_DIR/xvnc.log" 2>&1 &
sleep 2

# Openbox session
DISPLAY=:$DISPLAY_NUM "$HOME/.vnc/xstartup" > "$LOG_DIR/xstartup.log" 2>&1 &
sleep 1

# websockify + static noVNC (apt package ships /usr/bin/websockify;
# the pip fallback only provides the module)
if command -v websockify >/dev/null 2>&1; then
    websockify --web="$NOVNC_DIR" $WS_PORT 127.0.0.1:$VNC_PORT \
        > "$LOG_DIR/websockify.log" 2>&1 &
else
    python3 -m websockify --web="$NOVNC_DIR" $WS_PORT 127.0.0.1:$VNC_PORT \
        > "$LOG_DIR/websockify.log" 2>&1 &
fi

echo "[desktop] $VNC_BIN :$DISPLAY_NUM ($GEOMETRY@$DEPTH)  |  websockify :$WS_PORT  |  noVNC $NOVNC_DIR"
echo "[desktop] log: $LOG_DIR/{xvnc,xstartup,websockify}.log"
STARTDESK

cat > "$HOME/bin/stop-desktop" << 'STOPDESK'
#!/bin/bash
# NodePulse Desktop (Raspberry Pi) — stop the whole X session.
# Every pkill is scoped to our uid: the system dbus-daemon runs as
# 'messagebus' and other users' sessions must not be touched.
UID_SELF="$(id -u)"
pkill -u "$UID_SELF" -f "Xvnc :1" 2>/dev/null
pkill -u "$UID_SELF" -f "Xtigervnc :1" 2>/dev/null
pkill -u "$UID_SELF" -f "websockify.*6080" 2>/dev/null
pkill -u "$UID_SELF" -f "openbox" 2>/dev/null
pkill -u "$UID_SELF" -f "tint2" 2>/dev/null
# Kill desktop session helpers and any orphan session buses: stale
# dbus-daemon/gvfsd instances make xfdesktop attach to a dead bus on the
# next start (GIO unreachable -> degraded right-click menu, pcmanfm crash).
pkill -u "$UID_SELF" -f "xfdesktop" 2>/dev/null
pkill -u "$UID_SELF" -f "xfsettingsd" 2>/dev/null
pkill -u "$UID_SELF" -f "xfconfd" 2>/dev/null
pkill -u "$UID_SELF" -f "thunar" 2>/dev/null
pkill -u "$UID_SELF" -f "gvfsd" 2>/dev/null
pkill -u "$UID_SELF" -f "dunst" 2>/dev/null
pkill -u "$UID_SELF" -f "dbus-daemon --sh-syntax" 2>/dev/null
pkill -u "$UID_SELF" -f "dbus-launch" 2>/dev/null
rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null
echo "[desktop] stopped"
STOPDESK

chmod +x "$HOME/bin/start-desktop" "$HOME/bin/stop-desktop"
ok "start-desktop and stop-desktop written"

# ─────────────────────────────────────────────────────────────
# STEP 8 — nginx: /desktop/ locations + real-scheme fix
# ─────────────────────────────────────────────────────────────
step "8/10 nginx: location /desktop/ + real-scheme fix"

if grep -q 'location = /desktop/' "$NGINX_CONF"; then
    skip "nginx desktop location blocks already present"
else
    cp "$NGINX_CONF" "$NGINX_CONF.bak.desktop"

    # The conf on disk has literal nginx variables ($host etc.) — Python
    # writes them verbatim. Inserted INSIDE the server block, right before
    # the catch-all "location / {" written by rpi-setup.sh.
    #
    # $redirect_host: a Pi is usually reached BOTH through the tunnel
    # (https://xxx.trycloudflare.com) and directly on the LAN
    # (http://raspberrypi.local:8080). Tunnel requests carry
    # X-Forwarded-Proto -> use $host (public name, no port); LAN requests
    # do not -> use $http_host so the :8080 port survives the redirect.
    python3 - "$NGINX_CONF" << 'PYEOF'
import re, sys

conf = sys.argv[1]

with open(conf) as f:
    lines = f.readlines()

EXACT_BLOCK = '''\
        # Cloudflare Tunnel speaks plain HTTP to nginx; read real scheme from X-Forwarded-Proto.
        # Tunnel requests always carry X-Forwarded-Proto (added by cloudflared) -> $host
        # (public hostname, never has a port). Direct LAN access (raspberrypi.local:8080)
        # does not -> $http_host, so redirects keep the :8080 port.
        set $real_scheme $scheme;
        set $redirect_host $http_host;
        if ($http_x_forwarded_proto = "https") {
            set $real_scheme "https";
            set $redirect_host $host;
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
            return 302 $real_scheme://$redirect_host/desktop/vnc.html?path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1;
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
            return 302 $real_scheme://$redirect_host/cli-login.php;
        }

'''

inserted = False
for i, line in enumerate(lines):
    if re.match(r'\s+location\s+/\s*\{', line):
        lines.insert(i, EXACT_BLOCK)
        inserted = True
        break
if not inserted:
    print("ERROR: cannot find 'location / {' catch-all in nginx.conf", file=sys.stderr)
    sys.exit(1)

content = ''.join(lines)

# Fix $scheme://$http_host -> $real_scheme://$redirect_host in all return
# directives (the @cli_login redirect written by rpi-setup.sh): behind the
# tunnel $scheme is "http" and $http_host may carry :8080.
content = re.sub(r'\$scheme://\$http_host', r'$real_scheme://$redirect_host', content)

with open(conf, 'w') as f:
    f.write(content)

print("added desktop block + real-scheme fix")
PYEOF
    [ $? -eq 0 ] || { cp "$NGINX_CONF.bak.desktop" "$NGINX_CONF"; fail "nginx conf injection failed — backup restored"; }

    nginx_test > /dev/null 2>&1 || {
        nginx_test
        cp "$NGINX_CONF.bak.desktop" "$NGINX_CONF"
        fail "nginx config test failed — backup restored ($NGINX_CONF.bak.desktop)"
    }
    nginx_reload > /dev/null 2>&1 || true   # not running yet is fine
    ok "nginx desktop location blocks added"
fi

# ─────────────────────────────────────────────────────────────
# STEP 9 — Integrate with start-server / stop-server
# ─────────────────────────────────────────────────────────────
step "9/10 start-server / stop-server integration"

# ── start-server: call start-desktop BEFORE the nodepulse block ──
# start-server is also what the nodepulse systemd unit runs, so the
# desktop comes up at boot together with the rest of the node.
if grep -q 'start-desktop' "$HOME/bin/start-server"; then
    skip "start-desktop already integrated in start-server"
else
    python3 - "$HOME/bin/start-server" << 'PYEOF'
import sys

path = sys.argv[1]
with open(path) as f:
    lines = f.readlines()

INSERT = (
    '# Start desktop (Xvnc + openbox + websockify) — must run before nodepulse blocks\n'
    'bash "$HOME/bin/start-desktop"\n'
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
    [ $? -eq 0 ] || fail "start-server integration failed"
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

INSERT = 'bash "$HOME/bin/stop-desktop"\n\n'

markers = ['pm2 stop', 'pkill -u "$UID_SELF" cloudflared', 'pkill cloudflared', 'pkill -f nodepulse']
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
# STEP 10 — Dashboard card (~/www/desktop/)
# ─────────────────────────────────────────────────────────────
step "10/10 Dashboard card ~/www/desktop/"

mkdir -p "$HOME/www/desktop"

cat > "$HOME/www/desktop/index.php" << 'PHPEOF'
<?php
// NodePulse Desktop — redirect to noVNC.
// Uses an absolute Location header (PHP only rewrites relative URLs to http://host:8080/...).
// Tunnel requests carry X-Forwarded-Proto (added by cloudflared):
//   tunnel -> https, strip any backend port from the host
//   LAN    -> http,  KEEP the port (raspberrypi.local:8080)
$fwd  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
if ($fwd !== null) {
    $proto = $fwd;
    $host  = strtok($host, ':'); // strip :8080 (nginx backend port)
} else {
    $proto = 'http';
}
$qs = 'path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1';
header("Location: {$proto}://{$host}/desktop/vnc.html?{$qs}", true, 302);
exit;
PHPEOF
ok "index.php written (LAN-aware redirect: https via tunnel, http://host:8080 direct)"

# ─────────────────────────────────────────────────────────────
# Final verification
# ─────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Verification ──${NC}"

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

VNC_BIN_CHECK=$(command -v Xvnc 2>/dev/null || command -v Xtigervnc 2>/dev/null || true)

check "Xvnc/Xtigervnc binary"    test -n "$VNC_BIN_CHECK"
check "openbox binary"           command -v openbox
check "xsetroot binary"          command -v xsetroot
check "websockify importable"    python3 -c "import websockify"
check "noVNC vnc.html"           test -f "$NOVNC_DIR/vnc.html"
check "noVNC index.html link"    test -L "$NOVNC_DIR/index.html"
check "~/.vnc/passwd"            test -f "$HOME/.vnc/passwd"
check "~/.vnc/xstartup (+x)"     test -x "$HOME/.vnc/xstartup"
check "start-desktop (+x)"       test -x "$HOME/bin/start-desktop"
check "stop-desktop (+x)"        test -x "$HOME/bin/stop-desktop"
check "nginx exact-match"        grep -q 'location = /desktop/' "$NGINX_CONF"
check "nginx prefix-match"       grep -q 'location /desktop/' "$NGINX_CONF"
check "nginx redirect_host"      grep -q 'redirect_host' "$NGINX_CONF"
check "nginx config valid"       nginx_test
check "start-server integrated"  grep -q 'start-desktop' "$HOME/bin/start-server"
check "stop-server integrated"   grep -q 'stop-desktop' "$HOME/bin/stop-server"
check "www/desktop/ dir"         test -d "$HOME/www/desktop"

echo ""
if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed.${NC}"
    echo ""
    echo "Apply the change by restarting the node:"
    echo "  by hand:        stop-server && start-server"
    echo "  under systemd:  sudo systemctl restart nodepulse"
    echo "Then open:  https://<tunnel>.trycloudflare.com/desktop/"
    echo "  (LAN:     http://raspberrypi.local:8080/desktop/)"
    echo "Logs:       ~/tmp/{xvnc,xstartup,websockify}.log"
    echo "To set up a basic desktop configuration, execute 02-configure_desktop_raspberry.sh."
else
    echo -e "${RED}${BOLD}$ERRORS check(s) failed — review output above.${NC}"
    exit 1
fi
