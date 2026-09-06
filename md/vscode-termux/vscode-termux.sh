#!/data/data/com.termux/files/usr/bin/bash

#==============================================================================
# vscode-termux.sh
#
# Fully automates the install described in vscode-termux.md: code-server
# (browser VS Code) built from source on Termux, proxied by nginx under
# /vscode/ behind the existing NodePulse auth gate, with the "Claude Code"
# (Anthropic) extension sideloaded and both Termux-specific runtime bugs
# patched (glibc-vs-bionic native binary, process.platform === "android").
#
# Idempotent: safe to re-run. Each stage checks whether its result already
# exists and skips the work if so. Re-running after a code-server upgrade
# will redo the native rebuilds and the platform patch (patches don't survive
# a fresh `npm install` of code-server).
#
# Usage:
#   bash vscode-termux.sh                 # full install
#   bash vscode-termux.sh --skip-ext      # skip the Claude Code extension (step 14)
#   CLAUDE_EXT_VERSION=2.1.261 bash vscode-termux.sh   # pin extension version
#
# See vscode-termux.md for the full narrative / why each step exists.
#==============================================================================

set -uo pipefail

APP_DIR="$HOME/code-server-app"
CODE_SERVER_VERSION="4.135.0"
CLAUDE_EXT_VERSION="${CLAUDE_EXT_VERSION:-}"
SKIP_EXT=0
for arg in "$@"; do
  [ "$arg" = "--skip-ext" ] && SKIP_EXT=1
done

log()  { echo "[vscode-termux] $*"; }
die()  { echo "[vscode-termux] ERROR: $*" >&2; exit 1; }

export FORCE_NODE_VERSION=$(node -v | sed 's/v\([0-9]*\).*/\1/')
export NODE_PATH="$PREFIX/lib/node_modules"
export PATH="$PREFIX/lib/node_modules/npm/bin/node-gyp-bin:$PATH"

command -v node >/dev/null || die "node not found — install nodejs first"
command -v nginx >/dev/null || die "nginx not found — this guide assumes the NodePulse nginx setup"

log "toolchain: node $(node -v), npm $(npm -v 2>/dev/null), platform $(node -e 'console.log(process.platform,process.arch)')"

#------------------------------------------------------------------------------
# 0. Build prerequisites
#------------------------------------------------------------------------------
log "step 0: checking build prerequisites..."
MISSING=""
for p in build-essential binutils; do
  dpkg -l 2>/dev/null | grep -q "^ii  $p " || MISSING="$MISSING $p"
done
if [ -n "$MISSING" ]; then
  log "installing missing packages:$MISSING"
  pkg install -y nodejs python git $MISSING || die "pkg install failed"
fi

#------------------------------------------------------------------------------
# 2. node-gyp android_ndk_path fix
#------------------------------------------------------------------------------
log "step 2: node-gyp android_ndk_path fix..."
mkdir -p ~/.gyp
if [ ! -f ~/.gyp/include.gypi ] || ! grep -q android_ndk_path ~/.gyp/include.gypi 2>/dev/null; then
cat > ~/.gyp/include.gypi <<'EOF'
{
  'variables': {
    'android_ndk_path': '',
    'android_ndk_path%': ''
  }
}
EOF
fi

#------------------------------------------------------------------------------
# 3. node-addon-api global
#------------------------------------------------------------------------------
log "step 3: node-addon-api (global)..."
[ -d "$NODE_PATH/node-addon-api" ] || npm install -g node-addon-api || die "node-addon-api install failed"

#------------------------------------------------------------------------------
# 4-5. code-server project + tree (--ignore-scripts)
#------------------------------------------------------------------------------
log "step 4/5: code-server project tree..."
mkdir -p "$APP_DIR"
cd "$APP_DIR"
if [ ! -f package.json ]; then
cat > package.json <<EOF
{
  "name": "code-server-host",
  "private": true,
  "dependencies": {
    "code-server": "$CODE_SERVER_VERSION"
  }
}
EOF
fi
if [ ! -d node_modules/code-server ]; then
  npm install --ignore-scripts || die "npm install (code-server tree) failed"
fi

CS_DIR="$APP_DIR/node_modules/code-server"
VSCODE_DIR="$CS_DIR/lib/vscode"
[ -d "$CS_DIR" ] || die "code-server tree missing after install — check npm output above"

