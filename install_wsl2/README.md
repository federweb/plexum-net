# NodePulse — WSL2 Installation

## Requirements

- Windows 10 (2004+) or Windows 11
- WSL2 enabled — if not, open PowerShell as Administrator and run:
  ```powershell
  wsl --install
  ```
  Then reboot.

---

## Install

1. Download `install_wsl2.zip` and unzip it anywhere.

2. Open **PowerShell as Administrator**, navigate to the unzipped folder:
   ```powershell
   cd C:\path\to\install_wsl2
   ```

3. Run the installer:
   ```powershell
   .\wsl2-import.ps1
   ```

   The script will:
   - Download Ubuntu 24.04 (~200MB, saved in `%TEMP%` for reuse)
   - Create the `NodePulse` WSL2 distro
   - Install PHP, nginx, Python, cloudflared and all services
   - Isolate the distro from the Windows filesystem (C: not mounted)

   Wait for **"NodePulse is ready!"** to appear before continuing.

---

## Start

1. Open PowerShell (no admin needed) and enter the distro:
   ```powershell
   wsl -d NodePulse
   ```

2. Start all services:
   ```bash
   start-server
   ```

   The public Cloudflare tunnel URL will appear in the output once cloudflared connects. Open that URL in your browser.

---

## Edit files with VS Code

The NodePulse filesystem is isolated from Windows, so you cannot browse it via Explorer.
Use VS Code instead.

0. Mount disk C into WSL:
   ```bash
   mount -t drvfs C: /mnt/c
   ```

1. Install the **WSL** extension in VS Code.

2. Open VS Code on Windows, press `Ctrl+Shift+P` and run:
   **WSL: Connect to WSL using Distro** — select `NodePulse`.

3. Once connected: **File > Open Folder > `/root/www`**

---

## Other commands (run inside the distro)

```bash
stop-server      # Stop all services
server-status    # Check the status of all services
```

---

## Uninstall

From PowerShell (outside the distro):
```powershell
wsl --unregister NodePulse
```

To also delete the distro files:
```powershell
Remove-Item -Recurse -Force "$env:USERPROFILE\WSL\NodePulse"
```
