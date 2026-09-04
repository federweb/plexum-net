#!/bin/bash

#============================================================
# SETUP: PHP + PYTHON + WEB SERVER + CLOUDFLARE TUNNEL
# For Raspberry Pi OS 64-bit (Debian bookworm+) on Pi 3/4/5
#
# Differences from wsl2-setup.sh:
#   - runs as a NORMAL user with sudo (not root)
#   - nginx runs unprivileged with its own prefix in ~/nginx/
#     (/etc/nginx is never modified, the distro service is disabled)
#   - cloudflared binary picked by architecture (arm64 / arm)
#   - no /etc/wsl.conf; boot persistence via systemd unit instead
#   - apt failures are fatal and logged instead of silenced
#
# PHP FILES: ~/www/
# START:     start-server
# STOP:      stop-server
# STATUS:    server-status
#============================================================

set -o pipefail

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

WWW_DIR="$HOME/www"
TMP_DIR="$HOME/tmp"
BIN_DIR="$HOME/bin"
NGINX_PREFIX="$HOME/nginx"
MODE_FILE="$HOME/.server-mode"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APT_LOG="$TMP_DIR/apt-install.log"

ok()   { echo -e "${GREEN}[OK] $1${NC}"; }
fail() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }
info() { echo -e "${YELLOW}[...] $1${NC}"; }
warn() { echo -e "${YELLOW}[WARN] $1${NC}"; }

echo ""
echo "============================================"
echo "  PHP + CLOUDFLARE SETUP FOR RASPBERRY PI"
echo "============================================"
echo ""

# 0. PREFLIGHT
info "Preflight checks..."

# 0a. Must not be root: pm2, npm and the identity all live in a user home,
# and the web shell apps would otherwise run with full root privileges.
if [ "$(id -u)" -eq 0 ] && [ "$ALLOW_ROOT" != "1" ]; then
    fail "Do not run this as root. Log in as your normal user (e.g. 'pi') and
        run: bash rpi-setup.sh
        (set ALLOW_ROOT=1 to override, not recommended)"
fi

# 0b. sudo is required for apt, php.ini and the systemd unit
command -v sudo >/dev/null 2>&1 || fail "sudo not found — install it first: su -c 'apt install sudo'"
sudo -v || fail "sudo authentication failed"

# 0c. Architecture -> cloudflared asset name
UNAME_M="$(uname -m)"
case "$UNAME_M" in
    aarch64|arm64) CF_ARCH="arm64" ;;
    armv7l|armv6l) CF_ARCH="arm"   ;;
    x86_64|amd64)  CF_ARCH="amd64" ;;
    *) fail "Unsupported architecture: $UNAME_M" ;;
esac
ok "Architecture: $UNAME_M -> cloudflared-linux-$CF_ARCH"

if [ "$CF_ARCH" = "arm" ]; then
    warn "32-bit userland detected. NodePulse targets Raspberry Pi OS 64-bit;"
    warn "PHP and cloudflared will work but this combination is untested."
fi

# 0d. Debian-family check
[ -f /etc/debian_version ] || fail "Not a Debian-based system — this installer expects Raspberry Pi OS"
ok "Debian base: $(cat /etc/debian_version)"

mkdir -p "$TMP_DIR"

# 1. UPDATE AND PACKAGE INSTALLATION
info "Updating package lists (this can take a minute)..."
sudo apt-get update -y > "$APT_LOG" 2>&1 || {
    tail -n 15 "$APT_LOG"
    fail "apt-get update failed — see $APT_LOG"
}
ok "Package lists updated"

# Package notes:
#   - php-cli/php-cgi explicitly instead of the 'php' metapackage: on Debian
#     'php' drags in apache2, which would grab port 80 on the Pi.
#   - no php-fileinfo: fileinfo is built into php-cli on Debian, and an
#     unknown package name aborts the whole apt transaction.
#   - nginx-full (not nginx-light): guarantees http_auth_request_module,
#     which PulseTerminal's /cli/ gate depends on.
PKGS=(
    php-cli php-cgi php-curl php-mbstring php-intl php-zip php-xml
    nginx-full
    curl unzip openssl
    python3 python3-pip
    tmux
    nodejs npm
)

