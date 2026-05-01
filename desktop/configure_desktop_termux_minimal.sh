#!/bin/sh

# =============================
# **** ATTENTION **** Before running this file, it is necessary to run the environment setup file first: install_desktop_termux.sh
# =============================

set -e

# In Termux: no sudo, package manager is pkg, PREFIX is non-standard
PREFIX="${PREFIX:-/data/data/com.termux/files/usr}"

# xdg-utils postinst needs File::MimeInfo (Perl). CPAN 2.38 has a runner bug:
# it downloads the tarball correctly but fails to execute Makefile.PL internally.
# We build the module manually from the CPAN cache so dpkg --configure -a (below)
# can finish configuring xdg-utils and its dependents (qt6-qtbase, liblxqt, …).
if ! perl -MFile::MimeInfo -e 1 >/dev/null 2>&1; then
    _FMI=$(find "$HOME/.cpan/build" -maxdepth 1 -name 'File-MimeInfo-*' -type d 2>/dev/null | sort -r | head -1)
    if [ -z "$_FMI" ] || [ ! -f "$_FMI/Makefile.PL" ]; then
        # Not in cache yet — let CPAN download it (the build step will fail, that is expected)
        printf '[~] Downloading File::MimeInfo via CPAN (build failure is expected)...\n'
        PERL_MM_USE_DEFAULT=1 cpan -T File::MimeInfo >/dev/null 2>&1 || true
        _FMI=$(find "$HOME/.cpan/build" -maxdepth 1 -name 'File-MimeInfo-*' -type d 2>/dev/null | sort -r | head -1)
    fi
    if [ -n "$_FMI" ] && [ -f "$_FMI/Makefile.PL" ]; then
        printf '[~] Building File::MimeInfo from cache (%s)...\n' "$_FMI"
        (cd "$_FMI" && perl Makefile.PL >/dev/null 2>&1 && make install >/dev/null 2>&1) || true
    fi
fi

# If a previous install was interrupted (half-installed), finish the configure
# step so dpkg is in a clean state before pkg install runs.
dpkg --configure -a 2>&1 || true

# gvfs: GIO backend that implements trash:// — without it pcmanfm cannot
# "trash" files (the desktop trash stays fake/non-functional).
pkg install -y pcmanfm tint2 dbus xfce4-terminal rofi htop papirus-icon-theme gvfs

mkdir -p "$HOME/.config/openbox" \
         "$HOME/.config/tint2" \
         "$HOME/.config/pcmanfm/default" \
         "$HOME/.local/share/applications" \
         "$HOME/.local/share/Trash/files" \
         "$HOME/.local/share/Trash/info" \
         "$HOME/Templates" \
         "$HOME/Desktop"

# --- Icon theme lookup path for tint2 ----------------------------------------
# The tint2 binary from the Termux repo has search paths hardcoded to standard
# Linux paths (/usr/share/icons, ~/.icons, ~/.local/share/icons) and does NOT
# include $PREFIX/share/icons. Without a bridge, themes installed via pkg are
# not seen by tint2 and launchers show generic white icons.
# We create a symlink ~/.icons -> $PREFIX/share/icons (a path tint2 searches).
if [ ! -L "$HOME/.icons" ]; then
    rm -rf "$HOME/.icons"
    ln -s "$PREFIX/share/icons" "$HOME/.icons"
fi

# Regenerate the icon-theme.cache: gtk-update-icon-cache in the dpkg triggers
# may have found an existing cache and skipped the update.
for theme in Papirus Papirus-Dark Adwaita hicolor; do
    [ -d "$PREFIX/share/icons/$theme" ] && \
        gtk-update-icon-cache -f -q "$PREFIX/share/icons/$theme" 2>/dev/null || true
done

# --- user-dirs.dirs ----------------------------------------------------------
# Tells pcmanfm where Desktop / Templates / Downloads live
if [ ! -f "$HOME/.config/user-dirs.dirs" ]; then
    cat > "$HOME/.config/user-dirs.dirs" <<'EOF'
