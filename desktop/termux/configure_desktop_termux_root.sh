
#!/usr/bin/env bash

# =============================
# **** ATTENTION **** Before running this file, it is necessary to run the environment setup file first: install_desktop_termux.sh
# =============================


# ==============================================================================
# WARNING: SYSTEM COMPATIBILITY CHECK
# ------------------------------------------------------------------------------
# TECHNICAL NOTE:
# This package requires specific kernel-level permissions On standard non-rooted devices, 
# Android's will terminate the task.
#
# ERROR SYMPTOM:
# [Process completed (signal 9) - press Enter]
#
# REQUIREMENTS:
# 2. Root access OR Native Terminal environment
# ==============================================================================

# install-desktop.sh
# Install desktop tools and wire them into openbox autostart.
# Works on both WSL2 (running as root) and Termux proot-distro (Ubuntu/Debian).

set -e

# Use sudo only if we are not root and sudo is available
SUDO=""
if [ "$(id -u)" -ne 0 ] && command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
fi

$SUDO apt-get update
$SUDO apt-get install -y \
    pcmanfm \
    xfce4-settings \
    xfdesktop \
    thunar 

mkdir -p "$HOME/.config/openbox"

cat > "$HOME/.config/openbox/autostart" <<'EOF'
#!/bin/sh
# Openbox autostart

# Silence GTK "Locale not supported by C library" warnings
export LANG=C.UTF-8

# Give the current user its own XDG_RUNTIME_DIR.
# Needed on WSL2 when running as root: WSLg creates /mnt/wslg/runtime-dir
# owned by uid 1000, which dbus cannot use from uid 0.
# Harmless on Termux proot where the path already exists.
export XDG_RUNTIME_DIR=/run/user/$(id -u)
mkdir -p "$XDG_RUNTIME_DIR" && chmod 700 "$XDG_RUNTIME_DIR"

# Start a DBus session if one is not already running.
# WSL2: usually missing, we spawn one.
# Termux proot via noVNC: already set by openbox-session, skipped.
[ -z "$DBUS_SESSION_BUS_ADDRESS" ] && eval "$(dbus-launch --sh-syntax --exit-with-session)"

xfsettingsd & (sleep 1 && xfdesktop) & thunar --daemon
EOF

chmod +x "$HOME/.config/openbox/autostart"

echo "[+] Done. Restart the termux session to apply."