info "Installing packages: ${PKGS[*]}"
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "${PKGS[@]}" >> "$APT_LOG" 2>&1 || {
    tail -n 25 "$APT_LOG"
    fail "Package installation failed — see $APT_LOG"
}
ok "Packages installed"

# Verify every binary we actually depend on (apt can 'succeed' partially)
for bin in php php-cgi curl unzip openssl python3 tmux node npm; do
    command -v "$bin" >/dev/null 2>&1 || fail "Missing binary after install: $bin (see $APT_LOG)"
done

# nginx lives in /usr/sbin, which is not on a normal user's PATH on Debian
NGINX_BIN="$(command -v nginx 2>/dev/null)"
[ -z "$NGINX_BIN" ] && [ -x /usr/sbin/nginx ] && NGINX_BIN="/usr/sbin/nginx"
[ -z "$NGINX_BIN" ] && fail "nginx binary not found"
ok "Binaries verified (nginx: $NGINX_BIN)"

if ! "$NGINX_BIN" -V 2>&1 | grep -q -- '--with-http_auth_request_module'; then
    warn "This nginx lacks http_auth_request_module — PulseTerminal (/cli/) will not be gated."
    warn "Everything else works. Install nginx-full to fix it."
fi

info "Installing aiohttp for PulseTerminal..."
pip3 install --break-system-packages -q aiohttp >> "$APT_LOG" 2>&1 || \
    pip3 install -q aiohttp >> "$APT_LOG" 2>&1
python3 -c "import aiohttp" 2>/dev/null && ok "aiohttp OK" || fail "aiohttp install failed — see $APT_LOG"

# 2. DIRECTORIES
mkdir -p "$WWW_DIR" "$TMP_DIR" "$BIN_DIR"
mkdir -p "$NGINX_PREFIX/logs" "$NGINX_PREFIX/tmp"
ok "Directories: ~/www ~/tmp ~/bin ~/nginx"

# 3. DISABLE THE DISTRO NGINX SERVICE
# Raspberry Pi OS runs nginx under systemd on port 80 as www-data.
# We run our own unprivileged instance on 8080, so the packaged one must go
# or the two masters fight over pid files and logs.
info "Disabling the system-wide nginx service..."
sudo systemctl disable --now nginx > /dev/null 2>&1
ok "System nginx service disabled (our instance runs unprivileged on :8080)"

# 3b. Port 8080 must be free
if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ':8080 '; then
    fail "Port 8080 is already in use — free it before continuing (ss -ltnp | grep 8080)"
fi

# 4. CLOUDFLARED (architecture-matched Linux binary)
if [ ! -f "$BIN_DIR/cloudflared" ]; then
    info "Downloading cloudflared (linux-$CF_ARCH)..."
    curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-$CF_ARCH" \
        -o "$BIN_DIR/cloudflared" || fail "cloudflared download failed"
    chmod +x "$BIN_DIR/cloudflared"
    "$BIN_DIR/cloudflared" --version > /dev/null 2>&1 || \
        fail "Downloaded cloudflared does not run on this architecture ($UNAME_M)"
    ok "cloudflared installed: $("$BIN_DIR/cloudflared" --version 2>/dev/null | head -n1)"
else
    ok "cloudflared already installed"
fi

# 5. DOWNLOAD APPS PROJECT
if [ ! -f "$WWW_DIR/index.php" ]; then
    info "Downloading apps.zip..."
    curl -fL -o "$TMP_DIR/apps.zip" "https://www.plexum.net/nodepulse/core-dist/apps.zip" 2>/dev/null \
        || fail "apps.zip download failed"
    ok "Download complete"
    info "Extracting to ~/www/..."
    unzip -o "$TMP_DIR/apps.zip" -d "$WWW_DIR" > /dev/null 2>&1 \
        || fail "Error extracting apps.zip"
    ok "Project extracted to ~/www/"
    rm -f "$TMP_DIR/apps.zip"
