# Installing WordPress + MariaDB on WSL2 (Openbox)

Operational guide to install WordPress with a **MariaDB/MySQL** database on a
**WSL2 Ubuntu 24.04** instance running the **Openbox** desktop (no systemd as
PID 1), served by the **nginx** instance already present on the system.

- Stack: nginx 1.24 (port 8080) + php-cgi 8.3 (port 9000) + MariaDB 10.11 (port 3306)
- Nginx document root: `/root/www/`
- Project folder: `/root/www/${SITE}/`
- Local URL: `http://127.0.0.1:8080/${SITE}/`

> The guide is **parameterised**: define `SITE` (and a couple more variables)
> at the top of your shell and every block below works for any folder name,
> with no manual edits.

---

## 0. Prerequisites

### 0.1 Base stack already present

| Component | Check |
|---|---|
| **nginx** listening on `:8080` with root `/root/www/` | `ss -tln \| grep 8080` |
| **php-cgi** listening on `127.0.0.1:9000` | `ss -tln \| grep 9000` |
| **PHP 8.3** with extensions `curl gd intl mbstring xml zip` | `php -m` |
| **PHP handler in the nginx server block** (`location ~ \.php$`) | `grep -A3 'location ~ \\\\.php' /etc/nginx/sites-enabled/*` |

This procedure does **not** install nginx/php-cgi: it relies on what's already
there. nginx already serves `/root/www/` as the document root, so
`http://127.0.0.1:8080/${SITE}/` automatically resolves to files in
`/root/www/${SITE}/`.

### 0.2 Set the variables (ONLY thing to edit)

Open a shell and run this — it is the only personalised part. Every later
block in the guide uses these variables and works as-is.

```bash
# === EDIT HERE ===
export SITE="NEGOZIO"                                # folder name (case sensitive in the URL)
export WP_LOCALE="it_IT"                             # e.g. it_IT, en_US, es_ES, fr_FR
# =================

# Derived (do NOT change)
export SITE_LC=$(echo "$SITE" | tr '[:upper:]' '[:lower:]')
export WEB_ROOT="/root/www"
export SITE_DIR="${WEB_ROOT}/${SITE}"
export DB_NAME="wp_${SITE_LC}"
export DB_USER="wp_${SITE_LC}"
export CRED_FILE="/tmp/${DB_NAME}_credentials.txt"

# Verify
echo "SITE=$SITE  DIR=$SITE_DIR  DB=$DB_NAME  USER=$DB_USER  CRED=$CRED_FILE"
```

> Keep **this same shell open** for steps 1–7. If you close it, re-export the
> variables: the only file that persists across shells is `${CRED_FILE}`
> (it contains only the DB password).

---

## 1. Install MariaDB

Once per system, not per site.

```bash
DEBIAN_FRONTEND=noninteractive apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server
```

WSL2 does **not** run systemd as PID 1, so `systemctl` may not work.
Use the legacy `service` wrapper to start MariaDB:

```bash
service mariadb start
service mariadb status     # should report "Server version 10.11.x-MariaDB"
ss -tln | grep 3306        # 127.0.0.1:3306 must be LISTEN
```

> **Auto-start:** WSL2 doesn't boot like a normal Linux distro. To start
> MariaDB whenever a shell opens, add this to `~/.bashrc`:
> ```bash
> service mariadb status >/dev/null 2>&1 || sudo service mariadb start
> ```
> (or add it to the existing start-up script that already launches nginx/php-cgi).

### 1.1 Minimal hardening (optional)

Skip it for local use; if exposing to a network, run:

```bash
mariadb-secure-installation
```

---

## 2. Create database and user

```bash
# Create the site folder if it doesn't exist
mkdir -p "${SITE_DIR}"

# Generate a strong password and save it (we'll use it in wp-config.php)
DB_PASS="${DB_NAME}_$(openssl rand -hex 8)"
echo "DB_PASS=$DB_PASS" > "${CRED_FILE}"
chmod 600 "${CRED_FILE}"

mariadb -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# Verify the user can actually connect
mariadb -u "${DB_USER}" -p"${DB_PASS}" -e "SHOW DATABASES;"
```

Output must include `${DB_NAME}`.

> **DB_HOST note:** in `wp-config.php` use `127.0.0.1`, not `localhost`. On
> Debian/Ubuntu `localhost` tries the **unix socket**, whose path and
> permissions can vary; `127.0.0.1` forces TCP and is more predictable.

---

## 3. Download WordPress

```bash
cd /tmp
curl -LO "https://${WP_LOCALE%_*}.wordpress.org/latest-${WP_LOCALE}.tar.gz"
tar -xzf "latest-${WP_LOCALE}.tar.gz"
mv wordpress/* wordpress/.htaccess "${SITE_DIR}/" 2>/dev/null
rm -rf /tmp/wordpress "/tmp/latest-${WP_LOCALE}.tar.gz"
ls "${SITE_DIR}/" | head -10
```

> If the folder already held a previous installation, wipe it first:
> ```bash
> rm -rf "${SITE_DIR}"/* "${SITE_DIR}"/.[!.]*
> mariadb -u root -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;"
> ```