XDG_DESKTOP_DIR="$HOME/Desktop"
XDG_TEMPLATES_DIR="$HOME/Templates"
XDG_DOWNLOAD_DIR="$HOME/Downloads"
EOF
fi

# --- pcmanfm profile "default" ----------------------------------------------
# The autostart uses `pcmanfm --desktop --profile=default` so the configuration
# must be read from ~/.config/pcmanfm/default/. If the files don't exist pcmanfm
# starts with compiled defaults: the trash is NOT shown -> the desktop appears
# without the "Trash" icon.
# NOTE: the [volume] section (mount_on_startup / mount_removable / autorun) is
# intentionally OMITTED. On Termux/Android there is no gvfs-udisks2: asking
# pcmanfm to automount at startup causes it to probe gvfs endpoints that don't
# exist; the probe can race with the first user click and take down the
# pcmanfm desktop window (symptom: icons vanish + openbox root menu reappears
# on first click). See verifiche.md (section I) for the full diagnosis.
# We write only if missing, to avoid overwriting user customizations.
if [ ! -f "$HOME/.config/pcmanfm/default/pcmanfm.conf" ]; then
    cat > "$HOME/.config/pcmanfm/default/pcmanfm.conf" <<'EOF'
[config]
bm_open_method=0

[ui]
always_show_tabs=0
max_tab_chars=32
win_width=640
win_height=480
splitter_pos=150
media_in_new_tab=0
desktop_folder_new_win=0
change_tab_on_drop=1
close_on_unmount=1
focus_previous=0
side_pane_mode=places
view_mode=icon
show_hidden=0
sort=name;ascending;
toolbar=newtab;navigation;home;
show_statusbar=1
pathbar_mode_buttons=0
EOF
fi

# desktop-items-0.conf: show_trash=1 makes the "Trash" icon appear on the desktop
# (combined with gvfs installed above, makes the trash fully functional).
if [ ! -f "$HOME/.config/pcmanfm/default/desktop-items-0.conf" ]; then
    cat > "$HOME/.config/pcmanfm/default/desktop-items-0.conf" <<'EOF'
[*]
wallpaper_mode=color
wallpaper_common=1
desktop_bg=#000000
desktop_fg=#ffffff
desktop_shadow=#000000
desktop_font=Sans 12
show_wm_menu=0
sort=mtime;ascending;
show_documents=0
show_trash=1
show_mounts=0
EOF
fi

# --- xstartup (VNC session entry point) --------------------------------------
# Rewrite ~/.vnc/xstartup in minimal form. The version shipped by
# install_desktop_termux.sh launches `tint2 &` and `xterm &` BEFORE
# `exec openbox-session`, so openbox's own autostart ends up starting a SECOND
# tint2. The two tint2 instances fight over the systray/panel area and
# produce a flood of `BadDrawable` X errors; pcmanfm --desktop survives the
# initial paint but its desktop window is fragile, and the first user click
# causes it to vanish — leaving the openbox root menu exposed. This is the
# "icons disappear on first click" bug documented in verifiche.md.
#
# The rewrite:
#   - starts dbus ONCE here (before openbox), so openbox and every child
#     inherits DBUS_SESSION_BUS_ADDRESS (no autolaunch races);
#   - does NOT call `xsetroot` (the package `xorg-xsetroot` is not installed
#     on Termux by default; pcmanfm paints the desktop background anyway via
#     desktop-items-0.conf);
#   - does NOT start tint2 or a terminal here — openbox's autostart does it.
if [ -f "$HOME/.vnc/xstartup" ]; then
    cat > "$HOME/.vnc/xstartup" <<'XSTART'
#!/data/data/com.termux/files/usr/bin/bash
# NodePulse Desktop — minimal xstartup.
# All application startup (tint2, pcmanfm) is delegated to
# ~/.config/openbox/autostart to avoid double-launches.
unset SESSION_MANAGER
export LANG=en_US.UTF-8