else
    ok "~/www/index.php already exists, skipping download"
fi

# 6. CONFIGURE PHP.INI (php-cgi SAPI)
info "Configuring php.ini..."
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
PHP_INI_DIR="/etc/php/$PHP_VER/cgi/conf.d"
sudo mkdir -p "$PHP_INI_DIR"
# memory_limit is lower than the WSL2 fork: a Pi 4 has 2-8GB shared with
# everything else, and php-cgi children are spawned per request.
sudo tee "$PHP_INI_DIR/nodepulse.ini" > /dev/null << 'PHPINI'
opcache.enable=0
opcache.enable_cli=0
upload_max_filesize=900M
post_max_size=950M
memory_limit=512M
max_execution_time=600
max_input_time=600
PHPINI
ok "php.ini configured (php$PHP_VER, opcache=off, upload=900M, mem=512M)"

# 7. CONFIGURE NGINX (unprivileged, own prefix)
info "Configuring nginx (unprivileged, prefix ~/nginx)..."
WWW_DIR_ABS="$(realpath "$WWW_DIR")"
NGINX_PREFIX_ABS="$(realpath "$NGINX_PREFIX")"

# No 'user' directive: the master runs as us, so nginx cannot drop privileges
# and would only emit a warning. All writable paths are redirected into the
# prefix, otherwise nginx tries /var/lib/nginx and /var/log/nginx and dies.
cat > "$NGINX_PREFIX/nginx.conf" << NCONF
worker_processes 1;
daemon on;
pid $NGINX_PREFIX_ABS/nginx.pid;
error_log $NGINX_PREFIX_ABS/logs/error.log warn;

events {
    worker_connections 512;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    access_log $NGINX_PREFIX_ABS/logs/access.log;

    client_body_temp_path $NGINX_PREFIX_ABS/tmp/client_body;
    proxy_temp_path       $NGINX_PREFIX_ABS/tmp/proxy;
    fastcgi_temp_path     $NGINX_PREFIX_ABS/tmp/fastcgi;
    uwsgi_temp_path       $NGINX_PREFIX_ABS/tmp/uwsgi;
    scgi_temp_path        $NGINX_PREFIX_ABS/tmp/scgi;

    sendfile on;
    keepalive_timeout 65;

    server {
        listen 8080;
        server_name localhost;
        # Behind cloudflared: emit relative Location headers so the internal
        # port never leaks into redirects (e.g. /beacon -> :8080/beacon/).
        # Local access via 127.0.0.1:8080 keeps working: the browser resolves
        # the relative path against its own origin, port included.
        absolute_redirect off;
        root $WWW_DIR_ABS;
        index index.php index.html;

        client_max_body_size 950M;

        # PulseTerminal auth gate (internal)
        location = /cli-auth.php {
            internal;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME \$document_root/cli-auth.php;
            fastcgi_pass_request_body off;
            fastcgi_param CONTENT_LENGTH "";
            include /etc/nginx/fastcgi_params;
        }

        # PulseTerminal WebSocket proxy, gated by auth_gate
        location /cli/ {
            auth_request /cli-auth.php;
            error_page 401 = @cli_login;

            proxy_pass http://127.0.0.1:7681/;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
            proxy_buffering off;
        }

        location @cli_login {
            return 302 \$scheme://\$http_host/cli-login.php;
        }

        # PeerJS WebSocket + HTTP signaling proxy (meet/)
        location /peerjs/ {
            proxy_pass http://127.0.0.1:9001/peerjs/;
            proxy_http_version 1.1;
            proxy_set_header Upgrade \$http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
            proxy_buffering off;
        }

        location / {
            try_files \$uri \$uri/ /index.php?\$query_string;
        }

        location ~ \.php\$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            include /etc/nginx/fastcgi_params;
        }
    }
}
NCONF

# Record the binary so start-server/stop-server do not have to re-resolve it
echo "$NGINX_BIN" > "$HOME/.nginx-bin"

