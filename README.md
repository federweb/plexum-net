# Plexum / NodePulse

## What Is Plexum

Today's internet is locked behind a handful of cloud providers. Publishing anything online requires a domain, a static IP, and recurring costs. For billions of people on phones or behind NAT, running a server is simply not an option.

Plexum flips this model. Any device with an internet connection — an Android phone in a drawer, a Windows laptop, a Raspberry Pi — becomes a publicly reachable server. It runs a local HTTPS server, exposes it through a relay tunnel, and announces its URL to the network with a cryptographic signature. When the URL changes, the node signs and announces the new one. A browser-side recovery system ensures visitors are never lost.

The protocol that powers this is called **NodePulse**. It handles identity, discovery, gossip propagation, and self-healing — no account, no registration, no centralized authority. Your identity is your RSA key pair, and it persists across URL changes, device restarts, and relay migrations.

---

## Installation

The canonical, always up‑to‑date installation guide lives at:

**→ https://www.plexum.net/install.php**

All supported platforms (Termux/Android, WSL2/Windows and macOS) produce identical nodes with the same identity model and protocol behavior. The legacy MSYS2 port (`install_msys2/`) is deprecated and no longer supported.

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

### How it works

The installer generates an RSA‑2048 keypair (the unique node identity), starts the web server on port 8080, opens a cloudflared public tunnel, signs announcements with the private key, and propagates the signed announcement to seed nodes via the gossip protocol for network‑wide distribution.

### Troubleshooting

| Issue | Solution |
|-------|----------|
| Tunnel URL missing | Cloudflared takes 10–30 seconds — check the connection and wait. |
| Port 8080 in use | Run `stop-server` (or `bash ~/bin/stop-server` on Termux/macOS) to free it. |
| Node not visible in monitor | Network propagation takes 1–2 minutes — refresh the monitor page. |
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
