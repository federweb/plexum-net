#!/bin/bash

#============================================================
# DEFINITIVE SETUP: PHP + WEB SERVER + CLOUDFLARE TUNNEL
# For macOS (Apple Silicon & Intel) via Homebrew
#
# macOS is native BSD Unix: no cygpath (unlike MSYS2),
# no SELinux (unlike Termux). This is the cleanest of the
# three platforms. Stack:
#   - Homebrew  : package manager
#   - php       : php + php-cgi (FastCGI on 127.0.0.1:9000)
#   - nginx     : reverse proxy / web server (port 8080)
#   - cloudflared: tunnel
#   - python    : aiohttp for PulseTerminal
#   - node+pm2  : PeerServer (PeerJS signaling for meet/)
#   - tmux      : PulseTerminal sessions
#
# BSD vs GNU notes handled here and in the helper scripts:
#   - grep has no -P / \K        -> sed -nE / awk
#   - base64 has no -w0          -> openssl base64 -A
#   - readlink has no -f         -> cd/pwd
#
# PHP FILES GO IN: ~/www/
# START: start-server   STOP: stop-server   STATUS: server-status
#============================================================

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

WWW_DIR="$HOME/www"
TMP_DIR="$HOME/tmp"
BIN_DIR="$HOME/bin"
NP_DIR="$HOME/nodepulse-bin"        # holds nginx.conf + nginx runtime
MODE_FILE="$HOME/.server-mode"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

ok()   { echo -e "${GREEN}[OK] $1${NC}"; }
fail() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }
info() { echo -e "${YELLOW}[...] $1${NC}"; }

echo ""
echo "============================================"
echo "  PHP + CLOUDFLARE SETUP FOR macOS"
echo "============================================"
echo ""

# 1. HOMEBREW
if ! command -v brew > /dev/null 2>&1; then
    # Try the standard install locations before giving up
    if [ -x /opt/homebrew/bin/brew ]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [ -x /usr/local/bin/brew ]; then
        eval "$(/usr/local/bin/brew shellenv)"
    fi
fi
if ! command -v brew > /dev/null 2>&1; then
    info "Homebrew not found. Installing..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" \
        || fail "Homebrew installation failed"
    if [ -x /opt/homebrew/bin/brew ]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [ -x /usr/local/bin/brew ]; then
        eval "$(/usr/local/bin/brew shellenv)"
    fi
fi
command -v brew > /dev/null 2>&1 || fail "brew still not on PATH"
BREW_PREFIX="$(brew --prefix)"
ok "Homebrew ready ($BREW_PREFIX)"

# 2. INSTALL PACKAGES
info "Installing packages (php nginx cloudflared python tmux node)..."
brew install php nginx cloudflared python tmux node > /dev/null 2>&1
# cloudflared lives in homebrew/core; if the formula name differs, try the cask tap
command -v cloudflared > /dev/null 2>&1 || brew install cloudflare/cloudflare/cloudflared > /dev/null 2>&1
for b in php php-cgi nginx cloudflared python3 tmux node npm; do
    command -v "$b" > /dev/null 2>&1 || fail "Missing binary after install: $b"
done
ok "Packages installed (php $(php -r 'echo PHP_VERSION;'), nginx, cloudflared, python, tmux, node)"

info "Installing Python deps (aiohttp) for PulseTerminal..."
pip3 install --break-system-packages -q aiohttp > /dev/null 2>&1 || \
    pip3 install --break-system-packages aiohttp > /dev/null 2>&1
python3 -c "import aiohttp" 2>/dev/null && ok "aiohttp OK" || fail "aiohttp install failed"

# 3. DIRECTORIES
mkdir -p "$WWW_DIR" "$TMP_DIR" "$BIN_DIR" "$NP_DIR"
ok "Directories created: ~/www ~/tmp ~/bin ~/nodepulse-bin"

# 4. DOWNLOAD APPS PROJECT
if [ ! -f "$WWW_DIR/index.php" ]; then
    info "Downloading apps.zip..."
    curl -L -o "$TMP_DIR/apps.zip" "https://www.plexum.net/nodepulse/core-dist/apps.zip" 2>/dev/null
    if [ -f "$TMP_DIR/apps.zip" ]; then
        ok "Download complete"
        info "Extracting to ~/www/..."
        unzip -o "$TMP_DIR/apps.zip" -d "$WWW_DIR" > /dev/null 2>&1
        if [ $? -eq 0 ]; then
            ok "Project extracted to ~/www/"
            rm -f "$TMP_DIR/apps.zip"
        else
            fail "Error extracting apps.zip"
        fi
    else
        fail "apps.zip download failed"
    fi
