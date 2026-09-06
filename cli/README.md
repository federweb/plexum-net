## IMPORTANT ON WSL2 !!
echo 'set -g mouse on' > ~/.tmux.conf
To enable page scrolling with the mouse


# PulseTerminal v0.2

Web terminal over WebSocket, integrated into the NodePulse stack.
Reuses the existing nginx + cloudflared tunnel — no extra tunnel needed.

## How it works

```
Browser (xterm.js)
    | HTTPS
    v
Cloudflare Tunnel   (existing, shared with all apps)
    | HTTP / WS
    v
nginx :8080         (location /cli/  ->  proxy_pass 127.0.0.1:7681/)
    | HTTP / WS
    v
server.py (aiohttp, loopback only)
    | pty.fork()
    v
bash / tmux         (real PTY, full terminal)
```

nginx proxies `/cli/*` to the local Python server with WebSocket upgrade
headers. The Python server binds to `127.0.0.1:7681` only — it is never
directly exposed. All traffic flows through the existing tunnel.

## Auth — shared `auth_gate.php` session

`/cli/` is gated by nginx `auth_request`:

```
client ──► /cli/ ──auth_request──► /cli-auth.php   (internal)
                                       │
                                       ├── 204 OK ──► proxy to 127.0.0.1:7681
                                       │
                                       └── 401    ──► 302 /cli-login.php?return=/cli/
                                                          │
                                                          └── auth_gate.php form
                                                              └── on success → back to ?return= (or /cli/)
```

- `cli-auth.php` (internal) reads `$_SESSION['gate_auth']` from
  `~/tmp/.sessions/` and returns 204 or 401. No HTML, no body.
- `cli-login.php` wraps `auth_gate.php` so the login form is shown
  inside the shared theme, and redirects back to the page that
  triggered the login (`?return=`, stashed in the session across the
  form's POST/redirect/GET cycle), falling back to `/cli/`.
- Same cookie (`PHPSESSID`), same bcrypt hash
  (`~/.nodepulse/gate_password.hash`), same session as Terminal,
  FileManager, Cloud, Monitor, Bookmarks, Keychain, Browser,
  DomainSeed, Blog-admin. Log in once, every app unlocks.
- WebSocket upgrade goes through the same `auth_request` check: if the
  session cookie is valid, the WS is proxied; otherwise nginx closes
  with 401 before reaching the Python server.

Ed25519 was considered but dropped: PyNaCl needs native compilation on
Termux and fails on recent Android NDK (`memset_explicit` build error).
Session-cookie auth is simpler and reuses the existing infrastructure.

## Session persistence — tmux

`start-server` launches the Python server with `--tmux`, so every
connection attaches (`new-session -A`) to the shared session `pulse`.
Disconnecting from the browser leaves the session running; reconnect
later and pick up where you left off.

## Integrated flow

Handled automatically by the Termux installer:

- `termux-setup.sh` installs `python` + `tmux` + `aiohttp`, drops the
  `/cli/` + `/cli-auth.php` location blocks into `nginx.conf`, and
  copies `cli-auth.php` / `cli-login.php` into `~/www/`.
- `start-server` spawns `python server.py --tmux` alongside nginx.
- `stop-server` kills it.
- `server-status` reports it.

No new tunnel; the existing `cloudflared` already points at nginx :8080.

## Options

```
python server.py --help

  -p, --port PORT    Port (default: 7681)
  --host HOST        Bind address (default: 127.0.0.1)
  --shell SHELL      Shell to spawn (default: $SHELL)
  --tmux             Wrap in tmux for session persistence
```

## Stack

- **server.py** — Python, aiohttp, stdlib `pty`. ~150 lines, one dep.
- **terminal.html** — xterm.js 5.3 + fit + WebGL, relative WS URL.
- **Transport** — WebSocket over HTTP, CF-tunnel compatible.
- **Persistence** — tmux session `pulse`.
