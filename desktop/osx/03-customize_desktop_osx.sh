#!/usr/bin/env bash
# =============================================================
# NodePulse Desktop — customize_desktop_osx.sh
# macOS counterpart of customize_desktop_wsl2.sh.
# Installs extra apps + applies user customizations.
#
# Run order:
#   1) 01-install_desktop_osx.sh    (Screen Sharing + websockify + noVNC — base)
#   2) 02-configure_desktop_osx.sh  (native desktop prep: no sleep, Finder/Dock)
#   3) 03-customize_desktop_osx.sh  (this file — apps + full personalization)
#
# Linux → macOS app mapping (no apt, no GTK stack on macOS):
#   thunar/pcmanfm      → Finder (built-in, configured in 02)
#   tint2 taskbar       → Dock (layout managed here via dockutil)
#   xfce4-terminal      → iTerm2 (cask)
#   xfce4-taskmanager   → Activity Monitor (built-in) + htop (CLI)
#   xarchiver/p7zip     → Keka (cask) + p7zip (formula)
#   google-chrome .deb  → google-chrome (cask)
#   VSCode apt repo     → visual-studio-code (cask, ships the `code` CLI)
#   evince/gthumb       → Preview (built-in)
#   libreoffice w/c     → libreoffice (cask)
#   .desktop launchers  → Dock items + ~/Desktop app symlinks
#   openbox rc.xml keys → not replicable (Aqua owns global keybindings);
#                         use System Settings → Keyboard → Shortcuts.
#
# Safe to re-run: every step checks before acting.
# =============================================================

set -e

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*" >&2; exit 1; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

# Homebrew bootstrap (Apple Silicon: /opt/homebrew not on default PATH)
if ! command -v brew > /dev/null 2>&1; then
    if [ -x /opt/homebrew/bin/brew ]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    elif [ -x /usr/local/bin/brew ]; then
        eval "$(/usr/local/bin/brew shellenv)"
    fi
fi
command -v brew > /dev/null 2>&1 || fail "Homebrew not found — run osx-setup.sh first"
BREW_PREFIX="$(brew --prefix)"

echo -e "\n${BOLD}NodePulse Desktop — customization (macOS)${NC}"
echo "Working directory: $HOME"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Extra CLI formulas
# ─────────────────────────────────────────────────────────────
step "1/6  Extra brew formulas"

EXTRA_FORMULAS=(
    dockutil            # Dock layout from the CLI (step 5)
    defaultbrowser      # set default browser from the CLI (step 3)
    htop                # task manager in PulseTerminal
    rclone dos2unix wget
    p7zip unar          # archive support for the CLI
)

for f in "${EXTRA_FORMULAS[@]}"; do
    if brew list --formula "$f" > /dev/null 2>&1; then
        skip "$f already installed"
    else
        brew install "$f" > /dev/null 2>&1 && ok "$f installed" \
            || warn "$f install failed (continuing)"
    fi
done

# ─────────────────────────────────────────────────────────────
# STEP 2 — GUI apps + fonts (brew casks)
# Fonts live in the main homebrew/cask repo since mid-2024
# (the old homebrew/cask-fonts tap is deprecated — do not tap it).
# ─────────────────────────────────────────────────────────────
step "2/6  GUI apps + fonts (casks)"

EXTRA_CASKS=(
    google-chrome
    visual-studio-code   # ships the `code` CLI via the cask binary stanza
    iterm2
    filezilla
    keka                 # archiver (xarchiver equivalent)
    libreoffice
    font-fira-code
    font-jetbrains-mono
)

for c in "${EXTRA_CASKS[@]}"; do
    if brew list --cask "$c" > /dev/null 2>&1; then
        skip "$c already installed"
    else
        brew install --cask "$c" > /dev/null 2>&1 && ok "$c installed" \
            || warn "$c install failed (continuing)"
    fi
done

# ─────────────────────────────────────────────────────────────
# STEP 3 — Chrome as default browser
# Unlike Linux xdg-settings, macOS shows a confirmation dialog on
# the desktop the first time — accept it via VNC or the local screen.
# ─────────────────────────────────────────────────────────────
step "3/6  Default browser"