else
    ok "~/www/index.php already exists, skipping download"
fi

# 5. CONFIGURE PHP (conf.d drop-in — non-intrusive, keeps brew extension autoload)
info "Configuring PHP limits..."
PHP_CONFD="$(php --ini 2>/dev/null | awk -F': ' '/Scan for additional .ini files/ {print $2}' | tr -d ' ')"
if [ -z "$PHP_CONFD" ] || [ ! -d "$PHP_CONFD" ]; then
    # Fallback: derive from brew layout
    PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    PHP_CONFD="$BREW_PREFIX/etc/php/$PHP_VER/conf.d"
fi
mkdir -p "$PHP_CONFD"
cat > "$PHP_CONFD/nodepulse.ini" << 'PHPINI'
; NodePulse overrides
opcache.enable=0
opcache.enable_cli=0
upload_max_filesize=900M
post_max_size=950M
memory_limit=1024M
max_execution_time=600
max_input_time=600
PHPINI
ok "php config written ($PHP_CONFD/nodepulse.ini)"

# 6. CONFIGURE NGINX
# Self-contained conf with absolute pid/log/temp paths so it runs with
# `nginx -c <conf>` regardless of Homebrew's compiled-in prefix.
info "Configuring nginx..."
cat > "$NP_DIR/nginx.conf" << NCONF
worker_processes 1;
pid $NP_DIR/nginx.pid;
error_log $TMP_DIR/nginx-error.log;
events { worker_connections 128; }
http {
    include $BREW_PREFIX/etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log $TMP_DIR/nginx-access.log;
    client_body_temp_path $TMP_DIR/nginx_client_temp;
    proxy_temp_path $TMP_DIR/nginx_proxy_temp;
    fastcgi_temp_path $TMP_DIR/nginx_fastcgi_temp;
    client_max_body_size 950M;

    server {
        listen 8080;
        server_name localhost;
        root $WWW_DIR;
        index index.php index.html;

        # PulseTerminal auth gate — internal auth_request endpoint.
        location = /cli-auth.php {
            internal;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME \$document_root/cli-auth.php;
            fastcgi_pass_request_body off;
            fastcgi_param CONTENT_LENGTH "";
            include $BREW_PREFIX/etc/nginx/fastcgi_params;
        }

        # PulseTerminal — WebSocket proxy to local Python server.
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
            include $BREW_PREFIX/etc/nginx/fastcgi_params;
        }
    }
}
NCONF
ok "nginx configured ($NP_DIR/nginx.conf, port 8080)"

# 7. AUTO-DETECT MODE (nginx only on macOS — no SELinux quirks)
info "Testing nginx + php-cgi..."
nginx -c "$NP_DIR/nginx.conf" -s stop > /dev/null 2>&1
pkill -f "php-cgi" > /dev/null 2>&1
sleep 1

echo '<?php echo "PHP_OK"; ?>' > "$WWW_DIR/_phptest.php"

PHP_FCGI_CHILDREN=4 php-cgi -b 127.0.0.1:9000 > /dev/null 2>&1 &
PHPCGI_PID=$!
sleep 1
nginx -c "$NP_DIR/nginx.conf"
sleep 2

