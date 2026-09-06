# VS Code (code-server) on Termux / NodePulse — Clean Install Guide

This guide reproduces a **working** `code-server` (browser VS Code) install on this
Termux / NodePulse Android instance, served through nginx behind the Cloudflare
tunnel under the path **`/vscode/`**.

It is written so that a future operator (human or Claude) can follow it **top to
bottom without hitting the problems already solved here**. Do not skip steps and do
not reorder them — several steps exist specifically to avoid a failure documented
inline.

---

## Quick reference: starting/stopping the service day-to-day

Once installed (steps 1-14 below, done once), use these to turn the service on/off.
Helper scripts live in **`~/bin/`**, alongside the other NodePulse service scripts
(`start-server`, `stop-server`, `server-status`, `start-desktop`, `stop-desktop`,
...) for consistency — `~/bin` is already on `PATH`, so call them by name from
anywhere. They control the actual install in `~/code-server-app/` (that's where
`node_modules`, `cs.log`, `cs.pid` live — only the control scripts moved). They
track the process by a PID file (`cs.pid`), not by pattern-matching `pgrep` — this
avoids the self-kill trap described in step 11/14.5 (a `pgrep -f` pattern that also
matches the shell invoking it, killing the wrong thing).

```sh
vscode-status     # is it running? (tracked PID + actual process + HTTP check)
start-vscode      # start if not already running (safe to re-run)
stop-vscode       # stop it (SIGTERM, then SIGKILL after 1s if needed)
restart-vscode    # stop-vscode + start-vscode
```

There is **no autostart** — after a device/Termux reboot, code-server does not come
back on its own, and it is **not** wired into `start-server`/`stop-server` (by
request — those orchestrate the rest of the NodePulse stack and were left
untouched). Run `start-vscode` again after a reboot.

Internals, if you ever need to bypass the scripts: `code-server` runs as a
**parent/child pair** — the process you launch (`node node_modules/.bin/code-server`)
is a supervisor that forks the actual server (`.../code-server/out/node/entry`) as
its child. `start-vscode` captures the **parent's** PID (via `$!` right after
backgrounding) into `~/code-server-app/cs.pid`; killing that parent PID cleanly
takes the child down with it (verified — no orphan process left behind). Never kill
by `pgrep -f "code-server"` from a shell/script whose own command line happens to
contain that same string — see step 14.5.

---

## 0. Environment assumptions

- Termux on Android, architecture **aarch64**.
- **Node.js v24+** installed (`node -v`). This instance has v26; code-server's
  postinstall hard-requires v24, so we override the check (see below).
- **nginx** is the single public entry point on port **8080** (behind cloudflared).
  Port 8080 is therefore **not free** — code-server must listen on a different
  internal port. We use **8090**.
- The NodePulse nginx config already gates internal apps (`/cli/`, `/desktop/`) with
  an `auth_request` to `/cli-auth.php`. We reuse that same gate for `/vscode/`, so
  code-server itself runs with **`auth: none`** (no second login).
- RAM is limited (~2 GB free, heavy swap). Native modules **must be compiled one at
  a time**, never all at once, or the linker gets OOM-killed (`clang++: ... Aborted`).

Build tools required (install once if missing):

```sh
pkg install -y nodejs python build-essential binutils git
```

---

## 1. Shell environment used for EVERY build and run command

code-server and its native modules must be built/run with these two variables set.
Export them in the shell you use for all steps below:

```sh
export FORCE_NODE_VERSION=$(node -v | sed 's/v\([0-9]*\).*/\1/')   # e.g. 26
export NODE_PATH=$PREFIX/lib/node_modules
```

- `FORCE_NODE_VERSION` bypasses code-server's `postinstall.sh` check that otherwise
  aborts with `ERROR: code-server currently requires node v24`.
- `NODE_PATH` lets native module build scripts resolve globally-installed helpers
  (see step 3), because their `binding.gyp` runs `node -p "require('node-addon-api')"`.

---

## 2. Fix node-gyp for Termux (android_ndk_path)

On Termux, node-gyp builds fail with:

```
gyp: Undefined variable android_ndk_path in binding.gyp while trying to load binding.gyp
```

This comes from Node's bundled `common.gypi` (Android platform block) referencing a
variable that is never defined. Define it globally once:

```sh
mkdir -p ~/.gyp
cat > ~/.gyp/include.gypi <<'EOF'
{
  'variables': {
    'android_ndk_path': '',
    'android_ndk_path%': ''
  }
}
EOF
```

---

## 3. Install `node-addon-api` globally

