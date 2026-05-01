#============================================================
# wsl2-import.ps1
# Creates the dedicated WSL2 distro "NodePulse" from Ubuntu 24.04,
# copies the setup files and runs wsl2-setup.sh inside it.
#
# Usage (PowerShell):
#   .\wsl2-import.ps1
#
# The distro is installed in:
#   $env:USERPROFILE\WSL\NodePulse\
#
# After setup, C: is NOT mounted (wsl.conf automount=false).
# To enter the distro: wsl -d NodePulse
#============================================================

$ErrorActionPreference = "Stop"

$DISTRO_NAME  = "NodePulse"
$INSTALL_DIR  = "$env:USERPROFILE\WSL\NodePulse"
$ROOTFS_URL   = "https://cloud-images.ubuntu.com/wsl/releases/24.04/current/ubuntu-noble-wsl-amd64-wsl.rootfs.tar.gz"
$ROOTFS_FILE  = "$env:TEMP\ubuntu-nodepulse-rootfs.tar.gz"
$SCRIPT_DIR   = Split-Path -Parent $MyInvocation.MyCommand.Path
$SETUP_DIR    = $SCRIPT_DIR
$CLI_DIR      = Join-Path (Split-Path $SCRIPT_DIR -Parent) "cli"

function Write-Step { param($msg) Write-Host "[...] $msg" -ForegroundColor Yellow }
function Write-OK   { param($msg) Write-Host "[OK]  $msg" -ForegroundColor Green  }
function Write-Warn { param($msg) Write-Host "[WARN] $msg" -ForegroundColor DarkYellow }
function Write-Err  { param($msg) Write-Host "[ERR] $msg" -ForegroundColor Red; exit 1  }

# Converts a Windows path to a WSL /mnt/... path
function To-WslPath {
    param([string]$winPath)
    $drive = $winPath.Substring(0, 1).ToLower()
    $rest  = $winPath.Substring(2) -replace "\\", "/"
    return "/mnt/$drive$rest"
}

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  NodePulse — WSL2 Distro Installer"        -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""

# ── Prerequisites ─────────────────────────────────────────

if (-not (Get-Command wsl -ErrorAction SilentlyContinue)) {
    Write-Err "WSL2 not found. Open PowerShell as Admin and run: wsl --install"
}

$wslVersion = (wsl --status 2>$null) -join " "
Write-OK "WSL available"

# ── Check if the distro already exists ───────────────────

$existing = (wsl --list --quiet 2>$null) |
    ForEach-Object { $_ -replace "`0","" } |
    Where-Object { $_.Trim() -eq $DISTRO_NAME }

if ($existing) {
    Write-Warn "Distro '$DISTRO_NAME' already exists."
    $choice = Read-Host "        Unregister and reinstall? [y/N]"
    if ($choice -notin @("s","S","y","Y")) { Write-Host "Cancelled."; exit 0 }
    Write-Step "Unregistering '$DISTRO_NAME'..."
    wsl --unregister $DISTRO_NAME
    Write-OK "Distro removed."
}

# ── Check setup files ─────────────────────────────────────

$requiredFiles = @("wsl2-setup.sh","nodepulse.sh","start-server","stop-server","server-status")
foreach ($f in $requiredFiles) {
    if (-not (Test-Path (Join-Path $SETUP_DIR $f))) {
        Write-Err "Missing file: $f"
    }
}

# ── Download rootfs ───────────────────────────────────────

if (-not (Test-Path $ROOTFS_FILE)) {
    Write-Step "Downloading Ubuntu 24.04 WSL rootfs (~200MB)..."
    try {
        Invoke-WebRequest -Uri $ROOTFS_URL -OutFile $ROOTFS_FILE -UseBasicParsing
        Write-OK "Rootfs downloaded."
    } catch {
        Write-Err "Download failed: $_"
    }
} else {
    Write-OK "Rootfs already cached: $ROOTFS_FILE"
}

# ── Create directory and import distro ───────────────────

New-Item -ItemType Directory -Force -Path $INSTALL_DIR | Out-Null
Write-OK "Installation directory: $INSTALL_DIR"

Write-Step "Importing '$DISTRO_NAME' into WSL2..."
wsl --import $DISTRO_NAME $INSTALL_DIR $ROOTFS_FILE --version 2
if ($LASTEXITCODE -ne 0) { Write-Err "wsl --import failed (exit $LASTEXITCODE)" }
Write-OK "Distro '$DISTRO_NAME' imported."

# ── Copy setup files into the distro ─────────────────────
# Note: at this point automount is still active, so
# we can use /mnt/c/ to copy the files.

Write-Step "Copying setup files into NodePulse..."

$tmpDir = "/tmp/nodepulse-setup"
wsl -d $DISTRO_NAME -- bash -c "mkdir -p $tmpDir"

foreach ($f in $requiredFiles) {
    $src = Join-Path $SETUP_DIR $f
    $wslSrc = To-WslPath $src
    wsl -d $DISTRO_NAME -- bash -c "cp '$wslSrc' $tmpDir/$f && chmod +x $tmpDir/$f"
}

# Copy cli/ if present (fallback for dev install)
if (Test-Path $CLI_DIR) {
    wsl -d $DISTRO_NAME -- bash -c "mkdir -p /tmp/cli"
    $cliFiles = Get-ChildItem -Path $CLI_DIR -File
    foreach ($f in $cliFiles) {
        $wslSrc = To-WslPath $f.FullName
        wsl -d $DISTRO_NAME -- bash -c "cp '$wslSrc' /tmp/cli/$($f.Name) 2>/dev/null || true"
    }
    Write-OK "cli/ copied to /tmp/cli/"
}

Write-OK "Files copied to $tmpDir"

# ── Run the setup ─────────────────────────────────────────

Write-Host ""
Write-Step "Running wsl2-setup.sh inside '$DISTRO_NAME'..."
Write-Host "  (setup output below)" -ForegroundColor DarkGray
Write-Host ""

wsl -d $DISTRO_NAME -- bash "$tmpDir/wsl2-setup.sh"

if ($LASTEXITCODE -ne 0) {
    Write-Err "Setup failed (exit $LASTEXITCODE). Check the output above."
}

# ── Restart distro to apply wsl.conf ─────────────────────

Write-Host ""
Write-Step "Restarting '$DISTRO_NAME' to apply C: isolation..."
wsl --terminate $DISTRO_NAME
Start-Sleep -Seconds 3
Write-OK "Distro restarted — C: is no longer mounted."

# ── Cleanup ───────────────────────────────────────────────

Write-Step "Cleaning up temporary setup files..."
wsl -d $DISTRO_NAME -- bash -c "rm -rf $tmpDir /tmp/cli 2>/dev/null || true"
Write-OK "Cleanup complete."

# ── Summary ───────────────────────────────────────────────

Write-Host ""
Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  NodePulse is ready!"                       -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Enter the distro:"
Write-Host "    wsl -d NodePulse" -ForegroundColor White
Write-Host ""
Write-Host "  Start the server:"
Write-Host "    start-server" -ForegroundColor White
Write-Host ""
Write-Host "  Stop the server:"
Write-Host "    stop-server" -ForegroundColor White
Write-Host ""
Write-Host "  Service status:"
Write-Host "    server-status" -ForegroundColor White
Write-Host ""
Write-Host "  Remove the distro (if needed):"
Write-Host "    wsl --unregister NodePulse" -ForegroundColor DarkGray
Write-Host ""
