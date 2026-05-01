# NodePulse Desktop — Openbox Remote via cloudflared

Operational note for publishing a minimal X11 graphical desktop (Openbox) through the existing cloudflared tunnel, accessible from any modern browser with no plugins or dedicated clients.

Stack: **Xvnc** (tigervnc) + **openbox** + **websockify** + **noVNC** + **nginx auth_request** → `auth_gate` (same pattern as PulseTerminal / `cli/`).

---

## Architecture

```
Browser HTTPS
    │
    ▼
cloudflared (existing tunnel)
    │
    ▼
nginx :8080
    ├── auth_request → /cli-auth.php   (gate_auth cookie, 204/401)
    └── /desktop/  →  websockify :6080
                          │
                          ├── serves noVNC HTML5 client (--web)
                          └── WS → TCP :5901  →  Xvnc display :1
                                                      │
                                                      └── openbox + tint2 + xterm
```

No physical Android display required: `Xvnc` includes a virtual framebuffer. **Termux:X11 is not needed**, nor is any graphics hardware.

---

## 1. Packages

```bash
pkg install x11-repo -y
pkg install tigervnc openbox tint2 xterm dbus -y
```

- `tigervnc` → provides `Xvnc` (headless X server with built-in RFB).
- `openbox` → lightweight window manager.
- `tint2` → optional taskbar (removable).
- `xterm` → default graphical terminal; replaceable with others.
- `dbus` → required by some GTK applications.

---

## 2. websockify (pure-Python, no numpy)

`pip install websockify` pulls numpy as an optional dependency, which on Termux/ARM goes into native compilation and hangs for hours. Solution:

```bash
pip install --break-system-packages --no-deps websockify
```

Verify:
```bash
python -c "import websockify; print('ok')"
```

Pure-Python websockify easily handles a single desktop session. numpy is only needed for heavy multi-client throughput (not our case).

---

## 3. noVNC (HTML5 client)

Static files under `~/services/novnc/` (outside the web root, served directly by websockify):

```bash
mkdir -p $HOME/services
cd $HOME/services
curl -L -o novnc.tar.gz https://github.com/novnc/noVNC/archive/refs/tags/v1.5.0.tar.gz
tar xzf novnc.tar.gz
mv noVNC-1.5.0 novnc
rm novnc.tar.gz
ln -sf vnc.html novnc/index.html
cd $HOME
```

---

## 4. VNC Authentication

Recommended choice: **empty VNC password + auth_gate**. The traffic is already protected by:
- Xvnc loopback binding (`-localhost`)
- bcrypt `auth_gate` cookie via nginx `auth_request`
- End-to-end TLS from cloudflared

A second password prompt is just noise. Configuration:

```bash
mkdir -p $HOME/.vnc
touch $HOME/.vnc/passwd
chmod 600 $HOME/.vnc/passwd
```

Xvnc will then be started with `-SecurityTypes None`.

> If you'd rather add a VNC password on top: run `tigervncpasswd` and remove `-SecurityTypes None` from the start script.

---

## 5. Openbox session (xstartup)

**Critical step** — if this file is missing, Xvnc will start but show a black screen (no window manager, no apps). Create it with the heredoc below (do not skip):

```bash
cat > $HOME/.vnc/xstartup << 'XSTART'
#!/data/data/com.termux/files/usr/bin/bash
unset SESSION_MANAGER
unset DBUS_SESSION_BUS_ADDRESS
export LANG=en_US.UTF-8
xsetroot -solid "#0a0a0a"
tint2 &
xterm -geometry 100x30+50+50 -bg "#0a0a0a" -fg "#00ff88" &
exec openbox-session
XSTART
chmod +x $HOME/.vnc/xstartup
```

Verify:
```bash
ls -l ~/.vnc/xstartup    # must exist, be executable
cat ~/.vnc/xstartup      # must show the content above
```

Background `#0a0a0a` and accent `#00ff88` for visual consistency with the rest of the apps.

---

## 6. start-desktop / stop-desktop scripts

`~/bin/start-desktop`:

```bash
#!/data/data/com.termux/files/usr/bin/bash
# NodePulse Desktop — Xvnc + openbox + websockify (also serves noVNC)

DISPLAY_NUM=1
VNC_PORT=5901
WS_PORT=6080
GEOMETRY="1280x720"
DEPTH=24
NOVNC_DIR="$HOME/services/novnc"
LOG_DIR="$HOME/tmp"
mkdir -p "$LOG_DIR"

# Clean up previous sessions
# NOTE: no colon before the port in the websockify pattern — the actual argv
# is `websockify --web=... 6080 127.0.0.1:5901`, so "websockify.*:6080" misses.
pkill -f "Xvnc :$DISPLAY_NUM" 2>/dev/null
pkill -f "websockify.*$WS_PORT" 2>/dev/null
rm -f /tmp/.X${DISPLAY_NUM}-lock /tmp/.X11-unix/X${DISPLAY_NUM} 2>/dev/null
sleep 1

# Xvnc (loopback only, no VNC auth — protected by auth_gate)
Xvnc :$DISPLAY_NUM \
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

echo "[desktop] Xvnc :$DISPLAY_NUM  |  websockify :$WS_PORT  |  noVNC $NOVNC_DIR"
echo "[desktop] log: $LOG_DIR/{xvnc,xstartup,websockify}.log"
```

