#!/usr/bin/bash

#============================================================
# DEFINITIVE SETUP: PHP + PYTHON + WEB SERVER + CLOUDFLARE TUNNEL
# For MSYS64 (UCRT64) on Windows
#
# MSYS2 does NOT have php/nginx/lighttpd packages.
# This script downloads standalone Windows binaries:
#   - PHP from windows.php.net
#   - nginx from nginx.org
#   - cloudflared from GitHub
# And installs via pacman:
#   - Python + pip + requests + beautifulsoup4 + lxml (mingw-w64-ucrt-x86_64)
#
# All binaries installed to: ~/nodepulse-bin/
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
NP_DIR="$HOME/nodepulse-bin"
MODE_FILE="$HOME/.server-mode"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

ok()   { echo -e "${GREEN}[OK] $1${NC}"; }
fail() { echo -e "${RED}[ERROR] $1${NC}"; exit 1; }
info() { echo -e "${YELLOW}[...] $1${NC}"; }

echo ""
echo "============================================"
echo "  PHP + PYTHON + CLOUDFLARE SETUP FOR MSYS64/WINDOWS"
echo "============================================"
echo ""

# 1. INSTALL BASIC TOOLS FROM PACMAN (only curl and unzip)
info "Installing base tools..."
pacman -S --noconfirm --needed curl unzip openssl > /dev/null 2>&1
ok "Base tools ready (curl, unzip, openssl)"

# 2. INSTALL PYTHON + PACKAGES
info "Installing Python + packages..."
pacman -S --noconfirm --needed \
    mingw-w64-ucrt-x86_64-python \
    mingw-w64-ucrt-x86_64-python-pip \
    mingw-w64-ucrt-x86_64-python-requests \
    mingw-w64-ucrt-x86_64-python-beautifulsoup4 \
    mingw-w64-ucrt-x86_64-python-lxml \
    > /dev/null 2>&1
if command -v python3 > /dev/null 2>&1 || command -v python > /dev/null 2>&1; then
    PYTHON_VER=$(python3 --version 2>/dev/null || python --version 2>/dev/null)
    ok "Python installed ($PYTHON_VER) + requests, beautifulsoup4, lxml"
else
    fail "Python installation failed"
fi

# 3. DIRECTORIES
mkdir -p "$WWW_DIR" "$TMP_DIR" "$BIN_DIR" "$NP_DIR"
ok "Directories created: ~/www ~/tmp ~/bin ~/nodepulse-bin"

# 4. DOWNLOAD PHP FOR WINDOWS
PHP_DIR="$NP_DIR/php"
if [ ! -f "$PHP_DIR/php.exe" ]; then
    info "Downloading PHP for Windows..."
    PHP_ZIP_URL="https://windows.php.net/downloads/releases/php-8.4.16-nts-Win32-vs17-x64.zip"
    curl -L -o "$TMP_DIR/php.zip" "$PHP_ZIP_URL" 2>/dev/null
    if [ -f "$TMP_DIR/php.zip" ]; then
        mkdir -p "$PHP_DIR"
        unzip -o "$TMP_DIR/php.zip" -d "$PHP_DIR" > /dev/null 2>&1
        if [ -f "$PHP_DIR/php.exe" ]; then
            ok "PHP downloaded and extracted"
            rm -f "$TMP_DIR/php.zip"
        else
            rm -rf "$PHP_DIR"
            fail "PHP extraction failed — check ZIP URL"
        fi
    else
        fail "PHP download failed"
    fi
else
    ok "PHP already installed"
fi

# 5. DOWNLOAD NGINX FOR WINDOWS
NGINX_DIR="$NP_DIR/nginx"
if [ ! -f "$NGINX_DIR/nginx.exe" ]; then
    info "Downloading nginx for Windows..."
    NGINX_ZIP_URL="https://nginx.org/download/nginx-1.27.4.zip"
    curl -L -o "$TMP_DIR/nginx.zip" "$NGINX_ZIP_URL" 2>/dev/null
    if [ -f "$TMP_DIR/nginx.zip" ]; then
        unzip -o "$TMP_DIR/nginx.zip" -d "$TMP_DIR" > /dev/null 2>&1
        # nginx extracts to a subfolder like nginx-1.27.4/
        NGINX_EXTRACTED=$(find "$TMP_DIR" -maxdepth 1 -type d -name "nginx-*" | head -1)
        if [ -n "$NGINX_EXTRACTED" ] && [ -f "$NGINX_EXTRACTED/nginx.exe" ]; then
            rm -rf "$NGINX_DIR"
            mv "$NGINX_EXTRACTED" "$NGINX_DIR"
            ok "nginx downloaded and extracted"
            rm -f "$TMP_DIR/nginx.zip"
        else
            fail "nginx extraction failed — check ZIP URL"
        fi
    else
        fail "nginx download failed"
    fi
