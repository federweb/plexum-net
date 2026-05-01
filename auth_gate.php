<?php
/**
 * AUTH GATE — Shared session authentication for NodePulse apps.
 *
 * Usage: require_once __DIR__ . '/auth_gate.php';  (from root)
 *        require_once __DIR__ . '/../auth_gate.php'; (from subdir)
 *
 * First access with no password set → setup form.
 * Subsequent access → login form with session persistence.
 * Password stored as bcrypt hash in ~/.nodepulse/gate_password.hash
 *
 * Brute-force protection on login (mirrors change-password.php):
 *  - CSRF token (regenerated on every POST)
 *  - Server-side math captcha (regenerated on failure)
 *  - Rate limiting: 5 failed attempts → 15-minute lockout (file-based)
 */

// Determine home dir (Termux vs Windows/MSYS2)
if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(dirname(__DIR__))));
}
$npDir          = $npHome . '/.nodepulse';
$hashFile       = $npDir  . '/gate_password.hash';
$sessDir        = $npHome . '/tmp/.sessions';
$rateLimitFile  = $npDir  . '/login_ratelimit.json';

// Ensure dirs exist
if (!is_dir($npDir)) mkdir($npDir, 0700, true);
if (!is_dir($sessDir)) mkdir($sessDir, 0700, true);

// Start session (only if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_save_path($sessDir);
    session_start();
}

// --- Brute-force protection constants ---
if (!defined('AG_RL_MAX'))    define('AG_RL_MAX',    5);    // max failed attempts
if (!defined('AG_RL_WINDOW')) define('AG_RL_WINDOW', 900);  // lockout window (15 min)

// --- Rate-limit helpers (guarded to avoid collisions when included multiple times) ---
if (!function_exists('ag_rl_load')) {
    function ag_rl_load($f) {
        if (!file_exists($f)) return ['attempts' => [], 'blocked_until' => 0];
        $data = json_decode(file_get_contents($f), true);
        return $data ?: ['attempts' => [], 'blocked_until' => 0];
    }
}
if (!function_exists('ag_rl_save')) {
    function ag_rl_save($f, $d) {
        file_put_contents($f, json_encode($d), LOCK_EX);
    }
}
if (!function_exists('ag_rl_check')) {
    function ag_rl_check($f) {
        $now  = time();
        $data = ag_rl_load($f);
        if ($data['blocked_until'] > $now) {
            return ['blocked' => true, 'remaining' => $data['blocked_until'] - $now];
        }
        $data['attempts'] = array_values(array_filter($data['attempts'], function ($t) use ($now) {
            return $t > $now - AG_RL_WINDOW;
        }));
        if (count($data['attempts']) >= AG_RL_MAX) {
            $data['blocked_until'] = $now + AG_RL_WINDOW;
            ag_rl_save($f, $data);
            return ['blocked' => true, 'remaining' => AG_RL_WINDOW];
        }
        return ['blocked' => false];
    }
}
if (!function_exists('ag_rl_record')) {
    function ag_rl_record($f) {
        $data = ag_rl_load($f);
        $data['attempts'][] = time();
        ag_rl_save($f, $data);
    }
}
if (!function_exists('ag_rl_clear')) {
    function ag_rl_clear($f) {
        ag_rl_save($f, ['attempts' => [], 'blocked_until' => 0]);
    }
}
if (!function_exists('ag_new_captcha')) {
    function ag_new_captcha() {
        $a = random_int(2, 14);
        $b = random_int(2, 14);
        return ['a' => $a, 'b' => $b, 'answer' => $a + $b];
    }
}

// Bootstrap CSRF + captcha for gate
if (empty($_SESSION['ag_csrf']))    $_SESSION['ag_csrf']    = bin2hex(random_bytes(16));
if (empty($_SESSION['ag_captcha'])) $_SESSION['ag_captcha'] = ag_new_captcha();

// --- Handle actions ---

$gateError       = '';
$gateBlocked     = false;
$gateBlockedMins = 0;
$gateMode        = file_exists($hashFile) ? 'login' : 'setup';

