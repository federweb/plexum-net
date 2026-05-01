<?php
/**
 * CHANGE PASSWORD — Standalone page for NodePulse auth gate password change.
 *
 * Security layers:
 *  1. CSRF token (regenerated on every POST)
 *  2. Server-side math captcha (stored in session, regenerated on failure)
 *  3. Current password verification (bcrypt timing-safe)
 *  4. Rate limiting: 5 failed attempts → 15-minute lockout (file-based)
 *  5. New hash written with LOCK_EX + chmod 0600
 *  6. Session invalidated on success (forces re-login everywhere)
 */

// ── Paths (mirrors auth_gate.php logic) ─────────────────────────────────────
if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(dirname(__DIR__))));
}
$npDir        = $npHome . '/.nodepulse';
$hashFile     = $npDir  . '/gate_password.hash';
$sessDir      = $npHome . '/tmp/.sessions';
$rateLimitFile = $npDir . '/passwd_ratelimit.json';

if (!is_dir($npDir))   mkdir($npDir,   0700, true);
if (!is_dir($sessDir)) mkdir($sessDir, 0700, true);

// ── Session ──────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_save_path($sessDir);
    session_start();
}

// ── Constants ────────────────────────────────────────────────────────────────
const RL_MAX    = 5;    // max failed attempts
const RL_WINDOW = 900;  // lockout window in seconds (15 min)
const PW_MIN    = 8;    // minimum new-password length

// ── Helpers ──────────────────────────────────────────────────────────────────

function rl_load(string $f): array {
    if (!file_exists($f)) return ['attempts' => [], 'blocked_until' => 0];
    return json_decode(file_get_contents($f), true) ?: ['attempts' => [], 'blocked_until' => 0];
}

function rl_save(string $f, array $d): void {
    file_put_contents($f, json_encode($d), LOCK_EX);
}

function rl_check(string $f): array {
    $now  = time();
    $data = rl_load($f);
    if ($data['blocked_until'] > $now) {
        return ['blocked' => true, 'remaining' => $data['blocked_until'] - $now];
    }
    // prune attempts outside window
    $data['attempts'] = array_values(array_filter($data['attempts'], fn($t) => $t > $now - RL_WINDOW));
    if (count($data['attempts']) >= RL_MAX) {
        $data['blocked_until'] = $now + RL_WINDOW;
        rl_save($f, $data);
        return ['blocked' => true, 'remaining' => RL_WINDOW];
    }
    return ['blocked' => false];
}

function rl_record(string $f): void {
    $data = rl_load($f);
    $data['attempts'][] = time();
    rl_save($f, $data);
}

function rl_clear(string $f): void {
    rl_save($f, ['attempts' => [], 'blocked_until' => 0]);
}

function new_captcha(): array {
    $a = random_int(2, 14);
    $b = random_int(2, 14);
    return ['a' => $a, 'b' => $b, 'answer' => $a + $b];
}

// ── Bootstrap CSRF + captcha ─────────────────────────────────────────────────
if (empty($_SESSION['cp_csrf']))    $_SESSION['cp_csrf']    = bin2hex(random_bytes(16));
if (empty($_SESSION['cp_captcha'])) $_SESSION['cp_captcha'] = new_captcha();

