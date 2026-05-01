#!/data/data/com.termux/files/usr/bin/bash

#============================================================
# DEFINITIVE SETUP: PHP + WEB SERVER + CLOUDFLARE TUNNEL
# For Termux on Android
#
# RESOLVED ISSUES:
# - OPcache lock "Permission denied (13)" on Android SELinux
# - php -S and php-fpm do not work (same lock bug)
# - SELinux blocks process spawning between servers (Error 13)
# - Missing DNS resolver for cloudflared
# - killall requires root on some devices
#
# AUTO-DETECT STRATEGY:
# 1. Try lighttpd + php-cgi spawn (simpler)
# 2. If SELinux blocks PHP execution, use nginx + manual php-cgi (universal)
#
# PHP FILES GO IN: ~/www/
# START: start-server
# STOP:  stop-server
# STATUS: server-status
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
echo "  PHP + CLOUDFLARE SETUP FOR TERMUX"
echo "============================================"
echo ""

# 1. UPDATE AND INSTALL
info "Updating packages..."
yes | pkg update -y > /dev/null 2>&1
yes | pkg upgrade -y > /dev/null 2>&1
ok "System updated"

info "Installing packages..."
pkg install -y php lighttpd nginx cloudflared curl unzip openssl-tool python tmux nodejs > /dev/null 2>&1
ok "Packages installed"

info "Installing Python deps (aiohttp) for PulseTerminal..."
pip install --break-system-packages -q aiohttp > /dev/null 2>&1 || \
    pip install --break-system-packages aiohttp
python -c "import aiohttp" 2>/dev/null && ok "aiohttp OK" || fail "aiohttp install failed"

# 2. DIRECTORIES
mkdir -p "$WWW_DIR" "$TMP_DIR" "$BIN_DIR"
ok "Directories created: ~/www ~/tmp ~/bin"

# 3. DOWNLOAD APPS PROJECT
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

# 4. CONFIGURE PHP.INI
# NOTE: Termux PHP looks for php.ini in $PREFIX/etc/php/php.ini (directory), NOT $PREFIX/etc/php.ini
info "Configuring php.ini..."
PHP_INI_DIR="$PREFIX/etc/php"
PHP_INI="$PHP_INI_DIR/php.ini"
mkdir -p "$PHP_INI_DIR/conf.d"
# Write clean values (overwrite any existing file)
printf 'opcache.enable=0\nopcache.enable_cli=0\nupload_max_filesize=900M\npost_max_size=950M\nmemory_limit=1024M\nmax_execution_time=600\nmax_input_time=600\n' > "$PHP_INI"
ok "php.ini configured (opcache=off, upload=900M, memory=1024M)"

# 5. FIX DNS
info "Configuring DNS..."
echo "nameserver 8.8.8.8" > "$PREFIX/etc/resolv.conf"
echo "nameserver 1.1.1.1" >> "$PREFIX/etc/resolv.conf"
ok "DNS configured"

# 6. CONFIGURE LIGHTTPD
info "Configuring lighttpd..."
mkdir -p "$PREFIX/etc/lighttpd"
cat > "$PREFIX/etc/lighttpd/lighttpd.conf" << LCONF
server.document-root = "$WWW_DIR"
server.port = 8080
server.bind = "127.0.0.1"
server.errorlog = "$TMP_DIR/lighttpd-error.log"
index-file.names = ("index.php", "index.html")
mimetype.assign = (
    ".html" => "text/html",
    ".htm"  => "text/html",
    ".css"  => "text/css",
    ".js"   => "application/javascript",
    ".json" => "application/json",
    ".png"  => "image/png",
    ".jpg"  => "image/jpeg",
    ".jpeg" => "image/jpeg",
    ".gif"  => "image/gif",
    ".svg"  => "image/svg+xml",
    ".ico"  => "image/x-icon",
    ".webp" => "image/webp",
    ".woff" => "font/woff",
    ".woff2"=> "font/woff2",
    ".ttf"  => "font/ttf",
    ".otf"  => "font/otf",
    ".pdf"  => "application/pdf",
    ".zip"  => "application/zip",
    ".mp3"  => "audio/mpeg",
    ".mp4"  => "video/mp4",
    ".xml"  => "application/xml",
    ".txt"  => "text/plain"
)
server.modules += ("mod_fastcgi", "mod_rewrite")
fastcgi.server = (
    ".php" => ((
        "bin-path" => "$PREFIX/bin/php-cgi",
        "socket" => "$TMP_DIR/php.socket",
        "max-procs" => 1,
        "bin-environment" => (
            "PHP_FCGI_CHILDREN" => "2",
            "TMPDIR" => "$TMP_DIR"
        )
    ))
)
LCONF
ok "Lighttpd configured"

