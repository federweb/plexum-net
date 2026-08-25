# NodePulse — Raspberry Pi Installation

Turns a Raspberry Pi into an always-on Plexum node: PHP apps behind an
unprivileged nginx, published to the network through a Cloudflare tunnel,
restarted automatically by systemd after a reboot or a crash.

## Requirements

- Raspberry Pi 3, 4 or 5 (Pi 4 with 2GB+ recommended)
- **Raspberry Pi OS 64-bit** (Bookworm or newer), Lite is fine — no desktop needed
- A normal user account with `sudo` (the default `pi` user works)
- Internet access; no port forwarding and no public IP required

32-bit Raspberry Pi OS is detected and installed, but untested.

---

## Install

1. Copy `install_raspberry/` onto the Pi (or clone the repo there):
   ```bash
   scp -r install_raspberry pi@raspberrypi.local:~/
   ```

2. SSH into the Pi and run the installer **as your normal user, not root**:
   ```bash
   ssh pi@raspberrypi.local
   cd ~/install_raspberry
   bash rpi-setup.sh
   ```

   The script will:
   - Install PHP, nginx, Python, Node and cloudflared (`arm64` build)
   - Download the apps bundle into `~/www/`
   - Configure an unprivileged nginx on port 8080 with its own prefix `~/nginx/`
   - Generate the RSA node identity in `~/.nodepulse/`
   - Install `~/bin/` commands and a `nodepulse` systemd unit

   The `npm install peer` step takes several minutes on a Pi. That is normal.

3. Open a new shell (or `source ~/.bashrc`) so `~/bin` is on `PATH`.

---

## Start

Manually, in the foreground:

```bash
start-server
```

The public Cloudflare tunnel URL appears in the output once cloudflared
connects. Open it in a browser.

At boot, supervised by systemd — this is the point of running on a Pi:

```bash
sudo systemctl enable --now nodepulse
journalctl -u nodepulse -f          # follow the log, tunnel URL included
```

Use one or the other, not both at once: they would fight over port 8080.

---

## Other commands

```bash
stop-server        # Stop all services
server-status      # Services + SoC temperature, throttling, memory, disk
run-looped -12     # Restart the whole stack every 12 hours
```

`server-status` reports `vcgencmd get_throttled`. Anything other than `0x0`
means the Pi is being throttled — almost always an underpowered PSU, and the
most common cause of a node that silently degrades over days.

---

## How this differs from the WSL2 and Termux forks

| | WSL2 | Raspberry Pi |
|---|---|---|
| User | root | normal user (`pi`) |
| nginx | system-wide, `user root;` patched into `/etc/nginx` | unprivileged, own prefix `~/nginx/` |
| cloudflared | `linux-amd64` | `linux-arm64` (arch-detected) |
| Boot | `/etc/wsl.conf` boot command | `nodepulse.service` (systemd) |
| Isolation | dedicated throwaway distro | shared OS — see below |

`nodepulse.sh` itself is unchanged from the other Linux forks: Raspberry Pi OS
ships GNU coreutils, so `grep -oP` and `base64 -w0` behave identically.

---

## Security note

Unlike the WSL2 fork, this is not a disposable distro. The Pi is a real,
persistent machine on your LAN, and the tunnel exposes it publicly.

- Everything runs as your normal user, never root. Keep it that way: the
  Terminal and PulseTerminal apps hand out a shell as whichever user runs
  the stack, and on Raspberry Pi OS the default user often has passwordless
  `sudo`. Run `sudo passwd -l pi`, or drop the user from the `sudo` group,
  or install NodePulse under a dedicated unprivileged account.
- Set the `auth_gate` password on first access, before sharing the tunnel URL.
- The tunnel URL is public. Anything not behind `auth_gate` is world-readable.

---

## Files

```
rpi-setup.sh        Installer (run once)
nodepulse.sh        Tunnel capture + sign + announce  -> ~/bin/nodepulse
start-server        Start the whole stack             -> ~/bin/start-server
stop-server         Stop everything                   -> ~/bin/stop-server
server-status       Service + hardware health         -> ~/bin/server-status
run-looped          Periodic restart watchdog         -> ~/bin/run-looped
nodepulse.service   systemd unit template             -> /etc/systemd/system/
```

---

## Uninstall

```bash
sudo systemctl disable --now nodepulse
sudo rm /etc/systemd/system/nodepulse.service && sudo systemctl daemon-reload
pm2 delete peerserver 2>/dev/null; pm2 kill 2>/dev/null
rm -rf ~/www ~/nginx ~/bin ~/tmp ~/services ~/.nodepulse ~/.server-mode ~/.nginx-bin
sudo rm -f /etc/php/*/cgi/conf.d/nodepulse.ini
```

Deleting `~/.nodepulse/` destroys the node identity: the node rejoins the
network as a brand new `node_id`. Back that folder up if you want to keep it.
