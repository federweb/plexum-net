# FreshRSS Installation Guide — NodePulse Infrastructure

This guide covers installing FreshRSS on a **NodePulse** environment running on either **WSL2** (Windows) or **Termux** (Android). The infrastructure is identical in both cases: Nginx on port 8080, php-cgi on port 9000 (FastCGI), SQLite via pdo_sqlite.

---

## Prerequisites

NodePulse already provides:
- Nginx (port 8080, root at `/root/www`)
- php-cgi bound to `127.0.0.1:9000`
- PHP 8.3 with: `curl`, `mbstring`, `intl`, `zip`, `pdo_sqlite`, `openssl`, `sodium`

What is **missing by default** and must be installed:
- `php8.3-xml` — provides `dom`, `xml`, `simplexml` (required by FreshRSS)

---

## Step 1 — Install the missing PHP extension

**WSL2:**
```bash
apt-get install -y php8.3-xml
```

**Termux:**
```bash
pkg install php-xml
```

---

## Step 2 — Download FreshRSS

```bash
cd /root/www
wget https://github.com/FreshRSS/FreshRSS/archive/refs/heads/edge.zip -O FreshRSS-latest.zip
unzip FreshRSS-latest.zip
mv FreshRSS-edge rss
```

> The folder name (`rss`) becomes the URL path: `http://localhost:8080/rss`

---

## Step 3 — Set permissions

```bash
chown -R www-data:www-data /root/www/rss   # WSL2
# On Termux use: chmod -R 755 /root/www/rss
chmod -R 755 /root/www/rss/data
```

---

## Step 4 — Restart php-cgi

After installing new extensions you must restart php-cgi so they are loaded:

```bash
pkill php-cgi
php-cgi -b 127.0.0.1:9000 &
```

Verify it is running:
```bash
ss -tlnp | grep 9000
```

---

## Step 5 — Run the web installer

Open the installer in your browser (or via Cloudflare tunnel):

```
http://localhost:8080/rss/p/i/index.php?step=1
```

The installer walks through:

| Step | What it does |
|------|-------------|
| 1 | Checks PHP requirements (dom, xml, pdo_sqlite) |
| 2 | Database selection — choose **SQLite** (no extra setup needed) |
| 3 | Create admin account — choose your own username and password (min 7 chars) |
| 4 | Installation complete |

---

## Step 6 — Nginx configuration (already in NodePulse)

The NodePulse Nginx config already handles PHP via FastCGI. No changes needed. For reference, the relevant block is:

```nginx
location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

---

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `Missing DOM library` | `php8.3-xml` not installed | `apt-get install -y php8.3-xml` then restart php-cgi |
| `Missing XML library` | Same as above | Same fix |
| `Missing PDO or drivers` | pdo_sqlite not loaded | Check `/etc/php/8.3/cgi/conf.d/` for `20-pdo_sqlite.ini` |
| Blank page / 502 | php-cgi not running | Run `php-cgi -b 127.0.0.1:9000 &` |

---

## Notes

- **Database**: SQLite is the simplest choice on NodePulse — no extra services needed. The database file is stored in `data/` inside the FreshRSS directory.
- **Cloudflare tunnel**: NodePulse exposes the local port 8080 via a Cloudflare tunnel. FreshRSS works through it without any extra configuration.
- **php-cgi persistence**: On NodePulse, php-cgi is managed by the platform. After a restart of the environment, it will be relaunched automatically. If you manually kill it, restart with `php-cgi -b 127.0.0.1:9000 &`.