# 7. CONFIGURE NGINX
info "Configuring nginx..."
cat > "$PREFIX/etc/nginx/nginx.conf" << 'NCONF'
worker_processes 1;
events { worker_connections 128; }
http {
    include mime.types;
    client_max_body_size 950M;
    server {
        listen 8080;
        server_name localhost;
        root /data/data/com.termux/files/home/www;
        index index.php index.html;

        # PulseTerminal auth gate — internal auth_request endpoint.
        # Checks the auth_gate.php session cookie via php-cgi.
        # Not reachable from the outside (internal).
        location = /cli-auth.php {
            internal;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME $document_root/cli-auth.php;
            fastcgi_pass_request_body off;
            fastcgi_param CONTENT_LENGTH "";
            include fastcgi_params;
        }

        # PulseTerminal — WebSocket proxy to local Python server.
        # Gated by auth_gate session; on 401 → /cli-login.php handles login.
        # Takes precedence over static/php handling for /cli/*.
        location /cli/ {
            auth_request /cli-auth.php;
            error_page 401 = @cli_login;

            proxy_pass http://127.0.0.1:7681/;
            proxy_http_version 1.1;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
            proxy_buffering off;
        }

        location @cli_login {
            return 302 $scheme://$http_host/cli-login.php;
        }

        # PeerJS WebSocket + HTTP signaling proxy (meet/)
        location /peerjs/ {
            proxy_pass http://127.0.0.1:9001/peerjs/;
            proxy_http_version 1.1;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
            proxy_buffering off;
        }

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }
}
NCONF
ok "Nginx configured"

# 8. AUTO-DETECT MODE
# Tests actual PHP execution — lighttpd can return HTTP 200 for static
# files even when PHP-FastCGI is blocked by SELinux, so we must verify
# that PHP code actually runs before saving the mode.
info "Running server auto-detection..."

pkill lighttpd 2>/dev/null
nginx -s stop 2>/dev/null
pkill php-cgi 2>/dev/null
rm -f "$TMP_DIR/php.socket"* 2>/dev/null
sleep 1

# Create a PHP test file
echo '<?php echo "PHP_OK"; ?>' > "$WWW_DIR/_phptest.php"

# Try lighttpd first
lighttpd -f "$PREFIX/etc/lighttpd/lighttpd.conf"
sleep 2

