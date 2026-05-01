# Install Carbonyl on WSL2 (Ubuntu 24.04)

[Carbonyl](https://github.com/fathyb/carbonyl) is a Chromium-based browser that runs in the terminal. These are the exact steps used to install and test it on a WSL2 Ubuntu 24.04 (amd64) machine.

## 1. Prerequisites

Make sure Node.js (>= 16) and npm are available:

```bash
node --version
npm --version
```

If missing, install them:

```bash
sudo apt-get update
sudo apt-get install -y nodejs npm
```

## 2. Install Carbonyl via npm

The npm package ships prebuilt binaries — no compilation required.

```bash
sudo npm install -g carbonyl
```

Verify the binary is on PATH:

```bash
which carbonyl
carbonyl --version
```

## 3. Install Chromium runtime libraries

Carbonyl embeds Chromium, so it needs the same shared libraries. On a minimal Ubuntu 24.04 these are usually missing and you will see errors like `error while loading shared libraries: libnss3.so`.

Install them with:

```bash
sudo apt-get update
sudo apt-get install -y \
    libnss3 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libasound2t64 \
    libpango-1.0-0 \
    libcairo2
```

> On Ubuntu 22.04 or earlier, replace `libasound2t64` with `libasound2`.

## 4. Running as root (`--no-sandbox`)

If you run Carbonyl as root (the default user in many WSL2 setups) Chromium refuses to start its sandbox. Either run it as a regular user, or pass the flag:

```bash
carbonyl --no-sandbox https://example.com
```

## 5. Quick test

Open a real interactive terminal (Windows Terminal is recommended for true-color output) and run:

```bash
carbonyl --no-sandbox https://example.com
```

You should see the Example Domain page rendered with Unicode blocks. Keyboard shortcuts:

- `Ctrl+L` — focus the address bar
- `Ctrl+W` — close tab / quit
- Arrow keys — scroll
- `Tab` / `Shift+Tab` — move focus between links

## 6. Useful options

```bash
# Higher zoom level
carbonyl --no-sandbox --zoom=150 https://news.ycombinator.com

# Cap frame rate
carbonyl --no-sandbox --fps=30 https://github.com

# Render text as bitmaps (better glyph coverage, worse performance)
carbonyl --no-sandbox --bitmap https://wikipedia.org

# Debug logs
carbonyl --no-sandbox --debug https://example.com
```

## 7. Uninstall

```bash
sudo npm uninstall -g carbonyl
```

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `error while loading shared libraries: libnss3.so` | Re-run the `apt-get install` from step 3 |
| `Running as root without --no-sandbox is not supported` | Add `--no-sandbox` or run as a non-root user |
| `Failed to setup terminal: Inappropriate ioctl for device` | You are not on a real TTY (piped/redirected). Run inside an interactive terminal |
| Broken / blocky rendering | Use a true-color terminal (Windows Terminal, WezTerm) and a Nerd Font |
| Black window on WSLg | Carbonyl is a TUI app, it does not open a GUI window — run it inside the terminal, not via `wsl --gui` |

## Tested environment

- WSL2 kernel 5.15.x
- Ubuntu 24.04 LTS (amd64)
- Node.js 18 / npm 9
- Carbonyl 0.0.2