# Start DBus once, before openbox, so openbox and every client inherits it.
if [ -z "$DBUS_SESSION_BUS_ADDRESS" ]; then
    eval "$(dbus-launch --sh-syntax --exit-with-session)"
fi

exec openbox-session
XSTART
    chmod +x "$HOME/.vnc/xstartup"
fi

# --- Desktop watchdog --------------------------------------------------------
# On Termux/Android VNC, pcmanfm --desktop occasionally dies without any
# traceable cause (no log, no stderr — we checked: openbox.log empty,
# xstartup.log only contains the unrelated PyXDG warning). When it dies, the
# desktop window vanishes: icons disappear and the next click on the background
# is handled by openbox, which shows its default root menu. This is the
# "icons gone on first click" symptom the user reports.
#
# Rather than chase a Heisenbug across pcmanfm + gvfs + Xvnc on Android, we
# supervise pcmanfm (and tint2) with a tiny watchdog: if either dies, respawn
# it. The watchdog exits when Xvnc goes away, so we don't accumulate zombies
# trying to reconnect to a dead display.
cat > "$HOME/.config/openbox/desktop-watchdog.sh" <<'WDEOF'
#!/data/data/com.termux/files/usr/bin/sh
# Desktop watchdog: keep gvfsd, pcmanfm --desktop and tint2 alive for the whole
# X session. Started by ~/.config/openbox/autostart with setsid+detach.
#
# Why gvfsd is supervised here: DBus autolaunch starts gvfsd *without*
# XDG_RUNTIME_DIR inherited, so gvfsd skips creating its peer-to-peer socket
# under $XDG_RUNTIME_DIR/gvfsd. pcmanfm then prints endless GVFS-WARNING "peer-
# to-peer connection failed" and falls back to the session bus for every file
# info lookup. That fallback is slow and occasionally trips pcmanfm's desktop
# window handler on the first user interaction — the window disappears and
# openbox's root menu becomes visible (the "icons gone on click" symptom).
# Starting gvfsd ourselves with the right env fixes the peer-to-peer path.

LOG="$HOME/tmp/desktop-watchdog.log"
PCMANFM_ERR="$HOME/tmp/pcmanfm.err"
TINT2_ERR="$HOME/tmp/tint2.err"
GVFSD_ERR="$HOME/tmp/gvfsd.err"
mkdir -p "$HOME/tmp"

log() { printf '%s %s\n' "$(date '+%F %T')" "$*" >> "$LOG"; }

# Only one watchdog per session.
if [ -f "$HOME/tmp/desktop-watchdog.pid" ]; then
    OLD=$(cat "$HOME/tmp/desktop-watchdog.pid" 2>/dev/null)
    if [ -n "$OLD" ] && kill -0 "$OLD" 2>/dev/null; then
        log "another watchdog is alive (pid=$OLD), exiting"
        exit 0
    fi
fi
echo $$ > "$HOME/tmp/desktop-watchdog.pid"

# Make sure XDG_RUNTIME_DIR is set so every process we spawn (gvfsd above all)
# inherits it. autostart exports it too; this is belt-and-suspenders for
# manual invocations.
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-${TMPDIR:-/data/data/com.termux/files/usr/tmp}/runtime-$(id -u)}"
mkdir -p "$XDG_RUNTIME_DIR" && chmod 700 "$XDG_RUNTIME_DIR"

log "watchdog started (pid=$$, display=$DISPLAY, runtime=$XDG_RUNTIME_DIR)"

PCMANFM_FAST=0
TINT2_FAST=0

restart_gvfsd() {
    log "gvfsd not running — launching"
    setsid /data/data/com.termux/files/usr/libexec/gvfsd </dev/null >/dev/null 2>>"$GVFSD_ERR" &
}

restart_pcmanfm() {
    log "pcmanfm not running — launching"
    setsid pcmanfm --desktop --profile=default </dev/null >/dev/null 2>>"$PCMANFM_ERR" &
}

