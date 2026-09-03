#!/usr/bin/env bash
# =============================================================
# NodePulse Desktop — 03-customize_desktop_raspberry.sh
# Raspberry Pi counterpart of 03-customize_desktop_wsl2.sh.
# Installs extra apps + writes user customizations (taskbar, thunar,
# pcmanfm, task manager, gtk, openbox keybindings, .desktop launchers).
#
# Run order:
#   1) 01-install_desktop_raspberry.sh    (VNC + openbox + noVNC — base)
#   2) 02-configure_desktop_raspberry.sh  (minimal desktop: thunar/xfdesktop)
#   3) 03-customize_desktop_raspberry.sh  (this file — full personalization)
#
# Differences from the WSL2 script (arm64, normal user, SD card):
#   - Google Chrome has no Linux arm64 build -> Chromium from the
#     Raspberry Pi OS / Debian repos (package 'chromium', older images
#     'chromium-browser'). No --no-sandbox hacks: we are not root.
#   - VSCode: Raspberry Pi OS ships 'code' (arm64/armhf) in its own repo;
#     if apt cannot find it, the Microsoft repo is added with arm64/armhf.
#   - No root-only workarounds (WSLg env, root user-data-dir, Thunar
#     "running as root" banner, zutty segfault). xterm is kept.
#   - Smaller font set: fonts-noto alone is ~600MB on an SD card.
#   - amule/flatpak dropped (not useful on a headless node).
#   - Packages missing from the Pi archive are skipped with a [WARN]
#     instead of aborting the whole apt transaction.
#
# Safe to re-run: every file is backed up before overwrite.
# =============================================================

set -e

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*" >&2; exit 1; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

if [ "$(id -u)" -eq 0 ]; then
    fail "Do not run this as root — run it as the user that ran rpi-setup.sh"
fi
command -v sudo >/dev/null 2>&1 || fail "sudo not found"
sudo -v || fail "sudo authentication failed"
SUDO="sudo"