// Logout
if (isset($_GET['gate_logout'])) {
    unset($_SESSION['gate_auth']);
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Setup: save new password (first-run only, no brute-force surface)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_action']) && $_POST['gate_action'] === 'setup') {
    $pw = $_POST['gate_password'] ?? '';
    $pw2 = $_POST['gate_password_confirm'] ?? '';
    if (strlen($pw) < 4) {
        $gateError = 'Password too short (minimum 4 characters)';
    } elseif ($pw !== $pw2) {
        $gateError = 'Passwords do not match';
    } else {
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        file_put_contents($hashFile, $hash);
        chmod($hashFile, 0600);
        $_SESSION['gate_auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Login: verify password (with CSRF + captcha + rate limit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_action']) && $_POST['gate_action'] === 'login') {

    // 1. CSRF
    if (!hash_equals($_SESSION['ag_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $gateError = 'Invalid request. Reload the page and try again.';

    } else {
        // 2. Rate limit
        $rl = ag_rl_check($rateLimitFile);
        if ($rl['blocked']) {
            $gateBlocked     = true;
            $gateBlockedMins = (int)ceil($rl['remaining'] / 60);
            $gateError       = "Too many failed attempts. Wait {$gateBlockedMins} minute(s) before trying again.";

        } else {
            // 3. Captcha
            $captchaInput  = (int)($_POST['captcha'] ?? -999);
            $captchaAnswer = (int)$_SESSION['ag_captcha']['answer'];

            if ($captchaInput !== $captchaAnswer) {
                ag_rl_record($rateLimitFile);
                $gateError = 'Wrong answer to the security question.';
                $_SESSION['ag_captcha'] = ag_new_captcha();

            } else {
                // 4. Verify password
                $pw = $_POST['gate_password'] ?? '';
                $storedHash = trim(file_get_contents($hashFile));
                if (password_verify($pw, $storedHash)) {
                    ag_rl_clear($rateLimitFile);
                    $_SESSION['gate_auth'] = true;
                    unset($_SESSION['ag_captcha'], $_SESSION['ag_csrf']);
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                } else {
                    ag_rl_record($rateLimitFile);
                    $gateError = 'Wrong password';
                    $_SESSION['ag_captcha'] = ag_new_captcha();
                }
            }
        }
    }

    // Regenerate CSRF token on every POST (prevents replay)
    $_SESSION['ag_csrf'] = bin2hex(random_bytes(16));
}

// Change password (from authenticated state) — legacy path kept for compatibility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_action']) && $_POST['gate_action'] === 'change_password') {
    if (!empty($_SESSION['gate_auth'])) {
        $old = $_POST['gate_old_password'] ?? '';
        $pw = $_POST['gate_password'] ?? '';
        $pw2 = $_POST['gate_password_confirm'] ?? '';
        $storedHash = trim(file_get_contents($hashFile));
        if (!password_verify($old, $storedHash)) {
            $gateError = 'Current password is incorrect';
        } elseif (strlen($pw) < 4) {
            $gateError = 'New password too short (minimum 4 characters)';
        } elseif ($pw !== $pw2) {
            $gateError = 'New passwords do not match';
        } else {
            file_put_contents($hashFile, password_hash($pw, PASSWORD_BCRYPT));
            $gateError = ''; // success, just redirect
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
    }
}

// --- If authenticated, let the app through ---
if (!empty($_SESSION['gate_auth'])) {
    return;
}

// If not already flagged from a POST, check blocked state on GET as well
if (!$gateBlocked && $gateMode === 'login') {
    $check = ag_rl_check($rateLimitFile);
    if (!empty($check['blocked'])) {
        $gateBlocked     = true;
        $gateBlockedMins = (int)ceil($check['remaining'] / 60);
        if ($gateError === '') {
            $gateError = "Too many failed attempts. Wait {$gateBlockedMins} minute(s) before trying again.";
        }
    }
}

// --- Otherwise, show gate UI and exit ---
$appName = 'NodePulse';
$ca = (int)$_SESSION['ag_captcha']['a'];
$cb = (int)$_SESSION['ag_captcha']['b'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $appName ?> — Access</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
        background: #0a0a0a;
        color: #e0e0e0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .gate-box {
        background: #111;
        border: 1px solid #2a2a2a;
        border-radius: 8px;
        padding: 40px 36px 32px;
        width: 340px;
        text-align: center;
    }
    .gate-box h1 {
        font-size: 22px;
        font-weight: 600;
        color: #00ff88;
        margin-bottom: 4px;
    }
    .gate-box .subtitle {
        font-size: 12px;
        color: #666;
        margin-bottom: 28px;
    }
    .gate-box input[type="password"],
    .gate-box input[type="number"] {
        width: 100%;
        padding: 10px 14px;
        background: #1a1a1a;
        border: 1px solid #333;
        border-radius: 4px;
        color: #e0e0e0;
        font-size: 14px;
        margin-bottom: 12px;
        outline: none;
        -moz-appearance: textfield;
    }
    .gate-box input[type="number"]::-webkit-inner-spin-button,
    .gate-box input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; }
    .gate-box input[type="password"]:focus,
    .gate-box input[type="number"]:focus {
        border-color: #00ff88;
    }
    .gate-box button {
        width: 100%;
        padding: 10px;
        background: #00ff88;
        color: #000;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 4px;
    }
    .gate-box button:hover { background: #00cc6a; }
    .gate-box button:disabled {
        background: #333;
        color: #666;
        cursor: not-allowed;
    }
    .gate-error {
        background: #2a0a0a;
        color: #ff4444;
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 12px;
        margin-bottom: 14px;
        text-align: left;
        line-height: 1.5;
    }
    /* Captcha block */
    .captcha-block {
        background: #0d0d0d;
        border: 1px solid #2a2a2a;
        border-radius: 6px;
        padding: 14px 16px 6px;
        margin-bottom: 12px;
        text-align: left;
    }
    .captcha-label {
        font-size: 11px;
        color: #555;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 10px;
        display: block;
    }
    .captcha-challenge {
        font-size: 22px;
        font-weight: 700;
        color: #e0e0e0;
        font-family: 'Consolas', 'Courier New', monospace;
        margin-bottom: 10px;
        letter-spacing: .04em;
    }
    .captcha-challenge .num { color: #00ff88; }
    .captcha-block input[type="number"] {
        width: 100%;
        margin-bottom: 0;
        text-align: center;
        font-size: 18px;
        font-weight: 600;
        letter-spacing: .1em;
    }
</style>
</head>
<body>
<div class="gate-box">
    <h1><?= $appName ?></h1>

    <?php if ($gateMode === 'setup'): ?>
        <div class="subtitle">Set your access password</div>
        <?php if ($gateError): ?><div class="gate-error"><?= htmlspecialchars($gateError) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="gate_action" value="setup">
            <input type="password" name="gate_password" placeholder="New password" required autofocus>
            <input type="password" name="gate_password_confirm" placeholder="Confirm password" required>
            <button type="submit">Set password</button>
        </form>

    <?php else: ?>
        <div class="subtitle">Login required</div>
        <?php if ($gateError): ?><div class="gate-error"><?= htmlspecialchars($gateError) ?></div><?php endif; ?>

        <?php if ($gateBlocked): ?>
            <div style="font-size:12px;color:#666;line-height:1.6;">
                Login has been temporarily disabled to prevent brute-force attacks.<br>
                Try again in approximately <?= (int)$gateBlockedMins ?> minute(s).
            </div>
        <?php else: ?>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="gate_action" value="login">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ag_csrf']) ?>">

                <input type="password" name="gate_password" placeholder="Password" required autofocus>

                <div class="captcha-block">
                    <span class="captcha-label">&#x1F6E1; Security check</span>
                    <div class="captcha-challenge">
                        <span class="num"><?= $ca ?></span>
                        &nbsp;+&nbsp;
                        <span class="num"><?= $cb ?></span>
                        &nbsp;= &nbsp;?
                    </div>
                    <input type="number" name="captcha" placeholder="Answer" required autocomplete="off">
                </div>

                <button type="submit">Sign in</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php exit; ?>