Some native modules' `binding.gyp` evaluate `require('node-addon-api')` at configure
time and fail with `Cannot find module 'node-addon-api'` if it is not resolvable.
Install it globally; `NODE_PATH` (step 1) makes it visible to the builds:

```sh
npm install -g node-addon-api
```

---

## 4. Create the code-server project (local, NOT global)

A global `npm install -g code-server` fails on this system (its old pinned argon2
does not compile). Install **locally** instead — code-server 4.135.0 pins
`argon2@0.44.0` via its own `npm-shrinkwrap.json`, and 0.44.0 **does** compile with
the modern Termux libc++ (older 0.28.x does not: it uses
`std::char_traits<unsigned char>`, removed from recent libc++).

```sh
mkdir -p ~/code-server-app
cd ~/code-server-app
cat > package.json <<'EOF'
{
  "name": "code-server-host",
  "private": true,
  "dependencies": {
    "code-server": "4.135.0"
  }
}
EOF
```

---

## 5. Install the code-server tree WITHOUT running scripts

Running the install scripts here would compile argon2 while npm is also unpacking the
large tree → memory spike → linker OOM. So install the tree first with
`--ignore-scripts` (fast, no compilation):

```sh
cd ~/code-server-app
FORCE_NODE_VERSION=$FORCE_NODE_VERSION NODE_PATH=$NODE_PATH npm install --ignore-scripts
```

This leaves two things unbuilt/uninstalled that the next steps handle manually:
- argon2 is not compiled (step 6),
- `lib/vscode` production dependencies are not installed (step 7) because
  `--ignore-scripts` skipped code-server's `postinstall.sh`.

---

## 6. Compile argon2 alone

Build argon2 in isolation (low memory, succeeds):

```sh
cd ~/code-server-app/node_modules/code-server/node_modules/argon2
FORCE_NODE_VERSION=$FORCE_NODE_VERSION NODE_PATH=$NODE_PATH npm run install
```

Verify a binary was produced and it works:

```sh
ls build/Release/argon2.node   # must exist
node -e "require('./').hash('x').then(h=>require('./').verify(h,'x')).then(v=>console.log('argon2 ok:',v))"
# expected: argon2 ok: true
```

---

## 7. Install the VS Code server dependencies

code-server's `postinstall.sh` normally runs `cd lib/vscode && npm install --omit=dev`.
Because step 5 used `--ignore-scripts`, do it manually — **again with
`--ignore-scripts`** to avoid compiling native modules during the large unpack:

```sh
cd ~/code-server-app/node_modules/code-server/lib/vscode
FORCE_NODE_VERSION=$FORCE_NODE_VERSION NODE_PATH=$NODE_PATH npm install --omit=dev --ignore-scripts
```

> Skipping this step is the cause of the runtime 500:
> `Cannot find package '@microsoft/1ds-core-js' imported from .../out/server-main.js`.

---

## 8. Compile the VS Code server native modules — ONE AT A TIME

Still inside `~/code-server-app/node_modules/code-server/lib/vscode`:

```sh
cd ~/code-server-app/node_modules/code-server/lib/vscode
for m in @vscode/spdlog @vscode/sqlite3 @vscode/native-watchdog @vscode/deviceid node-pty kerberos; do
  echo "=== building $m ==="
  FORCE_NODE_VERSION=$FORCE_NODE_VERSION NODE_PATH=$NODE_PATH npm_config_jobs=1 npm rebuild "$m" || echo "!! $m failed"
done
```

All six compile successfully on this system. Notes:
- **`@parcel/watcher` is intentionally NOT in the list** — it does not build on
  Android. Its absence only degrades *recursive* file-watching (VS Code falls back);
  it does **not** block startup. Do not waste time trying to force it.
- **`@vscode/deviceid`** builds but logs `Error: Unsupported platform` at runtime on
  Android. This is harmless (device-id / telemetry only).

Sanity check the key binaries exist:

```sh
ls node_modules/node-pty/build/Release/pty.node
ls node_modules/@vscode/spdlog/build/Release/*.node
ls node_modules/@vscode/sqlite3/build/Release/*.node
```

---

## 9. code-server configuration (no second login)

Authentication is handled by the NodePulse nginx gate, so disable code-server's own
auth:

```sh
mkdir -p ~/.config/code-server
cat > ~/.config/code-server/config.yaml <<'EOF'
bind-addr: 127.0.0.1:8090
auth: none
cert: false
EOF
```

---

## 10. nginx: expose code-server under `/vscode/`

Edit `$PREFIX/etc/nginx/nginx.conf`. Inside the existing `server { ... }` block (the
one that `listen 8080;`), **before** the `location / {` block, add:

