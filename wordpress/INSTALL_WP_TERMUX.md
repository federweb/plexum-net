# Installing WordPress + SQLite on NodePulse (Termux + Nginx)

Operational guide to install WordPress on a **NodePulse** instance (Termux on
Android) using **SQLite** as the database — no MySQL/MariaDB required. The
official `sqlite-database-integration` plugin from WordPress.org provides the
database layer. No additional services: just the PHP and Nginx already present
on every standard NodePulse node.

- Stack: Nginx (port 8080) + php-cgi 8.x (port 9000) + SQLite (file-based)
- Nginx document root: `~/www/`
- Project folder: `~/www/${SITE}/`
- Local URL: `http://127.0.0.1:8080/${SITE}/`

> The guide is **parameterised**: define `SITE` (and the locale) at the top of
> your shell and every block below works for any folder name, with no manual
> edits.

---

## How it works

```
Browser (HTTPS)
    │
    ▼
Cloudflare Tunnel  ←── trycloudflare.com or custom domain
    │
    ▼
Nginx :8080  (~/www/)
    │
    ├── /${SITE}/         →  PHP-CGI :9000
    │       │
    │       └── wp-content/database/wordpress.sqlite   ← database (created on first run)
    │
    └── rest of the NodePulse site
```

- **Database**: SQLite, file `wp-content/database/wordpress.sqlite` — created
  automatically on first access
- **Active plugin**: `sqlite-database-integration` (official WordPress.org plugin)
- **Drop-in**: `wp-content/db.php` (intercepts all MySQL queries and translates
  them to SQLite)
- **HTTPS**: auto-detected from Cloudflare headers (`X-Forwarded-Proto`,
  `CF-Visitor`)

---

## 0. Prerequisites

### 0.1 Base stack already present

| Component | Check |
|---|---|
| **Nginx** listening on `:8080` with root `~/www/` | `ss -tln \| grep 8080` |
| **php-cgi** listening on `127.0.0.1:9000` | `ss -tln \| grep 9000` |
| **PHP 8.x** with extensions `pdo_sqlite sqlite3 mbstring curl zip xml openssl json` | `php -m` |

This procedure does **not** install Nginx/php-cgi: it relies on what's already
provided by the standard NodePulse image. Nginx already serves `~/www/` as the
document root, so `http://127.0.0.1:8080/${SITE}/` automatically resolves to
files in `~/www/${SITE}/`.

Verify the SQLite-related modules in one shot:

```bash
php -m | grep -Ei "sqlite|pdo|mbstring|curl|zip|xml"
```

All five lines must appear. They are bundled with Termux's `php` package — if
any is missing, run `pkg install php`.

### 0.2 Set the variables (ONLY thing to edit)

Open a shell and run this — it is the only personalised part. Every later
block in the guide uses these variables and works as-is.

```bash
# === EDIT HERE ===
export SITE="wordpress"                              # folder name (case sensitive in the URL)
export WP_LOCALE="en_US"                             # e.g. en_US, it_IT, es_ES, fr_FR
# =================

# Derived (do NOT change)
export WEB_ROOT="$HOME/www"
export SITE_DIR="${WEB_ROOT}/${SITE}"

# Verify
echo "SITE=$SITE  DIR=$SITE_DIR  LOCALE=$WP_LOCALE"
```

> Keep **this same shell open** for steps 1–5. If you close it, re-export the
> variables.

> **Default folder name:** `wordpress` matches the existing NodePulse nginx
> rule and the beacon/recovery wiring documented at the bottom of this file.
> Change `SITE` only if you actually want to host multiple WordPress instances
> on the same node — each one needs its own folder, its own SQLite file and
> its own nginx `location` block.

---

## 1. Download WordPress