"$NGINX_BIN" -t -c "$NGINX_PREFIX_ABS/nginx.conf" -p "$NGINX_PREFIX_ABS/" > /dev/null 2>&1 \
    || { "$NGINX_BIN" -t -c "$NGINX_PREFIX_ABS/nginx.conf" -p "$NGINX_PREFIX_ABS/"; fail "nginx config test failed"; }
ok "nginx configured (port 8080, /cli/ -> 7681, prefix ~/nginx)"

# 8. TEST nginx + php-cgi
info "Testing nginx + php-cgi..."
"$NGINX_BIN" -s stop -c "$NGINX_PREFIX_ABS/nginx.conf" -p "$NGINX_PREFIX_ABS/" 2>/dev/null
pkill -u "$(id -u)" php-cgi 2>/dev/null
sleep 1

echo '<?php echo "PHP_OK"; ?>' > "$WWW_DIR/_phptest.php"

PHP_FCGI_CHILDREN=2 PHP_FCGI_MAX_REQUESTS=1000 php-cgi -d opcache.enable=0 -b 127.0.0.1:9000 &
PHPCGI_PID=$!
sleep 1

"$NGINX_BIN" -c "$NGINX_PREFIX_ABS/nginx.conf" -p "$NGINX_PREFIX_ABS/"
sleep 2