restart_tint2() {
    log "tint2 not running — launching"
    setsid tint2 </dev/null >/dev/null 2>>"$TINT2_ERR" &
}

# gvfsd must be up before pcmanfm touches anything, else the first pcmanfm
# launch prints peer-to-peer failures and falls back to the session bus.
if ! pgrep -f '/libexec/gvfsd$' >/dev/null 2>&1; then
    restart_gvfsd
    sleep 1
fi

# Exit when the X server is gone. Poll every 1 s so the gap during which the
# desktop is missing (e.g. right after pcmanfm crashes) is short — clicks on
# the bare root are the visible part of the "icons disappear" bug, so we want
# to repaint the desktop as fast as possible.
while pgrep -x Xvnc >/dev/null 2>&1; do
    NOW=$(date +%s)

    if ! pgrep -f '/libexec/gvfsd$' >/dev/null 2>&1; then
        restart_gvfsd
    fi

    if ! pgrep -x pcmanfm >/dev/null 2>&1; then
        if [ -n "$LAST_PCMANFM" ] && [ $((NOW - LAST_PCMANFM)) -lt 10 ]; then
            PCMANFM_FAST=$((PCMANFM_FAST + 1))
        else
            PCMANFM_FAST=0
        fi
        LAST_PCMANFM=$NOW
        restart_pcmanfm
    fi

    if ! pgrep -x tint2 >/dev/null 2>&1; then
        if [ -n "$LAST_TINT2" ] && [ $((NOW - LAST_TINT2)) -lt 10 ]; then
            TINT2_FAST=$((TINT2_FAST + 1))
        else
            TINT2_FAST=0
        fi
        LAST_TINT2=$NOW
        restart_tint2
    fi

    if [ "$PCMANFM_FAST" -ge 3 ] || [ "$TINT2_FAST" -ge 3 ]; then
        log "crash loop detected (pcmanfm_fast=$PCMANFM_FAST tint2_fast=$TINT2_FAST) — backoff 30s"
        sleep 30
        PCMANFM_FAST=0
        TINT2_FAST=0
    else
        sleep 3
    fi
done

log "Xvnc gone — watchdog exiting"
rm -f "$HOME/tmp/desktop-watchdog.pid"
WDEOF
chmod +x "$HOME/.config/openbox/desktop-watchdog.sh"

# --- Autostart ---------------------------------------------------------------
# openbox-autostart runs this script as `sh $AUTOSTART`. When the shell exits,
# anything still attached to it can receive SIGHUP. Therefore: setsid + /dev/null
# redirects + background, so processes detach cleanly.
#
# The autostart is now minimal: it just hands off to the watchdog. The watchdog
# is responsible for spawning pcmanfm and tint2 (and respawning them if they
# die). We kill any leftover watchdog/pcmanfm/tint2 from a previous session
# first so we don't end up with duplicates after `openbox --restart`.
cat > "$HOME/.config/openbox/autostart" <<'EOF'
#!/bin/sh
# Openbox autostart — hand off to desktop-watchdog.sh (see script for rationale)

export LANG=C.UTF-8

# XDG_RUNTIME_DIR: /run is not accessible in native Termux, use TMPDIR instead
export XDG_RUNTIME_DIR="${TMPDIR:-/data/data/com.termux/files/usr/tmp}/runtime-$(id -u)"
mkdir -p "$XDG_RUNTIME_DIR" && chmod 700 "$XDG_RUNTIME_DIR"

# Start a DBus session if one is not already running (pcmanfm/gvfs depend on it).
# Normally xstartup already did this; the guard below keeps the script safe
# on pre-existing installations whose xstartup was not rewritten.
[ -z "$DBUS_SESSION_BUS_ADDRESS" ] && eval "$(dbus-launch --sh-syntax --exit-with-session)"