PHPTEST=$(curl -s http://127.0.0.1:8080/_phptest.php 2>/dev/null)
if [ "$PHPTEST" = "PHP_OK" ]; then
    echo "nginx" > "$MODE_FILE"
    ok "nginx + php-cgi works!"
else
    nginx -c "$NP_DIR/nginx.conf" -s stop > /dev/null 2>&1
    kill "$PHPCGI_PID" 2>/dev/null
    rm -f "$WWW_DIR/_phptest.php"
    fail "PHP execution failed. HTTP: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/_phptest.php 2>/dev/null)"
fi

nginx -c "$NP_DIR/nginx.conf" -s stop > /dev/null 2>&1
kill "$PHPCGI_PID" 2>/dev/null
pkill -f "php-cgi" > /dev/null 2>&1
rm -f "$WWW_DIR/_phptest.php"
SERVER_MODE=$(cat "$MODE_FILE")

# 8. GENERATE NODEPULSE IDENTITY
NP_IDENTITY="$HOME/.nodepulse"
if [ ! -f "$NP_IDENTITY/private.pem" ]; then
    info "Generating NodePulse identity (RSA-2048)..."
    mkdir -p "$NP_IDENTITY"

    # genpkey (OpenSSL/recent LibreSSL); fall back to genrsa for older LibreSSL
    openssl genpkey -algorithm RSA -out "$NP_IDENTITY/private.pem" \
        -pkeyopt rsa_keygen_bits:2048 2>/dev/null
    if [ ! -f "$NP_IDENTITY/private.pem" ]; then
        openssl genrsa -out "$NP_IDENTITY/private.pem" 2048 2>/dev/null
    fi
    [ -f "$NP_IDENTITY/private.pem" ] || fail "Failed to generate RSA key pair"

    openssl rsa -in "$NP_IDENTITY/private.pem" -pubout \
        -out "$NP_IDENTITY/public.pem" 2>/dev/null
    [ -f "$NP_IDENTITY/public.pem" ] || fail "Failed to extract public key"
    chmod 600 "$NP_IDENTITY/private.pem"

    # node_id: SHA-256 of DER-encoded public key, first 12 hex chars
    NODE_ID=$(openssl rsa -in "$NP_IDENTITY/public.pem" -pubin -outform DER 2>/dev/null \
        | openssl dgst -sha256 -hex | awk '{print $NF}' | cut -c1-12)
    if [ -z "$NODE_ID" ] || [ ${#NODE_ID} -ne 12 ]; then
        fail "Failed to compute node_id"
    fi
    echo "$NODE_ID" > "$NP_IDENTITY/node_id"

    CREATED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    php -r "
        \$pk = file_get_contents('$NP_IDENTITY/public.pem');
        \$id = array(
            'node_id'    => '$NODE_ID',
            'type'       => 'tunnel',
            'public_key' => \$pk,
            'created_at' => '$CREATED_AT',
            'version'    => '1.0.0',
            'platform'   => 'osx'
        );
        file_put_contents('$NP_IDENTITY/node_identity.json', json_encode(\$id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    "

    # Populate web-facing node_identity.json and node_config.json
    if [ -d "$WWW_DIR/nodepulse" ]; then
        php -r "
            \$pk = file_get_contents('$NP_IDENTITY/public.pem');
            \$id = array(
                'node_id'    => '$NODE_ID',
                'type'       => 'tunnel',
                'public_key' => \$pk,
                'created_at' => '$CREATED_AT',
                'version'    => '1.0.0',
                'platform'   => 'osx'
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

# 9. SETUP PEERSERVER (PeerJS signaling for meet/)
info "Setting up PeerServer (PeerJS signaling for meet/)..."
npm install -g pm2 > /dev/null 2>&1
command -v pm2 > /dev/null 2>&1 && ok "pm2 installed globally" || fail "pm2 install failed"

PEERSERVER_DIR="$HOME/services/peerserver"
mkdir -p "$PEERSERVER_DIR"

if [ ! -f "$PEERSERVER_DIR/server.js" ]; then
    cat > "$PEERSERVER_DIR/server.js" << 'PEERJS'
const { PeerServer } = require('peer');

const port = process.env.PEER_PORT ? parseInt(process.env.PEER_PORT) : 9001;
const path = process.env.PEER_PATH || '/peerjs';

const server = PeerServer({ port, path });

server.on('connection', (client) => {
  console.log(`[peerjs] connected: ${client.getId()}`);
});

server.on('disconnect', (client) => {
  console.log(`[peerjs] disconnected: ${client.getId()}`);
});

console.log(`PeerServer listening on port ${port}, path ${path}`);
PEERJS
fi

cd "$PEERSERVER_DIR"
if [ ! -d "$PEERSERVER_DIR/node_modules/peer" ]; then
    npm init -y > /dev/null 2>&1
    npm install peer > /dev/null 2>&1
    ok "peer npm package installed in ~/services/peerserver/"
else
    ok "peer npm package already installed"
fi

PEER_PORT=9001 pm2 start "$PEERSERVER_DIR/server.js" --name peerserver > /dev/null 2>&1 || \
    pm2 restart peerserver > /dev/null 2>&1
pm2 save > /dev/null 2>&1
ok "PeerServer started via PM2 on port 9001"
cd "$HOME"

# Optional: auto-start PM2 apps at login (prints a sudo command, does not run it)
info "To auto-start PeerServer at login, run the command printed by: pm2 startup"

# 10. INSTALL SCRIPTS FROM REPO
info "Installing scripts..."
for script in start-server stop-server server-status; do
    if [ ! -f "$SCRIPT_DIR/$script" ]; then
        fail "Missing file: $SCRIPT_DIR/$script"
    fi
    cp "$SCRIPT_DIR/$script" "$BIN_DIR/$script"
    chmod +x "$BIN_DIR/$script"
done

if [ ! -f "$SCRIPT_DIR/nodepulse.sh" ]; then
    fail "Missing file: $SCRIPT_DIR/nodepulse.sh"
fi
cp "$SCRIPT_DIR/nodepulse.sh" "$BIN_DIR/nodepulse"
chmod +x "$BIN_DIR/nodepulse"
ok "Scripts installed: start-server, stop-server, server-status, nodepulse"

# 11. INSTALL TERMINAL
info "Installing Terminal..."
if [ ! -f "$WWW_DIR/terminal/index.php" ] || [ ! -f "$WWW_DIR/terminal/daemon.php" ]; then
    fail "Terminal files not found in ~/www/terminal/ — check that apps.zip includes terminal/"
fi
mkdir -p "$TMP_DIR/.terminal" "$TMP_DIR/.sessions"
ok "Terminal ready (~/www/terminal/)"

# 11.5. INSTALL PULSETERMINAL (cli/)
info "Installing PulseTerminal (CLI over WS)..."
if [ ! -f "$WWW_DIR/cli/server.py" ] || [ ! -f "$WWW_DIR/cli/terminal.html" ]; then
    if [ -f "$SCRIPT_DIR/../cli/server.py" ] && [ -f "$SCRIPT_DIR/../cli/terminal.html" ]; then
        mkdir -p "$WWW_DIR/cli"
        cp "$SCRIPT_DIR/../cli/server.py" "$WWW_DIR/cli/server.py"
        cp "$SCRIPT_DIR/../cli/terminal.html" "$WWW_DIR/cli/terminal.html"
        ok "Copied cli/ from repo"
    else
        fail "PulseTerminal files not found in ~/www/cli/ — check apps.zip"
    fi
fi
for f in cli-auth.php cli-login.php; do
    if [ ! -f "$WWW_DIR/$f" ]; then
        if [ -f "$SCRIPT_DIR/../$f" ]; then
            cp "$SCRIPT_DIR/../$f" "$WWW_DIR/$f"
        else
            fail "Missing $f in ~/www/ and $SCRIPT_DIR/../"
        fi
    fi
done
ok "PulseTerminal ready (~/www/cli/, gated via auth_gate, nginx -> /cli/)"

# 12. PATH + brew shellenv in shell rc (zsh is default on macOS)
SHELL_RC="$HOME/.zshrc"
[ -n "$BASH_VERSION" ] && SHELL_RC="$HOME/.bash_profile"
touch "$SHELL_RC"
if ! grep -q 'nodepulse-bin' "$SHELL_RC" 2>/dev/null; then
    {
        echo ""
        echo '# NodePulse'
        echo "eval \"\$($BREW_PREFIX/bin/brew shellenv)\""
        echo 'export PATH="$HOME/bin:$PATH"'
    } >> "$SHELL_RC"
fi
export PATH="$HOME/bin:$PATH"
ok "PATH updated in $SHELL_RC (~/bin + brew shellenv)"

# 13. SUMMARY
echo ""
echo "============================================"
echo "  SETUP COMPLETE!"
echo "  Mode: $SERVER_MODE"
echo "============================================"
echo ""
echo "  Installed via Homebrew:"
echo "    php          - $(php -r 'echo PHP_VERSION;' 2>/dev/null)"
echo "    nginx        - $(nginx -v 2>&1 | sed -nE 's#.*/([0-9.]+).*#\1#p')"
echo "    cloudflared  - Cloudflare tunnel"
echo "    python       - $(python3 --version 2>/dev/null)"
echo "    node / pm2   - PeerServer"
echo ""
echo "  NodePulse identity:"
echo "    node_id      - $(cat "$HOME/.nodepulse/node_id" 2>/dev/null)"
echo "    keys         - ~/.nodepulse/"
echo ""
echo "  Your PHP files go in:  ~/www/"
echo ""
echo "  Available commands (open a new terminal first, or 'source $SHELL_RC'):"
echo "    start-server   - Start php-cgi + nginx + tunnel + NodePulse"
echo "    stop-server    - Stop everything"
echo "    server-status  - Check service status"
echo ""
echo "  Web terminal available at:"
echo "    http://<tunnel-url>/terminal/   (PHP daemon)"
echo "    http://<tunnel-url>/cli/        (PulseTerminal — WS + tmux)"
echo ""
echo "  The public trycloudflare.com link will appear after: start-server"
echo ""
