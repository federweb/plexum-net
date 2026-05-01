<?php
/**
 * CLI-LOGIN — Public entry point that establishes the session
 * needed to access /cli/ (PulseTerminal).
 *
 * Flow:
 *   - Not authed → auth_gate.php renders the login form and handles POST.
 *   - Authed    → redirect to /cli/.
 */

require_once __DIR__ . '/auth_gate.php';

header('Location: /cli/');
exit;