# Standard XDG trash directories. Without these gvfsd-trash does not publish
# trash:// and pcmanfm does not draw the Trash icon (even with show_trash=1).
mkdir -p "$HOME/.local/share/Trash/files" "$HOME/.local/share/Trash/info"

# Wipe anything from a previous incarnation of the session (re-login in VNC,
# openbox --restart, rerun of install script while the X server is up).
# gvfsd is also killed so the next one starts under the correct XDG_RUNTIME_DIR
# and can publish its peer-to-peer socket where pcmanfm expects it.
OLD_WD=""
[ -f "$HOME/tmp/desktop-watchdog.pid" ] && OLD_WD=$(cat "$HOME/tmp/desktop-watchdog.pid" 2>/dev/null)
[ -n "$OLD_WD" ] && kill "$OLD_WD" 2>/dev/null
pkill -f desktop-watchdog.sh 2>/dev/null
pkill -x tint2               2>/dev/null
pkill -x pcmanfm             2>/dev/null
pkill -f '/libexec/gvfsd'    2>/dev/null
sleep 1

# Hand off supervision of gvfsd + pcmanfm + tint2 to the watchdog. setsid +
# redirect detach it from the autostart shell so it survives openbox-autostart
# exiting. XDG_RUNTIME_DIR is in the env (exported above) so gvfsd, once
# spawned by the watchdog, publishes its peer-to-peer socket at
# $XDG_RUNTIME_DIR/gvfsd — where pcmanfm looks for it.
setsid "$HOME/.config/openbox/desktop-watchdog.sh" </dev/null >/dev/null 2>&1 &
EOF
chmod +x "$HOME/.config/openbox/autostart"

# --- Openbox rc.xml: bind Super+Space -> rofi, Super+Enter -> terminal -------
# In Termux the system config is under $PREFIX, not /etc
SYSTEM_RC="$PREFIX/etc/xdg/openbox/rc.xml"

if [ ! -f "$HOME/.config/openbox/rc.xml" ]; then
    if [ -f "$SYSTEM_RC" ]; then
        cp "$SYSTEM_RC" "$HOME/.config/openbox/rc.xml"
    else
        # Fallback: minimal rc.xml if even the system one is missing
        cat > "$HOME/.config/openbox/rc.xml" <<'RCEOF'
<?xml version="1.0" encoding="UTF-8"?>
<openbox_config xmlns="http://openbox.org/3.4/rc">
  <keyboard>
  </keyboard>
</openbox_config>
RCEOF
    fi
fi

# --- Keybindings + Root-context scrub in rc.xml (idempotent, via python3) ----
# Two changes to ~/.config/openbox/rc.xml:
#   1) W-space -> rofi, W-Return -> xfce4-terminal (add/replace keybinds).
#   2) Drop the Middle-press and Right-press mousebinds from <context name="Root">.
#      Rationale: pcmanfm --desktop paints a full-size desktop window on top of
#      root, so clicks there are handled by pcmanfm. The Root context fires
#      only when pcmanfm's desktop window is *gone* (watchdog hasn't respawned
#      it yet, or X started before it). In that transient state the stock
#      Root binding shows the openbox root-menu — that is exactly the "menu
#      contestuale sbagliato" the user sees on first click. Removing the
#      binding turns that transient into a silent no-op; the watchdog respawns
#      pcmanfm within ~1 s and the proper menu comes back.
if [ -f "$HOME/.config/openbox/rc.xml" ]; then
    if command -v python3 >/dev/null 2>&1; then
        python3 - "$HOME/.config/openbox/rc.xml" <<'PYEOF'
import re, sys
path = sys.argv[1]
content = open(path).read()

for key in ('W-space', 'W-Return'):
    content = re.sub(
        r'\s*<keybind key="' + re.escape(key) + r'">.*?</keybind>',
        '',
        content,
        flags=re.DOTALL
    )

bindings = '''    <keybind key="W-space">
      <action name="Execute"><command>rofi -show drun</command></action>
    </keybind>
    <keybind key="W-Return">
      <action name="Execute"><command>xfce4-terminal</command></action>
    </keybind>'''

