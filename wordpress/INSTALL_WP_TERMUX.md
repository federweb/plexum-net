# WordPress on NodePulse (Termux + Nginx + SQLite)

This WordPress installation runs on a **NodePulse** instance (Termux/Android) without MySQL, using **SQLite** as the database. No additional services required: just PHP and Nginx, already present on every standard NodePulse node.

---

## Quick Install

Download the pre-configured package and extract it directly into `~/www/`:

```bash
cd ~/www
curl -LO https://www.plexum.net/nodepulse/core-dist/wordpress.zip
unzip wordpress.zip
rm wordpress.zip
```

Then follow the **Nginx Configuration** and **First Run** sections below.

> **Tip — Claude Code install:** If you have Claude Code installed on your NodePulse terminal, the easiest way to complete the setup is to ask Claude directly:
> open the NodePulse terminal, run `claude`, and tell it:
> *"Read ~/www/wordpress/README.md and follow the installation instructions."*
> Claude will configure Nginx, generate secret keys, and guide you through the entire setup automatically.

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
    ├── /wordpress/        →  PHP-CGI :9000
    │       │
    │       └── wp-content/database/wordpress.sqlite   ← database (created on first run)
    │
    └── rest of the NodePulse site
```

- **Database**: SQLite, file `wp-content/database/wordpress.sqlite` — created automatically on first access
- **Active plugin**: `sqlite-database-integration` (official WordPress.org plugin)
- **Drop-in**: `wp-content/db.php` (intercepts all MySQL queries and translates them to SQLite)
- **HTTPS**: auto-detected from Cloudflare headers (`X-Forwarded-Proto`, `CF-Visitor`)

---

## Requirements on the NodePulse instance

### 1. Required stack (already present on standard NodePulse)

| Component | Minimum version | Notes |
|---|---|---|
| PHP-CGI | 8.0+ | Must run on `127.0.0.1:9000` |
| Nginx | any | Root at `~/www/`, port 8080 |
| Cloudflare tunnel | any | For public HTTPS |

### 2. Required PHP modules

All included in Termux's `php` package:

```
pdo_sqlite   sqlite3   mbstring   curl   zip   xml   openssl   json
```

Verify with: `php -m | grep -E "sqlite|pdo|mbstring|curl|zip|xml"`

### 3. Nginx configuration

Add the following block inside `server { ... }`, **before** the `location ~ \.php$` block.
The path `/wordpress` must match the folder name — this package is designed to live at `~/www/wordpress/`.

```nginx
# WordPress permalink support
location /wordpress {
    try_files $uri $uri/ /wordpress/index.php?$args;
}
```

Config file: `/data/data/com.termux/files/usr/etc/nginx/nginx.conf`

After editing: `nginx -t && nginx -s reload`

---

## First Run (new installation)

**Step 1** — Extract the package into `~/www/` (see Quick Install above).

**Step 2** — Add the Nginx block and reload (see section above).

**Step 3** — Generate fresh **secret keys** for this instance:

```bash
curl -s https://api.wordpress.org/secret-key/1.1/salt/
```

Replace the `define('AUTH_KEY', ...)` values in `~/www/wordpress/wp-config.php` with the output above.

**Step 4** — Open `https://<your-tunnel>/wordpress/wp-admin/install.php` and complete the setup wizard.

> The SQLite database (`wp-content/database/wordpress.sqlite`) is created automatically the first time WordPress runs. That is why it is not included in the zip package — each node gets its own fresh database on first access.

---

## Copying WordPress to another NodePulse node

### Method A: fresh install on a new node

> Use this to deploy a **clean new installation** on another node.

**Step 1** — Copy the `wordpress/` folder to the destination node (excluding the existing database):

```bash
rsync -av --exclude='wp-content/database/*.sqlite' wordpress/ destination:~/www/wordpress/
```

The `--exclude` flag prevents overwriting the destination node's own database (or lets the new database be created fresh on first run if none exists yet).

**Step 2** — Add the Nginx block and reload.

**Step 3** — Generate new secret keys (see Step 3 above) and update `wp-config.php`.

**Step 4** — Open `.../wordpress/wp-admin/install.php` and complete the wizard.

---

### Method B: full clone with database

> Use this to **replicate an already configured site** (content included).

**Step 1** — Copy the entire `wordpress/` folder including the SQLite database:

```bash
rsync -av wordpress/ destination:~/www/wordpress/
```

**Step 2** — Add the Nginx block and reload.

**Step 3** — The site is immediately live. WordPress auto-detects the tunnel URL via Cloudflare headers in `wp-config.php` — no changes needed.

> **Note**: The `wp-config.php` secret keys are shared between cloned instances. This is not a security issue as long as each node has its own tunnel and sessions are not shared. For maximum security, regenerate the keys anyway (see Step 3 of Method A).

---

## Relevant file structure

```
wordpress/
├── wp-config.php                          ← main configuration
├── wp-content/
│   ├── database/
│   │   └── wordpress.sqlite               ← DATABASE (created on first run, do not commit to git)
│   ├── db.php                             ← SQLite drop-in (do not remove)
│   └── plugins/
│       └── sqlite-database-integration/   ← official SQLite plugin
└── README.md                              ← this file
```

---

## NodePulse Resilience — Cloudflare Tunnel Recovery

NodePulse includes a **Recovery Browser** (`/beacon/`) that kicks in automatically when the Cloudflare tunnel goes down. It uses a Service Worker (scope `/`) combined with a connectivity monitor that pings the node every 5 seconds and redirects to the beacon page after 2 consecutive failures, allowing the user to reconnect once the tunnel is back up.

For this to work on WordPress pages — both the **public frontend** and the **wp-admin backend** — you need to inject the following script tag into every page's footer:

```html
<script src="/nodepulse-sw.js"></script>
```

What this script does:
- Registers the Service Worker with `scope: /` (site-wide coverage)
- Pings `/beacon/?ping` every 5 seconds to detect tunnel death
- After 2 consecutive failures: saves the current path to `localStorage`, then redirects to `/beacon/`
- Once the tunnel recovers, the beacon page redirects back to where the user was

**How to add it to WordPress:**

The cleanest way is via a plugin or by editing the active theme's `footer.php`. Using a plugin (e.g. *Insert Headers and Footers*):

1. `wp-admin → Settings → Insert Headers and Footers`
2. Paste `<script src="/nodepulse-sw.js"></script>` in the **Footer** field
3. Save

This covers all public-facing pages. For wp-admin pages, add the same tag to the theme's `functions.php`:

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
| `~/www/nodepulse/` | Pear to pear network |

Removing either of these will break the tunnel-recovery mechanism across the entire NodePulse site, including WordPress.

---

## WordPress updates

Automatic updates from wp-admin work normally. The SQLite database is not involved in core updates.

To update the SQLite plugin as well:
`wp-admin → Plugins → sqlite-database-integration → Update`

---

## Troubleshooting

| Error | Cause | Solution |
|---|---|---|
| `No input file specified` | Missing `/wordpress` Nginx block | Add the block and reload Nginx |
| CSS not loading (unstyled page) | `http://` URL on an `https://` page | Check that `wp-config.php` contains the HTTPS auto-detection block |
| `Error establishing a database connection` | Missing or incorrect `db.php` | Verify that `wp-content/db.php` exists and is the SQLite plugin drop-in |
| Redirect loop | `WP_SITEURL`/`WP_HOME` hardcoded in `wp-config.php` | Remove those `define()` lines and let auto-detect handle it |
