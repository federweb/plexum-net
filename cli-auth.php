<?php
/**
 * CLI-AUTH — Internal session check for nginx auth_request.
 *
 * Returns 204 if the visitor has a valid auth_gate session,
 * 401 otherwise. No body. Never reachable from the outside
 * (nginx marks this location "internal").
 *
 * Used to gate /cli/ (PulseTerminal) behind the shared session
 * cookie established by auth_gate.php.
 */

if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(__DIR__)));
}
$sessDir = $npHome . '/tmp/.sessions';

if (is_dir($sessDir)) {
    session_save_path($sessDir);
}
session_start();

if (!empty($_SESSION['gate_auth'])) {
    http_response_code(204);
} else {
    http_response_code(401);
}
