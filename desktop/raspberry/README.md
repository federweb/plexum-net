# NodePulse Desktop — Raspberry Pi

## Can the remote desktop be published from a Pi node?

**Yes, and it is the easiest port of the three.** Raspberry Pi OS is Debian,
so the WSL2 scripts already speak the right language (`apt`, `openbox`,
`tigervnc-standalone-server`, `xfdesktop4`). What changes is the environment
around them: a normal user with `sudo` instead of root, the unprivileged
nginx in `~/nginx/`, and a node that is started by systemd at boot with
nobody logged in.

## Why the WSL2 model (virtual display) and not the macOS model (native desktop)

The macOS port exposes the **native** desktop through the built-in Screen
Sharing VNC server because macOS always has a desktop running. A Pi node does
not:

- `install_raspberry/` targets **Raspberry Pi OS Lite**: no X11, no Wayland,
  no desktop at all. There is nothing native to expose.
- On the Desktop image (Bookworm+) the native session is Wayland
  (labwc/wayfire) served by `wayvnc`, which only exists while a user is
  logged in on the physical console, and negotiates TLS/RSA-AES auth that
  needs extra plumbing in noVNC.
- The node runs headless under `nodepulse.service`, usually without a monitor,
  often without autologin.

`Xvnc` ships its own framebuffer: it works on Lite, needs no monitor, no GPU,
no login, and starts from `start-server` like every other NodePulse service.
So the Pi reuses the WSL2/Termux pipeline unchanged:

```
Pi:     Xvnc :5902 (virtual display) ← openbox + tint2 + thunar
                 │
        websockify :6080 ──→ noVNC ──→ nginx /desktop/ ──→ auth_gate ──→ cloudflared
```

The browser experience is identical: open `https://<tunnel>/desktop/`, pass
the auth_gate, and get a full desktop via noVNC. On the LAN the same desktop
is at `http://raspberrypi.local:8080/desktop/`.

**Display :2 / port 5902, not :1 / 5901.** Raspberry Pi OS ships RealVNC
Server, whose own "Virtual Mode" defaults to display `:1` / port `5901` —
the same numbers TigerVNC-based ports traditionally use. Landing NodePulse
on `:1` collides head-on with that preexisting system service (same
display, same port, same lock file), not just an occasional stray process:
neither service's cleanup can ever touch the other's, so restarts leave one
of them permanently stuck holding the port. `:2` / `5902` keeps RealVNC's
virtual desktop and NodePulse's Openbox desktop independently reachable at
the same time. Also worth knowing: on this same binary name, Raspberry Pi
OS's `/usr/bin/Xvnc` is often RealVNC itself (running as `Xvnc-core` behind
an `Xvnc -rootHelper` wrapper) rather than TigerVNC — `start-desktop`
explicitly prefers `Xtigervnc`, a name only `tigervnc-standalone-server`
provides, to avoid picking it up by accident.

## Files

Run them in order, **as the same normal user that ran `rpi-setup.sh`**
(never root):

| # | File | What it does |
|---|------|--------------|
| 1 | `01-install_desktop_raspberry.sh` | Base plumbing: tigervnc + openbox + tint2 + xterm + X11 fonts (Lite has none), websockify (Debian package), noVNC v1.5.0, empty VNC passwd (auth is the auth_gate), `xstartup`, `loginctl enable-linger`, `start-desktop`/`stop-desktop` in `~/bin`, `/desktop/` location blocks injected into `~/nginx/nginx.conf`, integration with `start-server`/`stop-server`, dashboard card `~/www/desktop/`. |
| 2 | `02-configure_desktop_raspberry.sh` | Minimal desktop: pcmanfm, thunar, xfdesktop, xfce4-settings, xfce4-terminal, wired into `~/.config/openbox/autostart` with a dbus session and a runtime-dir fallback. |
| 3 | `03-customize_desktop_raspberry.sh` | Extra apps + full personalization: Chromium (Chrome has no arm64 build), VSCode (`code` from the Pi OS repo, Microsoft repo as fallback), taskbar, Thunar actions, GTK/icon theme, openbox keybindings, `~/Desktop` launchers. |

Prerequisite: the base NodePulse setup (`install_raspberry/rpi-setup.sh`) must
be completed first. The scripts check for `~/nginx/nginx.conf`,
`~/bin/start-server` and `~/bin/stop-server` and abort otherwise.

## WSL2 → Raspberry Pi differences

