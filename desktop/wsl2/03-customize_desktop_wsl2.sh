#!/usr/bin/env bash
# =============================================================
# NodePulse Desktop — customize_desktop_wsl2.sh
# Installs extra apps + writes user customizations (taskbar, thunar,
# pcmanfm, task manager, gtk, openbox keybindings, .desktop launchers).
#
# Run order:
#   1) install_desktop_wsl2.sh    (VNC + openbox + noVNC — base)
#   2) configure_desktop_wsl2.sh  (minimal desktop: thunar/xfdesktop)
#   3) customize_desktop_wsl2.sh  (this file — full personalization + Chrome)
#
# Safe to re-run: every file is backed up before overwrite.
# Includes: code-launch wrapper, matching .desktop launchers, and
# population of ~/Desktop with standard icons.
# =============================================================

set -e

GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"
BOLD="\033[1m"; NC="\033[0m"

ok()   { echo -e "${GREEN}[OK]${NC}   $*"; }
skip() { echo -e "${YELLOW}[SKIP]${NC} $*"; }
step() { echo -e "\n${BOLD}── $* ──${NC}"; }
fail() { echo -e "${RED}[FAIL]${NC}  $*" >&2; exit 1; }
warn() { echo -e "${YELLOW}[WARN]${NC}  $*"; }

SUDO=""
if [ "$(id -u)" -ne 0 ] && command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
fi

# place <path> [mode]: write stdin to <path>, mkdir -p the parent,
# backup existing file (.bak.YYYYMMDD-HHMMSS) only when content differs,
# skip silently when content already matches.
place() {
    local target="$1"
    local mode="${2:-644}"
    mkdir -p "$(dirname "$target")"
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    if [ -f "$target" ] && cmp -s "$tmp" "$target"; then
        rm -f "$tmp"
        skip "$target (already up to date)"
    else
        if [ -f "$target" ]; then
            cp -p "$target" "$target.bak.$(date +%Y%m%d-%H%M%S)"
        fi
        mv "$tmp" "$target"
        chmod "$mode" "$target"
        ok "$target"
    fi
}

echo -e "\n${BOLD}NodePulse Desktop — customization (WSL2)${NC}"
echo "Working directory: $HOME"

# ─────────────────────────────────────────────────────────────
# STEP 1 — Extra packages (file managers, taskbar, task manager,
#          archivers, viewers, fonts, dev utilities)
# ─────────────────────────────────────────────────────────────
step "1/8  Extra apt packages"

EXTRA_PKGS=(
    # Panel / WM helpers
    tint2 dunst zenity wmctrl libnotify-bin
    # xfsettingsd publishes XSETTINGS (GTK theme, font, decorations)
    # required for consistent Thunar/GTK rendering under Openbox+WSLg.
    # Without it, Thunar may lose its resize grips even with GTK_CSD=0.
    # xfwm4 is shipped because we keep an xfwm4.xml in this script and
    # the user may want to switch WM; harmless when openbox is the WM.
    xfce4-settings xfwm4
    # Application launcher / menu (replaces the "Applications" sidebar
    # entry that PCManFM provides via libfm — Thunar has no equivalent,
    # so we ship xfce4-appfinder as the installed-apps browser instead).
    xfce4-appfinder
    # File managers + actions
    pcmanfm thunar
    # GVfs — gives Thunar trash://, network://, MTP, and proper MIME
    # handling. Without it Thunar silently loses several side-pane
    # entries and many right-click actions.
    gvfs gvfs-backends gvfs-fuse
    # Terminal (xfce4-terminal works under WSLg; zutty needs GPU compute
    # shaders which WSLg's software stack cannot provide → segfault)
    xfce4-terminal
    # Task manager
    xfce4-taskmanager
    # Archivers — thunar-archive-plugin adds "Compress/Extract" entries
    # to Thunar's right-click menu, dispatched through xarchiver.
    xarchiver thunar-archive-plugin p7zip-full p7zip-rar unrar unzip zip
    # Viewers / utilities
    evince gthumb filezilla amule
    # LibreOffice (writer/calc only — keep minimal)
    libreoffice-writer libreoffice-calc libreoffice-gtk3
    # Misc tools
    flatpak rclone dos2unix strace librsvg2-bin
    # Icon themes — elementary-xfce-dark is what gtk-3.0/settings.ini
    # selects; adwaita-icon-theme provides the Adwaita cursor theme.
    elementary-xfce-icon-theme adwaita-icon-theme
    # GTK Adwaita theme — gtk-3.0/settings.ini sets gtk-theme-name=Adwaita
    # but the theme itself ships in gnome-themes-extra-data (the GTK3
    # variant) and gnome-themes-extra (GTK2 engine). Without these GTK
    # falls back to a borderless default and Thunar windows lose their
    # SSD resize grips.
    gnome-themes-extra gnome-themes-extra-data
    # Fonts
    fonts-firacode fonts-jetbrains-mono fonts-noto fonts-noto-color-emoji
    fonts-liberation fonts-ipafont-gothic fonts-wqy-zenhei
    fonts-tlwg-loma-otf fonts-unifont xfonts-cyrillic xfonts-scalable
)