content = content.replace('</keyboard>', bindings + '\n  </keyboard>', 1)

# Strip mousebinds inside <context name="Root"> for Middle/Right presses that
# show the openbox root-menu (client-list-combined-menu / root-menu). Leave
# the rest of the Root context untouched.
def _scrub_root(match):
    block = match.group(0)
    for btn in ('Middle', 'Right'):
        block = re.sub(
            r'\s*<mousebind button="' + btn + r'" action="Press">.*?</mousebind>',
            '',
            block,
            flags=re.DOTALL,
        )
    return block

content = re.sub(
    r'<context name="Root">.*?</context>',
    _scrub_root,
    content,
    flags=re.DOTALL,
)

open(path, 'w').write(content)
PYEOF
    else
        for KEY in 'W-space' 'W-Return'; do
            sed -i "/<keybind key=\"$KEY\">/,/<\/keybind>/d" "$HOME/.config/openbox/rc.xml"
        done
        BLOCK=$(printf \
            '    <keybind key="W-space">\n      <action name="Execute"><command>rofi -show drun<\/command><\/action>\n    <\/keybind>\n    <keybind key="W-Return">\n      <action name="Execute"><command>xfce4-terminal<\/command><\/action>\n    <\/keybind>')
        sed -i "s|</keyboard>|${BLOCK}\n  </keyboard>|" "$HOME/.config/openbox/rc.xml"
        # Best-effort scrub of Root-context Middle/Right mousebinds without python.
        # awk is portable enough on Termux.
        awk '
            /<context name="Root">/ {in_root=1}
            in_root && /<mousebind button="(Middle|Right)" action="Press">/ {skip=1}
            !skip {print}
            skip && /<\/mousebind>/ {skip=0; next}
            /<\/context>/ && in_root {in_root=0}
        ' "$HOME/.config/openbox/rc.xml" > "$HOME/.config/openbox/rc.xml.tmp" \
            && mv "$HOME/.config/openbox/rc.xml.tmp" "$HOME/.config/openbox/rc.xml"
    fi
fi

# --- tint2: launcher bar with rofi / terminal / file manager / htop / conf ---
# In the past tint2conf saved to ~/.config/tint2/t/nt2rc due to a path glitch;
# if it exists, we remove it so tint2 is not confused.
rm -rf "$HOME/.config/tint2/t"

cat > "$HOME/.config/tint2/tint2rc" <<'TINT2EOF'
#---- tint2rc: launcher bar + taskbar + systray + clock ----
# Backgrounds
# 1: Panel
rounded = 0
rounded_corners = TL TR BL BR
border_width = 0
border_sides = TBLR
background_color = #000 60
border_color = #000 30
background_color_hover = #000 60
border_color_hover = #000 30
background_color_pressed = #000 60
border_color_pressed = #000 30

# 2: Default task / Iconified
rounded = 4
rounded_corners = TL TR BL BR
border_width = 1
border_sides = TBLR
background_color = #777777 20
border_color = #777777 30
background_color_hover = #aaaaaa 22
border_color_hover = #eaeaea 44
background_color_pressed = #555555 4
border_color_pressed = #eaeaea 44

# 3: Active task
rounded = 4
rounded_corners = TL TR BL BR
border_width = 1
border_sides = TBLR
background_color = #777777 20
border_color = #ffffff 40
background_color_hover = #aaaaaa 22
border_color_hover = #eaeaea 44
background_color_pressed = #555555 4
border_color_pressed = #eaeaea 44

# 4: Urgent task
rounded = 4
rounded_corners = TL TR BL BR
border_width = 1
border_sides = TBLR
background_color = #aa4400 100
border_color = #aa7733 100
background_color_hover = #cc7700 100
border_color_hover = #aa7733 100
background_color_pressed = #555555 4
border_color_pressed = #aa7733 100

