# Paperclip on NodePulse (WSL2)

Guide to installing [Paperclip](https://github.com/paperclipai/paperclip) — "the app people use to manage AI agents for work" — on a **NodePulse WSL2** instance, reachable through the existing Cloudflare quick-tunnel at `/paperclip/`.

Paperclip is a Node.js/TypeScript monorepo (Express API + React SPA + Postgres), **not** a PHP app like the rest of `www/`. It is not served as static files by nginx: nginx reverse-proxies to a local Node process managed by pm2, the same pattern already used for `/cli/` (ttyd), `/desktop/` (noVNC) and `/peerjs/` (PeerServer).

---

## Architecture decision: why a "subpath hack", and its limits

NodePulse's cloudflared tunnel is a **quick tunnel** (`cloudflared tunnel --url http://127.0.0.1:8080`): one ephemeral hostname → one local origin (nginx `:8080`). Every app on this node is reachable only by nginx routing under that single hostname.

Paperclip's server (`server/src/app.ts`) has **no reverse-proxy base-path support**: it always serves `/api/*`, `/assets/*`, `/fonts/*`, `/brands/*` and its client-side router as root-absolute paths, regardless of `PAPERCLIP_PUBLIC_URL` (only the *hostname* of that URL is used, for the authenticated-mode trusted-host check — the path is discarded). There is no `basename`/`BASE_URL` option anywhere in the codebase (verified by reading `server/src/config.ts`, `ui/vite.config.ts` and `server/src/app.ts`).

Given that constraint, this install proxies:

- `/paperclip/` → strips the prefix → Paperclip (loads the app shell)
- `/api/`, `/assets/`, `/fonts/`, `/brands/` → proxied **unprefixed, at the site root** too, so the app keeps working once its client-side router (React) starts issuing root-relative requests

**Known limitation:** the very first in-app client-side navigation (any link click inside Paperclip) moves the browser address bar from `/paperclip/...` to a bare root path (e.g. `/board/42`), because the app's router has no base path to prepend. The app keeps working *within that browser session* (all its API/asset calls are root-relative and are proxied at root too), but a **hard refresh** or a bookmark saved at that point lands on NodePulse's own PHP homepage, not Paperclip — the user has to navigate back to `.../paperclip/` manually. Root-level static assets that double as NodePulse's own site identity (`favicon.ico`, `apple-touch-icon.png`, `site.webmanifest`, `sw.js`) are deliberately **not** proxied, so Paperclip's tab icon/PWA manifest/offline service worker do not work under this setup — cosmetic only, not a functional break.

If a future Paperclip release introduces new root-absolute prefixes, they will need a matching nginx `location` block, or they will 404 silently. Check `curl -s http://127.0.0.1:3100/ | grep -oE 'src="/[a-zA-Z0-9_/-]+' ` after an upgrade to see what the shell references.

---

## What was installed on this node

| Component | Version / detail |
|---|---|
| Node.js | v24.21.0 (system-wide, replaced Ubuntu's apt 18.19.1 via NodeSource `setup_24.x`) |
| Paperclip | run via `npx paperclipai@latest` (CLI-managed, no local build — no pnpm/Rust toolchain needed) |
| Database | system PostgreSQL 16 (apt), **not** Paperclip's embedded Postgres |
| Process manager | pm2 (same as `peerserver`), process name `paperclip` |
| Data dir | `/root/www/paperclip` (safe: nginx only reverse-proxies this path, it never serves files from it) |
| Port | `127.0.0.1:3100` (loopback only, matches `PAPERCLIP_BIND=loopback`) |

---

## Step-by-step install (fresh WSL2 NodePulse node)

### 1. Upgrade Node.js to 24+

Paperclip requires Node ≥ 24.11. NodePulse's own apt Node (18.x) is too old, and other services (`peerserver`, pm2 itself) keep working fine after this system-wide upgrade — they're pure JS with no version pin.

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x -o /tmp/setup_node24.sh
bash /tmp/setup_node24.sh
apt-get install -y nodejs
node -v   # v24.x
```

### 2. Install PostgreSQL

Paperclip's **embedded** Postgres (the zero-config default) refuses to run as root (`embedded-postgres` package hard-fails unless it can create and drop privileges to its own `postgres` OS user, which the CLI never exposes as a config option). Since this whole NodePulse node runs as root, use a real system PostgreSQL instead — it's also what the project's own docs recommend for production (`docs/deploy/database.md`).

```bash
apt-get install -y postgresql
service postgresql start

DB_PASS=$(openssl rand -hex 24)
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
CREATE ROLE paperclip LOGIN PASSWORD '${DB_PASS}';
CREATE DATABASE paperclip OWNER paperclip;
SQL
echo "DB_PASS=$DB_PASS" > /root/.paperclip_db_credentials
chmod 600 /root/.paperclip_db_credentials
```

### 3. Non-interactive onboarding

```bash
mkdir -p /root/www/paperclip
npx --yes paperclipai@latest onboard -y --bind loopback -d /root/www/paperclip --no-install-service
```

This runs `paperclip doctor`, generates `PAPERCLIP_AGENT_JWT_SECRET` / `PAPERCLIP_TOOL_ACTION_SIGNING_SECRET` into `/root/www/paperclip/instances/default/.env` (auto-loaded on every start — only `PAPERCLIP_*`-prefixed keys are persisted there), and writes `/root/www/paperclip/instances/default/config.json`. It will try to start with the embedded Postgres and fail with *"You are running this script as root..."* — that's expected, fixed in the next step.

### 4. Point the instance at system PostgreSQL + authenticated/public mode

Edit `/root/www/paperclip/instances/default/config.json`:

```jsonc
{
  "database": {
    "mode": "postgres",
    "connectionString": "postgres://paperclip:<DB_PASS>@127.0.0.1:5432/paperclip"
    // drop embeddedPostgresDataDir / embeddedPostgresPort
  },
  "server": {
    "deploymentMode": "authenticated",   // was local_trusted — a public tunnel must require login
    "exposure": "public",                // was private
    "bind": "loopback",
    "host": "127.0.0.1",
    "port": 3100,
    "serveUi": true
  },
  "auth": {
    "baseUrlMode": "explicit",           // was auto — required for authenticated+public
    "publicBaseUrl": "http://127.0.0.1:3100",  // placeholder; overridden by env at every start
    "disableSignUp": false
  }
}
```

`rm -rf /root/www/paperclip/instances/default/db` (leftover empty embedded-Postgres dir, unused now).

`authenticated` + `public` requires `auth.baseUrlMode=explicit` and an explicit public URL, or the server refuses to start (`server/src/index.ts`: *"authenticated public exposure requires auth.baseUrlMode=explicit"*).

### 5. Start wrapper — tracks the live tunnel hostname

The cloudflared quick-tunnel hostname changes on every reconnect. Paperclip's authenticated-mode trusted-host check needs `PAPERCLIP_PUBLIC_URL` to match the *current* hostname, so it's read fresh from NodePulse's own `nodes.json` (the node's own tunnel registry) on every process start, not hardcoded.

`/root/services/paperclip/start.sh`:

```bash
#!/bin/bash
set -euo pipefail

DATA_DIR="/root/www/paperclip"
NODES_JSON="/root/www/nodepulse/nodes.json"

TUNNEL_URL=$(node -e "
  const fs=require('fs');
  try {
    const db=JSON.parse(fs.readFileSync('$NODES_JSON','utf8'));
    const nodes=db.nodes || [];
    const last=nodes[nodes.length-1];
    process.stdout.write(last ? last.url : '');
  } catch { process.stdout.write(''); }
")
[ -z "$TUNNEL_URL" ] && TUNNEL_URL="http://127.0.0.1:3100"

export HOST=127.0.0.1
export PORT=3100
export PAPERCLIP_PUBLIC_URL="$TUNNEL_URL"
export DATABASE_URL="$(node -e "
  const c=require('$DATA_DIR/instances/default/config.json');
  process.stdout.write(c.database.connectionString);
")"

exec npx --yes paperclipai@latest run -d "$DATA_DIR" --no-repair
```

```bash
mkdir -p /root/services/paperclip
chmod +x /root/services/paperclip/start.sh
```

### 6. pm2

```bash
pm2 start /root/services/paperclip/start.sh --name paperclip --interpreter bash
pm2 save
```

### 7. nginx routing

Append to `/etc/nginx/sites-available/nodepulse` (inside the existing `server { }` block, alongside `/cli/`, `/desktop/`, `/peerjs/`):

```nginx
location ^~ /paperclip/ {
    proxy_pass http://127.0.0.1:3100/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $real_scheme;
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
    proxy_buffering off;
}

location ^~ /api/ {
    proxy_pass http://127.0.0.1:3100/api/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $real_scheme;
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
    proxy_buffering off;
}

location ^~ /assets/ {
    proxy_pass http://127.0.0.1:3100/assets/;
    proxy_set_header Host $host;
}

location ^~ /fonts/ {
    proxy_pass http://127.0.0.1:3100/fonts/;
    proxy_set_header Host $host;
}

location ^~ /brands/ {
    proxy_pass http://127.0.0.1:3100/brands/;
    proxy_set_header Host $host;
}
```

`$real_scheme` is already defined server-wide by the existing config (reads `X-Forwarded-Proto` from cloudflared) — reuse it, don't redefine it.

`^~` is required on all four so they win over the existing `location ~ \.php$` regex location without relying on declaration order.

```bash
nginx -t && nginx -s reload
```

### 8. Wire it into NodePulse's own lifecycle scripts

**`/root/bin/start-server`** — start PostgreSQL early (before the PHP/nginx block):

```bash
echo "[...] Starting PostgreSQL..."
service postgresql start > /dev/null 2>&1
echo "[OK] PostgreSQL running on :5432"
```

**`/root/bin/nodepulse`** — inside the loop, right after the maintenance daemon is (re)spawned on a new tunnel registration (search for `"Maintenance daemon active"`), add:

```bash
if command -v pm2 >/dev/null 2>&1 && [ -f "$HOME/services/paperclip/start.sh" ]; then
    pm2 describe paperclip > /dev/null 2>&1 \
        && pm2 restart paperclip > /dev/null 2>&1 \
        || pm2 start "$HOME/services/paperclip/start.sh" --name paperclip --interpreter bash > /dev/null 2>&1
    echo "[NodePulse] Paperclip refreshed for $TUNNEL_URL/paperclip/"
fi
```

This is what keeps `PAPERCLIP_PUBLIC_URL` in sync every time the quick-tunnel hostname rotates — `start.sh` re-reads `nodes.json` on every `pm2 restart`.

**`/root/bin/stop-server`** — alongside `pm2 stop peerserver`:

```bash
pm2 stop paperclip 2>/dev/null
```

---

## Verifying the install

```bash
# Direct, bypassing nginx
curl -s http://127.0.0.1:3100/api/health

# Through nginx, local
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/paperclip/
curl -s http://127.0.0.1:8080/api/health

# Through the public tunnel
TUNNEL_URL=$(node -e "const db=require('/root/www/nodepulse/nodes.json'); console.log(db.nodes[db.nodes.length-1].url)")
curl -s -o /dev/null -w "%{http_code}\n" "$TUNNEL_URL/paperclip/"

# Confirm NodePulse's own root/favicon are untouched
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/favicon.ico
```

pm2 logs: `pm2 logs paperclip`. On a healthy start you should see a banner with `Mode: external-postgres | static-ui`, `Deploy: authenticated (public)`, `Auth: ready`.

---

## First login and hardening

1. Generate a one-time bootstrap invite for the first admin account (`auth bootstrap-ceo`, not plain sign-up — with `disableSignUp: false` still set, sign-up is technically open, but the invite is the supported/documented path and works even after you lock sign-up down in step 3):

   ```bash
   TUNNEL_URL=$(node -e "const db=require('/root/www/nodepulse/nodes.json'); console.log(db.nodes[db.nodes.length-1].url)")
   npx --yes paperclipai@latest auth bootstrap-ceo -d /root/www/paperclip --base-url "$TUNNEL_URL"
   ```

   The command prints `Invite URL: <TUNNEL_URL>/invite/pcp_bootstrap_...` — **without** the `/paperclip/` prefix, because Paperclip doesn't know it's mounted on a subpath (same root-absolute-path limitation as everything else in this guide). Insert `/paperclip` manually before `/invite/...` when you open it:

   ```
   <TUNNEL_URL>/paperclip/invite/pcp_bootstrap_...
   ```

   Opening the unmodified link (without `/paperclip/`) hits NodePulse's own homepage, not Paperclip. The invite expires (`--expires-hours`, default a few days); re-run the command for a fresh one if it lapses. Completing sign-up through this link promotes that account to instance owner/admin (Paperclip's "board claim" flow).
2. Add at least one LLM provider key so agents can actually run — either export `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` in `start.sh` before the `exec` line and `pm2 restart paperclip`, or add it from inside the app (Instance → Secrets).
3. **Recommended:** once the owner account exists, set `"disableSignUp": true` in `config.json` and `pm2 restart paperclip`. As shipped (`disableSignUp: false`), anyone who has the tunnel URL can self-register a new account — fine for a first test, not for leaving it running unattended. Unlike the rest of NodePulse, Paperclip is **not** behind the shared `auth_gate.php` password; it uses its own Better-Auth login exclusively.
4. Database credentials are in `/root/.paperclip_db_credentials` (mode 600, root-only).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `You are running this script as root. Postgres does not support running as root.` | Embedded Postgres, not the external one | Confirm `config.json` → `database.mode` is `"postgres"` with a valid `connectionString`, not `"embedded-postgres"` |
| `authenticated public exposure requires auth.baseUrlMode=explicit` | `auth.baseUrlMode` left at `"auto"` | Set `"baseUrlMode": "explicit"` in `config.json` |
| Login/API calls fail with a trusted-host error | `PAPERCLIP_PUBLIC_URL` hostname doesn't match the URL in the browser (stale tunnel URL) | `pm2 restart paperclip` — `start.sh` re-reads the current tunnel URL from `nodes.json` every start |
| Blank page / JS 404s after visiting `/paperclip/xyz` directly (not via a link click) | The SPA has no base-path awareness; a direct/hard-refreshed deep link outside `/`, `/paperclip/` and the proxied prefixes isn't routed to Paperclip | Navigate to `/paperclip/` first, then use in-app links |
| `502` on `/paperclip/`, `/api/`, `/assets/` | pm2 process not running / PostgreSQL down | `pm2 logs paperclip`, `service postgresql status` |
| pm2 shows `paperclip` errored/looping | Bad `DATABASE_URL` or `nodes.json` missing | Check `pm2 logs paperclip`; ensure `/root/www/nodepulse/nodes.json` exists (created by the main NodePulse tunnel loop) |

---

## Notes

- No `pnpm`/Rust toolchain is needed on the node: `npx paperclipai@latest` pulls a CLI-managed, prebuilt install (server bundle + UI dist) from npm. A full git clone of the monorepo (kept for reference only at `/root/services/paperclip-src` on this node) is only needed to read the source, not to run the app.
- `/root/www/paperclip` holds Paperclip's live data (Postgres connection string, secrets, local file storage, logs) but is never served as static files by nginx — every `location` under it is a reverse proxy, not `root`/`try_files`. Treat it as sensitive despite living under `www/`.
- Data persists in system PostgreSQL (`paperclip` database) — back it up with normal `pg_dump`, independent of Paperclip's own `PAPERCLIP_DB_BACKUP_*` file-based backups (which back up the Postgres data dir it manages itself; irrelevant here since Postgres isn't embedded — check `instance.general` settings in-app if this matters to you).
