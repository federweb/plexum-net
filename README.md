# Plexum / NodePulse

## What Is Plexum

Today's internet is locked behind a handful of cloud providers. Publishing anything online requires a domain, a static IP, and recurring costs. For billions of people on phones or behind NAT, running a server is simply not an option.

Plexum flips this model. Any device with an internet connection — an Android phone in a drawer, a Windows laptop, a Raspberry Pi — becomes a publicly reachable server. It runs a local HTTPS server, exposes it through a relay tunnel, and announces its URL to the network with a cryptographic signature. When the URL changes, the node signs and announces the new one. A browser-side recovery system ensures visitors are never lost.

The protocol that powers this is called **NodePulse**. It handles identity, discovery, gossip propagation, and self-healing — no account, no registration, no centralized authority. Your identity is your RSA key pair, and it persists across URL changes, device restarts, and relay migrations.

---

## Installation

The canonical, always up‑to‑date installation guide lives at:

**→ https://www.plexum.net/install.php**

All supported platforms (Termux/Android, WSL2/Windows, macOS and Raspberry Pi OS) produce identical nodes with the same identity model and protocol behavior. The legacy MSYS2 port (`install_msys2/`) is deprecated and no longer supported.

### Prerequisites

**Termux (Android)**
- Android 7 or later
- Termux app installed (use the GitHub release — the Play Store build is outdated)
- ~200 MB free storage
- Active internet connection

**WSL2 (Windows)**
- Windows 10 (build 2004+) or Windows 11
- WSL2 enabled — if not yet enabled, open PowerShell as Administrator and run:
  ```
  wsl --install
  ```
- ~200 MB download
- Active internet connection

**macOS (Apple)**
- macOS 13 (Ventura) or later — older releases are no longer supported by Homebrew
- Intel or Apple Silicon
- ~3 GB free storage: the installer bootstraps Homebrew (and the Xcode Command Line Tools) if missing, then pulls PHP, nginx, Python and Node.js
- Active internet connection

**Raspberry Pi**
- Raspberry Pi 3, 4 or 5 (Pi 4 with 2 GB+ recommended)
- Raspberry Pi OS 64‑bit, Bookworm or newer — Lite is fine, no desktop needed (32‑bit is detected and installed, but untested)
- A normal user account with `sudo` (the default `pi` user works) — **do not run the installer as root**
- A power supply that can actually feed the board: undervolt throttling is the most common cause of a node degrading over days
- Active internet connection — no port forwarding and no public IP required

### Termux (Android)

1. **Install** — run in Termux:
   ```
   pkg install -y wget && wget https://www.plexum.net/nodepulse/core-dist/install_termux.zip && unzip install_termux.zip && bash ./termux-setup.sh
   ```
   The installer pulls dependencies (PHP, nginx/lighttpd, cloudflared, OpenSSL, Python, Node.js, tmux), generates the RSA‑2048 keypair, writes the node identity to `~/.nodepulse/node_identity.json`, and lays out the web server files.

2. **Start the server:**
   ```
   bash ~/bin/start-server
   ```
   The web server starts on port 8080 (PHP runs behind it via FastCGI) and a cloudflared tunnel is established with a public URL.

3. **First access** — open the tunnel URL in a browser. On first access you'll set the password that protects Terminal, Cloud, File Manager and the other gated apps. Local access is available at `http://localhost:8080`.

4. **Stop the server:**
   ```
   bash ~/bin/stop-server
   ```

### WSL2 (Windows)

1. **Download the installer:**
   Direct link: https://www.plexum.net/nodepulse/core-dist/install_wsl2.zip

   Or via PowerShell:
   ```
   curl.exe -L -o "$env:USERPROFILE\Desktop\install_wsl2.zip" https://www.plexum.net/nodepulse/core-dist/install_wsl2.zip
   ```

2. **Install** — open PowerShell **as Administrator**:
   ```
   cd C:\path\to\install_wsl2
   .\wsl2-import.ps1
   ```
   The script downloads Ubuntu 24.04, creates an isolated `NodePulse` distro, installs PHP/nginx/Python/cloudflared and generates the node identity.

3. **Start services:**
   ```
   wsl -d NodePulse
   start-server
   ```

4. **First access** — open the tunnel URL printed by the script. First access requires password setup, just like on Termux.

5. **Stop / status:**
   ```
   stop-server
   server-status
   ```

6. **VS Code integration** — install the WSL extension (`ms-vscode-remote.remote-wsl`), mount drive `C`, then run *"WSL: Connect to WSL using Distro"*, pick `NodePulse` and open `/root/www`.