# 5: Tooltip
rounded = 1
rounded_corners = TL TR BL BR
border_width = 1
border_sides = TBLR
background_color = #222222 100
border_color = #333333 100
background_color_hover = #222222 100
border_color_hover = #333333 100
background_color_pressed = #222222 100
border_color_pressed = #333333 100

#-------------------------------------
# Panel
panel_items = LTSC
panel_size = 100% 36
panel_margin = 0 0
panel_padding = 4 2 6
panel_background_id = 1
wm_menu = 1
panel_dock = 0
panel_position = bottom center horizontal
panel_layer = top
panel_monitor = all
panel_shrink = 0
autohide = 0
strut_policy = follow_size
panel_window_name = tint2
disable_transparency = 1
mouse_effects = 1

#-------------------------------------
# Taskbar
taskbar_mode = single_desktop
taskbar_padding = 0 0 2
taskbar_background_id = 0
taskbar_name = 0
task_align = left

#-------------------------------------
# Task
task_text = 1
task_icon = 1
task_centered = 1
task_maximum_size = 160 36
task_padding = 4 2 4
task_tooltip = 0
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

#-------------------------------------
# Systray
systray_padding = 0 4 2
systray_background_id = 0
systray_icon_size = 22
systray_icon_asb = 100 0 0
systray_monitor = primary

#-------------------------------------
# Launcher
launcher_padding = 2 6 4
launcher_background_id = 0
launcher_icon_background_id = 0
launcher_icon_size = 28
launcher_icon_asb = 100 0 0
# Papirus contains the colored app icons; Papirus-Dark is only a patch for the
# symbolic ones (Inherits=breeze-dark,hicolor, does NOT inherit Papirus) -> app
# icons unfindable. Use "Papirus" directly.
launcher_icon_theme = Papirus
launcher_icon_theme_override = 1
startup_notifications = 1
launcher_tooltip = 1
launcher_item_app = ~/.local/share/applications/rofi.desktop
launcher_item_app = /data/data/com.termux/files/usr/share/applications/xfce4-terminal.desktop
launcher_item_app = /data/data/com.termux/files/usr/share/applications/pcmanfm.desktop
launcher_item_app = ~/.local/share/applications/htop.desktop
launcher_item_app = /data/data/com.termux/files/usr/share/applications/tint2conf.desktop

#-------------------------------------
# Clock
time1_format = %H:%M
time2_format = %a %d %b
clock_font_color = #ffffff 100
clock_padding = 6 0
clock_background_id = 0

#-------------------------------------
# Tooltip
tooltip_show_timeout = 0.4
tooltip_hide_timeout = 0.1
tooltip_padding = 4 4
tooltip_background_id = 5
tooltip_font_color = #dddddd 100
TINT2EOF

# --- Launcher "Task Manager (htop)" ------------------------------------------
cat > "$HOME/.local/share/applications/htop.desktop" <<'EOF'
[Desktop Entry]
Type=Application
Version=1.0
Name=Task Manager (htop)
Comment=Monitor processes and system resources
Exec=xfce4-terminal --title=htop --command=htop
Icon=utilities-system-monitor
Terminal=false
Categories=System;Monitor;
EOF

# --- User override of rofi.desktop -------------------------------------------
# The system rofi.desktop declares Icon=rofi, but Papirus does not include the
# "rofi" icon -> fallback to hicolor (white monochrome SVG shipped by rofi itself).
# We recreate a local .desktop with Icon=xfce4-appfinder (colored, same
# concept: app launcher). The tint2rc already points to this file.
cat > "$HOME/.local/share/applications/rofi.desktop" <<'EOF'
[Desktop Entry]
Encoding=UTF-8
Version=1.0
Type=Application
Terminal=false
Exec=rofi -show drun
Name=Rofi
Icon=xfce4-appfinder
EOF