PHPTEST=$(curl -s http://127.0.0.1:8080/_phptest.php 2>/dev/null)
if [ "$PHPTEST" = "PHP_OK" ]; then
    echo "lighttpd" > "$MODE_FILE"
    ok "Lighttpd + PHP works! Using LIGHTTPD mode"
    pkill lighttpd 2>/dev/null
else
    pkill lighttpd 2>/dev/null
    info "Lighttpd PHP execution blocked (SELinux?), trying nginx..."
    sleep 1

    PHP_FCGI_CHILDREN=2 PHP_FCGI_MAX_REQUESTS=1000 php-cgi -d opcache.enable=0 -b 127.0.0.1:9000 &
    PHPCGI_PID=$!
    sleep 1

    nginx
    sleep 2

    PHPTEST=$(curl -s http://127.0.0.1:8080/_phptest.php 2>/dev/null)
    if [ "$PHPTEST" = "PHP_OK" ]; then
        echo "nginx" > "$MODE_FILE"
        ok "Nginx + php-cgi works! Using NGINX mode"
    else
        fail "No server can execute PHP. Check the logs."
    fi

    nginx -s stop 2>/dev/null
    kill $PHPCGI_PID 2>/dev/null
fi

rm -f "$WWW_DIR/_phptest.php"
SERVER_MODE=$(cat "$MODE_FILE")

# 8.5. GENERATE NODEPULSE IDENTITY
NP_IDENTITY="$HOME/.nodepulse"
if [ ! -f "$NP_IDENTITY/private.pem" ]; then
    info "Generating NodePulse identity (RSA-2048)..."
    mkdir -p "$NP_IDENTITY"

    # Generate RSA-2048 key pair
    openssl genpkey -algorithm RSA -out "$NP_IDENTITY/private.pem" \
        -pkeyopt rsa_keygen_bits:2048 2>/dev/null
    if [ ! -f "$NP_IDENTITY/private.pem" ]; then
        fail "Failed to generate RSA key pair"
    fi
    openssl rsa -in "$NP_IDENTITY/private.pem" -pubout \
        -out "$NP_IDENTITY/public.pem" 2>/dev/null
    if [ ! -f "$NP_IDENTITY/public.pem" ]; then
        fail "Failed to extract public key"
    fi
    chmod 600 "$NP_IDENTITY/private.pem"

    # Calculate node_id: SHA-256 of DER-encoded public key, first 12 hex chars
    NODE_ID=$(openssl rsa -in "$NP_IDENTITY/public.pem" -pubin -outform DER 2>/dev/null \
        | openssl dgst -sha256 -hex | awk '{print $NF}' | cut -c1-12)

    if [ -z "$NODE_ID" ] || [ ${#NODE_ID} -ne 12 ]; then
        fail "Failed to compute node_id"
    fi

    echo "$NODE_ID" > "$NP_IDENTITY/node_id"

    # Write node_identity.json (local) via PHP
    CREATED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    php -d opcache.enable=0 -r "
        \$pk = file_get_contents('$NP_IDENTITY/public.pem');
        \$id = array(
            'node_id'    => '$NODE_ID',
            'type'       => 'tunnel',
            'public_key' => \$pk,
            'created_at' => '$CREATED_AT',
            'version'    => '1.0.0',
            'platform'   => 'termux'
        );
        file_put_contents('$NP_IDENTITY/node_identity.json', json_encode(\$id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    "

    # Populate web-facing node_identity.json and node_config.json
    if [ -d "$WWW_DIR/nodepulse" ]; then
        php -d opcache.enable=0 -r "
            \$pk = file_get_contents('$NP_IDENTITY/public.pem');
            \$id = array(
                'node_id'    => '$NODE_ID',
                'type'       => 'tunnel',
                'public_key' => \$pk,
                'created_at' => '$CREATED_AT',
                'version'    => '1.0.0',
                'platform'   => 'termux'
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


# 8.7. SETUP PEERSERVER (PeerJS signaling for meet/)
# Installed in ~/services/peerserver/ — outside the web root to avoid
# serving node_modules/ files over HTTP.
info "Setting up PeerServer (PeerJS signaling for meet/)..."

npm install -g pm2 > /dev/null 2>&1
command -v pm2 &>/dev/null && ok "pm2 installed globally" || fail "pm2 install failed"

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

# Termux:Boot auto-start script
mkdir -p "$HOME/.termux/boot"
cat > "$HOME/.termux/boot/start-peerserver.sh" << 'BOOTSCRIPT'
#!/data/data/com.termux/files/usr/bin/bash
pm2 resurrect
BOOTSCRIPT
chmod +x "$HOME/.termux/boot/start-peerserver.sh"
ok "Termux:Boot script created (~/.termux/boot/start-peerserver.sh)"

# 9. INSTALL SCRIPTS FROM REPO
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

# terminal/index.php and terminal/daemon.php arrive via apps.zip (already in ~/www/terminal/).
# The setup only needs to verify they exist and create the queue/session dirs.
if [ ! -f "$WWW_DIR/terminal/index.php" ] || [ ! -f "$WWW_DIR/terminal/daemon.php" ]; then
    fail "Terminal files not found in ~/www/terminal/ — check that apps.zip includes terminal/"
fi

# Queue and session dirs kept outside ~/www/ to avoid web exposure
mkdir -p "$TMP_DIR/.terminal" "$TMP_DIR/.sessions"

ok "Terminal ready (~/www/terminal/)"

# 11.5. INSTALL PULSETERMINAL (cli/)
info "Installing PulseTerminal (CLI over WS)..."

# cli/server.py and cli/terminal.html ship with apps.zip at ~/www/cli/.
# Fallback: copy from the repo if present (dev installs).
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

# Auth gate glue: cli-auth.php (internal auth_request) + cli-login.php (public login entry).
# Shipped via apps.zip at ~/www/; fallback copy from repo for dev installs.
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

# 12. PATH
if ! grep -q 'export PATH="$HOME/bin:$PATH"' "$HOME/.bashrc" 2>/dev/null; then
    echo 'export PATH="$HOME/bin:$PATH"' >> "$HOME/.bashrc"
fi
export PATH="$HOME/bin:$PATH"
ok "~/bin added to PATH"

# 13. SUMMARY
echo ""
echo "============================================"
echo "  SETUP COMPLETE!"
echo "  Detected mode: $SERVER_MODE"
echo "============================================"
echo ""
echo "  NodePulse identity:"
echo "    node_id      - $(cat "$HOME/.nodepulse/node_id" 2>/dev/null)"
echo "    keys         - ~/.nodepulse/"
echo ""
echo "  Your PHP files go in:  ~/www/"
echo ""
echo "  Available commands:"
echo "    bash ~/bin/start-server   - Start PHP + Cloudflare tunnel"
echo "    bash ~/bin/stop-server    - Stop everything"
echo "    bash ~/bin/server-status  - Check service status"
echo ""
echo "  Web terminal available at:"
echo "    http://<tunnel-url>/terminal/   (PHP daemon)"
echo "    http://<tunnel-url>/cli/        (PulseTerminal — WS + tmux)"
echo ""
echo "  The public trycloudflare.com link will appear"
echo "  after running bash ~/bin/start-server"
echo ""
