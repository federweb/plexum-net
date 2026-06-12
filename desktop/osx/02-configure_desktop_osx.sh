#!/usr/bin/env bash

# =============================
# **** ATTENTION **** Before running this file, it is necessary to run the environment setup file first: 01-install_desktop_osx.sh
# =============================

# configure_desktop_osx.sh
# macOS counterpart of configure_desktop_wsl2.sh.
#
# On WSL2 this step installs the minimal desktop (thunar/xfdesktop/dbus)
# because the VM has no GUI at all. macOS already ships a full desktop
# (Finder, Dock, Aqua), so here the job is different: prepare the NATIVE
# desktop for unattended remote access via Screen Sharing + noVNC —
# keep the machine awake, kill the screensaver, and apply sane
# Finder/Dock defaults for remote work.

set -e

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

echo -e "\n${BOLD}NodePulse Desktop — configuration (macOS)${NC}"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Power management: never sleep (requires sudo)
# A sleeping Mac drops the Cloudflare tunnel AND the VNC session.
# displaysleep 0 too: on some Macs Screen Sharing renders a black
# frame buffer once the display has gone to sleep.
# ─────────────────────────────────────────────────────────────
step "1/4  Power management (pmset)"

if sudo pmset -a sleep 0 disksleep 0 displaysleep 0 2>/dev/null; then
    sudo pmset -a autorestart 1 2>/dev/null || warn "autorestart not supported on this model"
    sudo pmset -a womp 1        2>/dev/null || warn "wake-on-lan not supported on this model"
    ok "system sleep disabled (sleep/disksleep/displaysleep = 0, autorestart on)"
else
    warn "pmset failed — disable sleep manually: System Settings → Energy / Lock Screen"
fi

# ─────────────────────────────────────────────────────────────
# STEP 2 — Screensaver off
# The screensaver locks the session: every noVNC reconnect would
# require the macOS password again on top of auth_gate.
# ─────────────────────────────────────────────────────────────
step "2/4  Screensaver"

defaults -currentHost write com.apple.screensaver idleTime -int 0
ok "screensaver disabled (idleTime = 0)"
warn "if 'require password after screensaver' is enforced by policy, adjust it in System Settings → Lock Screen"

# ─────────────────────────────────────────────────────────────
# STEP 3 — Finder defaults (remote-work friendly)
# ─────────────────────────────────────────────────────────────
step "3/4  Finder defaults"

defaults write com.apple.finder AppleShowAllFiles -bool true          # show hidden files
defaults write com.apple.finder ShowPathbar -bool true                # path bar
defaults write com.apple.finder ShowStatusBar -bool true              # status bar
defaults write com.apple.finder FXEnableExtensionChangeWarning -bool false
defaults write com.apple.finder ShowHardDrivesOnDesktop -bool true
defaults write com.apple.finder ShowExternalHardDrivesOnDesktop -bool true
defaults write com.apple.finder FXPreferredViewStyle -string "Nlsv"   # list view by default
defaults write NSGlobalDomain AppleShowAllExtensions -bool true
defaults write NSGlobalDomain NSNavPanelExpandedStateForSaveMode -bool true

killall Finder 2>/dev/null || true
ok "Finder configured (hidden files, path/status bar, list view)"

# ─────────────────────────────────────────────────────────────
# STEP 4 — Dock defaults
# ─────────────────────────────────────────────────────────────
step "4/4  Dock defaults"

defaults write com.apple.dock minimize-to-application -bool true
defaults write com.apple.dock mru-spaces -bool false                  # don't reorder Spaces
defaults write com.apple.dock autohide -bool false                    # keep Dock visible over VNC
defaults write com.apple.dock show-recents -bool false

killall Dock 2>/dev/null || true
ok "Dock configured"

echo ""
echo "[+] Done. Restart the server (stop-server && start-server) to apply the desktop config."
echo "    For extra apps and full personalization, run 03-customize_desktop_osx.sh."