else
    ok "nginx already installed"
fi

# 6. DOWNLOAD CLOUDFLARED FOR WINDOWS
CFLARED_BIN="$NP_DIR/cloudflared.exe"
if [ ! -f "$CFLARED_BIN" ]; then
    info "Downloading cloudflared for Windows..."
    CFLARED_URL="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe"
    curl -L -o "$CFLARED_BIN" "$CFLARED_URL" 2>/dev/null
    if [ -f "$CFLARED_BIN" ]; then
        ok "cloudflared downloaded"
    else
        fail "cloudflared download failed"
    fi
else
    ok "cloudflared already installed"
fi

# 7. DOWNLOAD APPS PROJECT
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

# 8. CONFIGURE PHP.INI
info "Configuring php.ini..."
PHP_INI="$PHP_DIR/php.ini"
cat > "$PHP_INI" << 'PHPINI'
; Extensions required by PulseBrowser proxy and API
extension_dir = "ext"
extension=curl
extension=openssl
extension=mbstring
extension=intl
extension=fileinfo
extension=zip

; Performance
opcache.enable=0
opcache.enable_cli=0

; Limits
upload_max_filesize=900M
post_max_size=950M
memory_limit=1024M
max_execution_time=600
max_input_time=600
PHPINI
ok "php.ini configured (curl, openssl, mbstring, opcache=off, upload=900M)"

# 9. CONFIGURE NGINX
info "Configuring nginx..."
# nginx on Windows needs Windows-style paths with forward slashes
WWW_DIR_WIN=$(cygpath -m "$WWW_DIR" 2>/dev/null || echo "$WWW_DIR")
cat > "$NGINX_DIR/conf/nginx.conf" << NCONF
worker_processes 1;
events { worker_connections 128; }
http {
    include mime.types;
    client_max_body_size 950M;

    upstream php_pool {
        server 127.0.0.1:9000;
        server 127.0.0.1:9001;
        server 127.0.0.1:9002;
        server 127.0.0.1:9003;
    }

    server {
        listen 8080;
        server_name localhost;
        root "$WWW_DIR_WIN";
        index index.php index.html;

        location / {
            try_files \$uri \$uri/ /index.php?\$query_string;
        }

        location ~ \.php\$ {
            fastcgi_pass php_pool;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            include fastcgi_params;
        }
    }
}
NCONF
ok "nginx configured (port 8080)"

# 10. AUTO-DETECT MODE (nginx only — lighttpd not available on Windows)
info "Testing nginx + php-cgi..."

# Kill existing processes
taskkill //F //IM nginx.exe > /dev/null 2>&1
taskkill //F //IM php-cgi.exe > /dev/null 2>&1
sleep 1

# Create a PHP test file
echo '<?php echo "PHP_OK"; ?>' > "$WWW_DIR/_phptest.php"

# Start php-cgi
"$PHP_DIR/php-cgi.exe" -d opcache.enable=0 -b 127.0.0.1:9000 &
PHPCGI_PID=$!
sleep 1

# Start nginx (must run from its own directory on Windows)
(cd "$NGINX_DIR" && ./nginx.exe) &
sleep 2