7. **Uninstall:**
   ```
   wsl --unregister NodePulse
   Remove-Item -Recurse -Force "$env:USERPROFILE\WSL\NodePulse"
   ```

### macOS (Apple)

1. **Install** — open the built-in **Terminal** app and run:
   ```
   curl -L -O https://www.plexum.net/nodepulse/core-dist/install_osx.zip && unzip -o install_osx.zip && bash ./osx-setup.sh
   ```
   `curl` and `unzip` ship with macOS. The installer pulls dependencies via Homebrew (PHP, nginx, cloudflared, Python, Node.js, tmux), installing Homebrew first if it's missing, then generates the RSA‑2048 keypair, writes the node identity to `~/.nodepulse/node_identity.json`, and lays out the web server files.

2. **Start the server:**
   ```
   bash ~/bin/start-server
   ```
   nginx starts on port 8080 (PHP runs behind it via FastCGI) and a cloudflared tunnel is established with a public URL.

3. **First access** — open the tunnel URL in a browser. On first access you'll set the password that protects Terminal, Cloud, File Manager and the other gated apps. Local access is available at `http://localhost:8080`.

4. **Stop / status:**
   ```
   bash ~/bin/stop-server
   bash ~/bin/server-status
   ```
   In any new terminal the bare `start-server`, `stop-server` and `server-status` commands are also on PATH.

5. **Uninstall:**
   ```
   pm2 delete peerserver 2>/dev/null; rm -rf ~/.nodepulse ~/www ~/nodepulse-bin ~/services/peerserver ~/.server-mode ~/bin/start-server ~/bin/stop-server ~/bin/server-status ~/bin/nodepulse
   ```
   Homebrew packages (php, nginx, cloudflared, python, tmux, node) stay installed — remove them with `brew uninstall` if no longer needed, and delete the `# NodePulse` block from your shell rc file.

### Raspberry Pi

1. **Install** — SSH into the Pi and run this **as your normal user, not root**:
   ```
   curl -L -O https://www.plexum.net/nodepulse/core-dist/install_raspberry.zip && unzip -o install_raspberry.zip && bash ./rpi-setup.sh
   ```
   The installer pulls dependencies (PHP, nginx, Python, Node.js, tmux, and the arch‑detected `linux-arm64`/`armhf` cloudflared build), downloads the apps bundle into `~/www/`, configures an **unprivileged** nginx on port 8080 with its own prefix `~/nginx/` (the system‑wide nginx service is disabled), generates the RSA‑2048 keypair in `~/.nodepulse/`, installs the `~/bin/` commands and the `nodepulse` systemd unit (installed, not enabled).

   The `npm install peer` step takes several minutes on a Pi. That is normal — let it finish.

2. **Open a new shell** (or `source ~/.bashrc`) so `~/bin` lands on your `PATH`.

3. **Start the server** — in the foreground, for a first test:
   ```
   start-server
   ```
   The public Cloudflare tunnel URL appears in the output once cloudflared connects.

4. **Start at boot** — supervised by systemd, which is the point of running on a Pi:
   ```
   sudo systemctl enable --now nodepulse
   journalctl -u nodepulse -f
   ```
   Use one or the other, never both at once: the foreground `start-server` and the unit compete for port 8080.

5. **First access** — open the tunnel URL in a browser. On first access you'll set the password that protects Terminal, Cloud, File Manager and the other gated apps. Local access is available at `http://localhost:8080`.

6. **Stop / status:**
   ```
   stop-server
   server-status
   ```
   On a Pi `server-status` also reports SoC temperature, throttling (`vcgencmd get_throttled`), memory and disk. Anything other than `0x0` means the board is being throttled — almost always an underpowered PSU.

7. **Security note** — unlike the WSL2 fork, this is not a disposable distro: the Pi is a persistent machine on your LAN and the tunnel exposes it publicly. The Terminal and PulseTerminal apps hand out a shell as whichever user runs the stack, and on Raspberry Pi OS the default user often has passwordless `sudo`. Run `sudo passwd -l pi`, or drop the user from the `sudo` group, or install NodePulse under a dedicated unprivileged account. Set the auth gate password **before** sharing the tunnel URL — anything not behind it is world‑readable.

8. **Uninstall:**
   ```
   sudo systemctl disable --now nodepulse
   sudo rm /etc/systemd/system/nodepulse.service && sudo systemctl daemon-reload
   pm2 delete peerserver 2>/dev/null; pm2 kill 2>/dev/null
   rm -rf ~/www ~/nginx ~/bin ~/tmp ~/services ~/.nodepulse ~/.server-mode ~/.nginx-bin
   sudo rm -f /etc/php/*/cgi/conf.d/nodepulse.ini
   ```
   Deleting `~/.nodepulse/` destroys the node identity: the node rejoins the network as a brand new `node_id`. Back that folder up if you want to keep it.