MISSING=()
for p in "${EXTRA_PKGS[@]}"; do
    dpkg -s "$p" &>/dev/null || MISSING+=("$p")
done

if [ ${#MISSING[@]} -eq 0 ]; then
    skip "all extra packages already installed"
else
    echo "    Installing: ${MISSING[*]}"
    $SUDO apt-get update -qq
    $SUDO apt-get install -y "${MISSING[@]}" || fail "apt-get install failed"
    ok "extra packages installed (${#MISSING[@]})"
fi

# Remove xterm — segfaults under WSLg software OpenGL and we ship
# xfce4-terminal as the default terminal emulator instead.
if dpkg -s xterm &>/dev/null; then
    $SUDO apt-get purge -y xterm && ok "xterm purged" \
        || warn "xterm purge failed"
else
    skip "xterm not installed"
fi

# ─────────────────────────────────────────────────────────────
# STEP 2 — Google Chrome (from Google's official .deb)
# ─────────────────────────────────────────────────────────────
step "2/8  Google Chrome"

if command -v google-chrome-stable &>/dev/null; then
    skip "google-chrome-stable already installed"
else
    wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb \
        -O /tmp/chrome.deb || fail "failed to download Chrome .deb"
    $SUDO apt-get install -y /tmp/chrome.deb || fail "apt-get install chrome.deb failed"
    rm -f /tmp/chrome.deb
    ok "google-chrome-stable installed"
fi

# ─────────────────────────────────────────────────────────────
# STEP 3 — Visual Studio Code (from Microsoft apt repo)
# ─────────────────────────────────────────────────────────────
step "3/8  Visual Studio Code"

if command -v code &>/dev/null; then
    skip "code already installed"
else
    if [ ! -f /usr/share/keyrings/microsoft.gpg ]; then
        $SUDO apt-get install -y wget gpg apt-transport-https
        wget -qO- https://packages.microsoft.com/keys/microsoft.asc \
            | gpg --dearmor \
            | $SUDO tee /usr/share/keyrings/microsoft.gpg >/dev/null
    fi
    $SUDO tee /etc/apt/sources.list.d/vscode.sources >/dev/null <<'EOF'
Types: deb
URIs: https://packages.microsoft.com/repos/code
Suites: stable
Components: main
Architectures: amd64
Signed-By: /usr/share/keyrings/microsoft.gpg
EOF
    $SUDO apt-get update -qq
    $SUDO apt-get install -y code || fail "VSCode install failed"
    ok "VSCode installed"
fi

# ─────────────────────────────────────────────────────────────
# STEP 4 — Openbox: enriched autostart + custom rc.xml keybindings
# ─────────────────────────────────────────────────────────────
step "4/8  Openbox autostart + rc.xml"

# 4a) autostart — adds dunst notification daemon on top of the base config
place "$HOME/.config/openbox/autostart" 755 <<'EOF'
#!/bin/sh
# Openbox autostart

# Silence GTK "Locale not supported by C library" warnings
export LANG=C.UTF-8

# Disable GTK client-side decorations. Under Openbox + WSLg, Thunar's
# CSD header bar collides with the WM frame: the menubar gets folded
# into a hamburger button and the window's resize grips end up hidden
# behind Openbox's decorations, making the window appear non-resizable.
# Forcing server-side decorations restores the classic menubar and
# functional resize borders.
export GTK_CSD=0

# Force GTK3 apps to use the X11 backend (Xvnc). Without this, GTK3
# detects WAYLAND_DISPLAY and connects to WSLg's Wayland compositor
# instead of Xvnc — Openbox cannot manage those windows and they lose
# their server-side decorations and resize handles.
export GDK_BACKEND=x11
unset WAYLAND_DISPLAY

# Give the current user its own XDG_RUNTIME_DIR.
# Needed on WSL2 when running as root: WSLg creates /mnt/wslg/runtime-dir
# owned by uid 1000, which dbus cannot use from uid 0.
export XDG_RUNTIME_DIR=/run/user/$(id -u)
mkdir -p "$XDG_RUNTIME_DIR" && chmod 700 "$XDG_RUNTIME_DIR"

# Start a DBus session if one is not already running.
[ -z "$DBUS_SESSION_BUS_ADDRESS" ] && eval "$(dbus-launch --sh-syntax --exit-with-session)"

# Notification daemon
dunst &

# xfsettingsd publishes XSETTINGS to the X server (GTK theme, font,
# icon theme, decoration layout). Without it GTK apps under Openbox+WSLg
# fall back to incoherent defaults and Thunar windows can lose their
# SSD resize grips — even with GTK_CSD=0 set above. The --no-daemon
# flag keeps it in the foreground of this autostart's job control;
# we background it explicitly so the script returns.
xfsettingsd &

# Thunar in daemon mode (provides DBus actions for the file manager)
thunar --daemon &

# Desktop icons, wallpaper and right-click "Desktop Settings" (icon size,
# wallpaper, etc.). xfdesktop integrates with xfsettingsd for consistent
# theming and provides the full XFCE Desktop Settings dialog including
# icon size — pcmanfm --desktop lacks this control.
xfdesktop &
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
# NOTE: the "Apri con Code" action calls /usr/local/bin/code-launch which
# is NOT installed by this script — provide it separately.
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
#    .desktop launcher on the desktop. With quick_exec=1 trusted
#    launchers run immediately, matching the behaviour expected
#    by users used to a regular Linux desktop.
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

# ── GTK 3 CSS — hide Thunar "running as root" banner ─────────
place "$HOME/.config/gtk-3.0/gtk.css" <<'EOF'
/* Hide the "you are using the root account" banner in Thunar */
window.thunar infobar.warning,
window.thunar infobar revealer {
  min-height: 0;
  padding: 0;
}
window.thunar infobar.warning {
  opacity: 0;
  margin: 0;
}
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
place "$HOME/.config/xfce4/xfconf/xfce-perchannel-xml/xfce4-desktop.xml" <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>

<channel name="xfce4-desktop" version="1.0">
  <property name="backdrop" type="empty">
    <property name="screen0" type="empty">
      <property name="monitorVNC-0" type="empty">
        <property name="workspace0" type="empty">
          <property name="color-style" type="int" value="0"/>
          <property name="image-style" type="int" value="5"/>
          <property name="last-image" type="string" value="/usr/share/backgrounds/greybird.svg"/>
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
# Force TerminalEmulator to xfce4-terminal so anything calling
# `exo-open --launch TerminalEmulator` skips zutty (broken under WSLg's
# software OpenGL: requires compute shaders → segfault).
place "$HOME/.config/xfce4/helpers.rc" <<'EOF'
WebBrowser=google-chrome
TerminalEmulator=xfce4-terminal
EOF

# Repoint the system-wide `x-terminal-emulator` alternative away from
# zutty. Zutty has the highest update-alternatives priority on Debian
# and would otherwise be picked by every consumer.
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

# ── Cleanup: WhatsApp PWA was dropped (web.whatsapp.com refuses the
#    Chrome app-mode user-agent and the window stays blank).
for f in \
    "$HOME/.local/bin/whatsapp-pwa" \
    "$HOME/.local/share/applications/whatsapp.desktop" \
    "$HOME/Desktop/WhatsApp.desktop"; do
    if [ -e "$f" ]; then
        rm -f "$f" && ok "removed obsolete $f"
    fi
done

# ── code-launch: VSCode launcher with WSL2-friendly flags + log ──
place "/usr/local/bin/code-launch" 755 <<'EOF'
#!/bin/bash
LOG=/tmp/code-launch.log
{
  echo "=== $(date) ==="
  echo "ARGS: $*"
  echo "DISPLAY=$DISPLAY"
  echo "USER=$USER PWD=$PWD"
} >> "$LOG" 2>&1

exec setsid env DONT_PROMPT_WSL_INSTALL=1 DISPLAY="${DISPLAY:-:1.0}" \
  /usr/bin/code --no-sandbox --test-type --user-data-dir=/root/.vscode-root "$@" \
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
    "amule.desktop:amule.desktop"
)

# Remove obsolete icons from previous runs.
for f in \
    "$DESKTOP_DIR/thunar.desktop" \
    "$DESKTOP_DIR/pcmanfm.desktop" \
    "$DESKTOP_DIR/code.desktop"; do
    if [ -f "$f" ]; then
        rm -f "$f" && ok "removed obsolete $(basename "$f")"
    fi
done

find_desktop_source() {
    local name="$1"
    for d in \
        /usr/share/applications \
        /usr/local/share/applications \
        "$HOME/.local/share/applications" \
        /var/lib/flatpak/exports/share/applications; do
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
        # pcmanfm/openbox where the +x bit is enough.
        command -v gio &>/dev/null && \
            gio set "$target" "metadata::trusted" true 2>/dev/null || true
        ok "Desktop/$dst_name"
    fi
done

# Replace zutty-based Terminale.desktop on the desktop if a stale copy
# survived from a previous install (it segfaults under WSLg).
if [ -f "$DESKTOP_DIR/Terminale.desktop" ] && \
   grep -q '^Exec=zutty' "$DESKTOP_DIR/Terminale.desktop"; then
    sed -i 's|^Exec=zutty.*|Exec=xfce4-terminal|' "$DESKTOP_DIR/Terminale.desktop"
    ok "Desktop/Terminale.desktop (zutty → xfce4-terminal)"
fi

# Custom "File Manager" launcher — single Thunar-backed icon on the
# desktop. We write it directly (rather than copying thunar.desktop)
# so the visible name and metadata stay under our control.
place "$DESKTOP_DIR/File Manager.desktop" 755 <<'EOF'
[Desktop Entry]
Version=1.0
Type=Application
Name=File Manager
GenericName=File Manager
Comment=Browse the file system with the file manager
Exec=thunar %F
Icon=org.xfce.thunar
Terminal=false
StartupNotify=true
Categories=System;Utility;Core;GTK;FileTools;FileManager;
MimeType=inode/directory;
EOF
command -v gio &>/dev/null && \
    gio set "$DESKTOP_DIR/File Manager.desktop" "metadata::trusted" true 2>/dev/null || true

# Custom "Visual Studio Code" launcher — the system code.desktop uses a plain
# `Exec=code %F` wrapper that fails silently as root (missing --no-sandbox and
# the root user-data-dir). We write our own that calls code-launch, which sets
# DONT_PROMPT_WSL_INSTALL=1, --no-sandbox, --user-data-dir and redirects logs.
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

# Custom "Google Chrome" launcher — Chrome refuses to run as root under
# WSL2 without --no-sandbox (user namespaces are not usable for the root
# uid here, so the sandbox initialisation aborts and the process exits
# silently). Copying /usr/share/applications/google-chrome.desktop would
# inherit a plain `Exec=/usr/bin/google-chrome-stable %U` and the icon
# would not launch on double-click. We write our own with --no-sandbox.
if command -v google-chrome-stable &>/dev/null; then
    place "$DESKTOP_DIR/Google Chrome.desktop" 755 <<'EOF'
[Desktop Entry]
Version=1.0
Type=Application
Name=Google Chrome
GenericName=Web Browser
Comment=Access the Internet
Exec=/usr/bin/google-chrome-stable --no-sandbox %U
Icon=google-chrome
Terminal=false
StartupNotify=true
Categories=Network;WebBrowser;
MimeType=application/pdf;application/rdf+xml;application/rss+xml;application/xhtml+xml;application/xhtml_xml;application/xml;image/gif;image/jpeg;image/png;image/webp;text/html;text/xml;x-scheme-handler/http;x-scheme-handler/https;
Actions=new-window;new-private-window;

[Desktop Action new-window]
Name=New Window
Exec=/usr/bin/google-chrome-stable --no-sandbox

[Desktop Action new-private-window]
Name=New Incognito Window
Exec=/usr/bin/google-chrome-stable --no-sandbox --incognito
EOF
    command -v gio &>/dev/null && \
        gio set "$DESKTOP_DIR/Google Chrome.desktop" "metadata::trusted" true 2>/dev/null || true
else
    skip "Desktop/Google Chrome.desktop (google-chrome-stable not installed)"
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
check "google-chrome-stable binary"  command -v google-chrome-stable
check "code binary"                  command -v code
check "openbox/autostart (+x)"       test -x "$HOME/.config/openbox/autostart"
check "openbox/rc.xml"               test -f "$HOME/.config/openbox/rc.xml"
check "tint2/tint2rc"                test -f "$HOME/.config/tint2/tint2rc"
check "Thunar/uca.xml"               test -f "$HOME/.config/Thunar/uca.xml"
check "pcmanfm.conf"                 test -f "$HOME/.config/pcmanfm/default/pcmanfm.conf"
check "pcmanfm desktop-items"        test -f "$HOME/.config/pcmanfm/default/desktop-items-0.conf"
check "libfm.conf (quick_exec)"      test -f "$HOME/.config/libfm/libfm.conf"
check "gtk-3.0/gtk.css"              test -f "$HOME/.config/gtk-3.0/gtk.css"
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
check "Desktop/File Manager.desktop" test -f "$HOME/Desktop/File Manager.desktop"
check "Desktop/Google Chrome.desktop" test -f "$HOME/Desktop/Google Chrome.desktop"

echo ""
if [ "$ERRORS" -eq 0 ]; then
    echo -e "${GREEN}${BOLD}All checks passed.${NC}"
    echo ""
    echo "To apply the new desktop configuration: stop-server && start-server"
else
    echo -e "${RED}${BOLD}$ERRORS check(s) failed — review output above.${NC}"
    exit 1
fi