#------------------------------------------------------------------------------
# 6. argon2, compiled in isolation
#------------------------------------------------------------------------------
log "step 6: argon2 native build..."
ARGON2_DIR="$CS_DIR/node_modules/argon2"
if [ -d "$ARGON2_DIR" ] && [ ! -f "$ARGON2_DIR/build/Release/argon2.node" ]; then
  (
    cd "$ARGON2_DIR" || exit 1
    NGB="../.bin/node-gyp-build"
    [ -x "$NGB" ] || NGB="$(find "$APP_DIR/node_modules" -maxdepth 6 -path '*.bin/node-gyp-build' | head -1)"
    [ -n "$NGB" ] || { echo "node-gyp-build not found"; exit 1; }
    ZERO_AR_DATE=1 node "$NGB"
  ) || die "argon2 build failed"
fi
if [ -f "$ARGON2_DIR/build/Release/argon2.node" ]; then
  ( cd "$ARGON2_DIR" && node -e "require('./').hash('x').then(h=>require('./').verify(h,'x')).then(v=>{if(!v)throw new Error('verify failed')})" ) \
    || die "argon2 built but self-test failed"
  log "argon2: OK"
fi

#------------------------------------------------------------------------------
# 7. lib/vscode production deps (--ignore-scripts)
#------------------------------------------------------------------------------
log "step 7: lib/vscode dependencies..."
if [ -d "$VSCODE_DIR" ] && [ ! -d "$VSCODE_DIR/node_modules/@microsoft" ]; then
  ( cd "$VSCODE_DIR" && npm install --omit=dev --ignore-scripts ) || die "lib/vscode npm install failed"
fi

#------------------------------------------------------------------------------
# 8. native modules, one at a time
#------------------------------------------------------------------------------
log "step 8: native module rebuilds (one at a time)..."
if [ -d "$VSCODE_DIR" ]; then
  cd "$VSCODE_DIR"
  for m in @vscode/spdlog @vscode/sqlite3 @vscode/native-watchdog @vscode/deviceid node-pty kerberos; do
    marker="node_modules/$m/build/Release"
    if [ -d "$marker" ] && find "$marker" -maxdepth 1 -name '*.node' | grep -q .; then
      log "  $m: already built, skipping"
      continue
    fi
    log "  building $m..."
    npm_config_jobs=1 npm rebuild "$m" || log "  !! $m failed (see troubleshooting table in vscode-termux.md)"
  done
fi

#------------------------------------------------------------------------------
# 9. code-server config (auth: none, behind the NodePulse gate)
#------------------------------------------------------------------------------
log "step 9: code-server config.yaml..."
mkdir -p ~/.config/code-server
if [ ! -f ~/.config/code-server/config.yaml ]; then
cat > ~/.config/code-server/config.yaml <<'EOF'
bind-addr: 127.0.0.1:8090
auth: none
cert: false
EOF
fi

#------------------------------------------------------------------------------
# 10. nginx /vscode/ location block
#------------------------------------------------------------------------------
log "step 10: nginx /vscode/ route..."
NGINX_CONF="$PREFIX/etc/nginx/nginx.conf"
if [ -f "$NGINX_CONF" ] && ! grep -q "location /vscode/" "$NGINX_CONF"; then
  cp "$NGINX_CONF" "$NGINX_CONF.bak.$(date +%Y%m%d%H%M%S)"
  python3 - "$NGINX_CONF" <<'PYEOF'
import sys
path = sys.argv[1]
block = '''        # code-server (VS Code) -- proxied under /vscode/, gated by auth_gate.
        # code-server listens on 127.0.0.1:8090; nginx strips the /vscode/ prefix.
        location = /vscode {
            return 302 $real_scheme://$host/vscode/;
        }
        location /vscode/ {
            auth_request /cli-auth.php;
            error_page 401 = @cli_login;

            proxy_pass http://127.0.0.1:8090/;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header Upgrade $http_upgrade;
            proxy_set_header Connection "upgrade";
            proxy_set_header Accept-Encoding gzip;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $real_scheme;
            proxy_set_header X-Forwarded-Host $host;
            proxy_read_timeout 86400;
            proxy_send_timeout 86400;
            proxy_buffering off;
        }

        location / {'''
with open(path, encoding='utf-8') as f:
    content = f.read()
target = '        location / {'
if target not in content:
    print("marker 'location / {' not found in nginx.conf -- insert manually, see vscode-termux.md step 10")
    sys.exit(1)
content = content.replace(target, block, 1)
with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("nginx.conf patched")
PYEOF
  if [ $? -eq 0 ]; then
    nginx -t && nginx -s reload && log "nginx: /vscode/ route active" || die "nginx -t failed after patch — check $NGINX_CONF, backup at $NGINX_CONF.bak.*"
  else
    log "WARNING: could not auto-patch nginx.conf — add the /vscode/ block manually (step 10 in vscode-termux.md)"
  fi
else
  log "nginx: /vscode/ route already present, skipping"
fi