if command -v defaultbrowser > /dev/null 2>&1 && [ -d "/Applications/Google Chrome.app" ]; then
    CURRENT="$(defaultbrowser 2>/dev/null | sed -nE 's/^\* +//p')"
    if [ "$CURRENT" = "chrome" ]; then
        skip "Chrome is already the default browser"
    else
        defaultbrowser chrome 2>/dev/null || true
        ok "default browser set to Chrome (confirm the macOS dialog if it appears)"
    fi
else
    skip "defaultbrowser or Chrome not available"
fi

# ─────────────────────────────────────────────────────────────
# STEP 4 — `code` CLI check
# The visual-studio-code cask links `code` into $BREW_PREFIX/bin;
# if a manual .app copy was used instead, link it ourselves.
# ─────────────────────────────────────────────────────────────
step "4/6  VSCode CLI"

if command -v code > /dev/null 2>&1; then
    skip "code CLI already on PATH"
elif [ -x "/Applications/Visual Studio Code.app/Contents/Resources/app/bin/code" ]; then
    ln -sf "/Applications/Visual Studio Code.app/Contents/Resources/app/bin/code" \
        "$BREW_PREFIX/bin/code"
    ok "code CLI linked into $BREW_PREFIX/bin"
else
    warn "VSCode not found — code CLI not configured"
fi

# ─────────────────────────────────────────────────────────────
# STEP 5 — Dock layout (equivalent of tint2 launchers / taskbar)
# ─────────────────────────────────────────────────────────────
step "5/6  Dock items (dockutil)"

DOCK_APPS=(
    "/Applications/Google Chrome.app"
    "/Applications/Visual Studio Code.app"
    "/Applications/iTerm.app"
    "/Applications/FileZilla.app"
    "/System/Applications/Utilities/Activity Monitor.app"
)

DOCK_CHANGED=0
if command -v dockutil > /dev/null 2>&1; then
    for app in "${DOCK_APPS[@]}"; do
        name="$(basename "$app" .app)"
        if [ ! -d "$app" ]; then
            skip "Dock/$name (app not installed)"
            continue
        fi
        if dockutil --find "$name" > /dev/null 2>&1; then
            skip "Dock/$name (already present)"
        else
            dockutil --no-restart --add "$app" > /dev/null 2>&1 \
                && { ok "Dock/$name"; DOCK_CHANGED=1; } \
                || warn "Dock/$name add failed"
        fi
    done
    [ "$DOCK_CHANGED" -eq 1 ] && killall Dock 2>/dev/null || true
else
    warn "dockutil not available — Dock left untouched"
fi

# ── Desktop shortcuts (equivalent of ~/Desktop .desktop launchers) ──
# Symlinked .app bundles launch normally from Finder.
DESKTOP_DIR="$HOME/Desktop"
mkdir -p "$DESKTOP_DIR"
for app in "${DOCK_APPS[@]}"; do
    name="$(basename "$app")"
    if [ ! -d "$app" ]; then
        continue
    fi
    if [ -L "$DESKTOP_DIR/$name" ] || [ -e "$DESKTOP_DIR/$name" ]; then
        skip "Desktop/$name (already present)"
    else
        ln -s "$app" "$DESKTOP_DIR/$name" && ok "Desktop/$name"
    fi
done

# ─────────────────────────────────────────────────────────────
# STEP 6 — Final verification
# ─────────────────────────────────────────────────────────────
step "6/6  Verification"

ERRORS=0
check() {
    local label="$1"; shift
    if "$@" > /dev/null 2>&1; then
        ok "$label"
    else
        warn "MISSING: $label"
        ERRORS=$((ERRORS + 1))
    fi
}

check "Google Chrome.app"        test -d "/Applications/Google Chrome.app"
check "Visual Studio Code.app"   test -d "/Applications/Visual Studio Code.app"
check "iTerm.app"                test -d "/Applications/iTerm.app"
check "FileZilla.app"            test -d "/Applications/FileZilla.app"
check "Keka.app"                 test -d "/Applications/Keka.app"
check "LibreOffice.app"          test -d "/Applications/LibreOffice.app"
check "code CLI"                 command -v code
check "htop"                     command -v htop
check "dockutil"                 command -v dockutil
check "~/Desktop directory"      test -d "$HOME/Desktop"

echo ""
if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed.${NC}"
    echo ""
    echo "To apply everything: stop-server && start-server"
    echo "Then open: https://<tunnel>.trycloudflare.com/desktop/"
else
    echo -e "${RED}${BOLD}$ERRORS check(s) failed — review output above.${NC}"
    exit 1
fi