```nginx
        # code-server (VS Code) — proxied under /vscode/, gated by auth_gate.
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
```

Notes:
- The `@cli_login` named location and the `$real_scheme` variable already exist in the
  NodePulse config (used by `/cli/` and `/desktop/`). Reuse them; do not redefine.
- `proxy_pass` has a trailing slash → nginx strips the `/vscode/` prefix. code-server
  emits **relative** asset paths, so the browser correctly requests them under
  `/vscode/`. This is why the subpath works.

Test and reload (never restart the whole nginx unnecessarily):

```sh
nginx -t && nginx -s reload
```

---

## 11. Create the control scripts in `~/bin/`

These live in `~/bin/` for consistency with the other NodePulse service scripts
(`start-server`, `stop-server`, `server-status`, `start-desktop`, `stop-desktop`)
— `~/bin` is already on `PATH`. They track the process by a **PID file**
(`~/code-server-app/cs.pid`), never by `pgrep -f` pattern-matching — a pattern can
match the shell/script invoking it and kill the wrong process (this has actually
happened while operating this instance — see the warning in `stop-vscode` below).

`code-server` runs as a **parent/child pair**: the process you launch (`node
node_modules/.bin/code-server`) is a supervisor that forks the real server
(`.../code-server/out/node/entry`) as its child. Killing the parent cleanly takes
the child down with it (verified — no orphan left behind), so tracking just the
parent's PID (via `$!` right after backgrounding) is enough.

```sh
mkdir -p ~/bin

cat > ~/bin/start-vscode <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash

#============================================================
# start-vscode
# Starts code-server (browser VS Code), proxied by nginx under
# /vscode/ (see ~/www/md/vscode-termux.md). Safe to re-run if
# already running. Tracks the process via PID file, not pgrep -f,
# to avoid self-matching kills.
#============================================================

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
# Invoke via `node` explicitly, not the .bin/code-server symlink directly —
# its shebang is #!/usr/bin/env node, which fails with "bad interpreter"
# in shells without LD_PRELOAD set to the termux-exec shim.
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

#============================================================
# stop-vscode
# Stops code-server. Kills by PID file ONLY — never by pgrep -f
# pattern: a pattern match can catch the calling shell/script's
# own command line (it has happened on this instance) and kill
# the wrong process.
#============================================================

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

#============================================================
# vscode-status
# Shows whether code-server (browser VS Code) is running.
#============================================================

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
```

Confirm it is up (expect `HTTP 200`, and `Authentication is disabled` in the log):

```sh
start-vscode
sleep 2
grep -iE "listening|Authentication" ~/code-server-app/cs.log | tail -3
vscode-status
```

There is no autostart configured (by request; `nodepulse.sh`, `start-server` and
`stop-server` are left untouched — vscode is a separate, manually-managed
service). After a reboot, run `start-vscode` again.

---

## 12. Access

- **Locally on the device:** `http://127.0.0.1:8090/` (no auth).
- **Through the tunnel:** `https://<your-tunnel-host>/vscode/` — you will pass the
  NodePulse login gate (`cli-login.php`) once, then land directly in VS Code.

---

## 13. Quick end-to-end verification

```sh
# backend up, no login (-L follows the normal /?folder= redirect):
curl -sL -o /dev/null -w "backend /        -> HTTP %{http_code}\n" http://127.0.0.1:8090/
# nginx route gated (302 to the NodePulse login when unauthenticated):
curl -s -o /dev/null -w "nginx  /vscode/  -> HTTP %{http_code} %{redirect_url}\n" http://127.0.0.1:8080/vscode/
```

Expected:
```
backend /        -> HTTP 200
nginx  /vscode/  -> HTTP 302 http://127.0.0.1:8080/cli-login.php?return=/vscode/
```

(Without `-L`, the backend `/` returns `302 -> /?folder=...`; that is the normal
code-server workspace redirect, not an error.)

---

## 14. Installing the "Claude Code" extension (Anthropic) — sideload + fixes

The extension is **not on Open VSX**, so it never appears in code-server's built-in
Extensions panel (that's expected, not a bug — code-server never ships the
Microsoft/proprietary marketplace). Sideload the `.vsix` instead.

### 14.1 Download the right build