---

## 4. Configure `wp-config.php`

### 4.1 Fetch the secret keys

```bash
SALTS=$(curl -s https://api.wordpress.org/secret-key/1.1/salt/)
DB_PASS=$(grep DB_PASS= "${CRED_FILE}" | cut -d= -f2)
```

### 4.2 Generate the file with expanded variables

> The `PHP` heredoc is **unquoted**, so bash expands `${SALTS}`, `${DB_NAME}`,
> `${DB_USER}`, `${DB_PASS}`. **PHP** variables are escaped with `\$` so bash
> does not confuse them with its own.

```bash
cat > "${SITE_DIR}/wp-config.php" <<PHP
<?php
/**
 * WordPress configuration - ${SITE} (MySQL/MariaDB on WSL2)
 */

define( 'DB_NAME',     '${DB_NAME}' );
define( 'DB_USER',     '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST',     '127.0.0.1' );
define( 'DB_CHARSET',  'utf8mb4' );
define( 'DB_COLLATE',  '' );

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

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP

php -l "${SITE_DIR}/wp-config.php"     # sanity check: "No syntax errors"
```

### 4.3 Permissions

```bash
chmod 640 "${SITE_DIR}/wp-config.php"
chown -R www-data:www-data "${SITE_DIR}/wp-content/"
```

---

## 5. nginx rule for the site

For clean permalinks (e.g. `?p=123` rewritten to `/2026/05/20/title/`) you
need a `try_files` block scoped to the site's folder.

```bash
# Check whether the rule is already present in the server block
grep -n "location /${SITE}" /etc/nginx/sites-enabled/* 2>/dev/null
```

If it isn't, add it **inside the `server { ... }`**, **before** the
`location ~ \.php$` block. Block to paste:

```nginx
# WordPress permalink support for /${SITE}/
location /${SITE} {
    try_files $uri $uri/ /${SITE}/index.php?$args;
}
```

> Replace `${SITE}` with its actual value (e.g. `NEGOZIO`): nginx does not
> expand shell variables in its config.

Reload nginx:

```bash
nginx -t && nginx -s reload
```

---

## 6. Restart php-cgi after installing mysqli

> **Typical WSL2 gotcha.**
> If `php-cgi` was already running **before** you installed the MariaDB /
> `php-mysql` packages, the process won't see the `mysqli` extension and
> WordPress prints:
>
> *"Your PHP installation appears to be missing the MySQL extension which
> is required by WordPress."*
>
> The fix is to **restart php-cgi**, not reinstall anything.

### 6.1 Detect the problem

```bash
cat > "${WEB_ROOT}/_mysqltest.php" <<'EOF'
<?php
echo "mysqli loaded: " . (extension_loaded('mysqli') ? 'YES' : 'NO') . "\n";
EOF
curl -s http://127.0.0.1:8080/_mysqltest.php
rm "${WEB_ROOT}/_mysqltest.php"
```

If it prints `NO`, continue with 6.2.

### 6.2 Restart php-cgi

```bash
pkill -f 'php-cgi.*9000'
sleep 1
nohup env PHP_FCGI_CHILDREN=2 PHP_FCGI_MAX_REQUESTS=0 \
    /usr/bin/php-cgi -d opcache.enable=0 -b 127.0.0.1:9000 \
    >/tmp/php-cgi.log 2>&1 &
disown
sleep 2
ss -tln | grep 9000        # 127.0.0.1:9000 must be LISTEN
```

> If the system already has a bootstrap script (e.g. `/root/bin/start-server`),
> use it instead of launching php-cgi by hand, so the restart stays aligned
> with the rest of the start-up (nginx, cloudflared, …).

---

## 7. Verify and run the browser installer

### 7.1 CLI check

```bash
curl -sI "http://127.0.0.1:8080/${SITE}/"
# Expected: HTTP/1.1 302 Found
#           Location: http://127.0.0.1:8080/${SITE}/wp-admin/install.php

curl -sI "http://127.0.0.1:8080/${SITE}/wp-admin/install.php"
# Expected: HTTP/1.1 200 OK
```

### 7.2 Run the installer in the browser

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
- **Search engine visibility**: tick the box to discourage indexing (this is
  a local development instance)

Click **Install WordPress** → then **Log in**.

---

## 8. Maintenance

> Every block assumes the variables from section 0.2 are exported.
> If you're in a fresh shell, re-export them first.

### 8.1 After a WSL restart

```bash
service mariadb start
# nginx + php-cgi: should restart from your start-server script
```

### 8.2 Database backup

```bash
DB_PASS=$(grep DB_PASS= "${CRED_FILE}" | cut -d= -f2)
mariadb-dump -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    | gzip > "${SITE_DIR}/_backup-$(date +%F).sql.gz"
```

### 8.3 Full backup (DB + uploads)