| WSL2 | Raspberry Pi |
|---|---|
| runs as root | normal user + `sudo` (scripts refuse to run as root) |
| `/etc/nginx/sites-available/nodepulse`, `nginx -t` | `~/nginx/nginx.conf`, `nginx -t -c … -p …` (unprivileged instance from `rpi-setup.sh`) |
| `apt-get install` | `sudo apt-get install`, packages missing from the Pi archive are skipped with a warning instead of aborting |
| `pkill -f …` | `pkill -u <uid> -f …` — a Pi is a shared, long-lived machine |
| WSLg quirks (`XDG_RUNTIME_DIR=/run/user/0`, `GDK_BACKEND`, xterm/zutty segfaults) | `loginctl enable-linger` + fallback to `~/tmp/xdg-runtime`; `GDK_BACKEND=x11` kept so GTK never attaches to a Wayland session on the HDMI output |
| Google Chrome `.deb` (amd64) + `--no-sandbox` | `chromium` from the repos, stock `.desktop`, no sandbox hacks |
| VSCode from the Microsoft repo (amd64) | `code` from the Raspberry Pi OS repo; Microsoft repo (arm64/armhf) only on plain Debian |
| `fonts-noto` + CJK sets | `fonts-noto-core` + DejaVu/Liberation only (SD card space) |
| amule, flatpak | dropped |
| redirects use `$real_scheme://$redirect_host` | same: tunnel → `https://<public>`, LAN → `http://raspberrypi.local:8080` keeps the port |

## Practical notes

1. **The desktop starts with the node.** `start-server` calls `start-desktop`
   before the tunnel block, and `start-server` is what `nodepulse.service`
   runs. Enable the unit and the desktop comes back after every reboot or
   power cut, with nobody logged in.

2. **`stop-desktop` / `start-desktop` are safe to run back-to-back on their
   own** — both wait for the old Xvnc/websockify to actually die before
   touching the display/port again, instead of trusting a fixed sleep. A
   full node restart still works too, for changes that need the rest of the
   stack (nginx conf, etc.):

   ```bash
   stop-desktop && start-desktop         # desktop only
   stop-server && start-server           # whole node, by hand
   sudo systemctl restart nodepulse      # whole node, under systemd
   ```

3. **Resolution and depth** default to 1280x720 @ 24 bit. Xvnc renders in
   software on the ARM cores, the VideoCore GPU is not involved, so a bigger
   framebuffer costs CPU on every update and bandwidth on the tunnel. Override
   per run with `DESKTOP_GEOMETRY=1600x900 DESKTOP_DEPTH=16 start-desktop`,
   or edit `~/bin/start-desktop`.

4. **Memory.** The base stack (Xvnc + openbox + tint2 + websockify) is under
   100 MB. Chromium and LibreOffice from script 03 are what hurt on a 1 GB
   Pi 3; on a Pi 4/5 with 2 GB+ they are fine. `server-status` shows memory
   and throttling.

5. **Authentication.** Xvnc binds to loopback with `-SecurityTypes None`; the
   only lock is the auth_gate session cookie checked by nginx `auth_request`
   on every `/desktop/` request, including the WebSocket upgrade. Set the
   auth_gate password before sharing the tunnel URL. The desktop hands out a
   session as your user, so the `sudo passwd -l pi` advice in
   `install_raspberry/INSTALL.txt` applies here too.

6. **Pi with a monitor attached and the Desktop image.** The Xvnc session is
   independent from whatever runs on HDMI: two different displays, two
   different sessions, same user. Nothing you do in the browser appears on
   the physical screen and vice versa.

7. **RealVNC's own virtual desktop keeps working, unchanged.** If you (or a
   past setup) already use Raspberry Pi OS's built-in RealVNC Server —
   connecting with a native VNC client to `:1` / port 5901, e.g. over
   WireGuard — that session is untouched by any of this. NodePulse's
   Openbox desktop lives entirely on `:2` / port 5902; the two are
   reachable at the same time and never fight over the same socket.

## Usage

```bash
scp -r desktop/raspberry pi@raspberrypi.local:~/
ssh pi@raspberrypi.local
cd ~/raspberry
bash 01-install_desktop_raspberry.sh
bash 02-configure_desktop_raspberry.sh
bash 03-customize_desktop_raspberry.sh      # optional, slow on a Pi

sudo systemctl restart nodepulse            # or: stop-server && start-server
```

Then open `https://<tunnel>.trycloudflare.com/desktop/` (or
`http://raspberrypi.local:8080/desktop/` on the LAN) and log in to the
auth_gate.

Logs: `~/tmp/xvnc.log`, `~/tmp/xstartup.log`, `~/tmp/websockify.log`