`~/bin/stop-desktop`:

```bash
#!/data/data/com.termux/files/usr/bin/bash
pkill -f "Xvnc :1" 2>/dev/null
pkill -f "websockify.*6080" 2>/dev/null
pkill -f "openbox" 2>/dev/null
pkill -f "tint2" 2>/dev/null
rm -f /tmp/.X1-lock /tmp/.X11-unix/X1 2>/dev/null
echo "[desktop] stopped"
```

```bash
chmod +x $HOME/bin/start-desktop $HOME/bin/stop-desktop
```

---

## 7. nginx integration

Add the following to the `server { ... }` block in `$PREFIX/etc/nginx/nginx.conf`, **before** `location /` and in the same style as `/cli/`:

```nginx
# NodePulse Desktop — Openbox via noVNC (WebSocket proxy)
# Gated by the same auth_gate used by /cli/ (gate_auth cookie)

# Exact match: redirect bare /desktop/ to noVNC with correct WS path+params.
# IMPORTANT: noVNC default path='websockify' builds wss://host/websockify (root),
# but nginx only proxies under /desktop/ — must force path=desktop/websockify.
location = /desktop/ {
    auth_request /cli-auth.php;
    error_page 401 = @desktop_login;
    return 302 /desktop/vnc.html?path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1;
}

# Prefix match: proxy static files + WebSocket to websockify
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
    return 302 $scheme://$http_host/cli-login.php;
}
```

Notes:
- `/cli-auth.php` is already `internal` and reads `$_SESSION['gate_auth']` (defined in `termux-setup.sh`, step 7, `nginx.conf`). Reused as-is, no duplication.
- websockify serves both the static noVNC client and the WS channel on the same port → a single `location /desktop/` covers everything.

Reload:
```bash
nginx -s reload
```

---

## 8. start-server / stop-server integration

In `~/bin/start-server`, **before** the cloudflared/nodepulse block (nginx is already up at this point; the desktop stack must be ready so that the moment the tunnel opens the `/desktop/` endpoint is live):

```bash
# Start desktop (Xvnc + openbox + websockify) — must run before nodepulse blocks
bash $HOME/bin/start-desktop
```

In `~/bin/stop-server`, before `pm2 stop` / `pkill cloudflared` / `pkill -f nodepulse`:

```bash
bash $HOME/bin/stop-desktop
```

The installer finds these markers automatically and patches both files idempotently. After editing, `stop-server` + `start-server` from the local device applies the changes.

---

## 9. Dashboard card

`~/www/desktop/index.php` — the dashboard auto-discovers it via `is_dir()` (same mechanism as all other apps).

**Do not use `header('Location: /desktop/...')`**. Under nginx → php-cgi/php-fpm the CGI SAPI rewrites any relative `Location:` to an absolute URL using backend `SERVER_PORT` (8080) and without `HTTPS` set — the browser ends up redirected to `http://host:8080/desktop/vnc.html?...` (plain HTTP, backend port), which is unreachable through cloudflared. Use an HTML-level redirect with a **relative** URL instead: the browser resolves it against the current document's origin and scheme, preserving `https://` and the public hostname.

```php
<?php
// NodePulse Desktop — directory placeholder for dashboard auto-discovery.
// The nginx "location = /desktop/" exact-match handles the canonical redirect,
// but dashboard links may hit /desktop/index.php directly (PHP regex match wins
// over the /desktop/ prefix). Avoid PHP's Location header: CGI SAPI rewrites
// relative URLs to http://host:8080/... using backend SERVER_PORT.
$qs = 'path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1';
?><!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0;url=vnc.html?<?= $qs ?>">
<title>NodePulse Desktop</title>
<script>location.replace('vnc.html?<?= $qs ?>');</script>
</head>
<body style="background:#0a0a0a;color:#00ff88;font-family:monospace;padding:2em">
<a href="vnc.html?<?= $qs ?>" style="color:#00ff88">Open Desktop →</a>
</body>
</html>
```

noVNC query parameters:
- `path=desktop/websockify` → absolute WS path (matches the nginx `/desktop/` location; noVNC builds `wss://<host>/<path>` from host root, **not** relative to the current page).
- `autoconnect=1` → connect immediately.
- `resize=scale` → scale the desktop to viewport.
- `reconnect=1` → auto-reconnect on drop.

If the WS fails: DevTools → Network → inspect the actual WS URL — it must be `wss://<tunnel>/desktop/websockify`. Adjust `path=` accordingly.