```bash
DATE=$(date +%F-%H%M)
DB_PASS=$(grep DB_PASS= "${CRED_FILE}" | cut -d= -f2)
mkdir -p /root/backup
mariadb-dump -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    > "/root/backup/${DB_NAME}-${DATE}.sql"
tar -czf "/root/backup/${SITE}-${DATE}.tar.gz" \
    -C "${WEB_ROOT}" "${SITE}" --exclude="${SITE}/_backup-*"
```

### 8.4 Restore

```bash
mariadb -u root "${DB_NAME}" < "/root/backup/${DB_NAME}-YYYY-MM-DD.sql"
tar -xzf "/root/backup/${SITE}-YYYY-MM-DD-HHMM.tar.gz" -C "${WEB_ROOT}/"
```

### 8.5 WordPress updates

From the admin panel: **Dashboard → Updates**. WordPress upgrades itself,
plugins and themes automatically. Core updates do not touch the database.

### 8.6 Reset admin password from the CLI

```bash
DB_PASS=$(grep DB_PASS= "${CRED_FILE}" | cut -d= -f2)
mariadb -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -e \
    "UPDATE wp_users SET user_pass = MD5('newpassword') WHERE user_login = 'USERNAME';"
```

(On the next login WordPress rehashes the MD5 into the modern phpass format.)

### 8.7 Full uninstall

```bash
rm -rf "${SITE_DIR}"
mariadb -u root -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; DROP USER IF EXISTS '${DB_USER}'@'localhost';"
rm -f "${CRED_FILE}"
# Don't forget to remove the `location /${SITE}` block from nginx and reload it
```

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `500 Internal Server Error` with "missing MySQL extension" message | php-cgi has not loaded `mysqli` | Restart php-cgi (section 6.2) |
| `Error establishing a database connection` | MariaDB not running, wrong password, or wrong `DB_HOST` | `service mariadb start`; double-check `DB_PASSWORD` in `wp-config.php` and `DB_HOST=127.0.0.1` |
| Permalinks return 404 (URLs like `/2026/05/20/title/`) | Missing `try_files` rule in nginx | Add the `location /${SITE}` block (section 5) and reload nginx |
| CSS/JS not loading (unstyled page) | `siteurl` is `http://` but the site is reached over `https://` | Handled by the HTTPS auto-detection block in `wp-config.php` — verify it's still there |
| `service mariadb start` prints `invoke-rc.d: could not determine current runlevel` | WSL2 without systemd | Harmless warning. MariaDB starts anyway, verify with `ss -tln \| grep 3306` |
| Redirect loop after login | `WP_SITEURL` / `WP_HOME` defined in `wp-config.php` with the wrong URL | Remove those `define()` lines and let WordPress auto-detect from `HTTP_HOST` |
| Absolute links generated with `:8080` (CSS/JS missing, weird HTTPS redirects) | Wizard run via `https://...trycloudflare.com:8080/...`: WordPress stored `siteurl`/`home` with the port | Fix in DB: `mariadb -u root ${DB_NAME} -e "UPDATE wp_options SET option_value = REPLACE(option_value, ':8080', '') WHERE option_name IN ('siteurl','home');"` (also replace `http://` with `https://` if needed). The port-stripping block in `wp-config.php` (section 4.2) prevents it from happening again |
| Browser keeps going to `:8080` even after the DB fix | Browser cache / history / bookmarks | Test in private/incognito: if it works there, it's only the browser. Clear browsing data for the domain |
| "Allowed memory size exhausted" | PHP `memory_limit` too low | In `wp-config.php`: `define('WP_MEMORY_LIMIT', '256M');` |
| Uploads > 2 MB fail | PHP defaults | In `/etc/php/8.3/cgi/php.ini` raise `upload_max_filesize` and `post_max_size` (then restart php-cgi, section 6.2) |

---

## 10. Quick reference

(Substitute `${SITE}` with the value chosen in section 0.2.)

```
nginx document root:   /root/www/
WordPress files:       /root/www/${SITE}/
WP config:             /root/www/${SITE}/wp-config.php
Database:              ${DB_NAME}   (user: ${DB_USER} @ 127.0.0.1:3306)
DB password file:      ${CRED_FILE}   (LOCAL — do not commit)
Local admin URL:       http://127.0.0.1:8080/${SITE}/wp-admin/
Tunnel admin URL:      https://<sub>.trycloudflare.com/${SITE}/wp-admin/
nginx log:             /var/log/nginx/error.log
php-cgi log:           /tmp/php-cgi.log
MariaDB log:           /var/log/mysql/error.log
nginx config:          /etc/nginx/sites-enabled/nodepulse
php-cgi config:        /etc/php/8.3/cgi/php.ini  +  /etc/php/8.3/cgi/conf.d/
MariaDB config:        /etc/mysql/mariadb.conf.d/
```

---

## Appendix A — Multiple sites in parallel

The same scheme supports several installations on the same WSL host: every
site gets its own folder, database and DB user thanks to the variables in
section 0.2. For each new site repeat **only** sections 2–7 with a different
`SITE`. MariaDB (section 1) is installed once.

Example shell for a second site:

```bash
export SITE="BLOG"
export WP_LOCALE="en_US"
# (re-run the "Derived" lines from section 0.2)
```