```bash
mkdir -p "${SITE_DIR}"
cd "$TMPDIR"     # on Termux /tmp is not user-writable; $TMPDIR is

if [ "$WP_LOCALE" = "en_US" ]; then
    # English: WordPress.org serves the canonical tarball without locale suffix
    curl -LO "https://wordpress.org/latest.tar.gz"
    WP_TARBALL="latest.tar.gz"
else
    # Localized builds live on the language sub-domain (it.wordpress.org, …)
    curl -LO "https://${WP_LOCALE%_*}.wordpress.org/latest-${WP_LOCALE}.tar.gz"
    WP_TARBALL="latest-${WP_LOCALE}.tar.gz"
fi

tar -xzf "${WP_TARBALL}"
mv wordpress/* wordpress/.htaccess "${SITE_DIR}/" 2>/dev/null
rm -rf "$TMPDIR/wordpress" "$TMPDIR/${WP_TARBALL}"
ls "${SITE_DIR}/" | head -10
```

> Use `WP_LOCALE="en_US"` for English. For other languages set the matching
> locale (`it_IT`, `es_ES`, `fr_FR`, `de_DE`, …) and the localized build will
> be fetched from the language sub-domain.

> If the folder already held a previous installation, wipe it first:
> ```bash
> rm -rf "${SITE_DIR}"/* "${SITE_DIR}"/.[!.]*
> ```

---

## 2. Add SQLite support

Three pieces are needed on top of vanilla WordPress: the official SQLite plugin,
its drop-in (`db.php`), and a protected `database/` folder where the SQLite
file will live.

### 2.1 Install the `sqlite-database-integration` plugin

```bash
cd "$TMPDIR"
curl -LO https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
unzip -q sqlite-database-integration.latest-stable.zip -d "${SITE_DIR}/wp-content/plugins/"
rm sqlite-database-integration.latest-stable.zip
ls "${SITE_DIR}/wp-content/plugins/sqlite-database-integration/" | head
```

You should see `db.copy`, `load.php`, `wp-includes/`, etc.

### 2.2 Install the drop-in

`db.copy` shipped with the plugin **is** the drop-in: copy it verbatim to
`wp-content/db.php`. WordPress looks for that file before bootstrapping the
default MySQL layer.

```bash
cp "${SITE_DIR}/wp-content/plugins/sqlite-database-integration/db.copy" \
   "${SITE_DIR}/wp-content/db.php"
```

### 2.3 Create the protected database folder

```bash
mkdir -p "${SITE_DIR}/wp-content/database"
echo 'DENY FROM ALL'              > "${SITE_DIR}/wp-content/database/.htaccess"
echo '<?php // Silence is gold. ?>' > "${SITE_DIR}/wp-content/database/index.php"
```

The `.sqlite` file will be created automatically on first access. The
`.htaccess` and `index.php` stubs prevent direct download if Nginx ever serves
the directory by mistake.

---

## 3. Configure `wp-config.php`

### 3.1 Fetch the secret keys

```bash
SALTS=$(curl -s https://api.wordpress.org/secret-key/1.1/salt/)
```

### 3.2 Generate the file with expanded variables

> The `PHP` heredoc is **unquoted**, so bash expands `${SALTS}`. **PHP**
> variables are escaped with `\$` so bash does not confuse them with its own.

