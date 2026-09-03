# Nitter on NodePulse (Raspberry Pi 4)

A guide to installing [Nitter](https://github.com/zedeus/nitter) (a privacy-friendly X/Twitter frontend) alongside NodePulse on a Raspberry Pi, reachable through the same cloudflared quick tunnel NodePulse already uses.

**Tested on:** Raspberry Pi 4, Raspberry Pi OS / Debian GNU/Linux 13 (trixie), aarch64, 1.8 GiB RAM.

---

## How it works

- NodePulse's nginx (`~/nginx/nginx.conf`) already listens on `:8080` and is the **only** origin cloudflared's quick tunnel (`cloudflared tunnel --url http://127.0.0.1:8080`) points to. A quick tunnel gives a single rotating `https://<random>.trycloudflare.com` hostname — there's no way to add a second hostname for Nitter, so it's exposed as a **path** (`/nitter/`) on the same origin, the same pattern NodePulse already uses for `/cli/` (PulseTerminal) and `/peerjs/`.
- Nitter runs as a **Docker container** (official `zedeus/nitter` image) on `127.0.0.1:8081` — loopback only, not reachable directly. `8081` was picked because NodePulse already uses `8080` (nginx), `9000` (php-cgi), `9001` (node/PeerJS), `7681` (ttyd/PulseTerminal).
- Nitter has **no subpath/base-path support** — it always emits root-relative links (`href="/username"`, `src="/css/..."`). Nginx's `sub_filter` rewrites those on the fly so the app works correctly under `/nitter/`.
- Nitter needs an **authenticated X/Twitter session** (guest tokens are blocked by X). The session lives in `~/nitter/sessions.jsonl` and is captured **manually from a real browser login** — see below for why, and how.
- Because the tunnel hostname rotates on every NodePulse restart, `nodepulse.sh` was extended to rewrite Nitter's `hostname` config and restart the container automatically whenever it detects a new tunnel URL.

---

## 1. Install Docker

```bash
sudo apt-get update
sudo apt-get install -y docker.io docker-compose
sudo usermod -aG docker "$USER"
sudo systemctl enable --now docker
```

No Nim/nimble toolchain is needed — Nitter is run from the official prebuilt image, which avoids compiling it from source on a memory-constrained Pi (Nim + nimble are not even packaged in the Debian trixie repos).

> Group membership (`docker`) only applies to **new** login sessions. See the [Known issue: docker group vs. sudo](#known-issue-docker-group-vs-sudo) section — the automatic hostname-sync feature below does **not** rely on this group at all, precisely to avoid that trap.

## 2. Create the Nitter directory

```bash
mkdir -p ~/nitter
cd ~/nitter
```

### `~/nitter/compose.yml`

Adapted from Nitter's official `compose.yml` — only the host port changed (`8081` instead of `8080`, to avoid NodePulse's nginx) and the SELinux `:Z` volume flags removed (not applicable on Debian):

```yaml
services:

  nitter:
    image: zedeus/nitter:latest
    container_name: nitter
    ports:
      - "127.0.0.1:8081:8080"
    volumes:
      - ./nitter.conf:/src/nitter.conf:ro
      - ./sessions.jsonl:/src/sessions.jsonl:ro
    depends_on:
      - nitter-redis
    restart: unless-stopped
    healthcheck:
      test: wget -nv --tries=1 --spider http://127.0.0.1:8080/Jack/status/20 || exit 1
      interval: 30s
      timeout: 5s
      retries: 2
    user: "998:998"
    read_only: true
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL

  nitter-redis:
    image: redis:6-alpine
    container_name: nitter-redis
    command: redis-server --save 60 1 --loglevel warning
    volumes:
      - nitter-redis:/data
    restart: unless-stopped
    healthcheck:
      test: redis-cli ping
      interval: 30s
      timeout: 5s
      retries: 2
    user: "999:1000"
    read_only: true
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL

volumes:
  nitter-redis:
```

### `~/nitter/nitter.conf`

Based on Nitter's `nitter.example.conf`. Generate your own `hmacKey` with `openssl rand -hex 32` — don't reuse the one below.

```ini
[Server]
hostname = "<your-current-tunnel-hostname>.trycloudflare.com"
title = "nitter"
address = "0.0.0.0"
port = 8080
https = true                 # reverse-proxied via cloudflared/nginx over HTTPS
httpMaxConnections = 100
staticDir = "./public"

[Cache]
listMinutes = 240
rssMinutes = 10
redisHost = "nitter-redis"   # docker-compose service name, not localhost
redisPort = 6379
redisPassword = ""
redisConnections = 20
redisMaxConnections = 30

[Config]
hmacKey = "<openssl rand -hex 32>"
base64Media = false
enableRSS = true
enableRSSUserTweets = true
enableRSSUserReplies = true
enableRSSUserMedia = true
enableRSSUserArticles = true
enableRSSSearch = true
enableRSSList = true
enableDebug = false
proxy = ""
proxyAuth = ""
apiProxy = ""
disableTid = false
maxConcurrentReqs = 2
maxRetries = 1
retryDelayMs = 150

[Preferences]
theme = "Nitter"
replaceTwitter = "nitter.net"
replaceYouTube = "piped.video"
replaceReddit = "teddit.net"
proxyVideos = true
hlsPlayback = false
infiniteScroll = false
```

> `hostname` only affects **cosmetic** absolute links (RSS self-link, `/opensearch`, oembed) — it does **not** affect normal browsing, which nginx handles path-relatively (see §4). It's kept in sync automatically — see §5.

## 3. Get a session (`sessions.jsonl`)

### Why not fully automated?

Nitter ships browser-automation helper scripts (`tools/create_session_browser.py`, using `zendriver` + headless/headed Chromium) to log in and capture the session cookies for you, including solving TOTP 2FA. On this Pi, several automation attempts were tried and **all failed**:

- **`tools/get_session.py`** (old method, direct API calls via `cloudscraper`) — X now blocks it outright (non-JSON challenge response).
- **`tools/create_session_browser.py`** (Chromium via `zendriver`/CDP), both headless and non-headless on the Pi's real X11 display (`:0`, via `xset`/`DISPLAY=:0`) — got past username+password some runs (confirmed via CDP-level `Enter` keypresses instead of clicking a "Continue" button, since X's onboarding page renders **duplicate** copies of the login form in the DOM, and localizes the button text, e.g. "Continua" in Italian, breaking the script's English-only text match) but was inconsistently bounced back to the start of the login flow afterwards — consistent with active anti-automation defenses on X's side.
- **A Playwright + real Firefox port** of the same script (written from scratch this session, since `zendriver` only speaks Chrome's CDP and can't drive Firefox) — installed via `pip install playwright && playwright install firefox` (works fine on arm64), but hit the same kind of silent failure.

Given X's login flow actively resists scripted browsers, the reliable method is:

### Manual capture via a real browser (recommended)

1. Log into **x.com** normally, in any real browser on any computer (not the Pi) — complete 2FA as usual.
2. Open DevTools (**F12**).
3. **Chrome/Edge:**
   - Easiest: tab **Network** → reload the page (F5) → click any request to `x.com` → in the **Headers** panel, under "Request Headers", find the **`cookie`** line (a single long string). If it's not shown directly, look for a "raw headers" / "view source" toggle at the top of the Headers panel.
   - Alternative: tab **Application** → left sidebar **Storage → Cookies** → `https://x.com` → read the `Value` column for each cookie individually.
4. From that cookie string, extract:
   - **`auth_token`**
   - **`ct0`**
   - **`twid`** (optional — gives the numeric user id; format is `u%3D<digits>`, the digits are the id)

### Session file format

Nitter's session parser (`src/experimental/parser/session.nim` / `RawSession`) expects one JSON object per line in `sessions.jsonl`. Field matching is snake_case/camelCase-insensitive (via `jsony`), so `auth_token` in the file correctly fills the Nim `authToken` field — no need to rename it.

```json
{"kind": "cookie", "username": "<x_username>", "id": "<numeric_id_or_omit>", "auth_token": "<value>", "ct0": "<value>"}
```

Write it to `~/nitter/sessions.jsonl` (one line, no trailing content after the closing brace besides the newline). `id` can be `null`/omitted — Nitter defaults it to `0` if empty.

## 4. Start it

```bash
cd ~/nitter
docker compose up -d
docker logs nitter   # should show: "successfully added 1 valid account sessions"
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/Jack/status/20   # expect 200
```

## 5. Nginx: expose it under `/nitter/`

Nginx build must include `--with-http_sub_module` (check with `nginx -V 2>&1 | grep sub_module`) — Debian's stock nginx package has it compiled in statically, no extra module loading needed.

Add the `map` block at the **top of the `http { ... }` block** (outside `server{}`), and the `location` blocks inside `server { listen 8080; ... }`, **before** the catch-all `location /`:

```nginx
http {
    # nginx normalizes (%2F -> '/', then merge_slashes collapses doubled
    # slashes) the URI it uses to reconstruct a proxy_pass target when the
    # location's prefix is stripped via the standard "proxy_pass .../;"
    # mechanism. Nitter's /video/ and /pic/ routes embed a full percent-
    # encoded original media URL as a path segment and HMAC-sign it, so any
    # normalization corrupts the signature and the backend 404s. map(),
    # unlike location-URI-replacement or "if"-based regex captures, reads
    # $request_uri (raw, never decoded) and its output is used as-is by
    # proxy_pass without re-escaping, so it's the only method here that
    # preserves the original bytes exactly.
    map $request_uri $nitter_uri {
        "~^/nitter(?<rest>.*)$"  $rest;
    }

    server {
        ...

        # Nitter (Twitter/X frontend), Docker container on 127.0.0.1:8081.
        # Nitter has no subpath/base-path support, so it's reverse-proxied
        # under /nitter/ with sub_filter rewriting its root-relative links.
        location = /nitter {
            return 301 /nitter/;
        }

        # HLS playlists (/video/<hmac>/<...m3u8-url>) embed further /video/...
        # references INSIDE the playlist body itself (variant streams, audio
        # tracks, subtitles, media segments - each its own nested/leaf
        # playlist), as bare lines or URI="..." attributes, not HTML
        # attributes - a separate, more specific location (nginx prefers the
        # longest matching prefix) with its own narrowly-scoped
        # sub_filter_types + a single blanket rule handles this without
        # risking a double "/nitter/nitter/" prefix on the main location's
        # href="/src="/etc rules (which would otherwise also match the
        # "/video/" substring inside an already-rewritten "/nitter/video/...").
        location /nitter/video/ {
            proxy_pass http://127.0.0.1:8081$nitter_uri;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header Accept-Encoding "";

            sub_filter_types application/vnd.apple.mpegurl application/x-mpegurl;
            sub_filter_once off;
            sub_filter '/video/' '/nitter/video/';
        }

        location /nitter/ {
            proxy_pass http://127.0.0.1:8081$nitter_uri;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header Accept-Encoding "";
            proxy_redirect / /nitter/;
            proxy_cookie_path / /nitter/;

            sub_filter_types text/html text/css application/json application/rss+xml application/atom+xml application/xml application/javascript application/manifest+json;
            sub_filter_once off;
            sub_filter 'href="/' 'href="/nitter/';
            sub_filter 'src="/' 'src="/nitter/';
            sub_filter 'action="/' 'action="/nitter/';
            sub_filter 'poster="/' 'poster="/nitter/';
            sub_filter 'data-url="/' 'data-url="/nitter/';
            sub_filter 'url(/' 'url(/nitter/';
        }
    }
}
```

Notes:
- `proxy_set_header Accept-Encoding "";` forces the backend to respond uncompressed — `sub_filter` cannot rewrite gzip-encoded bodies.
- `sub_filter` only rewrites **root-relative** links (`href="/..."` etc). Nitter also emits a few **fully-qualified** absolute URLs built from `nitter.conf`'s `hostname` (RSS self-link, `/opensearch`, oembed `href`) — those are *not* rewritten by this ruleset and will 404 until §6's hostname sync runs, but core browsing/search/timelines are unaffected since those use root-relative links.
- **Videos need `poster="/` and `data-url="/` too, not just `src="/`.** Nitter's video player (`src/views/tweet.nim`, `renderVideoAttachment`) renders differently depending on the stream's `playbackType`: a direct-mp4 video uses `<source src="/pic/...">` (already covered by the `src="` rule), but an **HLS/m3u8 or vmap** stream — common for real (non-GIF) X videos — renders `<video poster="/pic/..." data-url="/video/<hmac>/<encoded-url>" data-autoload="false">` and only fetches `data-url` client-side when the user clicks play. Without the `poster=`/`data-url=` rules, the video's poster thumbnail and the fetched stream both hit `/video/...` / `/pic/...` on nginx's **root** location (NodePulse's PHP app) instead of `/nitter/video/...`, which 404s ("file not found") — while plain images (`<img src="/pic/...">`) kept working fine since `src=` was already covered.
- **Even with the `data-url=` rule, the actual video/download fetch still 404'd — this needed the `$nitter_uri` map, not just `proxy_pass http://127.0.0.1:8081/;`.** Root cause, found by proxying a test request to a raw `nc -l` listener and inspecting the exact bytes nginx forwarded: nginx's standard `location <prefix> { proxy_pass http://backend/; }` idiom (URI replacement) reconstructs the backend URI from the **normalized, percent-decoded** `$uri` — decoding `%2F` to a literal `/` and then `merge_slashes` (default on) collapsing the resulting doubled slashes. Nitter's `/video/<hmac>/<url-encoded-original-media-url>` and `/pic/<url-encoded-path>` routes embed a **percent-encoded URL as a single path segment**, and `/video/` additionally HMAC-signs that exact string (`hmacKey` in `nitter.conf`) — once nginx decodes/merges it in transit, the segment nitter receives no longer matches what it (or its signature) expects, so it responds `404`/"file not found", even though the *same* request sent directly to `127.0.0.1:8081` (bypassing nginx) worked fine. Plain images survived only because their `/pic/...` handler doesn't require an exact signature match — but real videos, HMAC-checked, did not.
  - Fixed by adding an `http`-level `map $request_uri $nitter_uri { "~^/nitter(?<rest>.*)$" $rest; }` and changing `proxy_pass` to use `$nitter_uri` instead of the location-URI-replacement form. `map`'s output is substituted into `proxy_pass` verbatim, with **no decoding and no re-encoding**, unlike `location`'s automatic URI-replacement or `set`/`if`-based regex captures (tried first — `if ($x ~ "^/nitter(.*)$") { set $x $1; }` came *closer* but nginx then **double-encoded** the result, turning `%3A` into `%253A`, presumably because rewrite-module capture variables go through their own escaping pass; plain `map` doesn't).
- **Download and the correctly-fixed `$nitter_uri` route worked, but the in-page player (HLS/m3u8) still wouldn't play.** The `.m3u8` Nitter serves for `/video/` isn't a single file — it's a *master playlist* referencing per-resolution/per-audio-track *variant playlists* (also `.m3u8`, also proxied through `/video/...`), each of which in turn lists the actual media segments (`.m4s`, plus an `EXT-X-MAP` init segment) — also as `/video/<hmac>/<url>` entries, either as bare lines (`#EXT-X-STREAM-INF:...` followed by a raw `/video/...` line) or `URI="/video/..."` attributes (`#EXT-X-MEDIA`, `#EXT-X-MAP`). None of that text is HTML, so the main location's `href="`/`src="` -style `sub_filter` rules never see it, and its content-type (`application/vnd.apple.mpegurl`) wasn't in `sub_filter_types` either — so every nested reference stayed prefix-less and 404'd once the player tried to fetch it, even though the *outer* `data-url` request (the master playlist itself) had already been fixed and returned `200`.
  - Fixed with the dedicated `location /nitter/video/ { ... }` above: nginx matches it in preference to the shorter `/nitter/` prefix for anything under `/nitter/video/`, so it alone handles every level of the playlist tree (master → variant → segments) with one blanket `sub_filter '/video/' '/nitter/video/';`, scoped only to `application/vnd.apple.mpegurl`/`application/x-mpegurl` responses. The actual binary segments (`video/mp4`, `video/mp2t`, etc.) don't match that `sub_filter_types` list, so they pass through completely untouched — as they must, since `sub_filter` on binary data would corrupt it. A blanket, unanchored `/video/` substring match would be unsafe in the *main* `/nitter/` location (it would also match — and double-prefix — the `/video/` substring inside an `href="/nitter/video/..."` already rewritten by that location's own `href="` rule), which is exactly why this needed its own location rather than just adding one more `sub_filter` line to the existing block.

Test and reload:

```bash
nginx -t -c ~/nginx/nginx.conf -p ~/nginx/
nginx -s reload -c ~/nginx/nginx.conf -p ~/nginx/
```

Verify:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/nitter/Jack/status/20   # 200
curl -s -o /dev/null -w '%{http_code}\n' "https://<your-tunnel>.trycloudflare.com/nitter/Jack/status/20"   # 200
```

Nitter is now live at:
```
https://<your-current-tunnel>.trycloudflare.com/nitter/
```

## 6. Keep `nitter.conf`'s hostname in sync automatically

Since NodePulse's quick tunnel gets a **new** `https://<random>.trycloudflare.com` hostname every time it (re)connects, `nodepulse.sh` (**attached: `nodepulse.sh`**, the exact version running on this Pi) was extended with an `update_nitter_hostname()` function, called right after a new tunnel URL is confirmed (same place `register_node_local`/`announce_to_network` already run):

- Compares the new tunnel hostname against the current `hostname = "..."` line in `~/nitter/nitter.conf`.
- If different: rewrites it with `sed` and restarts **only** the `nitter` container (`nitter.conf` is a read-only bind mount, so the container just needs to reread it — no image rebuild).
- If unchanged: does nothing.

This patched script must replace **both**:
- `~/nodepulse.sh`
- `~/bin/nodepulse` (this is the one actually executed — check with `ps aux | grep nodepulse`; keep both in sync, they were identical copies before the patch)

```bash
chmod +x ~/bin/nodepulse
bash -n ~/bin/nodepulse   # syntax check
```

### Known issue: docker group vs. sudo

The first version of `update_nitter_hostname()` called `docker compose restart nitter` directly, relying on `pi-creto` being in the `docker` group. **This breaks in practice**: group membership is only re-evaluated on a *fresh login* — closing and reopening a terminal **inside an already-running desktop/VNC session** does **not** pick up a group added after that session started. Symptom seen: `[NodePulse] WARNING: Nitter container restart failed`, even though the container kept working fine (it just never picked up the new hostname — only the cosmetic absolute links were affected, per §5's note, which is why "it looked like it worked").

Confirmed by comparing groups of the running `nodepulse` process vs. a fresh SSH login:
```bash
cat /proc/<nodepulse_pid>/status | grep Groups   # missing the docker GID
id                                                # fresh session: includes docker
```

**Fix applied:** a dedicated passwordless sudo rule, so the restart no longer depends on group membership or session freshness at all:

`/etc/sudoers.d/nitter-docker-restart`:
```
pi-creto ALL=(root) NOPASSWD: /usr/bin/docker compose -f /home/pi-creto/nitter/compose.yml restart nitter
```

Install it with `visudo -c -f <file>` first to validate syntax, then copy it into place with mode `440`, owner `root:root`, and re-run `visudo -c` (no path) to confirm the whole sudoers tree still parses. The script then runs:

```bash
sudo -n /usr/bin/docker compose -f "$NITTER_DIR/compose.yml" restart nitter
```

`-n` (non-interactive) makes sudo fail fast instead of hanging on a password prompt if the rule is ever missing/wrong, instead of silently blocking the NodePulse loop.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `[NodePulse] WARNING: Nitter container restart failed` | Running `nodepulse` process predates `usermod -aG docker` and/or the sudoers rule isn't installed | Install the sudoers rule in §6; no relogin needed afterwards |
| Nitter pages load but RSS/opensearch/oembed links 404 | `nitter.conf`'s `hostname` is stale (tunnel rotated, container not yet restarted) | Happens automatically within the same nodepulse cycle once §6 is working; harmless in the meantime — core browsing is unaffected |
| `docker: comando non trovato` inside a script | `docker` not in a non-login shell's `PATH`, or Docker not installed/started | `sudo systemctl status docker`; ensure `docker.io` is installed |
| `nginx -t` warns `duplicate MIME type "text/html"` | Pre-existing, harmless — unrelated to the Nitter changes | Ignore |
| Video **poster/thumbnail** doesn't load, link shows a bare `/video/...` or `/pic/...` with no `/nitter/` prefix | `data-url=` / `poster=` weren't in the original `sub_filter` list (HLS videos lazy-load via `data-url`, not `src`) | Add `sub_filter 'poster="/' ...` and `sub_filter 'data-url="/' ...` (§5) |
| Poster loads, `/nitter/` prefix is correct, but the video itself (and its **download link**) still 404 with "file not found" | nginx's `location /nitter/ { proxy_pass http://.../; }` reconstructs the backend request from the **decoded** `$uri` (`%2F`→`/`, then `merge_slashes` collapses doubles), corrupting the percent-encoded, HMAC-signed original media URL embedded in `/video/<hmac>/<url>` and `/pic/<url>` | Use the `map $request_uri $nitter_uri {...}` + `proxy_pass http://127.0.0.1:8081$nitter_uri;` form in §5, which preserves the request bytes exactly |
| Download works, poster loads, but the **in-page player never actually plays** anything (overlay disappears, nothing streams) | The `.m3u8` HLS master playlist Nitter serves is itself proxied correctly, but it *contains further* `/video/...` references (variant/audio/subtitle sub-playlists, then media segments) that aren't HTML and weren't covered by any `sub_filter` rule or `sub_filter_types` entry | Add the dedicated `location /nitter/video/ { ... }` block in §5, scoped to `application/vnd.apple.mpegurl`/`application/x-mpegurl` with a blanket `sub_filter '/video/' '/nitter/video/';` |
| Downloaded video file has no proper `.mp4` extension (e.g. `..._tag=12` instead of ending in `.mp4`) | Nitter's own download route doesn't set a clean `Content-Disposition` filename — the browser derives one from the full (sanitized) URL, and the original media URL's query string (`?tag=12`) ends up *after* `.mp4` in that derived name | Cosmetic, not caused by the nginx/proxy setup — rename the file after downloading, or open it with an app that ignores extensions (most video players do) |

---

## Security notes

- Nitter's container runs `read_only: true`, `cap_drop: ALL`, `no-new-privileges:true`, and as a non-root numeric UID (matches the official `compose.yml`).
- `sessions.jsonl` and `nitter.conf` contain a live X/Twitter session token (`auth_token`, `ct0`) — treat `~/nitter/sessions.jsonl` like a credential file (not world-readable, not committed anywhere).
- The sudoers rule in §6 is scoped to **one exact command line** (`docker compose -f /home/pi-creto/nitter/compose.yml restart nitter`) — it does not grant broader Docker or root access.
- Port `8081` is bound to `127.0.0.1` only in `compose.yml` — Nitter is unreachable except through nginx's `/nitter/` path (and thus only via the auth-less but URL-obscured cloudflared tunnel, same trust model as the rest of NodePulse).
