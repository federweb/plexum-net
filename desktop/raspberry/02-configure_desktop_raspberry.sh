#!/usr/bin/env bash

# =============================
# **** ATTENTION **** Before running this file, it is necessary to run the environment setup file first: 01-install_desktop_raspberry.sh
# =============================

# 02-configure_desktop_raspberry.sh
# Raspberry Pi counterpart of 02-configure_desktop_wsl2.sh.
# Installs the minimal desktop tools (thunar, xfdesktop, xfce4-terminal)
# and wires them into the openbox autostart of the Xvnc session.
#
# Runs as a normal user with sudo (never root). The only Pi-specific
# part is XDG_RUNTIME_DIR: when the desktop is started by the nodepulse
# systemd unit there is no login session, so /run/user/<uid> exists only
# if 01 managed to enable linger — otherwise we fall back to ~/tmp.

set -e

if [ "$(id -u)" -eq 0 ]; then
    echo "[FAIL] Do not run this as root — run it as the user that ran rpi-setup.sh" >&2
    exit 1
fi

SUDO=""
if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
fi

$SUDO apt-get update
$SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y \
    pcmanfm \
    xfce4-settings \
    xfdesktop4 \
    xfce4-terminal \
    thunar \
    dbus-x11

mkdir -p "$HOME/.config/openbox"

cat > "$HOME/.config/openbox/autostart" <<'EOF'
#!/bin/sh
# Openbox autostart (NodePulse Desktop — Raspberry Pi)

# Silence GTK "Locale not supported by C library" warnings
export LANG=C.UTF-8

# Always talk to Xvnc, never to a Wayland compositor that may be running
# on the Pi's physical output (Raspberry Pi OS Desktop uses labwc/wayfire).
export GDK_BACKEND=x11
unset WAYLAND_DISPLAY

# XDG_RUNTIME_DIR for dbus/gvfs/xfconf.
# /run/user/<uid> is created by systemd-logind only for logged-in users
# or for accounts with linger enabled (01 does that). When the desktop is
# started by the nodepulse systemd unit without linger, fall back to a
# private directory in ~/tmp.
RUNTIME_DIR="/run/user/$(id -u)"
if [ ! -d "$RUNTIME_DIR" ] || [ ! -w "$RUNTIME_DIR" ]; then
    RUNTIME_DIR="$HOME/tmp/xdg-runtime"
    mkdir -p "$RUNTIME_DIR" && chmod 700 "$RUNTIME_DIR"
fi
export XDG_RUNTIME_DIR="$RUNTIME_DIR"

# Start a DBus session if one is not already running.
[ -z "$DBUS_SESSION_BUS_ADDRESS" ] && eval "$(dbus-launch --sh-syntax --exit-with-session)"

# Ensure the desktop folder exists before xfdesktop starts: on a fresh
# install it is missing and xfdesktop degrades its right-click menu
# (no Create Launcher/Folder/Document entries) until restarted.
mkdir -p "$HOME/Desktop"

# 3s delay: on first boot dbus/gvfs and xfconf caches are still being
# built (slowly, on an SD card); starting xfdesktop too early leaves it
# on a degraded GIO layer.
xfsettingsd & (sleep 3 && xfdesktop) & thunar --daemon
EOF

chmod +x "$HOME/.config/openbox/autostart"

echo "[+] Done. Restart the node to apply the desktop config:"
echo "    by hand:        stop-server && start-server"
echo "    under systemd:  sudo systemctl restart nodepulse"
echo "    For extra apps and full personalization, run 03-customize_desktop_raspberry.sh."