```bash
cat > "${SITE_DIR}/wp-config.php" <<PHP
<?php
/**
 * WordPress configuration - ${SITE} (SQLite on NodePulse/Termux)
 */

// ** Database settings (SQLite via sqlite-database-integration plugin) ** //
define( 'DB_NAME',     'wordpress' );
define( 'DB_USER',     'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST',     'localhost' );
define( 'DB_CHARSET',  'utf8mb4' );
define( 'DB_COLLATE',  '' );

// SQLite database path (consumed by the sqlite-database-integration plugin)
define( 'DB_DIR',  __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', 'wordpress.sqlite' );

/**#@+
 * Authentication unique keys and salts.
 */
${SALTS}
/**#@-*/

\$table_prefix = 'wp_';

define( 'WP_DEBUG', false );

// Reverse-proxy / Cloudflare tunnel: detect HTTPS when the proxy speaks plain HTTP to nginx
if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
    \$_SERVER['HTTPS'] = 'on';
}
if ( isset( \$_SERVER['HTTP_CF_VISITOR'] ) ) {
    \$cf = json_decode( \$_SERVER['HTTP_CF_VISITOR'], true );
    if ( isset( \$cf['scheme'] ) && \$cf['scheme'] === 'https' ) {
        \$_SERVER['HTTPS'] = 'on';
    }
}

// nginx listens on :8080 but the tunnel is published on 443. If a client sends
// "Host: domain:8080" (e.g. URL with explicit :8080), strip the port so
// WordPress does not replicate it in absolute links.
if ( isset( \$_SERVER['HTTP_HOST'] ) ) {
    \$_SERVER['HTTP_HOST'] = preg_replace( '/:\d+\$/', '', \$_SERVER['HTTP_HOST'] );
}
if ( isset( \$_SERVER['SERVER_PORT'] ) && ! empty( \$_SERVER['HTTPS'] ) && \$_SERVER['HTTPS'] === 'on' ) {
    \$_SERVER['SERVER_PORT'] = 443;
}

// Dynamic site URL — survives Cloudflare tunnel URL changes. The trycloudflare
// hostname rotates on every restart, so we derive WP_HOME/WP_SITEURL from the
// current request instead of trusting whatever was stored in the DB during the
// install wizard. basename(__DIR__) gives the install folder (e.g. "${SITE}").
if ( isset( \$_SERVER['HTTP_HOST'] ) ) {
    \$_wp_proto = ( ! empty( \$_SERVER['HTTPS'] ) && \$_SERVER['HTTPS'] === 'on' ) ? 'https' : 'http';
    \$_wp_base  = \$_wp_proto . '://' . \$_SERVER['HTTP_HOST'] . '/' . basename( __DIR__ );
    define( 'WP_HOME',    \$_wp_base );
    define( 'WP_SITEURL', \$_wp_base );
    unset( \$_wp_proto, \$_wp_base );
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP

php -l "${SITE_DIR}/wp-config.php"     # sanity check: "No syntax errors"
```

### 3.3 Permissions

Termux runs everything as the regular Android user — there is no `www-data`.
The default 0700 from Termux is fine; just tighten `wp-config.php` so the
salts are not world-readable inside any shared backup:

```bash
chmod 600 "${SITE_DIR}/wp-config.php"
```

---

## 4. Nginx rule for the site

For clean permalinks (e.g. `?p=123` rewritten to `/2026/05/20/title/`) you
need a `try_files` block scoped to the site's folder.

Config file: `/data/data/com.termux/files/usr/etc/nginx/nginx.conf`

```bash
# Check whether the rule is already present in the server block
grep -n "location /${SITE}" /data/data/com.termux/files/usr/etc/nginx/nginx.conf
```

If it isn't, add it **inside the `server { ... }`**, **before** the
`location ~ \.php$` block. Block to paste:

```nginx
# WordPress permalink support for /${SITE}/
location /${SITE} {
    try_files $uri $uri/ /${SITE}/index.php?$args;
}
```

> Replace `${SITE}` with its actual value (e.g. `wordpress`): nginx does not
> expand shell variables in its config.

Reload nginx:

```bash
nginx -t && nginx -s reload
```

---

## 5. Verify and run the browser installer

### 5.1 CLI check

```bash
curl -sI "http://127.0.0.1:8080/${SITE}/"
# Expected: HTTP/1.1 302 Found
#           Location: http://127.0.0.1:8080/${SITE}/wp-admin/install.php

curl -sI "http://127.0.0.1:8080/${SITE}/wp-admin/install.php"
# Expected: HTTP/1.1 200 OK
```

### 5.2 Run the installer in the browser

⚠️ **Important — accessing via the Cloudflare Tunnel.**
If you have a `cloudflared tunnel --url http://127.0.0.1:8080` running, the
tunnel is **published on standard port 443**
(`https://<sub>.trycloudflare.com`). **Do not append `:8080`** to the public
URL: doing so forces the browser to send `Host: ...trycloudflare.com:8080`,
which WordPress then replicates in every link it generates, polluting
`siteurl`/`home` in the database.

Use **one** of:

```
# local, always HTTP
http://127.0.0.1:8080/${SITE}/wp-admin/install.php

# tunnel, always HTTPS, NEVER with :8080
https://<sub>.trycloudflare.com/${SITE}/wp-admin/install.php
```

Fill in the wizard:

- **Language**: matches the value of `WP_LOCALE`
- **Site title**: your choice
- **Username**: your choice — do **not** use `admin`
- **Password**: auto-generated, **write it down**
- **Your email**: your address
- **Search engine visibility**: tick the box to discourage indexing if this is
  a development node

Click **Install WordPress** → then **Log in**.

> The SQLite database file (`wp-content/database/wordpress.sqlite`) is created
> automatically the first time WordPress runs.

---

## 6. Copying WordPress to another NodePulse node

### Method A: fresh install on a new node

> Use this to deploy a **clean new installation** on another node.

**Step 1** — Copy the `${SITE}/` folder to the destination node (excluding the
existing database):

```bash
rsync -av --exclude='wp-content/database/*.sqlite' "${SITE_DIR}/" \
    destination:~/www/${SITE}/
```

The `--exclude` flag prevents overwriting the destination node's own database
(or lets the new database be created fresh on first run if none exists yet).

**Step 2** — On the destination node, add the Nginx block (section 4) and
reload.

**Step 3** — Generate fresh secret keys and replace the salts in
`~/www/${SITE}/wp-config.php`:

```bash
curl -s https://api.wordpress.org/secret-key/1.1/salt/
```

**Step 4** — Open `.../${SITE}/wp-admin/install.php` and complete the wizard.

---

### Method B: full clone with database

> Use this to **replicate an already configured site** (content included).

**Step 1** — Copy the entire `${SITE}/` folder including the SQLite database:

```bash
rsync -av "${SITE_DIR}/" destination:~/www/${SITE}/
```

**Step 2** — On the destination node, add the Nginx block (section 4) and
reload.

**Step 3** — The site is immediately live. WordPress auto-detects the tunnel
URL via the Cloudflare headers in `wp-config.php` — no changes needed.

> **Note**: the `wp-config.php` secret keys are shared between cloned
> instances. This is not a security issue as long as each node has its own
> tunnel and sessions are not shared. For maximum security, regenerate the
> keys anyway (see Step 3 of Method A).

---

## 7. Relevant file structure

```
${SITE}/
├── wp-config.php                          ← main configuration
├── wp-content/
│   ├── database/
│   │   ├── .htaccess                     ← DENY FROM ALL
│   │   ├── index.php                     ← silence stub
│   │   └── wordpress.sqlite              ← DATABASE (created on first run, do not commit to git)
│   ├── db.php                             ← SQLite drop-in (do not remove)
│   └── plugins/
│       └── sqlite-database-integration/   ← official SQLite plugin
└── (the rest of vanilla WordPress)
```

---

## 8. NodePulse Resilience — Cloudflare Tunnel Recovery

NodePulse includes a **Recovery Browser** (`/beacon/`) that kicks in
automatically when the Cloudflare tunnel goes down. It uses a Service Worker
(scope `/`) combined with a connectivity monitor that pings the node every 5
seconds and redirects to the beacon page after 2 consecutive failures,
allowing the user to reconnect once the tunnel is back up.

For this to work on WordPress pages — both the **public frontend** and the
**wp-admin backend** — you need to inject the following script tag into every
page's footer:

```html
<script src="/nodepulse-sw.js"></script>
```

What this script does:
- Registers the Service Worker with `scope: /` (site-wide coverage)
- Pings `/beacon/?ping` every 5 seconds to detect tunnel death
- After 2 consecutive failures: saves the current path to `localStorage`, then
  redirects to `/beacon/`
- Once the tunnel recovers, the beacon page redirects back to where the user was

**How to add it to WordPress:**

The cleanest way is via a plugin or by editing the active theme's `footer.php`.
Using a plugin (e.g. *Insert Headers and Footers*):

1. `wp-admin → Settings → Insert Headers and Footers`
2. Paste `<script src="/nodepulse-sw.js"></script>` in the **Footer** field
3. Save

This covers all public-facing pages. For wp-admin pages, add the same tag to
the theme's `functions.php`:

```php
add_action('admin_footer', function () {
    echo '<script src="/nodepulse-sw.js"></script>';
});
```

**Important — do not delete these files:**

| File / Folder | Purpose |
|---|---|
| `~/www/nodepulse-sw.js` | Connectivity monitor + SW registration script |
| `~/www/beacon/` | Recovery Browser — fallback when tunnel is down |
| `~/www/nodepulse/` | Peer-to-peer network |

