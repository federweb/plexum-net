<?php
/**
 * LOGOUT — Destroys the shared NodePulse auth-gate session.
 *
 * Mirrors the session-path logic of auth_gate.php so the correct store is
 * targeted on both Windows/MSYS2 and Termux/Android. After wiping the
 * session the user is redirected back to the root, where auth_gate.php
 * (on protected apps) will ask for credentials again on next access.
 */

// Resolve home directory (Termux vs Windows/MSYS2)
if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(dirname(__DIR__))));
}
$sessDir = $npHome . '/tmp/.sessions';

if (session_status() === PHP_SESSION_NONE) {
    if (is_dir($sessDir)) session_save_path($sessDir);
    session_start();
}

// Wipe session data
$_SESSION = [];

// Expire the session cookie on the client
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Back to the dashboard — index.php is public and will simply render
// without the logged-in-only controls (e.g. the Logout button).
header('Location: /index.php');
exit;