# --- "New Launcher" template for right-click on the pcmanfm desktop ----------
# Placing a .desktop in ~/Templates makes "Create New > New Launcher" appear
cat > "$HOME/Templates/Launcher.desktop" <<'EOF'
[Desktop Entry]
Type=Application
Version=1.0
Name=New Launcher
Comment=
Exec=
Icon=application-x-executable
Terminal=false
Categories=Utility;
EOF

# --- xfce4-terminal as the default terminal ----------------------------------
XTERM_LINK="$PREFIX/bin/x-terminal-emulator"
if [ ! -e "$XTERM_LINK" ] || [ "$(readlink "$XTERM_LINK" 2>/dev/null)" != "xfce4-terminal" ]; then
    ln -sf xfce4-terminal "$XTERM_LINK"
fi

# Update the .desktop DB (rofi/launcher). If the command is missing, ignore.
command -v update-desktop-database >/dev/null 2>&1 && \
    update-desktop-database "$HOME/.local/share/applications" >/dev/null 2>&1 || true

# --- Hot-apply without restarting the X session ------------------------------
# Note: `pgrep -x openbox` would fail on Termux — openbox is launched with an
# absolute path so /proc/PID/cmdline[0] is `/data/data/com.termux/files/usr/bin/openbox`
# and `pgrep -x` (which matches against the bare cmdline[0]) returns empty.
# We use `pgrep -f` against a unique substring of the full cmdline instead.
if [ -n "$DISPLAY" ] && pgrep -f 'usr/bin/openbox' >/dev/null 2>&1; then
    echo "[~] openbox already running: applying changes live..."

    export XDG_RUNTIME_DIR="${TMPDIR:-/data/data/com.termux/files/usr/tmp}/runtime-$(id -u)"
    mkdir -p "$XDG_RUNTIME_DIR" && chmod 700 "$XDG_RUNTIME_DIR"

    # Reload rc.xml -> apply the new keybinds without a restart
    openbox --reconfigure 2>/dev/null && echo "    [+] rc.xml reloaded"

    # DBus for the current session, if not present
    if [ -z "$DBUS_SESSION_BUS_ADDRESS" ]; then
        eval "$(dbus-launch --sh-syntax)" 2>/dev/null && \
            echo "    [+] DBus session started"
    fi

    # Stop any previous watchdog + desktop processes so the new config is
    # reloaded from scratch (tint2rc, pcmanfm profile, watchdog script).
    # gvfsd is killed too: the one spawned by DBus autolaunch has no
    # XDG_RUNTIME_DIR and therefore no peer-to-peer socket, which is the
    # underlying cause of the "icons gone on click" bug. The watchdog
    # respawns gvfsd with the right env.
    # All of the below commands may fail (pid file missing, no matches) — we
    # don't want `set -e` to abort the script on those, hence `|| true`.
    if [ -f "$HOME/tmp/desktop-watchdog.pid" ]; then
        OLD_WD=$(cat "$HOME/tmp/desktop-watchdog.pid" 2>/dev/null || true)
        if [ -n "$OLD_WD" ]; then kill "$OLD_WD" 2>/dev/null || true; fi
    fi
    pkill -f desktop-watchdog.sh 2>/dev/null || true
    pkill -x pcmanfm             2>/dev/null || true
    pkill -x tint2               2>/dev/null || true
    pkill -f '/libexec/gvfsd'    2>/dev/null || true
    sleep 1

    # Start the watchdog — first tick starts gvfsd, then pcmanfm + tint2.
    setsid "$HOME/.config/openbox/desktop-watchdog.sh" </dev/null >/dev/null 2>&1 &
    echo "    [+] desktop-watchdog started (gvfsd + pcmanfm + tint2 will follow)"
fi

echo "[+] Done."
echo "[+] tint2 panel at the bottom: rofi | xfce4-terminal | pcmanfm | htop | tint2conf"
echo "[+] Super+Space -> rofi, Super+Enter -> xfce4-terminal (if you have a physical keyboard)."
echo "[+] Right-click on the desktop -> Create New -> New Launcher (editable template)."
echo "[+] If openbox was NOT running, restart the X session to apply."
