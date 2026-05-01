#!/bin/bash

#============================================================
# SETUP: PHP + PYTHON + WEB SERVER + CLOUDFLARE TUNNEL
# For WSL2 Ubuntu 24.04 — dedicated distro "NodePulse"
#
# Differences from termux-setup.sh:
#   - apt instead of pkg
#   - nginx only (no lighttpd, no SELinux workaround)
#   - cloudflared: Linux binary from GitHub
#   - /etc/wsl.conf disables C: mount after setup
#   - Runs as root (default for distros imported via wsl --import)
#
# PHP FILES: ~/www/
# START:     start-server
# STOP:      stop-server
# STATUS:    server-status
#============================================================

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

WWW_DIR="$HOME/www"
TMP_DIR="$HOME/tmp"
BIN_DIR="$HOME/bin"
MODE_FILE="$HOME/.server-mode"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

ok()   { echo -e "${GREEN}[OK] $1${NC}"; }
fail() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }
info() { echo -e "${YELLOW}[...] $1${NC}"; }

echo ""
echo "============================================"
echo "  PHP + CLOUDFLARE SETUP FOR WSL2 (NodePulse)"
echo "============================================"
echo ""

# 1. UPDATE AND PACKAGE INSTALLATION
info "Updating package lists..."
apt update -y > /dev/null 2>&1
ok "Package lists updated"

info "Installing packages (php, nginx, nodejs, python3, tmux, curl, openssl)..."
DEBIAN_FRONTEND=noninteractive apt install -y \
    php php-cgi php-curl php-mbstring php-intl php-zip php-fileinfo \
    nginx curl unzip openssl python3 python3-pip tmux \
    nodejs npm \
    > /dev/null 2>&1
ok "Packages installed"

info "Installing aiohttp for PulseTerminal..."
pip3 install --break-system-packages -q aiohttp > /dev/null 2>&1 || \
    pip3 install -q aiohttp
python3 -c "import aiohttp" 2>/dev/null && ok "aiohttp OK" || fail "aiohttp install failed"

# 2. DIRECTORIES
mkdir -p "$WWW_DIR" "$TMP_DIR" "$BIN_DIR"
ok "Directories: ~/www ~/tmp ~/bin"

# 3. CLOUDFLARED (Linux binary)
if [ ! -f "$BIN_DIR/cloudflared" ]; then
    info "Downloading cloudflared (Linux amd64)..."
    curl -sL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64" \
        -o "$BIN_DIR/cloudflared"
    chmod +x "$BIN_DIR/cloudflared"
    ok "cloudflared downloaded"
else
    ok "cloudflared already installed"
fi

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

# 5. CONFIGURE PHP.INI
info "Configuring php.ini..."
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
PHP_INI_DIR="/etc/php/$PHP_VER/cgi/conf.d"
mkdir -p "$PHP_INI_DIR"
cat > "$PHP_INI_DIR/nodepulse.ini" << 'PHPINI'
opcache.enable=0
opcache.enable_cli=0
upload_max_filesize=900M
post_max_size=950M
memory_limit=1024M
max_execution_time=600
max_input_time=600
PHPINI
ok "php.ini configured (php$PHP_VER, opcache=off, upload=900M)"

# 6. CONFIGURE NGINX
info "Configuring nginx..."
# On Ubuntu nginx runs as www-data, but /root/ has 700 permissions.
# Set user root to allow access to ~/www/.
sed -i 's/^user www-data;/user root;/' /etc/nginx/nginx.conf
WWW_DIR_ABS="$(realpath "$WWW_DIR")"
cat > /etc/nginx/sites-available/nodepulse << NCONF
server {
    listen 8080;
    server_name localhost;
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
        include fastcgi_params;
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
        include fastcgi_params;
    }
}
NCONF

ln -sf /etc/nginx/sites-available/nodepulse /etc/nginx/sites-enabled/nodepulse
rm -f /etc/nginx/sites-enabled/default
ok "nginx configured (port 8080, /cli/ -> 7681)"

# 7. TEST nginx + php-cgi
info "Testing nginx + php-cgi..."
nginx -s stop 2>/dev/null
pkill php-cgi 2>/dev/null
sleep 1

echo '<?php echo "PHP_OK"; ?>' > "$WWW_DIR/_phptest.php"

PHP_FCGI_CHILDREN=2 PHP_FCGI_MAX_REQUESTS=1000 php-cgi -d opcache.enable=0 -b 127.0.0.1:9000 &
PHPCGI_PID=$!
sleep 1

nginx
sleep 2