Full details, including a WSL2/Raspberry Pi comparison and extended troubleshooting, are in [`install_raspberry/README.md`](install_raspberry/README.md).

### Scheduled restart (`run-looped`)

`run-looped` is a watchdog that periodically restarts the whole server stack. Every *N* hours it invokes `stop-server`, waits 5 seconds, then invokes `start-server` again — keeping the node fresh over long uptimes.

**Usage:**
```
run-looped -<hours>
```

- `<hours>` — interval (in hours) between automatic restarts. The parameter is **mandatory**: run without it and the command prints a usage notice and exits.

**Example** — restart the server every 12 hours:
```
run-looped -12
```

The command is installed alongside `start-server`/`stop-server` (on PATH for WSL2 and Raspberry Pi, where `~/bin` is added to `PATH` by `.bashrc`; under `~/bin/` on Termux and macOS, e.g. `bash ~/bin/run-looped -12`). On a Raspberry Pi running under systemd the watchdog is redundant: the `nodepulse` unit already has `Restart=always`. It runs a long-lived loop, so launch it inside `tmux` (or with `nohup`) to keep it alive after you close the session. Press `Ctrl-C` to stop the loop cleanly (it runs `stop-server` on exit). Note: each restart of the cloudflared quick tunnel produces a new public URL.

### How it works

The installer generates an RSA‑2048 keypair (the unique node identity), starts the web server on port 8080, opens a cloudflared public tunnel, signs announcements with the private key, and propagates the signed announcement to seed nodes via the gossip protocol for network‑wide distribution.

### Troubleshooting

| Issue | Solution |
|-------|----------|
| Tunnel URL missing | Cloudflared takes 10–30 seconds — check the connection and wait. |
| Port 8080 in use | Run `stop-server` (bare command on WSL2 and Raspberry Pi; `bash ~/bin/stop-server` on Termux/macOS) to free it. |
| Node not visible in monitor | Network propagation takes 1–2 minutes — refresh the monitor page. |
| PHP errors / 502 on Raspberry Pi | Check `~/nginx/logs/error.log` — the unprivileged nginx does not log to `/var/log/nginx`. |
| Node slow or unstable on Raspberry Pi | Run `server-status`: a throttling value other than `0x0` means an underpowered power supply. |
| Full removal | `rm -rf ~/.nodepulse ~/www ~/.server-mode ~/services/peerserver ~/bin/start-server ~/bin/stop-server ~/bin/server-status ~/bin/nodepulse` — on macOS use the Uninstall step above. |

**Support:** dev@plexum.org

---

# NodePulse — Node Guide (`www/` folder)

This repository mirrors the `www/` folder of an installed NodePulse node. The sections below describe what's inside it.

## Do not delete

These folders and files are essential for your node to work on the Plexum network:

- **`nodepulse/`** — Node backend (API, gossip, cryptographic verification, configuration)
- **`beacon/`** — Recovery Browser: lets visitors find your node even when the tunnel URL changes
- **`nodepulse-sw.js`** — Service Worker registration script and connectivity monitor
- **`cli/`** — Shell Manager do not remove or rename if want to use shell
- **`auth_gate.php`** — Shared session authentication gate for all protected apps
- **`change-password.php`** — Password change page for the auth gate
- **`cli-auth.php`** — Internal auth endpoint used by nginx `auth_request` for the shell
- **`cli-login.php`** — Login wrapper that routes unauthenticated shell access through the auth gate
- **`logout.php`** — Logout from all procedure

If removed, the node will stop working on the network.

## Everything else is yours

You can remove any other file or folder and deploy whatever you want: your website, your app, your cms, shop, blog.

The only requirement to integrate your content into the Plexum network is to include `nodepulse-sw.js` in every HTML page, for example in the footer:

```html
<script src="/nodepulse-sw.js"></script>
```

### What this script does

When a visitor opens one of your pages, the script does two things:

1. **Registers a Service Worker** with scope `/` that caches the beacon pages. If the tunnel goes down and the site becomes unreachable, the Service Worker automatically serves the Recovery Browser from the visitor's browser cache — no server needed.

2. **Monitors connectivity** with a ping every 5 seconds. After 2 consecutive failed pings, it saves the current page and redirects the visitor to the Recovery Browser (`/beacon/`), where they can look up the node's new address on the network and return exactly where they were.

In short: your visitors never lose you. Even if the tunnel URL changes, their browser already knows how to find you again.