$error   = '';
$success = false;
$hashExists = file_exists($hashFile);

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CSRF
    if (!hash_equals($_SESSION['cp_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid request token. Please reload the page and try again.';

    } else {
        // 2. Rate limit
        $rl = rl_check($rateLimitFile);
        if ($rl['blocked']) {
            $mins  = (int)ceil($rl['remaining'] / 60);
            $error = "Too many failed attempts. Please wait {$mins} minute(s) before trying again.";

        } else {
            // 3. Captcha
            $captchaInput  = (int)($_POST['captcha'] ?? -999);
            $captchaAnswer = (int)$_SESSION['cp_captcha']['answer'];

            if ($captchaInput !== $captchaAnswer) {
                rl_record($rateLimitFile);
                $error = 'Incorrect answer to the security question. Please try again.';
                $_SESSION['cp_captcha'] = new_captcha();

            } else {
                // 4. Validate fields
                $oldPw  = $_POST['old_password']       ?? '';
                $newPw  = $_POST['new_password']        ?? '';
                $newPw2 = $_POST['new_password_confirm'] ?? '';

                if (!$hashExists) {
                    $error = 'No password has been set yet. Use the main login page to create one first.';

                } elseif ($oldPw === '') {
                    $error = 'Current password is required.';
                    rl_record($rateLimitFile);
                    $_SESSION['cp_captcha'] = new_captcha();

                } elseif (!password_verify($oldPw, trim(file_get_contents($hashFile)))) {
                    $error = 'Current password is incorrect.';
                    rl_record($rateLimitFile);
                    $_SESSION['cp_captcha'] = new_captcha();

                } elseif (strlen($newPw) < PW_MIN) {
                    $error = 'New password must be at least ' . PW_MIN . ' characters long.';

                } elseif ($newPw !== $newPw2) {
                    $error = 'New passwords do not match.';

                } elseif ($newPw === $oldPw) {
                    $error = 'New password must be different from the current one.';

                } else {
                    // ✅ All checks passed — write new hash
                    $newHash = password_hash($newPw, PASSWORD_BCRYPT);
                    file_put_contents($hashFile, $newHash, LOCK_EX);
                    chmod($hashFile, 0600);

                    rl_clear($rateLimitFile);

                    // Invalidate session so all apps require re-login
                    session_regenerate_id(true);
                    unset($_SESSION['gate_auth'], $_SESSION['cp_captcha'], $_SESSION['cp_csrf']);

                    $success = true;
                }
            }
        }
    }

    // Regenerate CSRF token on every POST (prevents replay)
    if (!$success) {
        $_SESSION['cp_csrf'] = bin2hex(random_bytes(16));
    }
}

// Ensure captcha always available for display
if (empty($_SESSION['cp_captcha'])) {
    $_SESSION['cp_captcha'] = new_captcha();
}

$ca = (int)$_SESSION['cp_captcha']['a'];
$cb = (int)$_SESSION['cp_captcha']['b'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NodePulse — Change Password</title>
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
        width: 360px;
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

    .gate-success {
        background: #0a2a1a;
        color: #00ff88;
        padding: 14px 16px;
        border-radius: 4px;
        font-size: 13px;
        margin-bottom: 20px;
        line-height: 1.6;
        border: 1px solid #00ff8830;
    }

    .gate-success strong { display: block; font-size: 15px; margin-bottom: 6px; }

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

    .captcha-challenge .num {
        color: #00ff88;
    }

    .captcha-block input[type="number"] {
        width: 100%;
        margin-bottom: 0;
        text-align: center;
        font-size: 18px;
        font-weight: 600;
        letter-spacing: .1em;
    }

    .divider {
        border: none;
        border-top: 1px solid #1e1e1e;
        margin: 20px 0;
    }

    .back-link {
        display: block;
        margin-top: 18px;
        font-size: 12px;
        color: #444;
        text-decoration: none;
    }

    .back-link:hover { color: #00ff88; }

    .field-label {
        display: block;
        text-align: left;
        font-size: 11px;
        color: #555;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 4px;
    }
</style>
</head>
<body>
<div class="gate-box">
    <h1>NodePulse</h1>
    <div class="subtitle">Change access password</div>

    <?php if ($success): ?>

        <div class="gate-success">
            <strong>&#10003; Password updated</strong>
            Your password has been changed successfully. All active sessions have been invalidated — please sign in again.
        </div>
        <a href="/" style="display:block;padding:10px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:4px;color:#00ff88;text-decoration:none;font-size:13px;font-weight:600;">
            &larr; Back to dashboard
        </a>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="gate-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['cp_csrf']) ?>">

            <label class="field-label">Current password</label>
            <input type="password" name="old_password" placeholder="••••••••" required autofocus>

            <hr class="divider">

            <label class="field-label">New password</label>
            <input type="password" name="new_password" placeholder="••••••••" required minlength="<?= PW_MIN ?>">

            <label class="field-label">Confirm new password</label>
            <input type="password" name="new_password_confirm" placeholder="••••••••" required>

            <hr class="divider">

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

            <button type="submit">Update password</button>
        </form>

        <a href="/" class="back-link">&larr; Back to dashboard</a>

    <?php endif; ?>
</div>
</body>
</html>