PHPTEST=$(curl -s http://127.0.0.1:8080/_phptest.php 2>/dev/null)
if [ "$PHPTEST" = "PHP_OK" ]; then
    echo "nginx" > "$MODE_FILE"
    ok "nginx + php-cgi works!"
else
    fail "PHP execution failed (HTTP: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/_phptest.php))"
fi

nginx -s stop 2>/dev/null
kill $PHPCGI_PID 2>/dev/null
pkill php-cgi 2>/dev/null
rm -f "$WWW_DIR/_phptest.php"

# 8. GENERATE NODEPULSE IDENTITY
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
            'platform'   => 'wsl2'
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
                'platform'   => 'wsl2'
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

# 8.5. SETUP PEERSERVER (PeerJS signaling for meet/)
# Installed in ~/services/peerserver/ — outside the web root to avoid
# serving node_modules/ files over HTTP.
info "Setting up PeerServer (PeerJS signaling for meet/)..."

npm install -g pm2 > /dev/null 2>&1
command -v pm2 &>/dev/null && ok "pm2 installed globally" || fail "pm2 install failed"

PEERSERVER_DIR="$HOME/services/peerserver"
mkdir -p "$PEERSERVER_DIR"

# Write server.js only if not already present (preserve customizations)
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

# Install peer npm package
cd "$PEERSERVER_DIR"
if [ ! -d "$PEERSERVER_DIR/node_modules/peer" ]; then
    npm init -y > /dev/null 2>&1
    npm install peer > /dev/null 2>&1
    ok "peer npm package installed in ~/services/peerserver/"
else
    ok "peer npm package already installed"
fi

# Start peerserver via PM2 and save process list for boot resurrection
PEER_PORT=9001 pm2 start "$PEERSERVER_DIR/server.js" --name peerserver > /dev/null 2>&1 || \
    pm2 restart peerserver > /dev/null 2>&1
pm2 save > /dev/null 2>&1
ok "PeerServer started via PM2 on port 9001"
cd "$HOME"

# 9. INSTALL SCRIPTS
info "Installing scripts..."
for script in start-server stop-server server-status; do
    [ ! -f "$SCRIPT_DIR/$script" ] && fail "Missing file: $SCRIPT_DIR/$script"
    cp "$SCRIPT_DIR/$script" "$BIN_DIR/$script"
    chmod +x "$BIN_DIR/$script"
done
[ ! -f "$SCRIPT_DIR/nodepulse.sh" ] && fail "Missing file: $SCRIPT_DIR/nodepulse.sh"
cp "$SCRIPT_DIR/nodepulse.sh" "$BIN_DIR/nodepulse"
chmod +x "$BIN_DIR/nodepulse"
ok "Scripts installed: start-server, stop-server, server-status, nodepulse"

# 10. PULSETERMINAL (cli/)
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

# 11. TERMINAL SESSION DIRECTORIES
mkdir -p "$TMP_DIR/.terminal" "$TMP_DIR/.sessions"

# 12. PATH
if ! grep -q 'export PATH="$HOME/bin:$PATH"' "$HOME/.bashrc" 2>/dev/null; then
    echo 'export PATH="$HOME/bin:$PATH"' >> "$HOME/.bashrc"
fi
export PATH="$HOME/bin:$PATH"
ok "~/bin added to PATH"

# 13. WSL.CONF — disable C: mount (isolation)
info "Writing /etc/wsl.conf (disabling Windows drive automount)..."
cat > /etc/wsl.conf << 'WSLCONF'
[automount]
enabled = false
mountFsTab = false

[network]
generateResolvConf = true

[boot]
systemd = false
command = su -c "pm2 resurrect" root
WSLCONF
ok "wsl.conf written — C: will be isolated on next distro start"

# 14. SUMMARY
echo ""
echo "============================================"
echo "  SETUP COMPLETE! (WSL2 — NodePulse distro)"
echo "============================================"
echo ""
echo "  NodePulse identity:"
echo "    node_id  — $(cat "$HOME/.nodepulse/node_id" 2>/dev/null)"
echo "    keys     — ~/.nodepulse/"
echo ""
echo "  PHP files: ~/www/"
echo ""
echo "  Commands:"
echo "    start-server   — Start nginx + PHP + Cloudflare tunnel"
echo "    stop-server    — Stop everything"
echo "    server-status  — Check service status"
echo ""
echo "  IMPORTANT: restart the distro to isolate C:"
echo "    (from PowerShell) wsl --terminate NodePulse && wsl -d NodePulse"
echo ""