# place <path> [mode]: write stdin to <path>, mkdir -p the parent,
# backup existing file (.bak.YYYYMMDD-HHMMSS) only when content differs,
# skip silently when content already matches.
# Uses sudo for targets outside $HOME (e.g. /usr/local/bin).
place() {
    local target="$1"
    local mode="${2:-644}"
    local as=""
    case "$target" in
        "$HOME"/*) as="" ;;
        *)         as="$SUDO" ;;
    esac
    $as mkdir -p "$(dirname "$target")"
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    if [ -f "$target" ] && cmp -s "$tmp" "$target"; then
        rm -f "$tmp"
        skip "$target (already up to date)"
    else
        if [ -f "$target" ]; then
            $as cp -p "$target" "$target.bak.$(date +%Y%m%d-%H%M%S)"
        fi
        $as install -m "$mode" "$tmp" "$target"
        rm -f "$tmp"
        ok "$target"
    fi
}

echo -e "\n${BOLD}NodePulse Desktop — customization (Raspberry Pi)${NC}"
echo "Working directory: $HOME"
echo "Architecture:      $(dpkg --print-architecture)"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Extra packages (file managers, taskbar, task manager,
#          archivers, viewers, fonts, dev utilities)
# ─────────────────────────────────────────────────────────────
step "1/8  Extra apt packages"

EXTRA_PKGS=(
    # Panel / WM helpers
    tint2 dunst zenity wmctrl libnotify-bin
    # xfsettingsd publishes XSETTINGS (GTK theme, font, decorations)
    # required for consistent Thunar/GTK rendering under Openbox.
    # xfwm4 is shipped because we keep an xfwm4.xml in this script and
    # the user may want to switch WM; harmless when openbox is the WM.
    xfce4-settings xfwm4
    # Application launcher / menu (installed-apps browser)
    xfce4-appfinder
    # File managers + actions
    pcmanfm thunar
    # GVfs — gives Thunar trash://, network://, MTP, and proper MIME
    # handling. Without it Thunar silently loses several side-pane
    # entries and many right-click actions.
    gvfs gvfs-backends gvfs-fuse
    # Terminal
    xfce4-terminal
    # Task manager + screenshots (bound in rc.xml / xfce4 shortcuts)
    xfce4-taskmanager xfce4-screenshooter scrot
    # Archivers — thunar-archive-plugin adds "Compress/Extract" entries
    # to Thunar's right-click menu, dispatched through xarchiver.
    xarchiver thunar-archive-plugin p7zip-full unzip zip
    # Viewers / utilities
    evince gthumb filezilla
    # LibreOffice (writer/calc only — keep minimal, it is ~400MB on a Pi)
    libreoffice-writer libreoffice-calc libreoffice-gtk3
    # Misc tools
    rclone dos2unix librsvg2-bin
    # Icon themes — elementary-xfce-dark is what gtk-3.0/settings.ini
    # selects; adwaita-icon-theme provides the Adwaita cursor theme.
    elementary-xfce-icon-theme adwaita-icon-theme
    # GTK Adwaita theme — gtk-3.0/settings.ini sets gtk-theme-name=Adwaita
    # but the theme itself ships in gnome-themes-extra-data (the GTK3
    # variant) and gnome-themes-extra (GTK2 engine).
    gnome-themes-extra gnome-themes-extra-data
    # Fonts (trimmed for the SD card: no fonts-noto, no CJK sets)
    fonts-firacode fonts-jetbrains-mono fonts-noto-core fonts-noto-color-emoji
    fonts-liberation fonts-dejavu
)

MISSING=()
UNAVAILABLE=()
for p in "${EXTRA_PKGS[@]}"; do
    if dpkg -s "$p" &>/dev/null; then
        continue
    fi
    # Skip packages the Pi archive does not carry for this architecture
    # instead of letting one unknown name abort the whole transaction.
    if apt-cache show "$p" &>/dev/null; then
        MISSING+=("$p")
    else
        UNAVAILABLE+=("$p")
    fi
done

if [ ${#UNAVAILABLE[@]} -gt 0 ]; then
    warn "not available in this archive, skipped: ${UNAVAILABLE[*]}"
fi

if [ ${#MISSING[@]} -eq 0 ]; then
    skip "all extra packages already installed"
else
    echo "    Installing (${#MISSING[@]} packages, slow on a Pi): ${MISSING[*]}"
    $SUDO apt-get update -qq
    $SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y "${MISSING[@]}" \
        || fail "apt-get install failed"
    ok "extra packages installed (${#MISSING[@]})"
fi

# ─────────────────────────────────────────────────────────────
# STEP 2 — Chromium (Google Chrome has no Linux arm64 build)
# ─────────────────────────────────────────────────────────────
step "2/8  Chromium"

# Raspberry Pi OS (bookworm+) and Debian: 'chromium' with chromium.desktop.
# Older Pi OS images: 'chromium-browser' with chromium-browser.desktop.
CHROMIUM_BIN=""
CHROMIUM_DESKTOP=""
detect_chromium() {
    CHROMIUM_BIN="$(command -v chromium 2>/dev/null || command -v chromium-browser 2>/dev/null || true)"
    CHROMIUM_DESKTOP=""
    for d in chromium.desktop chromium-browser.desktop; do
        if [ -f "/usr/share/applications/$d" ]; then
            CHROMIUM_DESKTOP="$d"
            break
        fi
    done
}

detect_chromium
if [ -n "$CHROMIUM_BIN" ]; then
    skip "chromium already installed ($CHROMIUM_BIN)"
else
    if apt-cache show chromium &>/dev/null; then
        $SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y chromium \
            || fail "apt-get install chromium failed"
    elif apt-cache show chromium-browser &>/dev/null; then
        $SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y chromium-browser \
            || fail "apt-get install chromium-browser failed"
    else
        fail "no chromium package in this archive"
    fi
    detect_chromium
    ok "chromium installed ($CHROMIUM_BIN)"
fi

# Default browser (http / https). We are a normal user: the stock
# .desktop file works as is, no --no-sandbox override needed.
if [ -n "$CHROMIUM_DESKTOP" ]; then
    xdg-settings set default-web-browser "$CHROMIUM_DESKTOP"            2>/dev/null || true
    xdg-mime default "$CHROMIUM_DESKTOP" x-scheme-handler/http          2>/dev/null || true
    xdg-mime default "$CHROMIUM_DESKTOP" x-scheme-handler/https         2>/dev/null || true
    $SUDO update-alternatives --set x-www-browser "$CHROMIUM_BIN"       2>/dev/null || true
    ok "Chromium set as default browser ($CHROMIUM_DESKTOP)"
else
    warn "chromium .desktop file not found — default browser not set"
fi

# ─────────────────────────────────────────────────────────────
# STEP 3 — Visual Studio Code
# ─────────────────────────────────────────────────────────────
step "3/8  Visual Studio Code"

if command -v code &>/dev/null; then
    skip "code already installed"
else
    # Raspberry Pi OS carries 'code' (arm64 + armhf) in archive.raspberrypi.com.
    if apt-cache show code &>/dev/null; then
        $SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y code \
            && ok "VSCode installed from the Raspberry Pi OS repo" \
            || warn "VSCode install failed (continuing)"
    else
        # Plain Debian on a Pi: add the Microsoft repo for arm64/armhf.
        ARCH="$(dpkg --print-architecture)"
        case "$ARCH" in
            arm64|armhf|amd64) ;;
            *) warn "no VSCode build for $ARCH — skipped"; ARCH="" ;;
        esac
        if [ -n "$ARCH" ]; then
            if [ ! -f /usr/share/keyrings/microsoft.gpg ]; then
                $SUDO apt-get install -y wget gpg apt-transport-https
                wget -qO- https://packages.microsoft.com/keys/microsoft.asc \
                    | gpg --dearmor \
                    | $SUDO tee /usr/share/keyrings/microsoft.gpg >/dev/null
            fi
            $SUDO tee /etc/apt/sources.list.d/vscode.sources >/dev/null <<EOF
Types: deb
URIs: https://packages.microsoft.com/repos/code
Suites: stable
Components: main
Architectures: $ARCH
Signed-By: /usr/share/keyrings/microsoft.gpg
EOF
            $SUDO apt-get update -qq
            $SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y code \
                && ok "VSCode installed from the Microsoft repo" \
                || warn "VSCode install failed (continuing)"
        fi
    fi
fi

# ─────────────────────────────────────────────────────────────
# STEP 4 — Openbox: enriched autostart + custom rc.xml keybindings
# ─────────────────────────────────────────────────────────────
step "4/8  Openbox autostart + rc.xml"

# 4a) autostart — adds dunst notification daemon on top of the base config
place "$HOME/.config/openbox/autostart" 755 <<'EOF'
#!/bin/sh
# Openbox autostart (NodePulse Desktop — Raspberry Pi)

# Silence GTK "Locale not supported by C library" warnings
export LANG=C.UTF-8

# Disable GTK client-side decorations: under Openbox, Thunar's CSD
# header bar collides with the WM frame (menubar folded into a
# hamburger button, resize grips hidden). Server-side decorations
# restore the classic menubar and functional resize borders.
export GTK_CSD=0

# Always talk to Xvnc, never to a Wayland compositor that may be running
# on the Pi's physical output (Raspberry Pi OS Desktop uses labwc/wayfire).
export GDK_BACKEND=x11
unset WAYLAND_DISPLAY

# XDG_RUNTIME_DIR for dbus/gvfs/xfconf.
# /run/user/<uid> exists only for logged-in users or accounts with linger
# enabled (01 does that). Under the nodepulse systemd unit without linger,
# fall back to a private directory in ~/tmp.
RUNTIME_DIR="/run/user/$(id -u)"
if [ ! -d "$RUNTIME_DIR" ] || [ ! -w "$RUNTIME_DIR" ]; then
    RUNTIME_DIR="$HOME/tmp/xdg-runtime"
    mkdir -p "$RUNTIME_DIR" && chmod 700 "$RUNTIME_DIR"
fi
export XDG_RUNTIME_DIR="$RUNTIME_DIR"

# Start a DBus session if one is not already running.
[ -z "$DBUS_SESSION_BUS_ADDRESS" ] && eval "$(dbus-launch --sh-syntax --exit-with-session)"

# Notification daemon
dunst &

# xfsettingsd publishes XSETTINGS to the X server (GTK theme, font,
# icon theme, decoration layout). Without it GTK apps under Openbox
# fall back to incoherent defaults.
xfsettingsd &

# Thunar in daemon mode (provides DBus actions for the file manager)
thunar --daemon &

# Ensure the desktop folder exists before xfdesktop starts (fresh
# install: missing folder -> degraded right-click menu until restart).
mkdir -p "$HOME/Desktop"

# Desktop icons, wallpaper and right-click "Desktop Settings".
# 3s delay: dbus/gvfs/xfconf caches are still being built on the SD card.
(sleep 3 && xfdesktop) &
EOF

# 4b) rc.xml — keybindings (Ctrl+Alt+arrows for desktop switch, etc.)
place "$HOME/.config/openbox/rc.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc"
                xmlns:xi="http://www.w3.org/2001/XInclude">

<resistance>
  <strength>10</strength>
  <screen_edge_strength>20</screen_edge_strength>
</resistance>

<focus>
  <focusNew>yes</focusNew>
  <followMouse>no</followMouse>
  <focusLast>yes</focusLast>
  <underMouse>no</underMouse>
  <focusDelay>200</focusDelay>
  <raiseOnFocus>no</raiseOnFocus>
</focus>

<placement>
  <policy>Smart</policy>
  <center>yes</center>
  <monitor>Primary</monitor>
  <primaryMonitor>1</primaryMonitor>
</placement>

<theme>
  <name>Clearlooks</name>
  <titleLayout>NLIMC</titleLayout>
  <keepBorder>yes</keepBorder>
  <animateIconify>yes</animateIconify>
  <font place="ActiveWindow"><name>sans</name><size>8</size><weight>bold</weight><slant>normal</slant></font>
  <font place="InactiveWindow"><name>sans</name><size>8</size><weight>bold</weight><slant>normal</slant></font>
  <font place="MenuHeader"><name>sans</name><size>9</size><weight>normal</weight><slant>normal</slant></font>
  <font place="MenuItem"><name>sans</name><size>9</size><weight>normal</weight><slant>normal</slant></font>
  <font place="ActiveOnScreenDisplay"><name>sans</name><size>9</size><weight>bold</weight><slant>normal</slant></font>
  <font place="InactiveOnScreenDisplay"><name>sans</name><size>9</size><weight>bold</weight><slant>normal</slant></font>
</theme>

<desktops>
  <number>1</number>
  <firstdesk>1</firstdesk>
  <names/>
  <popupTime>875</popupTime>
</desktops>

<resize>
  <drawContents>no</drawContents>
  <popupShow>Nonpixel</popupShow>
  <popupPosition>Center</popupPosition>
  <popupFixedPosition><x>10</x><y>10</y></popupFixedPosition>
</resize>

<margins><top>0</top><bottom>0</bottom><left>0</left><right>0</right></margins>

<dock>
  <position>TopLeft</position>
  <floatingX>0</floatingX><floatingY>0</floatingY>
  <noStrut>no</noStrut>
  <stacking>Above</stacking>
  <direction>Vertical</direction>
  <autoHide>no</autoHide>
  <hideDelay>300</hideDelay><showDelay>300</showDelay>
  <moveButton>Middle</moveButton>
</dock>

<keyboard>
  <chainQuitKey>C-g</chainQuitKey>

  <!-- Desktop switching -->
  <keybind key="C-A-Left"><action name="GoToDesktop"><to>left</to><wrap>no</wrap></action></keybind>
  <keybind key="C-A-Right"><action name="GoToDesktop"><to>right</to><wrap>no</wrap></action></keybind>
  <keybind key="C-A-Up"><action name="GoToDesktop"><to>up</to><wrap>no</wrap></action></keybind>
  <keybind key="C-A-Down"><action name="GoToDesktop"><to>down</to><wrap>no</wrap></action></keybind>
  <keybind key="S-A-Left"><action name="SendToDesktop"><to>left</to><wrap>no</wrap></action></keybind>
  <keybind key="S-A-Right"><action name="SendToDesktop"><to>right</to><wrap>no</wrap></action></keybind>
  <keybind key="S-A-Up"><action name="SendToDesktop"><to>up</to><wrap>no</wrap></action></keybind>
  <keybind key="S-A-Down"><action name="SendToDesktop"><to>down</to><wrap>no</wrap></action></keybind>
  <keybind key="W-F1"><action name="GoToDesktop"><to>1</to></action></keybind>
  <keybind key="W-F2"><action name="GoToDesktop"><to>2</to></action></keybind>
  <keybind key="W-F3"><action name="GoToDesktop"><to>3</to></action></keybind>
  <keybind key="W-F4"><action name="GoToDesktop"><to>4</to></action></keybind>
  <keybind key="W-d"><action name="ToggleShowDesktop"/></keybind>

  <!-- Window management -->
  <keybind key="A-F4"><action name="Close"/></keybind>
  <keybind key="A-Escape"><action name="Lower"/><action name="FocusToBottom"/><action name="Unfocus"/></keybind>
  <keybind key="A-space"><action name="ShowMenu"><menu>client-menu</menu></action></keybind>
  <keybind key="A-Print"><action name="Execute"><command>scrot -s</command></action></keybind>

  <!-- Window switching -->
  <keybind key="A-Tab"><action name="NextWindow"><finalactions><action name="Focus"/><action name="Raise"/><action name="Unshade"/></finalactions></action></keybind>
  <keybind key="A-S-Tab"><action name="PreviousWindow"><finalactions><action name="Focus"/><action name="Raise"/><action name="Unshade"/></finalactions></action></keybind>
  <keybind key="C-A-Tab"><action name="NextWindow"><panels>yes</panels><desktop>yes</desktop><finalactions><action name="Focus"/><action name="Raise"/><action name="Unshade"/></finalactions></action></keybind>

  <keybind key="W-S-Right"><action name="DirectionalCycleWindows"><direction>right</direction></action></keybind>
  <keybind key="W-S-Left"><action name="DirectionalCycleWindows"><direction>left</direction></action></keybind>
  <keybind key="W-S-Up"><action name="DirectionalCycleWindows"><direction>up</direction></action></keybind>
  <keybind key="W-S-Down"><action name="DirectionalCycleWindows"><direction>down</direction></action></keybind>

  <keybind key="W-e">
    <action name="Execute">
      <startupnotify><enabled>true</enabled><name>FileManager</name></startupnotify>
      <command>thunar</command>
    </action>
  </keybind>
  <keybind key="Print"><action name="Execute"><command>scrot</command></action></keybind>
</keyboard>

<mouse>
  <dragThreshold>1</dragThreshold>
  <doubleClickTime>500</doubleClickTime>
  <screenEdgeWarpTime>400</screenEdgeWarpTime>
  <screenEdgeWarpMouse>false</screenEdgeWarpMouse>

  <context name="Frame">
    <mousebind button="A-Left" action="Press"><action name="Focus"/><action name="Raise"/></mousebind>
    <mousebind button="A-Left" action="Click"><action name="Unshade"/></mousebind>
    <mousebind button="A-Left" action="Drag"><action name="Move"/></mousebind>
    <mousebind button="A-Right" action="Press"><action name="Focus"/><action name="Raise"/><action name="Unshade"/></mousebind>
    <mousebind button="A-Right" action="Drag"><action name="Resize"/></mousebind>
    <mousebind button="A-Middle" action="Press"><action name="Lower"/><action name="FocusToBottom"/><action name="Unfocus"/></mousebind>
  </context>

  <context name="Titlebar">
    <mousebind button="Left" action="Drag"><action name="Move"/></mousebind>
    <mousebind button="Left" action="DoubleClick"><action name="ToggleMaximize"/></mousebind>
  </context>

  <context name="Titlebar Top Right Bottom Left TLCorner TRCorner BRCorner BLCorner">
    <mousebind button="Left" action="Press"><action name="Focus"/><action name="Raise"/><action name="Unshade"/></mousebind>
    <mousebind button="Middle" action="Press"><action name="Lower"/><action name="FocusToBottom"/><action name="Unfocus"/></mousebind>
    <mousebind button="Right" action="Press"><action name="Focus"/><action name="Raise"/><action name="ShowMenu"><menu>client-menu</menu></action></mousebind>
  </context>

  <context name="Bottom Left Right TLCorner TRCorner BRCorner BLCorner">
    <mousebind button="Left" action="Drag"><action name="Resize"/></mousebind>
  </context>

  <context name="Client">
    <mousebind button="Left" action="Press"><action name="Focus"/><action name="Raise"/></mousebind>
    <mousebind button="Middle" action="Press"><action name="Focus"/><action name="Raise"/></mousebind>
    <mousebind button="Right" action="Press"><action name="Focus"/><action name="Raise"/></mousebind>
  </context>

  <context name="Close">
    <mousebind button="Left" action="Press"><action name="Focus"/><action name="Raise"/><action name="Unshade"/></mousebind>
    <mousebind button="Left" action="Click"><action name="Close"/></mousebind>
  </context>

  <context name="Iconify">
    <mousebind button="Left" action="Press"><action name="Focus"/><action name="Raise"/><action name="Unshade"/></mousebind>
    <mousebind button="Left" action="Click"><action name="Iconify"/></mousebind>
  </context>

  <context name="Maximize">
    <mousebind button="Left" action="Press"><action name="Focus"/><action name="Raise"/><action name="Unshade"/></mousebind>
    <mousebind button="Left" action="Click"><action name="ToggleMaximize"/></mousebind>
    <mousebind button="Middle" action="Click"><action name="ToggleMaximize"><direction>vertical</direction></action></mousebind>
    <mousebind button="Right" action="Click"><action name="ToggleMaximize"><direction>horizontal</direction></action></mousebind>
  </context>

  <context name="Root">
    <mousebind button="Middle" action="Press"><action name="ShowMenu"><menu>client-list-combined-menu</menu></action></mousebind>
    <mousebind button="Right" action="Press"><action name="ShowMenu"><menu>root-menu</menu></action></mousebind>
  </context>
</mouse>

<menu>
  <file>/var/lib/openbox/debian-menu.xml</file>
  <file>menu.xml</file>
  <hideDelay>200</hideDelay>
  <middle>no</middle>
  <submenuShowDelay>100</submenuShowDelay>
  <submenuHideDelay>400</submenuHideDelay>
  <showIcons>yes</showIcons>
  <manageDesktops>yes</manageDesktops>
</menu>

<applications/>

</openbox_config>
EOF

# ─────────────────────────────────────────────────────────────
# STEP 5 — Personalization configs (tint2, thunar, pcmanfm, gtk, xfce4)
# ─────────────────────────────────────────────────────────────
step "5/8  tint2 / thunar / pcmanfm / gtk / xfce4 configs"

# ── tint2 (taskbar) ──────────────────────────────────────────
place "$HOME/.config/tint2/tint2rc" <<'EOF'
#---- Generated by tint2conf aeaf ----
# Backgrounds
# Background 1: Panel
rounded = 0
border_width = 0
border_sides = TBLR
background_color = #000000 60
border_color = #000000 30
background_color_hover = #000000 60
border_color_hover = #000000 30
background_color_pressed = #000000 60
border_color_pressed = #000000 30

# Background 2: Default task, Iconified task
rounded = 4
border_width = 1
border_sides = TBLR
background_color = #777777 20
border_color = #777777 30
background_color_hover = #aaaaaa 22
border_color_hover = #eaeaea 44
background_color_pressed = #555555 4
border_color_pressed = #eaeaea 44

# Background 3: Active task
rounded = 4
border_width = 1
border_sides = TBLR
background_color = #777777 20
border_color = #ffffff 40
background_color_hover = #aaaaaa 22
border_color_hover = #eaeaea 44
background_color_pressed = #555555 4
border_color_pressed = #eaeaea 44

# Background 4: Urgent task
rounded = 4
border_width = 1
border_sides = TBLR
background_color = #aa4400 100
border_color = #aa7733 100
background_color_hover = #cc7700 100
border_color_hover = #aa7733 100
background_color_pressed = #555555 4
border_color_pressed = #aa7733 100

# Background 5: Tooltip
rounded = 1
border_width = 1
border_sides = TBLR
background_color = #222222 100
border_color = #333333 100
background_color_hover = #ffffaa 100
border_color_hover = #000000 100
background_color_pressed = #ffffaa 100
border_color_pressed = #000000 100

# Panel
panel_items = LTSC
panel_size = 100% 30
panel_margin = 0 0
panel_padding = 2 0 2
panel_background_id = 1
wm_menu = 1
panel_dock = 0
panel_position = bottom center horizontal
panel_layer = top
panel_monitor = all
panel_shrink = 0
autohide = 0
autohide_show_timeout = 0
autohide_hide_timeout = 0.5
autohide_height = 2
strut_policy = follow_size
panel_window_name = tint2
disable_transparency = 1
mouse_effects = 1
font_shadow = 0
mouse_hover_icon_asb = 100 0 10
mouse_pressed_icon_asb = 100 0 0

# Taskbar
taskbar_mode = single_desktop
taskbar_hide_if_empty = 0
taskbar_padding = 0 0 2
taskbar_background_id = 0
taskbar_active_background_id = 0
taskbar_name = 0
taskbar_hide_inactive_tasks = 0
taskbar_hide_different_monitor = 0
taskbar_hide_different_desktop = 0
taskbar_always_show_all_desktop_tasks = 0
taskbar_name_padding = 4 2
taskbar_name_background_id = 0
taskbar_name_active_background_id = 0
taskbar_name_font_color = #e3e3e3 100
taskbar_name_active_font_color = #ffffff 100
taskbar_distribute_size = 0
taskbar_sort_order = none
task_align = left

# Task
task_text = 1
task_icon = 1
task_centered = 1
urgent_nb_of_blink = 100000
task_maximum_size = 150 35
task_padding = 2 2 4
task_tooltip = 1
task_thumbnail = 0
task_thumbnail_size = 210
task_font_color = #ffffff 100
task_background_id = 2
task_active_background_id = 3
task_urgent_background_id = 4
task_iconified_background_id = 2
mouse_left = toggle_iconify
mouse_middle = none
mouse_right = close
mouse_scroll_up = toggle
mouse_scroll_down = iconify

# System tray
systray_padding = 0 4 2
systray_background_id = 0
systray_sort = ascending
systray_icon_size = 24
systray_icon_asb = 100 0 0
systray_monitor = 1
systray_name_filter =

# Launcher
launcher_padding = 2 4 2
launcher_background_id = 0
launcher_icon_background_id = 0
launcher_icon_size = 24
launcher_icon_asb = 100 0 0
launcher_icon_theme_override = 0
startup_notifications = 1
launcher_tooltip = 1

# Clock
time1_format = %H:%M
time2_format = %A %d %B
time1_timezone =
time2_timezone =
clock_font_color = #ffffff 100
clock_padding = 2 0
clock_background_id = 0
clock_tooltip =
clock_tooltip_timezone =
clock_lclick_command =
clock_rclick_command = orage
clock_mclick_command =
clock_uwheel_command =
clock_dwheel_command =

# Battery
battery_tooltip = 1
battery_low_status = 10
battery_low_cmd = xmessage 'tint2: Battery low!'
battery_full_cmd =
battery_font_color = #ffffff 100
bat1_format =
bat2_format =
battery_padding = 1 0
battery_background_id = 0
battery_hide = 101
battery_lclick_command =
battery_rclick_command =
battery_mclick_command =
battery_uwheel_command =
battery_dwheel_command =
ac_connected_cmd =
ac_disconnected_cmd =

# Tooltip
tooltip_show_timeout = 0.5
tooltip_hide_timeout = 0.1
tooltip_padding = 4 4
tooltip_background_id = 5
tooltip_font_color = #dddddd 100
EOF

# ── Thunar custom actions (uca.xml) ──────────────────────────
# The "Apri con Code" action calls /usr/local/bin/code-launch, written
# in step 6 below.
place "$HOME/.config/Thunar/uca.xml" 600 <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<actions>
<action>
	<icon>utilities-terminal</icon>
	<name>Open Terminal Here</name>
	<submenu></submenu>
	<unique-id>1776973945543100-1</unique-id>
	<command>exo-open --working-directory %f --launch TerminalEmulator</command>
	<description>Open a terminal in the current directory</description>
	<range></range>
	<patterns>*</patterns>
	<startup-notify/>
	<directories/>
</action>
<action>
	<icon>vscode</icon>
	<name>Apri con Code</name>
	<submenu></submenu>
	<unique-id>1798800000000000-1</unique-id>
	<command>/usr/local/bin/code-launch %F</command>
	<description>Apri in Visual Studio Code</description>
	<range></range>
	<patterns>*</patterns>
	<startup-notify/>
	<directories/>
	<text-files/>
	<image-files/>
	<audio-files/>
	<video-files/>
	<other-files/>
</action>
</actions>
EOF

# ── Thunar xfconf preferences (zoom, hidden files, columns) ──
place "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/thunar.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>

<channel name="thunar" version="1.0">
  <property name="last-view" type="string" value="ThunarDetailsView"/>
  <property name="last-icon-view-zoom-level" type="string" value="THUNAR_ZOOM_LEVEL_100_PERCENT"/>
  <property name="last-separator-position" type="int" value="170"/>
  <property name="last-show-hidden" type="bool" value="true"/>
  <property name="last-details-view-zoom-level" type="string" value="THUNAR_ZOOM_LEVEL_38_PERCENT"/>
  <property name="last-details-view-column-widths" type="string" value="50,50,124,117,86,89,50,50,274,50,50,71,50,130"/>
  <property name="last-window-maximized" type="bool" value="false"/>
  <property name="last-window-width" type="int" value="640"/>
  <property name="last-window-height" type="int" value="480"/>
  <property name="last-menubar-visible" type="bool" value="true"/>
  <property name="last-details-view-visible-columns" type="string" value="THUNAR_COLUMN_DATE_MODIFIED,THUNAR_COLUMN_DATE_DELETED,THUNAR_COLUMN_NAME,THUNAR_COLUMN_SIZE,THUNAR_COLUMN_TYPE"/>
</channel>
EOF

# ── pcmanfm desktop-mode preferences (icons on ~/Desktop) ────
place "$HOME/.config/pcmanfm/default/desktop-items-0.conf" <<'EOF'
[*]
wallpaper_mode=color
wallpaper_common=1
desktop_bg=#1d1f21
desktop_fg=#ffffff
desktop_shadow=#000000
desktop_font=Sans 10
show_wm_menu=0
sort=name;ascending;
show_documents=0
show_trash=1
show_mounts=0
EOF

# ── libfm: quick_exec disables the "Execute / Open / Cancel"
#    prompt that pcmanfm shows every time you double-click a
#    .desktop launcher on the desktop.
place "$HOME/.config/libfm/libfm.conf" <<'EOF'
[config]
single_click=0
use_trash=1
confirm_del=1
confirm_trash=1
quick_exec=1
terminal=xfce4-terminal
big_icon_size=48
small_icon_size=16
pane_icon_size=24
thumbnail_size=128
thumbnail_max=2048
thumbnail_local=1

[places]
places_home=1
places_desktop=1
places_root=1
places_computer=0
places_trash=1
places_applications=0
places_network=0
places_unmounted=1
EOF

# ── pcmanfm preferences ──────────────────────────────────────
place "$HOME/.config/pcmanfm/default/pcmanfm.conf" <<'EOF'
[config]
bm_open_method=0

[volume]
mount_on_startup=1
mount_removable=1
autorun=1

[ui]
always_show_tabs=0
max_tab_chars=32
win_width=745
win_height=480
splitter_pos=150
media_in_new_tab=0
desktop_folder_new_win=0
change_tab_on_drop=1
close_on_unmount=1
focus_previous=0
side_pane_mode=places
view_mode=list
show_hidden=0
sort=name;ascending;
columns=name:200;desc:158;size;mtime;
toolbar=newtab;navigation;home;
show_statusbar=1
pathbar_mode_buttons=0
EOF

# ── GTK 3 settings — icon theme (elementary-xfce-dark) + font ──
place "$HOME/.config/gtk-3.0/settings.ini" <<'EOF'
[Settings]
gtk-icon-theme-name=elementary-xfce-dark
gtk-theme-name=Adwaita
gtk-font-name=Sans 10
gtk-cursor-theme-name=Adwaita
gtk-application-prefer-dark-theme=0
EOF

# ── GTK 2 settings — same icon theme for legacy GTK2 apps ─────
place "$HOME/.gtkrc-2.0" <<'EOF'
gtk-icon-theme-name="elementary-xfce-dark"
gtk-theme-name="Adwaita"
gtk-font-name="Sans 10"
gtk-cursor-theme-name="Adwaita"
EOF

# ── xfce4-taskmanager preferences ────────────────────────────
place "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-taskmanager.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>

<channel name="xfce4-taskmanager" version="1.0">
  <property name="interface" type="empty">
    <property name="process-tree" type="bool" value="false"/>
    <property name="show-legend" type="bool" value="true"/>
  </property>
  <property name="window-maximized" type="bool" value="false"/>
  <property name="window-width" type="int" value="732"/>
  <property name="window-height" type="int" value="542"/>
  <property name="columns" type="empty">
    <property name="sort-type" type="uint" value="1"/>
    <property name="sort-id" type="uint" value="7"/>
  </property>
</channel>
EOF

# ── xfce4-desktop (background, icon size) ────────────────────
# xfce-shapes.svg ships with xfdesktop4-data, so it exists on Lite too
# (Raspberry Pi OS Lite has none of the rpd-wallpaper images).
# "VNC-0" is the RandR output name Xtigervnc reports.
place "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-desktop.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>

<channel name="xfce4-desktop" version="1.0">
  <property name="backdrop" type="empty">
    <property name="screen0" type="empty">
      <property name="monitorVNC-0" type="empty">
        <property name="workspace0" type="empty">
          <property name="color-style" type="int" value="0"/>
          <property name="image-style" type="int" value="5"/>
          <property name="last-image" type="string" value="/usr/share/backgrounds/xfce/xfce-shapes.svg"/>
        </property>
        <property name="workspace1" type="empty">
          <property name="color-style" type="int" value="0"/>
          <property name="image-style" type="int" value="5"/>
          <property name="last-image" type="string" value="/usr/share/backgrounds/xfce/xfce-shapes.svg"/>
        </property>
        <property name="workspace2" type="empty">
          <property name="color-style" type="int" value="0"/>
          <property name="image-style" type="int" value="5"/>
          <property name="last-image" type="string" value="/usr/share/backgrounds/xfce/xfce-shapes.svg"/>
        </property>
        <property name="workspace3" type="empty">
          <property name="color-style" type="int" value="0"/>
          <property name="image-style" type="int" value="5"/>
          <property name="last-image" type="string" value="/usr/share/backgrounds/xfce/xfce-shapes.svg"/>
        </property>
      </property>
    </property>
  </property>
  <property name="desktop-icons" type="empty">
    <property name="icon-size" type="uint" value="40"/>
    <property name="show-tooltips" type="bool" value="false"/>
  </property>
</channel>
EOF

# ── xfwm4 (workspace names) ──────────────────────────────────
place "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfwm4.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>

<channel name="xfwm4" version="1.0">
  <property name="general" type="empty">
    <property name="workspace_names" type="array">
      <value type="string" value="desktop 1"/>
      <value type="string" value="desktop 2"/>
      <value type="string" value="desktop 3"/>
      <value type="string" value="desktop 4"/>
    </property>
    <property name="workspace_count" type="int" value="1"/>
  </property>
</channel>
EOF

# ── xfce4 keyboard shortcuts (Ctrl+Alt+f → thunar, etc.) ─────
place "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-keyboard-shortcuts.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>

<channel name="xfce4-keyboard-shortcuts" version="1.0">
  <property name="commands" type="empty">
    <property name="custom" type="empty">
      <property name="&lt;Alt&gt;F2" type="string" value="xfce4-appfinder --collapsed">
        <property name="startup-notify" type="bool" value="true"/>
      </property>
      <property name="&lt;Alt&gt;Print" type="string" value="xfce4-screenshooter -w"/>
      <property name="&lt;Super&gt;r" type="string" value="xfce4-appfinder -c">
        <property name="startup-notify" type="bool" value="true"/>
      </property>
      <property name="XF86WWW" type="string" value="exo-open --launch WebBrowser"/>
      <property name="XF86Mail" type="string" value="exo-open --launch MailReader"/>
      <property name="&lt;Alt&gt;F3" type="string" value="xfce4-appfinder">
        <property name="startup-notify" type="bool" value="true"/>
      </property>
      <property name="Print" type="string" value="xfce4-screenshooter"/>
      <property name="&lt;Primary&gt;Escape" type="string" value="pcmanfm --desktop-pref"/>
      <property name="&lt;Shift&gt;Print" type="string" value="xfce4-screenshooter -r"/>
      <property name="&lt;Primary&gt;&lt;Alt&gt;Delete" type="string" value="xfce4-session-logout"/>
      <property name="&lt;Primary&gt;&lt;Alt&gt;t" type="string" value="exo-open --launch TerminalEmulator"/>
      <property name="&lt;Primary&gt;&lt;Alt&gt;f" type="string" value="thunar"/>
      <property name="&lt;Primary&gt;&lt;Alt&gt;l" type="string" value="xflock4"/>
      <property name="&lt;Alt&gt;F1" type="string" value="xfce4-popup-applicationsmenu"/>
      <property name="&lt;Super&gt;p" type="string" value="xfce4-display-settings --minimal"/>
      <property name="&lt;Primary&gt;&lt;Shift&gt;Escape" type="string" value="xfce4-taskmanager"/>
      <property name="&lt;Super&gt;e" type="string" value="thunar"/>
      <property name="&lt;Primary&gt;&lt;Alt&gt;Escape" type="string" value="xkill"/>
      <property name="HomePage" type="string" value="exo-open --launch WebBrowser"/>
      <property name="XF86Display" type="string" value="xfce4-display-settings --minimal"/>
      <property name="override" type="bool" value="true"/>
    </property>
  </property>
  <property name="providers" type="array">
    <value type="string" value="commands"/>
  </property>
</channel>
EOF

# ── Default web browser + terminal helpers ───────────────────
# exo-open --launch WebBrowser / TerminalEmulator resolve through this file.
WEB_HELPER="${CHROMIUM_DESKTOP%.desktop}"
[ -z "$WEB_HELPER" ] && WEB_HELPER="chromium"
place "$HOME/.config/xfce4/helpers.rc" <<EOF
WebBrowser=$WEB_HELPER
TerminalEmulator=xfce4-terminal
EOF

# Point the system-wide x-terminal-emulator alternative at xfce4-terminal
# so every consumer (Debian menu, exo-open, scripts) uses the same terminal.
if command -v xfce4-terminal &>/dev/null; then
    $SUDO update-alternatives --set x-terminal-emulator /usr/bin/xfce4-terminal 2>/dev/null \
        && ok "x-terminal-emulator → xfce4-terminal" \
        || skip "x-terminal-emulator already redirected"
elif command -v xterm &>/dev/null; then
    $SUDO update-alternatives --set x-terminal-emulator /usr/bin/xterm 2>/dev/null \
        && ok "x-terminal-emulator → xterm (fallback)" \
        || skip "x-terminal-emulator already redirected"
fi

# ─────────────────────────────────────────────────────────────
# STEP 6 — Custom wrappers + .desktop launchers
# ─────────────────────────────────────────────────────────────
step "6/8  Custom wrappers + .desktop launchers"

# ── code-launch: VSCode launcher pinned to the Xvnc display + log ──
# Normal user: no --no-sandbox, no custom user-data-dir. setsid detaches
# it from Thunar/xfdesktop so closing the launcher does not kill Code.
# --disable-gpu: Xvnc has no GL acceleration; Electron would otherwise
# spend seconds probing the (absent) VideoCore driver at every launch.
place "/usr/local/bin/code-launch" 755 <<'EOF'
#!/bin/bash
LOG="${TMPDIR:-/tmp}/code-launch-$(id -u).log"
{
  echo "=== $(date) ==="
  echo "ARGS: $*"
  echo "DISPLAY=$DISPLAY"
  echo "USER=$USER PWD=$PWD"
} >> "$LOG" 2>&1

exec setsid env DISPLAY="${DISPLAY:-:2.0}" \
  /usr/bin/code --disable-gpu "$@" \
  >> "$LOG" 2>&1 < /dev/null
EOF

# ── .desktop: "Apri con Code" — used by Thunar uca.xml action ──
place "$HOME/.local/share/applications/code-open.desktop" <<'EOF'
[Desktop Entry]
Type=Application
Name=Apri con Code
GenericName=Visual Studio Code
Comment=Apri file o cartella in Visual Studio Code
Exec=/usr/local/bin/code-launch %F
Icon=vscode
Terminal=false
StartupNotify=false
Categories=TextEditor;Development;IDE;
MimeType=inode/directory;text/plain;text/x-python;text/x-c;text/x-c++;text/x-java;text/x-shellscript;text/x-script;text/x-makefile;text/markdown;text/html;text/css;text/xml;text/csv;text/x-log;application/json;application/javascript;application/xml;application/x-yaml;application/x-toml;application/x-php;application/x-ruby;application/x-perl;application/sql;application/x-sh;application/x-shellscript;application/x-desktop;application/octet-stream;
EOF

# Refresh desktop database so the new .desktop files are discovered
if command -v update-desktop-database &>/dev/null; then
    update-desktop-database "$HOME/.local/share/applications" 2>/dev/null \
        && ok "desktop database refreshed" \
        || skip "desktop database refresh skipped"
fi

# ─────────────────────────────────────────────────────────────
# STEP 7 — Populate ~/Desktop with launcher icons
# ─────────────────────────────────────────────────────────────
step "7/8  Desktop icons (~/Desktop)"

DESKTOP_DIR="$HOME/Desktop"
mkdir -p "$DESKTOP_DIR"

# Source → destination name on the desktop. We search the standard
# .desktop directories so the script tolerates packages that aren't
# installed (logs a [SKIP] instead of failing).
DESKTOP_ICONS=(
    "xfce4-appfinder.desktop:Applicazioni.desktop"
    "xfce4-taskmanager.desktop:xfce4-taskmanager.desktop"
    "xfce4-terminal.desktop:Terminale.desktop"
    "filezilla.desktop:FileZilla.desktop"
    "libreoffice-writer.desktop:Word.desktop"
    "libreoffice-calc.desktop:Excel.desktop"
)
# Chromium: stock .desktop works for a normal user, copy it as is.
[ -n "$CHROMIUM_DESKTOP" ] && DESKTOP_ICONS+=("$CHROMIUM_DESKTOP:Chromium.desktop")

# Remove obsolete icons from previous runs / other platforms.
for f in \
    "$DESKTOP_DIR/thunar.desktop" \
    "$DESKTOP_DIR/pcmanfm.desktop" \
    "$DESKTOP_DIR/Google Chrome.desktop" \
    "$DESKTOP_DIR/amule.desktop" \
    "$DESKTOP_DIR/File Manager.desktop"; do
    if [ -f "$f" ]; then
        rm -f "$f" && ok "removed obsolete $(basename "$f")"
    fi
done

find_desktop_source() {
    local name="$1"
    for d in \
        /usr/share/applications \
        /usr/local/share/applications \
        "$HOME/.local/share/applications"; do
        if [ -f "$d/$name" ]; then
            printf '%s' "$d/$name"
            return 0
        fi
    done
    return 1
}

for entry in "${DESKTOP_ICONS[@]}"; do
    src_name="${entry%%:*}"
    dst_name="${entry##*:}"
    src="$(find_desktop_source "$src_name")" || {
        skip "Desktop/$dst_name (source $src_name not installed)"
        continue
    }
    target="$DESKTOP_DIR/$dst_name"
    if [ -f "$target" ] && cmp -s "$src" "$target"; then
        skip "Desktop/$dst_name (up to date)"
    else
        [ -f "$target" ] && cp -p "$target" "$target.bak.$(date +%Y%m%d-%H%M%S)"
        cp "$src" "$target"
        chmod +x "$target"
        # gio metadata::trusted is what GNOME requires; harmless under
        # xfdesktop/openbox where the +x bit is enough.
        command -v gio &>/dev/null && \
            gio set "$target" "metadata::trusted" true 2>/dev/null || true
        ok "Desktop/$dst_name"
    fi
done

# Custom "Visual Studio Code" launcher routed through code-launch
# (pins DISPLAY to Xvnc, --disable-gpu, logs to /tmp).
if command -v code &>/dev/null; then
    place "$DESKTOP_DIR/code.desktop" 755 <<'EOF'
[Desktop Entry]
Version=1.0
Type=Application
Name=Visual Studio Code
GenericName=Text Editor
Comment=Code Editing. Redefined.
Exec=/usr/local/bin/code-launch %F
Icon=vscode
Terminal=false
StartupNotify=true
Categories=TextEditor;Development;IDE;
MimeType=inode/directory;text/plain;
Actions=new-empty-window;

[Desktop Action new-empty-window]
Name=New Empty Window
Exec=/usr/local/bin/code-launch
EOF
    command -v gio &>/dev/null && \
        gio set "$DESKTOP_DIR/code.desktop" "metadata::trusted" true 2>/dev/null || true
else
    skip "Desktop/code.desktop (code not installed)"
fi

# ─────────────────────────────────────────────────────────────
# STEP 8 — Final verification
# ─────────────────────────────────────────────────────────────
step "8/8  Verification"

ERRORS=0
check() {
    local label="$1"; shift
    if "$@" &>/dev/null; then
        ok "$label"
    else
        warn "MISSING: $label"
        ERRORS=$((ERRORS + 1))
    fi
}

check "tint2 binary"                 command -v tint2
check "thunar binary"                command -v thunar
check "pcmanfm binary"               command -v pcmanfm
check "xfce4-taskmanager binary"     command -v xfce4-taskmanager
check "xfce4-terminal binary"        command -v xfce4-terminal
check "dunst binary"                 command -v dunst
check "chromium binary"              test -n "$CHROMIUM_BIN"
check "chromium .desktop"            test -n "$CHROMIUM_DESKTOP"
check "code binary"                  command -v code
check "openbox/autostart (+x)"       test -x "$HOME/.config/openbox/autostart"
check "openbox/rc.xml"               test -f "$HOME/.config/openbox/rc.xml"
check "tint2/tint2rc"                test -f "$HOME/.config/tint2/tint2rc"
check "Thunar/uca.xml"               test -f "$HOME/.config/Thunar/uca.xml"
check "pcmanfm.conf"                 test -f "$HOME/.config/pcmanfm/default/pcmanfm.conf"
check "pcmanfm desktop-items"        test -f "$HOME/.config/pcmanfm/default/desktop-items-0.conf"
check "libfm.conf (quick_exec)"      test -f "$HOME/.config/libfm/libfm.conf"
check "gtk-3.0/settings.ini"         test -f "$HOME/.config/gtk-3.0/settings.ini"
check "gtkrc-2.0"                    test -f "$HOME/.gtkrc-2.0"
check "elementary-xfce-dark theme"   test -d "/usr/share/icons/elementary-xfce-dark"
check "xfce4-taskmanager.xml"        test -f "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-taskmanager.xml"
check "xfce4-desktop.xml"            test -f "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-desktop.xml"
check "xfwm4.xml"                    test -f "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfwm4.xml"
check "xfce4-keyboard-shortcuts.xml" test -f "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-keyboard-shortcuts.xml"
check "helpers.rc"                   test -f "$HOME/.config/xfce4/helpers.rc"
check "code-launch (+x)"             test -x "/usr/local/bin/code-launch"
check "code-open.desktop"            test -f "$HOME/.local/share/applications/code-open.desktop"
check "~/Desktop directory"          test -d "$HOME/Desktop"
check "Desktop/Chromium.desktop"     test -f "$HOME/Desktop/Chromium.desktop"

echo ""
if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed.${NC}"
    echo ""
    echo "To apply the new desktop configuration:"
    echo "  by hand:        stop-server && start-server"
    echo "  under systemd:  sudo systemctl restart nodepulse"
else
    echo -e "${RED}${BOLD}$ERRORS check(s) failed — review output above.${NC}"
    exit 1
fi