For consistent visual integration, drop a `~/www/desktop/icon.png` into the folder (the dashboard card layout picks it up automatically).

---

## 10. Verification

1. `bash ~/bin/start-server` → nginx + cloudflared + desktop
2. Open `https://<tunnel>.trycloudflare.com/` → login via auth_gate
3. Click the "Desktop" card (or go directly to `/desktop/`)
4. noVNC connects automatically → openbox appears with tint2 + xterm
5. Right-click on the background → openbox menu; other GUI apps installed via `pkg install` will show up in the menu after the next X session restart

---

## Troubleshooting

| Symptom | Cause / Fix |
|---|---|
| Black screen, noVNC UI visible | Most common cause: `~/.vnc/xstartup` is missing or not executable. Check `~/tmp/xstartup.log` — if it says "No such file or directory", go back to step 5 and recreate it via the heredoc. |
| Black screen, xstartup exists | `openbox-session` may not be in PATH. Check `~/tmp/xstartup.log`, verify `which openbox-session`; if missing, reinstall: `pkg reinstall openbox`. As a secondary cause, X authentication may be blocking clients — add `-auth $HOME/.vnc/xauth` to Xvnc and prepend `XAUTHORITY=$HOME/.vnc/xauth` to the xstartup invocation. |
| "No password configured for VNC Auth" | Xvnc wants a `passwd` file even with `-SecurityTypes None`. Fix: `touch $HOME/.vnc/passwd; chmod 600 $HOME/.vnc/passwd`. |
| "Connection refused" from websockify | Xvnc not yet ready on :5901 when websockify starts. Increase `sleep 2` to `sleep 3-4` in `start-desktop`. |
| WebSocket closes right after handshake | Wrong WS path in the `path=` parameter. Open browser console → Network tab → inspect the WS URL. It must be `wss://<tunnel>/desktop/websockify`. |
| Browser redirected to `http://<host>:8080/desktop/vnc.html` (HTTP, port 8080) | `~/www/desktop/index.php` is using `header('Location: /desktop/...')`. The php-cgi SAPI rewrites the relative Location to absolute with backend `SERVER_PORT`. Replace with the HTML meta-refresh + JS variant in section 9 (uses relative `vnc.html?...` so the browser resolves it against the current HTTPS origin). |
| `/desktop/index.php` hit by PHP instead of the nginx `/desktop/` proxy | Expected: the global `location ~ \.php$` regex wins over the `/desktop/` prefix. The placeholder `index.php` in section 9 handles this case correctly by redirecting to `vnc.html` without any PHP `Location` header. |
| Cursor not visible | Add `xsetroot -cursor_name left_ptr` to `xstartup`. |
| Poor performance | Use `-depth 16` instead of 24; set `GEOMETRY="1024x600"`; in the noVNC URL add `&quality=4&compression=9`. |
| Audio | Not supported via VNC/noVNC. Remote audio would require Xpra or PulseAudio over TCP (out of scope). |
| Copy/paste browser ↔ desktop | Works if you launch `vncconfig -iconic &` in `xstartup` (the `vncconfig` binary ships with `tigervnc`, already installed). |

---

## Security

- **Loopback binding**: Xvnc (`-localhost`) and websockify (default) listen only on 127.0.0.1 → unreachable outside the device.
- **Application-layer gate**: bcrypt `auth_gate` cookie (via `auth_request`) + optional VNC password.
- **End-to-end TLS**: cloudflared guarantees WSS from browser to device.
- **No extra public TCP port**: everything rides the existing cloudflared tunnel.
- **Minimal attack surface**: Xvnc is an X server — it does not expose a shell or file access by default. The `openbox` WM has no network services.

---

## Future extensions

- **Useful GUI apps**: `pkg install geany mousepad pcmanfm gimp` (check availability with `pkg search` — the x11-repo catalogue is smaller than Debian's).
- **Session persistence**: the Xvnc session persists until you kill it. Detach browser/device, reattach later, you get the same windows and state back.
- **Future multi-user**: for concurrent sessions, use displays `:2`, `:3` etc. with separate websockify instances on distinct ports (`6081`, `6082`). Out of scope for this note — revisit when/if NodePulse becomes multi-account.
- **Clipboard bridge**: `autocutsel` (`pkg install autocutsel`) synchronizes CLIPBOARD ↔ PRIMARY on the Xvnc side, useful if `vncconfig` alone isn't enough.

---

## Integrated conventions

- **Theme**: `#0a0a0a` background, `#00ff88` accent in xterm (consistent with the dashboard).
- **Logs**: `~/tmp/xvnc.log`, `~/tmp/xstartup.log`, `~/tmp/websockify.log`.
- **PHP version**: the `~/www/desktop/index.php` card runs under PHP 8.* (it's not in `nodepulse/`, so no PHP 5.6 constraint).
- **Auth gate reuse**: no duplicate `cli-auth.php` / `cli-login.php` — same cookie, same single-login UX as all other protected apps.
