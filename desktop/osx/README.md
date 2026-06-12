# NodePulse Desktop — macOS

## Can the Openbox procedure be replicated on macOS?

**Openbox itself: no. The end result: yes.**

Openbox is a Linux X11 window manager, and the entire GTK stack used by the
WSL2/Termux versions (tint2, thunar, pcmanfm, xfce4-*) does not exist in
Homebrew — there is no apt and no Linux desktop environment on macOS.

But macOS already ships a full desktop (Finder, Dock, Aqua). So instead of
building a virtual X11 desktop inside a VNC server, this port exposes the
**native macOS desktop** through the built-in **Screen Sharing** VNC server
(port 5900) and reuses the rest of the pipeline unchanged:

```
WSL2:   Xvnc :5901 (virtual display) ← openbox + tint2 + thunar
                 │
        websockify :6080 ──→ noVNC ──→ nginx /desktop/ ──→ auth_gate

macOS:  Screen Sharing :5900 (native desktop — Finder, Dock, Aqua)
                 │
        websockify :6080 ──→ noVNC ──→ nginx /desktop/ ──→ auth_gate
```

The browser experience is identical: open `https://<tunnel>/desktop/`,
pass the auth_gate, and get a full remote desktop via noVNC.

## Files

Run them in order:

| # | File | What it does |
|---|------|--------------|
| 1 | `01-install_desktop_osx.sh` | Base plumbing: enables Screen Sharing (`launchctl`), installs websockify + noVNC v1.5.0, writes `start-desktop`/`stop-desktop` (websockify bridge 6080 → 5900 — no Xvnc/openbox to launch), injects the `/desktop/` location blocks into `~/nodepulse-bin/nginx.conf`, integrates with `start-server`/`stop-server`, adds the dashboard card. |
| 2 | `02-configure_desktop_osx.sh` | The WSL2 equivalent installs the minimal desktop (thunar/xfdesktop) because the VM has no GUI. On macOS the desktop already exists, so this step prepares it for unattended remote use: disables sleep and the screensaver (`pmset`, `defaults`), applies remote-friendly Finder and Dock defaults. |
| 3 | `03-customize_desktop_osx.sh` | Apps + personalization: Chrome, VSCode, iTerm2, FileZilla, Keka, LibreOffice and fonts via brew casks; Chrome as default browser; Dock layout via `dockutil`; app shortcuts on `~/Desktop`. |

Prerequisite: the base NodePulse setup (`install_osx/osx-setup.sh`) must be
completed first — the scripts check for it and abort otherwise.

## Linux → macOS app mapping

| WSL2 (Linux) | macOS |
|---|---|
| openbox (window manager) | Aqua (built-in) |
| Xvnc / Xtigervnc (virtual display) | Screen Sharing (built-in VNC, physical display) |
| thunar / pcmanfm (file manager) | Finder (built-in) |
| tint2 (taskbar + launchers) | Dock (layout via `dockutil`) |
| xfce4-terminal | iTerm2 (cask) |
| xfce4-taskmanager | Activity Monitor (built-in) + htop |
| xarchiver / p7zip | Keka (cask) + p7zip (formula) |
| Google Chrome (.deb) | google-chrome (cask) |
| VSCode (Microsoft apt repo) | visual-studio-code (cask, ships the `code` CLI) |
| evince / gthumb (viewers) | Preview (built-in) |
| libreoffice-writer/calc | libreoffice (cask) |
| `.desktop` launchers on `~/Desktop` | `.app` symlinks on `~/Desktop` |
| openbox `rc.xml` keybindings | **not replicable** — Aqua owns global shortcuts; use System Settings → Keyboard → Shortcuts |

## Practical differences to know

1. **Authentication is double-layered.** As on WSL2, nginx gates `/desktop/`
   behind the shared auth_gate session (`auth_request` → `cli-auth.php`).
   On top of that, noVNC ≥ 1.1 natively supports Apple Remote Desktop
   authentication (RFB security type 30): after the gate, the user enters
   their **macOS username and password** in the noVNC credentials dialog.
   No `kickstart` hacks and no VNC legacy password are needed.

2. **The physical console is shared.** Unlike Xvnc (an invisible virtual
   display), Screen Sharing mirrors the real screen: anyone sitting in
   front of the Mac sees the remote session, and vice versa.

3. **The Mac must stay awake.** A sleeping Mac drops both the Cloudflare
   tunnel and the VNC session — `02-configure_desktop_osx.sh` disables
   system/display sleep and the screensaver for exactly this reason.

4. **nginx layout differs.** macOS uses a single self-contained
   `~/nodepulse-bin/nginx.conf` (no `sites-available`). The `/desktop/`
   blocks and the `$real_scheme` fix are injected inside the `server`
   block (nginx `set` is not valid at `http` level, where the macOS conf
   keeps `client_max_body_size`).

## Usage

```bash
bash 01-install_desktop_osx.sh
bash 02-configure_desktop_osx.sh
bash 03-customize_desktop_osx.sh

stop-server && start-server
```

Then open `https://<tunnel>.trycloudflare.com/desktop/` — log in to the
auth_gate, then with your macOS account in the noVNC dialog.

Logs: `~/tmp/websockify.log`