The `latest` version tag on the marketplace does not resolve `targetPlatform`
correctly for this extension (it 404s or silently serves `win32-x64`). Request an
**explicit version** with `targetPlatform=linux-arm64`. Check the current version
first via the marketplace query API if needed. The response is **gzip-encoded**
(there's a `content-encoding: gzip` header) — `curl` does not auto-decompress
without `--compressed`, so the raw download is a `.gz` stream, not a valid zip,
until you gunzip it:

```sh
cd ~
curl -sL -D headers.txt -o claude-code.vsix.gz \
  "https://marketplace.visualstudio.com/_apis/public/gallery/publishers/anthropic/vsextensions/claude-code/<VERSION>/vspackage?targetPlatform=linux-arm64"
gunzip -c claude-code.vsix.gz > claude-code.vsix
rm claude-code.vsix.gz
```

### 14.2 Install it

```sh
export FORCE_NODE_VERSION=$(node -v | sed 's/v\([0-9]*\).*/\1/')
export NODE_PATH=$PREFIX/lib/node_modules
node ~/code-server-app/node_modules/.bin/code-server --install-extension ~/claude-code.vsix
```

### 14.3 Fix: native `claude` binary fails to launch (glibc vs Termux bionic)

The extension bundles a real native binary at
`extensions/anthropic.claude-code-<ver>/resources/native-binary/claude`, linked
against **glibc** (`interpreter /lib/ld-linux-aarch64.so.1`, needs `libc.so.6` etc.).
Termux's own libc is bionic (Android's), not glibc and not musl — the extension's
own error message ("musl loader missing") is a red herring for Termux; the real
issue is bionic vs glibc.

This Termux install already has the **official Termux glibc runtime** (`pkg install
glibc glibc-runner` — packages `glibc`, `glibc-runner`, `termux-exec-glibc`, repo
`termux-glibc` in `sources.list.d`). Its wrapper `grun` runs glibc ELF binaries
directly:

```sh
grun ~/.local/share/code-server/extensions/anthropic.claude-code-*/resources/native-binary/claude --version
# -> "2.1.261 (Claude Code)" — confirms it works
```

The extension exposes exactly the hook needed for this:
`claudeCode.claudeProcessWrapper` (VS Code setting, scope `machine` — i.e. the
server-side `settings.json` in a code-server install, not something a client sets).
When set, the extension spawns `<wrapper> <real-binary-path> <args...>` instead of
the binary directly. Set it to `grun`:

```sh
# ~/.local/share/code-server/User/settings.json
{
    "claudeCode.claudeProcessWrapper": "/data/data/com.termux/files/usr/bin/grun"
}
```

Restart code-server after editing.

### 14.4 Fix: integrated terminal doesn't accept input / never starts a shell

Root cause, and it's bigger than just the terminal: **Termux's Node.js reports
`process.platform === "android"`**, not `"linux"` (`node -e
"console.log(process.platform)"` — confirm on your instance). VS Code's bundled
server code has several `switch(process.platform)` blocks that only handle
`"darwin"` and `"linux"` and `throw new Error("Platform not supported")` for
anything else — this crashes the Pty Host at startup (log shows `[IPC Library: Pty
Host] Unhandled Promise Rejection: Error: Platform not supported`, then `No
ptyHost response to createProcess after 5 seconds`), so no terminal ever gets a
real shell attached — the panel opens but nothing you type goes anywhere.

Found and patched in three bundled files (same pattern in each — a
`case"darwin":...;break;case"linux":...;break;default:throw new Error("Platform
not supported")` switch). Add an `android` case that falls through to the `linux`
one:

```sh
cd ~/code-server-app/node_modules/code-server/lib/vscode/out
for f in server-main.js \
         vs/platform/terminal/node/ptyHostMain.js \
         vs/platform/agentHost/node/agentHostMain.js; do
  cp "$f" "$f.orig"
  python3 -c "
import re
p='$f'
s=open(p,encoding='utf-8').read()
pat=re.compile(r'(case\"darwin\":[^;]+;break;)case\"linux\":')
assert len(pat.findall(s))==1, p
open(p,'w',encoding='utf-8').write(pat.sub(r'\1case\"android\":case\"linux\":', s))
"
done
```

This has to be **re-applied after every code-server/vscode version upgrade** (it
patches generated/minified output, not source). If a future version's minified
variable names differ, `grep -o 'case"darwin"[^}]\{0,250\}' <file>` finds the
current shape of the switch to adjust the regex.

Restart code-server after patching. A clean startup log (no `Platform not
supported`, no `ptyHost` timeout lines) confirms the fix took.

### 14.5 Reminder: the self-kill trap is real

Hit it again while testing this: `kill $(pgrep -f "code-server-app/node_modules/code-server/out/node/entry")`
run from a shell whose own invocation happens to embed that same literal string
(e.g. an orchestration layer that echoes the full command being run) matches and
kills itself along with the target. If restarting from such a context, filter by
`/proc/<pid>/comm` instead of trusting `pgrep -f` alone:

```sh
for pid in $(pgrep -f "code-server/out/node/entry"); do
  [ "$(cat /proc/$pid/comm 2>/dev/null)" = "node" ] && kill "$pid"
done
```

---

## Troubleshooting quick reference

| Symptom | Cause | Fix |
|---|---|---|
| `Undefined variable android_ndk_path` | Termux node-gyp | Step 2 (`~/.gyp/include.gypi`) |
| `Cannot find module 'node-addon-api'` | build helper not resolvable | Step 1 (`NODE_PATH`) + step 3 |
| `ERROR: code-server currently requires node v24` | postinstall version guard | `FORCE_NODE_VERSION` (step 1) |
| `clang++: ... Aborted` / linker killed | OOM (too much compiled at once) | build native modules one at a time (steps 6, 8) |
| `char_traits<unsigned char>` compile error | argon2 ≤0.28 vs modern libc++ | use code-server's pinned argon2 0.44.0 (step 4 — do not force old argon2) |
| 500 `Cannot find package '@microsoft/1ds-core-js'` | `lib/vscode` deps not installed | Step 7 |
| `@parcel/watcher` build fails | unsupported on Android | expected — skip it (step 8) |
| `@vscode/deviceid: Unsupported platform` in log | Android has no device-id backend | harmless, ignore |
| `sh: 1: cross-env: not found` during argon2 step 6 | npm doesn't put the hoisted bin on PATH from this nested dir | run `node ../.bin/node-gyp-build` directly with `ZERO_AR_DATE=1` exported, skip `npm run install` |
| `spawn node-gyp ENOENT` during step 8 rebuilds | no `node-gyp` binary on PATH (nested packages' devDependencies aren't installed) | add npm's bundled one: `export PATH="$PREFIX/lib/node_modules/npm/bin/node-gyp-bin:$PATH"` before the rebuild loop |
| `/usr/bin/env: bad interpreter: No such file or directory` running any `node_modules/.bin/*` script (incl. starting code-server itself) | shell has no `LD_PRELOAD` for the `termux-exec` shim that rewrites `#!/usr/bin/env` shebangs (happens in non-login shells, e.g. this one) | invoke the target via `node` directly instead of executing the shebang script, e.g. `node ~/code-server-app/node_modules/.bin/code-server` instead of `~/code-server-app/node_modules/.bin/code-server` |
| Claude extension: "native binary ... failed to launch" / mentions a musl loader | binary is glibc-linked; Termux's libc is bionic (neither glibc nor musl — the extension's own message is misleading here) | Step 14.3 — use the Termux `glibc`+`grun` runtime, set `claudeCode.claudeProcessWrapper` to `grun`'s path |
| Integrated terminal panel opens but typing does nothing; log shows `Pty Host ... Error: Platform not supported` / `No ptyHost response to createProcess` | Termux's Node reports `process.platform === "android"`; VS Code's bundled `switch(process.platform)` blocks only handle `darwin`/`linux` and throw for anything else | Step 14.4 — patch `server-main.js`, `ptyHostMain.js`, `agentHostMain.js` to add an `android` case falling through to `linux` |

---

## Verified re-run log

**2026-09-05** — full re-test after switching the Termux apt repo from the old
`termux.net` to the official `packages.termux.dev` (via Cloudflare cache mirror).
Toolchain: `node v26.4.0`, `npm 11.19.1`, `clang 21.1.8`, `termux-exec 2.5.0`.
Starting state had **no prior code-server install** (fresh `~/code-server-app`, no
`~/.gyp/include.gypi`).

- Steps 0-13 (base install + nginx integration): guide's architecture held. Three
  npm/toolchain-drift issues hit and fixed inline (cross-env, node-gyp ENOENT,
  `/usr/bin/env` bad interpreter — see troubleshooting table above). End-to-end
  verification (step 13) passed, plus a regression check that `/desktop/` and `/`
  still worked after the nginx reload.
- Step 14 (Claude Code extension): sideload worked once the correct
  `targetPlatform=linux-arm64` + explicit version + gunzip were used. Two further
  runtime issues found and fixed the same day: the glibc-vs-bionic native binary
  (14.3) and the `process.platform === "android"` crashes breaking the pty host and
  therefore the integrated terminal (14.4). After both fixes, the extension
  activated and the integrated terminal accepted input — confirmed working by the
  operator in-browser.
- Control scripts (step 11) were moved from ad-hoc `.sh` files into `~/bin/` as
  `start-vscode` / `stop-vscode` / `restart-vscode` / `vscode-status`, matching this
  instance's existing script naming convention; full start/stop/restart cycle
  re-tested after the move.
