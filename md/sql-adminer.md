# SQL Adminer on NodePulse

A guide to installing [Adminer](https://www.adminer.org/) as a protected web page on NodePulse, for both **WSL2** and **Termux** setups.

Adminer is a single-file PHP database manager that supports SQLite, MySQL, PostgreSQL, and more. This setup places the Adminer core outside the web root so it cannot be accessed directly, and protects the entry point with NodePulse's shared auth gate.

---

## How it works

- `adminer-core.php` lives **outside** the web root — unreachable via HTTP
- `/sql-adminer/index.php` is the only entry point — it runs `auth_gate.php` first, then includes Adminer
- No additional Nginx rules are needed

---

## WSL2

### 1. Download Adminer outside the web root

```bash
curl -sL "https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1.php" \
     -o /root/adminer-core.php
```

### 2. Create the directory

```bash
mkdir -p /root/www/sql-adminer
```

### 3. Create `/root/www/sql-adminer/index.php`

```php
<?php
require_once __DIR__ . '/../auth_gate.php';

function adminer_object() {
    class AdminerCustom extends Adminer {
        function name() { return 'Sql Adminer'; }
        function login($login, $password) { return true; }
    }
    return new AdminerCustom();
}

require '/root/adminer-core.php';
```

### 4. Check PHP has the required driver

For **SQLite**:
```bash
php -r "echo in_array('sqlite', PDO::getAvailableDrivers()) ? 'OK' : 'Missing';"
```

For **MySQL** (optional):
```bash
php -r "echo extension_loaded('pdo_mysql') ? 'OK' : 'Missing';"
```

If a driver is missing, install it:
```bash
# SQLite
sudo apt install php-sqlite3

# MySQL
sudo apt install php-mysql
```

Then restart PHP-FPM:
```bash
sudo systemctl restart php8.*-fpm
```

### 5. Open in browser

```
http://localhost:8080/sql-adminer/
```

Log in with your NodePulse auth gate password, then fill in the database connection form inside Adminer.

---

## Termux

### 1. Download Adminer outside the web root

```bash
curl -sL "https://github.com/vrana/adminer/releases/download/v4.8.1/adminer-4.8.1.php" \
     -o $HOME/adminer-core.php
```

### 2. Create the directory

```bash
mkdir -p $HOME/www/sql-adminer
```

### 3. Create `$HOME/www/sql-adminer/index.php`

```php
<?php
require_once __DIR__ . '/../auth_gate.php';

function adminer_object() {
    class AdminerCustom extends Adminer {
        function name() { return 'Sql Adminer'; }
        function login($login, $password) { return true; }
    }
    return new AdminerCustom();
}

require getenv('HOME') . '/adminer-core.php';
```

### 4. Check PHP has the required driver

```bash
php -r "echo in_array('sqlite', PDO::getAvailableDrivers()) ? 'OK' : 'Missing';"
```

If SQLite is missing:
```bash
pkg install php-sqlite
```

### 5. Open in browser

```
http://localhost:8080/sql-adminer/
```

---

## Pre-filling the connection form (optional)

You can customise the login form to pre-fill the driver and database path, saving a few clicks each time. Override `loginForm()` inside the `AdminerCustom` class:

```php
function loginForm() {
    echo '<input type="hidden" name="auth[driver]" value="sqlite">';
    echo '<table cellspacing="0">';
    echo '<tr><th>Database file</th><td>';
    echo '<input type="text" name="auth[db]" value="/path/to/your/database.db" style="width:300px">';
    echo '</td></tr></table>';
    echo '<p><input type="submit" value="Connect"></p>';
}

function credentials() { return ['', '', '']; }
```

Replace `/path/to/your/database.db` with the absolute path to your SQLite file.

---

## Security notes

- Access is gated by NodePulse's `auth_gate.php` (bcrypt session cookie).
- `adminer-core.php` is stored outside the web root and cannot be served directly by Nginx.
- Adminer's own login is bypassed (`login()` returns `true`) because the auth gate already handles authentication. Do not use this setup on a publicly exposed server without additional hardening (IP allowlist, HTTPS, etc.).