PHPTEST=$(curl -s http://127.0.0.1:8080/_phptest.php 2>/dev/null)
if [ "$PHPTEST" = "PHP_OK" ]; then
    echo "nginx" > "$MODE_FILE"
    ok "nginx + php-cgi works!"
else
    fail "PHP execution failed. HTTP: $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/_phptest.php 2>/dev/null)"
fi

# Stop test servers
taskkill //F //IM nginx.exe > /dev/null 2>&1
kill $PHPCGI_PID 2>/dev/null
taskkill //F //IM php-cgi.exe > /dev/null 2>&1

rm -f "$WWW_DIR/_phptest.php"
SERVER_MODE=$(cat "$MODE_FILE")

# 10.5. GENERATE NODEPULSE IDENTITY
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

    # Write node_identity.json (local) and populate web-facing JSON files via PHP
    PUBKEY_WIN=$(cygpath -m "$NP_IDENTITY/public.pem")
    IDENTITY_WIN=$(cygpath -m "$NP_IDENTITY/node_identity.json")
    CREATED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    "$PHP_DIR/php.exe" -d opcache.enable=0 -r "
        \$pk = file_get_contents('$PUBKEY_WIN');
        \$id = array(
            'node_id'    => '$NODE_ID',
            'type'       => 'tunnel',
            'public_key' => \$pk,
            'created_at' => '$CREATED_AT',
            'version'    => '1.0.0',
            'platform'   => 'msys2'
        );
        file_put_contents('$IDENTITY_WIN', json_encode(\$id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    "

    # Populate web-facing node_identity.json and node_config.json
    if [ -d "$WWW_DIR/nodepulse" ]; then
        WEB_ID_WIN=$(cygpath -m "$WWW_DIR/nodepulse/node_identity.json")
        WEB_CFG_WIN=$(cygpath -m "$WWW_DIR/nodepulse/node_config.json")

        "$PHP_DIR/php.exe" -d opcache.enable=0 -r "
            \$pk = file_get_contents('$PUBKEY_WIN');
            \$id = array(
                'node_id'    => '$NODE_ID',
                'type'       => 'tunnel',
                'public_key' => \$pk,
                'created_at' => '$CREATED_AT',
                'version'    => '1.0.0',
                'platform'   => 'msys2'
            );
            file_put_contents('$WEB_ID_WIN', json_encode(\$id, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
            file_put_contents('$WEB_CFG_WIN', json_encode(\$cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        "
    fi

    ok "Identity generated: node_id=$NODE_ID"
else
    NODE_ID=$(cat "$NP_IDENTITY/node_id" 2>/dev/null)
    ok "Identity already exists: node_id=$NODE_ID"
fi

# 11. INSTALL SCRIPTS FROM REPO
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

# 12. INSTALL TERMINAL
info "Installing Terminal..."

if [ ! -f "$WWW_DIR/terminal/index.php" ] || [ ! -f "$WWW_DIR/terminal/daemon.php" ]; then
    fail "Terminal files not found in ~/www/terminal/ — check that apps.zip includes terminal/"
fi

mkdir -p "$TMP_DIR/.terminal" "$TMP_DIR/.sessions"
ok "Terminal ready (~/www/terminal/)"

# 13. PATH
if ! grep -q 'export PATH="$HOME/bin:$HOME/nodepulse-bin/php:$HOME/nodepulse-bin:$PATH"' "$HOME/.bashrc" 2>/dev/null; then
    # Remove old PATH entry if present
    sed -i '/export PATH="\$HOME\/bin:\$PATH"/d' "$HOME/.bashrc" 2>/dev/null
    echo 'export PATH="$HOME/bin:$HOME/nodepulse-bin/php:$HOME/nodepulse-bin:$PATH"' >> "$HOME/.bashrc"
fi
export PATH="$HOME/bin:$HOME/nodepulse-bin/php:$HOME/nodepulse-bin:$PATH"
ok "PATH updated (~/bin, ~/nodepulse-bin/php, ~/nodepulse-bin)"

# 14. SUMMARY
echo ""
echo "============================================"
echo "  SETUP COMPLETE!"
echo "  Mode: $SERVER_MODE"
echo "============================================"
echo ""
echo "  Binaries installed in:  ~/nodepulse-bin/"
echo "    php/         - PHP $(${PHP_DIR}/php.exe -r 'echo PHP_VERSION;' 2>/dev/null)"
echo "    nginx/       - nginx"
echo "    cloudflared  - Cloudflare tunnel"
echo ""
echo "  Installed via pacman:"
echo "    python       - $(python3 --version 2>/dev/null || python --version 2>/dev/null)"
echo "    pip          - $(pip3 --version 2>/dev/null | cut -d' ' -f1-2 || echo 'pip')"
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
echo "    http://<tunnel-url>/terminal/"
echo ""
echo "  The public trycloudflare.com link will appear"
echo "  after running: bash ~/bin/start-server"
echo ""