PHPTEST=$(curl -s http://127.0.0.1:8080/_phptest.php 2>/dev/null)
if [ "$PHPTEST" = "PHP_OK" ]; then
    echo "nginx" > "$MODE_FILE"
    ok "nginx + php-cgi works!"
else
    echo "--- nginx error log ---"
    tail -n 10 "$NGINX_PREFIX/logs/error.log" 2>/dev/null
    fail "PHP execution failed (HTTP: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/_phptest.php))"
fi

"$NGINX_BIN" -s stop -c "$NGINX_PREFIX_ABS/nginx.conf" -p "$NGINX_PREFIX_ABS/" 2>/dev/null
kill $PHPCGI_PID 2>/dev/null
pkill -u "$(id -u)" php-cgi 2>/dev/null
rm -f "$WWW_DIR/_phptest.php"

# 9. GENERATE NODEPULSE IDENTITY
NP_IDENTITY="$HOME/.nodepulse"
if [ ! -f "$NP_IDENTITY/private.pem" ]; then
    info "Generating NodePulse identity (RSA-2048)..."
    mkdir -p "$NP_IDENTITY"

    openssl genpkey -algorithm RSA -out "$NP_IDENTITY/private.pem" \
        -pkeyopt rsa_keygen_bits:2048 2>/dev/null
    [ ! -f "$NP_IDENTITY/private.pem" ] && fail "Failed to generate RSA key pair"

    openssl rsa -in "$NP_IDENTITY/private.pem" -pubout \
        -out "$NP_IDENTITY/public.pem" 2>/dev/null
    [ ! -f "$NP_IDENTITY/public.pem" ] && fail "Failed to extract public key"

    chmod 600 "$NP_IDENTITY/private.pem"

    NODE_ID=$(openssl rsa -in "$NP_IDENTITY/public.pem" -pubin -outform DER 2>/dev/null \
        | openssl dgst -sha256 -hex | awk '{print $NF}' | cut -c1-12)
    [ -z "$NODE_ID" ] || [ ${#NODE_ID} -ne 12 ] && fail "Failed to compute node_id"

    echo "$NODE_ID" > "$NP_IDENTITY/node_id"

    CREATED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    php -d opcache.enable=0 -r "
        \$pk = file_get_contents('$NP_IDENTITY/public.pem');
        \$id = array(
            'node_id'    => '$NODE_ID',
            'type'       => 'tunnel',
            'public_key' => \$pk,
            'created_at' => '$CREATED_AT',
            'version'    => '1.0.0',
            'platform'   => 'rpi'
        );
        file_put_contents('$NP_IDENTITY/node_identity.json', json_encode(\$id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    "

    if [ -d "$WWW_DIR/nodepulse" ]; then
        php -d opcache.enable=0 -r "
            \$pk = file_get_contents('$NP_IDENTITY/public.pem');
            \$id = array(
                'node_id'    => '$NODE_ID',
                'type'       => 'tunnel',
                'public_key' => \$pk,
                'created_at' => '$CREATED_AT',
                'version'    => '1.0.0',
                'platform'   => 'rpi'
            );
            file_put_contents('$WWW_DIR/nodepulse/node_identity.json', json_encode(\$id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            \$cfg = array(
                'node_id'                => '$NODE_ID',
                'gossip_interval_sec'    => 300,
                'heartbeat_interval_sec' => 60,
                'max_peers'              => 50,
                'ttl_hours'              => 24,
                'serve_downloads'        => true,
                'auto_update'            => true,
                'log_level'              => 'info'
            );
            file_put_contents('$WWW_DIR/nodepulse/node_config.json', json_encode(\$cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        "
    fi

    ok "Identity generated: node_id=$NODE_ID"
else
    NODE_ID=$(cat "$NP_IDENTITY/node_id" 2>/dev/null)
    ok "Identity already exists: node_id=$NODE_ID"
fi

# 10. SETUP PEERSERVER (PeerJS signaling for meet/)
# Installed in ~/services/peerserver/ — outside the web root to avoid
# serving node_modules/ files over HTTP.
info "Setting up PeerServer (PeerJS signaling for meet/)..."

# Global npm installs need sudo unless a user prefix is configured
sudo npm install -g pm2 > /dev/null 2>&1
PM2_BIN="$(command -v pm2 2>/dev/null)"
[ -z "$PM2_BIN" ] && [ -x /usr/local/bin/pm2 ] && PM2_BIN="/usr/local/bin/pm2"
if [ -n "$PM2_BIN" ]; then
    ok "pm2 installed globally"
else
    warn "pm2 install failed — meet/ signaling will be unavailable, everything else works"
fi

PEERSERVER_DIR="$HOME/services/peerserver"
mkdir -p "$PEERSERVER_DIR"

# Write server.js only if not already present (preserve customizations)
if [ ! -f "$PEERSERVER_DIR/server.js" ]; then
    cat > "$PEERSERVER_DIR/server.js" << 'PEERJS'
const { PeerServer } = require('peer');

const port = process.env.PEER_PORT ? parseInt(process.env.PEER_PORT) : 9001;
const path = process.env.PEER_PATH || '/peerjs';

// Loopback-only, like every other backend service in this stack — nginx is
// the only front door. Without this, PeerServer defaults to 0.0.0.0.
const server = PeerServer({ port, path, host: '127.0.0.1' });

server.on('connection', (client) => {
  console.log(`[peerjs] connected: ${client.getId()}`);
});

server.on('disconnect', (client) => {
  console.log(`[peerjs] disconnected: ${client.getId()}`);
});

console.log(`PeerServer listening on port ${port}, path ${path}`);
PEERJS
fi

# Install peer npm package (slow on a Pi — several minutes on first run)
cd "$PEERSERVER_DIR"
if [ ! -d "$PEERSERVER_DIR/node_modules/peer" ]; then
    info "Installing the 'peer' npm package (slow on a Pi, be patient)..."
    npm init -y > /dev/null 2>&1
    npm install peer > /dev/null 2>&1 \
        && ok "peer npm package installed in ~/services/peerserver/" \
        || warn "npm install peer failed — meet/ signaling unavailable"
else
    ok "peer npm package already installed"
fi
cd "$HOME"

# No 'pm2 startup': the nodepulse systemd unit calls start-server, which
# starts peerserver itself. Two boot paths would race for port 9001.

# 11. INSTALL SCRIPTS
info "Installing scripts..."
for script in start-server stop-server server-status run-looped; do
    [ ! -f "$SCRIPT_DIR/$script" ] && fail "Missing file: $SCRIPT_DIR/$script"
    cp "$SCRIPT_DIR/$script" "$BIN_DIR/$script"
    chmod +x "$BIN_DIR/$script"
done
[ ! -f "$SCRIPT_DIR/nodepulse.sh" ] && fail "Missing file: $SCRIPT_DIR/nodepulse.sh"
cp "$SCRIPT_DIR/nodepulse.sh" "$BIN_DIR/nodepulse"
chmod +x "$BIN_DIR/nodepulse"
ok "Scripts installed: start-server, stop-server, server-status, run-looped, nodepulse"

# 12. PULSETERMINAL (cli/)
info "Installing PulseTerminal (CLI over WS)..."
if [ ! -f "$WWW_DIR/cli/server.py" ] || [ ! -f "$WWW_DIR/cli/terminal.html" ]; then
    if [ -f "$SCRIPT_DIR/../cli/server.py" ]; then
        mkdir -p "$WWW_DIR/cli"
        cp "$SCRIPT_DIR/../cli/"*.py "$WWW_DIR/cli/" 2>/dev/null
        cp "$SCRIPT_DIR/../cli/"*.html "$WWW_DIR/cli/" 2>/dev/null
        ok "Copied cli/ from repo"
    else
        fail "PulseTerminal files not found in ~/www/cli/ — check apps.zip"
    fi
fi

for f in cli-auth.php cli-login.php; do
    if [ ! -f "$WWW_DIR/$f" ]; then
        [ -f "$SCRIPT_DIR/../$f" ] && cp "$SCRIPT_DIR/../$f" "$WWW_DIR/$f" || fail "Missing $f"
    fi
done
ok "PulseTerminal ready (~/www/cli/, gated via auth_gate, nginx -> /cli/)"

# 13. TERMINAL SESSION DIRECTORIES
mkdir -p "$TMP_DIR/.terminal" "$TMP_DIR/.sessions"

# 14. PATH
if ! grep -q 'export PATH="$HOME/bin:$PATH"' "$HOME/.bashrc" 2>/dev/null; then
    echo 'export PATH="$HOME/bin:$PATH"' >> "$HOME/.bashrc"
fi
export PATH="$HOME/bin:$PATH"
ok "~/bin added to PATH"

# 15. SYSTEMD UNIT (installed but not enabled)
# This is what a Pi buys you over Termux/WSL2: a node that survives reboots
# and restarts itself if the whole stack dies.
info "Installing systemd unit..."
if [ -f "$SCRIPT_DIR/nodepulse.service" ]; then
    sed -e "s|%USER%|$(id -un)|g" \
        -e "s|%GROUP%|$(id -gn)|g" \
        -e "s|%HOME%|$HOME|g" \
        "$SCRIPT_DIR/nodepulse.service" | sudo tee /etc/systemd/system/nodepulse.service > /dev/null
    sudo systemctl daemon-reload
    ok "systemd unit installed (not enabled — see summary below)"
else
    warn "nodepulse.service template not found, skipping boot persistence"
fi

# 16. SUMMARY
echo ""
echo "============================================"
echo "  SETUP COMPLETE! (Raspberry Pi)"
echo "============================================"
echo ""
echo "  NodePulse identity:"
echo "    node_id  — $(cat "$HOME/.nodepulse/node_id" 2>/dev/null)"
echo "    keys     — ~/.nodepulse/"
echo ""
echo "  PHP files: ~/www/"
echo "  nginx:     unprivileged, prefix ~/nginx/ (logs in ~/nginx/logs/)"
echo ""
echo "  Commands:"
echo "    start-server   — Start nginx + PHP + Cloudflare tunnel"
echo "    stop-server    — Stop everything"
echo "    server-status  — Check service status"
echo ""
echo "  Run at boot (recommended on a Pi):"
echo "    sudo systemctl enable --now nodepulse"
echo "    journalctl -u nodepulse -f      # follow the tunnel URL"
echo ""
echo "  Open a NEW shell (or: source ~/.bashrc) so ~/bin is on PATH."
echo ""
