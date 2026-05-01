# Configure Desktop Icons on WSL2 (Openbox + idesk + Google Chrome)

## Context

This guide documents how to set up desktop icons and a working right-click menu in a
WSL2 environment running **Openbox** via Xvnc (TigerVNC + websockify + noVNC).

**PCManFM `--desktop` crashes** (segfault) in this VNC environment, so desktop icons
are managed by **idesk** instead. PCManFM still works as a file manager launched normally.

---

## 1. Install Google Chrome (without snap)

Ubuntu 24.04 ships `chromium-browser` as a snap-only wrapper. Since snap does not work
in WSL2, install Chrome via its official `.deb`:

```bash
wget -q https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb -O /tmp/chrome.deb
apt install -y /tmp/chrome.deb
```

Create a wrapper script that always adds `--no-sandbox` (required when running as root):

```bash
cat > /usr/local/bin/chrome << 'EOF'
#!/bin/bash
exec /usr/bin/google-chrome-stable --no-sandbox "$@"
EOF
chmod +x /usr/local/bin/chrome
```

Use `chrome` instead of `google-chrome` everywhere from this point on.

---

## 2. Install idesk (and PCManFM)

```bash
apt install -y idesk pcmanfm
```

`pcmanfm` is not preinstalled on minimal Ubuntu/Debian: if missing, the
"File Manager" desktop icon and the Openbox menu entry fail silently
(no visible error, simply nothing happens on click).

### Configure `~/.ideskrc`

The `Background.*` keys must live inside `table Config` — **not** in a separate
`table Background` block (that format causes idesk to exit with code 255):

```
table Config
  FontName: Sans
  FontSize: 11
  FontColor: #ffffff
  ToolTip.FontSize: 11
  ToolTip.FontName: Sans
  ToolTip.ForeColor: #ffffff
  ToolTip.BackColor: #000000
  ToolTip.CaptionOnHover: true
  ToolTip.CaptionPlacement: bottom
  Locked: false
  Transparency: 100
  Shadow: true
  ShadowColor: #000000
  ShadowX: 1
  ShadowY: 1
  Bold: false
  ClickDelay: 300
  IconSnap: true
  SnapWidth: 10
  SnapHeight: 10
  SnapOrigin: BottomRight
  SnapShadow: false
  SnapShadowTrans: 200
  CaptionOnHover: false
  CaptionPlacement: bottom
  Background.Delay: 0
  Background.Source: None
  Background.File: None
  Background.Mode: Center
  Background.Color: #0a0a0a
end

table Actions
  Lock: control right doubleClk
  Reload: middle doubleClk
  Drag: left hold
  EndDrag: left singleClk
  Execute[0]: left doubleClk
  Execute[1]: right doubleClk
end
```

### Create `~/.idesktop/` icon files

Each icon is a `.lnk` file. Positions are in pixels from the top-left corner.

**`~/.idesktop/chrome.lnk`**
```
table Icon
  Caption: Google Chrome
  Command: /usr/local/bin/chrome
  Icon: /usr/share/icons/hicolor/48x48/apps/google-chrome.png
  X: 60
  Y: 60
end
```

**`~/.idesktop/filemanager.lnk`**
```
table Icon
  Caption: File Manager
  Command: pcmanfm
  Icon: /usr/share/icons/nuoveXT2/48x48/apps/system-file-manager.png
  X: 60
  Y: 160
end
```

**`~/.idesktop/terminal.lnk`**
```
table Icon
  Caption: Terminale
  Command: xterm
  Icon: /usr/share/icons/hicolor/48x48/apps/xterm-color.png
  X: 60
  Y: 260
end
```

Icons are launched with **double-click** by default (see `Execute[0]` in Actions).

---

## 3. Configure the Openbox right-click menu

Create `~/.config/openbox/menu.xml` (overrides the system default):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<openbox_menu xmlns="http://openbox.org/"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:schemaLocation="http://openbox.org/
                file:///usr/share/openbox/menu.xsd">

<menu id="root-menu" label="Menu">
  <item label="Google Chrome">
    <action name="Execute"><execute>chrome</execute></action>
  </item>
  <item label="File Manager (PCManFM)">
    <action name="Execute"><execute>pcmanfm</execute></action>
  </item>
  <item label="Terminale (XTerm)">
    <action name="Execute"><execute>xterm</execute></action>
  </item>
  <separator />
  <menu id="applications-menu" label="Applications" execute="/usr/bin/obamenu"/>
  <separator />
  <item label="Riconfigura Openbox">
    <action name="Reconfigure" />
  </item>
  <item label="Riavvia Openbox">
    <action name="Restart" />
  </item>
  <separator />
  <item label="Esci">
    <action name="Exit" />
  </item>
</menu>

</openbox_menu>
```

The `Applications` submenu is generated dynamically by `obamenu` (package `obamenu`,
requires `lxmenu-data`).

---

## 4. Autostart idesk with Openbox

Create `~/.config/openbox/autostart`:

```bash
# Desktop icons
idesk &
```

This file is sourced by `openbox-autostart` at session start, after
`/etc/xdg/openbox/autostart`.

---

## 5. Apply changes without rebooting

```bash
# Reload idesk
pkill idesk
DISPLAY=:1 idesk &

# Reload Openbox menu
DISPLAY=:1 openbox --reconfigure
```

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `pcmanfm --desktop` segfaults | GTK rendering issue in Xvnc | Use idesk instead |
| `chromium-browser` requires snap | Ubuntu 24.04 snap-only wrapper | Install Chrome `.deb` from Google |
| idesk exits with code 255 | `table Background` used instead of `Background.*` keys in Config | Use the flat key format shown above |
| Right-click menu shows broken items | System `menu.xml` loaded | Create `~/.config/openbox/menu.xml` |
| Applications submenu empty | `obamenu` or `lxmenu-data` missing | `apt install obamenu lxmenu-data` |
| Icons not visible after reboot | idesk not in autostart | Add `idesk &` to `~/.config/openbox/autostart` |