Removing any of these will break the tunnel-recovery mechanism across the
entire NodePulse site, including WordPress.

---

## 9. Maintenance

### 9.1 WordPress core / plugin updates

Automatic updates from wp-admin work normally. The SQLite database is not
involved in core updates.

To update the SQLite plugin itself:
`wp-admin → Plugins → sqlite-database-integration → Update`

After a plugin update the drop-in (`wp-content/db.php`) may need to be
re-synchronised with the new `db.copy`:

```bash
cp "${SITE_DIR}/wp-content/plugins/sqlite-database-integration/db.copy" \
   "${SITE_DIR}/wp-content/db.php"
```

### 9.2 Database backup

```bash
DATE=$(date +%F-%H%M)
mkdir -p ~/backup
cp "${SITE_DIR}/wp-content/database/wordpress.sqlite" \
   "$HOME/backup/${SITE}-db-${DATE}.sqlite"
gzip "$HOME/backup/${SITE}-db-${DATE}.sqlite"
```

### 9.3 Full backup (DB + files)

```bash
DATE=$(date +%F-%H%M)
mkdir -p ~/backup
tar -czf "$HOME/backup/${SITE}-${DATE}.tar.gz" \
    -C "${WEB_ROOT}" "${SITE}"
```

### 9.4 Full uninstall

```bash
rm -rf "${SITE_DIR}"
# Don't forget to remove the `location /${SITE}` block from nginx.conf and reload it
```

---

## 10. Troubleshooting

| Error | Cause | Solution |
|---|---|---|
| `No input file specified` | Missing `/${SITE}` Nginx block | Add the block (section 4) and reload Nginx |
| CSS not loading (unstyled page) | `http://` URL on an `https://` page | Verify the HTTPS auto-detection block in `wp-config.php` (section 3.2) |
| `Error establishing a database connection` | Missing or incorrect `db.php` | Check that `wp-content/db.php` is the SQLite plugin drop-in (re-run section 2.2) |
| `Your PHP installation appears to be missing the MySQL extension which is required by WordPress.` | `wp-content/db.php` not loaded (drop-in missing or named wrong) | Re-copy `db.copy` → `db.php` (section 2.2) and confirm the `sqlite-database-integration` plugin folder exists |
| Permalinks return 404 (URLs like `/2026/05/20/title/`) | Missing `try_files` rule in nginx | Add the `location /${SITE}` block (section 4) and reload nginx |
| Redirect loop after login | `WP_SITEURL`/`WP_HOME` hard-coded with a stale URL | The dynamic block in `wp-config.php` (section 3.2) derives them from `HTTP_HOST` on every request — make sure that block is present and not replaced by a static URL |
| Every click bounces to `/beacon/` (recovery browser) on a fresh tunnel | DB still holds the previous `trycloudflare.com` host in `siteurl`/`home`, WordPress 302s to the dead URL, the ping fails, beacon kicks in | Add the dynamic `WP_HOME`/`WP_SITEURL` block from section 3.2; optionally clean the DB: `php -r '$db=new PDO("sqlite:wp-content/database/wordpress.sqlite"); $db->exec("UPDATE wp_options SET option_value=\"/${SITE}\" WHERE option_name IN (\"siteurl\",\"home\")");'` |
| Absolute links generated with `:8080` (CSS/JS missing, weird HTTPS redirects) | Wizard run via `https://...trycloudflare.com:8080/...`: WordPress stored `siteurl`/`home` with the port | Fix from the SQLite CLI: `sqlite3 wp-content/database/wordpress.sqlite "UPDATE wp_options SET option_value = REPLACE(option_value, ':8080', '') WHERE option_name IN ('siteurl','home');"`. The port-stripping block in `wp-config.php` (section 3.2) prevents it from happening again |
| Browser keeps going to `:8080` even after the DB fix | Browser cache / history / bookmarks | Test in private/incognito. Clear browsing data for the domain |
| "Allowed memory size exhausted" | PHP `memory_limit` too low | In `wp-config.php`: `define('WP_MEMORY_LIMIT', '256M');` |
