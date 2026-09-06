<?php
/**
 * CLI-LOGIN — Public entry point that establishes the session
 * needed to access /cli/, /desktop/ and /vscode/.
 *
 * Flow:
 *   - nginx sends here on 401, passing the page that triggered the
 *     redirect as ?return=<uri> (see @cli_login / @desktop_login).
 *   - Not authed → auth_gate.php renders the login form and handles POST.
 *   - Authed    → redirect back to the page that triggered the login,
 *                 falling back to /cli/ when none was captured.
 *
 * The return URL is stashed in the session (not just the query string)
 * because auth_gate.php's own POST→redirect→GET cycle strips the query
 * string to prevent form resubmission, so it wouldn't survive a login
 * attempt if only passed around as ?return=.
 */

// Same session bootstrap as auth_gate.php — started here (before it) so
// the return URL can be stashed before auth_gate.php takes over the request.
if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: __DIR__));
}
$sessDir = $npHome . '/tmp/.sessions';
if (is_dir($sessDir)) {
    session_save_path($sessDir);
}
session_start();

// Only accept same-site absolute paths (single leading slash, no scheme,
// no CR/LF) to avoid turning this into an open redirect.
if (isset($_GET['return']) && preg_match('#^/(?!/)[^\r\n]*$#', $_GET['return'])) {
    $_SESSION['gate_return'] = $_GET['return'];
}

require_once __DIR__ . '/auth_gate.php';

$dest = $_SESSION['gate_return'] ?? '/cli/';
unset($_SESSION['gate_return']);

header('Location: ' . $dest);
exit;