#------------------------------------------------------------------------------
# 11. control scripts in ~/bin/
#------------------------------------------------------------------------------
log "step 11: control scripts in ~/bin/..."
mkdir -p ~/bin

cat > ~/bin/start-vscode <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash
APP_DIR="$HOME/code-server-app"
PID_FILE="$APP_DIR/cs.pid"
LOG_FILE="$APP_DIR/cs.log"

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo "[vscode] already running (PID $(cat "$PID_FILE"))"
    exit 0
fi

export FORCE_NODE_VERSION=$(node -v | sed 's/v\([0-9]*\).*/\1/')
export NODE_PATH=$PREFIX/lib/node_modules

echo "[...] Starting code-server..."
nohup node "$APP_DIR/node_modules/.bin/code-server" > "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"
disown

sleep 3
if kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo "[OK] code-server started (PID $(cat "$PID_FILE")), listening on 127.0.0.1:8090"
else
    echo "[ERROR] code-server failed to start — check $LOG_FILE"
    exit 1
fi
EOF

cat > ~/bin/stop-vscode <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash
APP_DIR="$HOME/code-server-app"
PID_FILE="$APP_DIR/cs.pid"

if [ ! -f "$PID_FILE" ]; then
    echo "[vscode] no PID file — nothing tracked as running."
    pgrep -af "code-server/out/node/entry" && echo "[vscode] (untracked process found above — inspect manually)"
    exit 0
fi

PID=$(cat "$PID_FILE")
if kill -0 "$PID" 2>/dev/null; then
    kill "$PID"
    sleep 1
    if kill -0 "$PID" 2>/dev/null; then
        echo "[vscode] PID $PID still alive, sending SIGKILL"
        kill -9 "$PID"
    fi
    echo "[OK] vscode stopped (was PID $PID)"
else
    echo "[vscode] PID $PID in file was not running (stale)"
fi
rm -f "$PID_FILE"
EOF

cat > ~/bin/restart-vscode <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash
"$(dirname "$(readlink -f "$0")")/stop-vscode"
"$(dirname "$(readlink -f "$0")")/start-vscode"
EOF

cat > ~/bin/vscode-status <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash
APP_DIR="$HOME/code-server-app"
PID_FILE="$APP_DIR/cs.pid"

echo ""
echo "=== VSCODE (code-server) STATUS ==="
echo ""

if [ -f "$PID_FILE" ] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo "Tracked:     RUNNING (PID $(cat "$PID_FILE"))"
else
    echo "Tracked:     STOPPED"
fi

if pgrep -f "code-server/out/node/entry" > /dev/null; then
    echo "Process:     RUNNING"
    pgrep -af "code-server/out/node/entry"
else
    echo "Process:     STOPPED"
fi

HTTP_CODE=$(curl -sL -o /dev/null -w "%{http_code}" http://127.0.0.1:8090/ 2>/dev/null)
echo "HTTP test:   $HTTP_CODE (127.0.0.1:8090, backend direct)"
echo ""
EOF

chmod +x ~/bin/start-vscode ~/bin/stop-vscode ~/bin/restart-vscode ~/bin/vscode-status

#------------------------------------------------------------------------------
# 14.4 Termux platform patch (process.platform === "android") — applied BEFORE
# first start, so the terminal works from the very first boot.
#------------------------------------------------------------------------------
log "step 14.4: patching process.platform=android handling..."
PLATFORM_VAL="$(node -e 'process.stdout.write(process.platform)')"
if [ "$PLATFORM_VAL" = "android" ] && [ -d "$VSCODE_DIR" ]; then
  for f in "$VSCODE_DIR/out/server-main.js" \
           "$VSCODE_DIR/out/vs/platform/terminal/node/ptyHostMain.js" \
           "$VSCODE_DIR/out/vs/platform/agentHost/node/agentHostMain.js"; do
    [ -f "$f" ] || continue
    if grep -q 'case"android":case"linux"' "$f"; then
      log "  $(basename "$f"): already patched"
      continue
    fi
    cp "$f" "$f.orig" 2>/dev/null
    python3 - "$f" <<'PYEOF'
import re, sys
path = sys.argv[1]
with open(path, encoding='utf-8') as fh:
    s = fh.read()
pat = re.compile(r'(case"darwin":[^;]+;break;)case"linux":')
if len(pat.findall(s)) == 1:
    s = pat.sub(r'\1case"android":case"linux":', s)
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(s)
    print("patched:", path)
else:
    print("WARNING: pattern not found (0 or >1 matches) in", path, "-- inspect manually, see vscode-termux.md step 14.4")
PYEOF
  done
else
  log "  process.platform=$PLATFORM_VAL — patch not needed"
fi

#------------------------------------------------------------------------------
# Start code-server so the extension install step below has a live server
#------------------------------------------------------------------------------
log "starting code-server..."
~/bin/start-vscode || die "code-server failed to start — check $APP_DIR/cs.log"

#------------------------------------------------------------------------------
# 14. Claude Code extension (optional, --skip-ext to skip)
#------------------------------------------------------------------------------
if [ "$SKIP_EXT" -eq 1 ]; then
  log "step 14: skipped (--skip-ext)"
else
  log "step 14: Claude Code extension..."
  EXT_ID="anthropic.claude-code"
  ALREADY_INSTALLED=$(node "$APP_DIR/node_modules/.bin/code-server" --list-extensions 2>/dev/null | grep -i "^$EXT_ID$")

  if [ -n "$ALREADY_INSTALLED" ] && [ -z "$CLAUDE_EXT_VERSION" ]; then
    log "  extension already installed, skipping (set CLAUDE_EXT_VERSION to force a specific version)"
  else
    VERSION="$CLAUDE_EXT_VERSION"
    if [ -z "$VERSION" ]; then
      log "  querying marketplace for latest version..."
      VERSION=$(curl -s -X POST "https://marketplace.visualstudio.com/_apis/public/gallery/extensionquery" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json;api-version=3.0-preview.1" \
        -d '{"filters":[{"criteria":[{"filterType":7,"value":"anthropic.claude-code"}]}],"flags":914}' \
        | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['results'][0]['extensions'][0]['versions'][0]['version'])" 2>/dev/null)
    fi
    if [ -z "$VERSION" ]; then
      log "  WARNING: could not resolve extension version automatically — skipping. Set CLAUDE_EXT_VERSION= and re-run, see step 14.1"
    else
      log "  downloading anthropic.claude-code@$VERSION (linux-arm64)..."
      TMP_GZ=$(mktemp)
      TMP_VSIX="$TMP_GZ.vsix"
      curl -sL -o "$TMP_GZ" \
        "https://marketplace.visualstudio.com/_apis/public/gallery/publishers/anthropic/vsextensions/claude-code/$VERSION/vspackage?targetPlatform=linux-arm64"
      if gunzip -c "$TMP_GZ" > "$TMP_VSIX" 2>/dev/null && [ -s "$TMP_VSIX" ]; then
        node "$APP_DIR/node_modules/.bin/code-server" --install-extension "$TMP_VSIX" \
          && log "  extension installed: $EXT_ID@$VERSION" \
          || log "  WARNING: extension install command failed — see output above"
      else
        log "  WARNING: download/gunzip failed for version $VERSION — check the version exists for linux-arm64 (step 14.1)"
      fi
      rm -f "$TMP_GZ" "$TMP_VSIX"
    fi

    # 14.3: glibc wrapper for the native binary, via grun if available
    if command -v grun >/dev/null 2>&1; then
      SETTINGS="$HOME/.local/share/code-server/User/settings.json"
      mkdir -p "$(dirname "$SETTINGS")"
      GRUN_PATH="$(command -v grun)"
      python3 - "$SETTINGS" "$GRUN_PATH" <<'PYEOF'
import json, sys, os
path, grun = sys.argv[1], sys.argv[2]
data = {}
if os.path.exists(path):
    try:
        with open(path, encoding='utf-8') as f:
            data = json.load(f)
    except Exception:
        data = {}
data['claudeCode.claudeProcessWrapper'] = grun
with open(path, 'w', encoding='utf-8') as f:
    json.dump(data, f, indent=4)
print("settings.json updated:", path)
PYEOF
    else
      log "  WARNING: 'grun' not found — install the Termux glibc runtime (pkg install glibc glibc-runner) for the native binary to work, see step 14.3"
    fi

    log "  restarting code-server to load the extension + settings..."
    ~/bin/restart-vscode || die "code-server failed to restart after extension install"
  fi
fi

#------------------------------------------------------------------------------
# Final verification
#------------------------------------------------------------------------------
log "verifying..."
sleep 2
BACKEND=$(curl -sL -o /dev/null -w "%{http_code}" http://127.0.0.1:8090/ 2>/dev/null)
NGINX_ROUTE=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/vscode/ 2>/dev/null)
log "backend  http://127.0.0.1:8090/       -> HTTP $BACKEND (expect 200)"
log "nginx    http://127.0.0.1:8080/vscode/ -> HTTP $NGINX_ROUTE (expect 302, gated by NodePulse login)"

if [ "$BACKEND" = "200" ]; then
  log "DONE. Access via your tunnel at https://<host>/vscode/ (passes the NodePulse login gate once)."
  log "Manage the service with: vscode-status | start-vscode | stop-vscode | restart-vscode"
else
  die "backend did not come up cleanly — check $APP_DIR/cs.log"
fi
