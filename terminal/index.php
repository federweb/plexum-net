<?php
require_once __DIR__ . '/../auth_gate.php';
/**
 * NODEPULSE TERMINAL v2.0
 * Web-based interactive terminal for Termux.
 *
 * Architecture: php-cgi (web) writes commands to a file queue,
 * daemon.php (PHP CLI) executes them and writes the results.
 * This bypasses the SELinux restriction on php-cgi.
 *
 * START DAEMON: php -d opcache.enable=0 ~/www/terminal/daemon.php &
 */

// ============================================================
// CONFIGURATION
// ============================================================
// Auto-detect platform: Termux (Android) or MSYS2/Windows
if (PHP_OS_FAMILY !== 'Windows') {
    $ALLOWED_DIR = '/data/data/com.termux/files/home';
} else {
    $ALLOWED_DIR = str_replace('\\', '/', dirname(dirname(__DIR__)));
}
// Queue and session dirs are kept outside ~/www/ to prevent
// web-accessible exposure of command data and session files.
$QUEUE_DIR    = $ALLOWED_DIR . '/tmp/.terminal';
$HISTORY_FILE = __DIR__ . '/terminal_history.json';
$PID_FILE     = $QUEUE_DIR . '/daemon.pid';
$MAX_HISTORY  = 100;
$CMD_TIMEOUT  = 200; // seconds to wait for daemon response

// Server-side alias map (must mirror the JS aliases)
$aliases_map = [
    'status' => 'server-status',
    'start'  => 'start-server',
    'stop'   => 'stop-server',
    'logs'   => PHP_OS_FAMILY !== 'Windows' ? 'tail -20 ~/tmp/lighttpd-error.log' : 'tail -20 ~/tmp/terminal-daemon.log',
    'll'     => 'ls -la',
    'la'     => 'ls -la',
    '..'     => 'cd ..',
];

error_reporting(0);
ini_set('display_errors', 0);

// ============================================================
// BLOCKING COMMAND FILTER
// ============================================================
// Commands that hang the terminal because they run forever or
// require an interactive TTY. Each entry maps to a user-friendly
// message explaining what happened.
$BLOCKED_PATTERNS = [
    // Streaming / follow commands
    '/\btail\s+.*-[^\s]*f/'    => 'tail -f',
    '/\btail\s+.*--follow/'    => 'tail --follow',
    '/\bjournalctl\s+.*-f/'    => 'journalctl -f',
    // Interactive / full-screen TUI
    '/^\s*(vim|vi|nano|emacs|less|more|man)\b/' => 'interactive editor/pager',
    '/^\s*(top|htop|btop|glances|nmon)\b/'      => 'interactive monitor',
    '/^\s*(watch)\b/'          => 'watch',
    '/^\s*(screen|tmux)\b/'    => 'terminal multiplexer',
    '/^\s*(ssh)\b/'            => 'ssh (interactive session)',
    '/^\s*(python3?|node|php|irb|lua)\s*$/' => 'interactive REPL',
    // Dangerous infinite loops
    '/^\s*yes\b/'              => 'yes',
    '/^\s*cat\s*$/'            => 'cat (stdin read)',
    '/^\s*read\b/'             => 'read (stdin)',
];

function isBlockedCommand($cmd, $patterns) {
    $cmd = trim($cmd);
    foreach ($patterns as $pattern => $label) {
        if (preg_match($pattern, $cmd)) {
            return $label;
        }
    }
    return false;
}

// ============================================================
// AUTO-RESTART DAEMON
// ============================================================
function isDaemonRunning($pid_file) {
    if (!file_exists($pid_file)) return false;
    $pid = trim(file_get_contents($pid_file));
    if (empty($pid)) return false;
    // Check if PID is still alive (platform-aware)
    if (PHP_OS_FAMILY === 'Windows') {
        $out = shell_exec("tasklist /FI \"PID eq $pid\" 2>NUL") ?? '';
        return strpos($out, (string)$pid) !== false;
    } else {
        $check = trim(shell_exec("kill -0 $pid 2>&1; echo \$?") ?? '1');
        return $check === '0';
    }
}

function tryRestartDaemon($allowed_dir) {
    $daemon_path = $allowed_dir . '/www/terminal/daemon.php';
    if (!file_exists($daemon_path)) return false;
    if (PHP_OS_FAMILY === 'Windows') {
        $php_bin = $allowed_dir . '/nodepulse-bin/php/php.exe';
        if (!file_exists($php_bin)) $php_bin = 'php';
        $log = $allowed_dir . '/tmp/browser-daemon.log';
        $cmd = "start /B \"\" \"$php_bin\" -d opcache.enable=0 \"$daemon_path\" >> \"$log\" 2>&1";
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = "php -d opcache.enable=0 $daemon_path >> $allowed_dir/tmp/browser-daemon.log 2>&1 &";
        exec($cmd);
    }
    usleep(500000); // 500ms
    return true;
}

// ============================================================
// SESSION & DIRS
// ============================================================
if (!is_dir($QUEUE_DIR)) mkdir($QUEUE_DIR, 0700, true);

// ============================================================
// API: send command to daemon
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cmd'])) {
    header('Content-Type: application/json');

    if (empty($_SESSION['gate_auth'])) {
        echo json_encode(['output' => "Session expired. Reload the page.\n", 'cwd' => $ALLOWED_DIR, 'code' => 1]);
        exit;
    }

    $cmd = $_POST['cmd'];
    $cwd = $_POST['cwd'] ?? $ALLOWED_DIR;
    if (!is_dir($cwd)) $cwd = $ALLOWED_DIR;

    // Check for blocked commands
    $resolved_cmd = $cmd;
    $parts_chk = explode(' ', trim($cmd));
    if (isset($aliases_map[$parts_chk[0]])) {
        $resolved_cmd = $aliases_map[$parts_chk[0]] . ' ' . implode(' ', array_slice($parts_chk, 1));
    }
    $blocked = isBlockedCommand($resolved_cmd, $BLOCKED_PATTERNS);
    if ($blocked === false) $blocked = isBlockedCommand($cmd, $BLOCKED_PATTERNS);
    if ($blocked !== false) {
        echo json_encode([
            'output' => "⚠ Blocked: '$blocked' is not supported in the web terminal.\n" .
                        "These commands run forever or need an interactive TTY.\n" .
                        "Tip: use 'tail -20' instead of 'tail -f', or 'cat FILE' instead of 'cat'.\n" .
                        "Type 'help' for more info.\n",
            'cwd'    => $cwd,
            'code'   => 1
        ]);
        exit;
    }

    // Handle cd locally (no daemon needed)
    if (preg_match('/^cd\s+(.+)$/', $cmd, $m)) {
        $target = trim($m[1]);
        if ($target === '~') $target = $ALLOWED_DIR;
        if ($target === '-') $target = $ALLOWED_DIR;
        if ($target[0] !== '/') $target = $cwd . '/' . $target;
        $target = realpath($target);
        if ($target && is_dir($target)) {
            if (PHP_OS_FAMILY === 'Windows') $target = str_replace('\\', '/', $target);
            echo json_encode(['output' => '', 'cwd' => $target, 'code' => 0]);
        } else {
            echo json_encode(['output' => "cd: no such directory\n", 'cwd' => $cwd, 'code' => 1]);
        }
        exit;
    }

    // Save history
    $history = [];
    if (file_exists($HISTORY_FILE)) {
        $history = json_decode(file_get_contents($HISTORY_FILE), true) ?: [];
    }
    array_unshift($history, ['cmd' => $cmd, 'time' => date('H:i:s')]);
    $history = array_slice($history, 0, $MAX_HISTORY);
    file_put_contents($HISTORY_FILE, json_encode($history));

    // Auto-restart daemon if it's not running
    if (!isDaemonRunning($PID_FILE)) {
        tryRestartDaemon($ALLOWED_DIR);
        // Re-check after restart attempt
        if (!isDaemonRunning($PID_FILE)) {
            $hint = PHP_OS_FAMILY !== 'Windows'
                ? "Start it manually on Termux:\n  php -d opcache.enable=0 ~/www/terminal/daemon.php &\n"
                : "Start it manually on MSYS2:\n  ~/nodepulse-bin/php/php.exe -d opcache.enable=0 ~/www/terminal/daemon.php &\n";
            echo json_encode([
                'output' => "⚠ Daemon is not running and auto-restart failed.\n" . $hint,
                'cwd'    => $cwd,
                'code'   => 1
            ]);
            exit;
        }
        // Daemon was restarted successfully, continue
    }

    // Write command to queue
    $job_id = uniqid('cmd_', true);
    $job = [
        'id'     => $job_id,
        'cmd'    => $cmd,
        'cwd'    => $cwd,
        'status' => 'pending'
    ];
    file_put_contents($QUEUE_DIR . '/' . $job_id . '.cmd', json_encode($job));

    // Wait for result (polling with timeout)
    $result_file = $QUEUE_DIR . '/' . $job_id . '.result';
    $timeout = $CMD_TIMEOUT;
    $start   = time();

    while (!file_exists($result_file) && (time() - $start) < $timeout) {
        usleep(100000); // 100ms
        clearstatcache(true, $result_file);
    }

    if (file_exists($result_file)) {
        $result = json_decode(file_get_contents($result_file), true);
        @unlink($QUEUE_DIR . '/' . $job_id . '.cmd');
        @unlink($result_file);
        echo json_encode($result);
    } else {
        // Timeout — attempt auto-restart for next command
        @unlink($QUEUE_DIR . '/' . $job_id . '.cmd');
        $restart_msg = '';
        if (tryRestartDaemon($ALLOWED_DIR)) {
            $restart_msg = "\n♻ Daemon auto-restarted. Try your command again.\n";
        }
        echo json_encode([
            'output' => "Timeout: daemon not responding." . $restart_msg .
                        "\nIf the problem persists, start it manually:\n" .
                        "  php -d opcache.enable=0 ~/www/terminal/daemon.php &\n",
            'cwd'    => $cwd,
            'code'   => 1
        ]);
    }
    exit;
}

// ============================================================
// API: history
// ============================================================
if (isset($_GET['history']) && !empty($_SESSION['gate_auth'])) {
    header('Content-Type: application/json');
    $history = [];
    if (file_exists($HISTORY_FILE)) {
        $history = json_decode(file_get_contents($HISTORY_FILE), true) ?: [];
    }
    echo json_encode($history);
    exit;
}

// ============================================================
// API: check daemon status
// ============================================================
if (isset($_GET['daemon']) && !empty($_SESSION['gate_auth'])) {
    header('Content-Type: application/json');
    $running = isDaemonRunning($PID_FILE);
    $pid = $running ? trim(file_get_contents($PID_FILE)) : '';
    echo json_encode(['running' => $running, 'pid' => $pid]);
    exit;
}

// ============================================================
// HTML PAGE
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>NodePulse Terminal</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #0a0e14;
    --bg-light: #111820;
    --text: #c5cdd9;
    --green: #00ff9c;
    --green-dim: #00cc7a;
    --red: #ff3333;
    --yellow: #ffcc00;
    --blue: #00bfff;
    --border: #1a2332;
    --cursor: #00ff9c;
    --selection: rgba(0, 255, 156, 0.15);
}

* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { height: 100%; overflow: hidden; }

body {
    font-family: 'JetBrains Mono', 'Courier New', monospace;
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    line-height: 1.5;
}

::selection { background: var(--selection); color: var(--green); }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: #2a3a4e; border-radius: 3px; }

.login-container {
    display: flex; align-items: center; justify-content: center; height: 100vh;
}

.login-box {
    background: var(--bg-light); border: 1px solid var(--border);
    padding: 40px; width: 380px; text-align: center;
}

.login-box h1 {
    font-family: 'Space Mono', monospace; color: var(--green);
    font-size: 18px; margin-bottom: 8px; letter-spacing: 4px; text-transform: uppercase;
}

.login-box .subtitle { color: #4a5568; font-size: 11px; margin-bottom: 30px; letter-spacing: 2px; }

.login-box input {
    width: 100%; padding: 12px 16px; background: var(--bg); border: 1px solid var(--border);
    color: var(--green); font-family: 'JetBrains Mono', monospace; font-size: 14px;
    outline: none; margin-bottom: 16px; text-align: center; letter-spacing: 4px;
}

.login-box input:focus { border-color: var(--green-dim); box-shadow: 0 0 20px rgba(0,255,156,0.05); }
.login-box input::placeholder { color: #2a3a4e; letter-spacing: 2px; }

.login-box button {
    width: 100%; padding: 12px; background: transparent; border: 1px solid var(--green-dim);
    color: var(--green); font-family: 'Space Mono', monospace; font-size: 12px;
    cursor: pointer; letter-spacing: 3px; text-transform: uppercase; transition: all 0.2s;
}

.login-box button:hover { background: rgba(0,255,156,0.05); box-shadow: 0 0 30px rgba(0,255,156,0.1); }
.login-error { color: var(--red); font-size: 11px; margin-bottom: 16px; }

.terminal-container { display: flex; flex-direction: column; height: 100vh; }

.terminal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 16px; background: var(--bg-light); border-bottom: 1px solid var(--border); flex-shrink: 0;
}

.terminal-title { font-family: 'Space Mono', monospace; color: var(--green); font-size: 11px; letter-spacing: 3px; text-transform: uppercase; }

.terminal-info { display: flex; gap: 16px; align-items: center; }
.terminal-info span { font-size: 10px; color: #4a5568; letter-spacing: 1px; }

.dot {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    margin-right: 6px; animation: pulse 2s infinite;
}

.dot.online { background: var(--green); }
.dot.offline { background: var(--red); animation: none; }

@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

.header-actions { display: flex; gap: 8px; }

.header-btn {
    padding: 4px 12px; background: transparent; border: 1px solid var(--border);
    color: #4a5568; font-family: 'JetBrains Mono', monospace; font-size: 10px;
    cursor: pointer; transition: all 0.2s; letter-spacing: 1px;
}

.header-btn:hover { border-color: var(--green-dim); color: var(--green); }
.header-btn.danger:hover { border-color: var(--red); color: var(--red); }
.header-btn.active { border-color: var(--green); color: var(--green); background: rgba(0,255,156,0.08); }

/* Retro toggle switch */
.retro-toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; }
.retro-toggle .toggle-label { font-size: 10px; color: #4a5568; letter-spacing: 1px; transition: color 0.2s; }
.retro-toggle.on .toggle-label { color: var(--green-dim); }
.toggle-track {
    width: 32px; height: 16px; border: 1px solid var(--border); background: var(--bg);
    position: relative; transition: all 0.3s;
}
.retro-toggle.on .toggle-track { border-color: var(--green-dim); box-shadow: 0 0 8px rgba(0,255,156,0.15); }
.toggle-knob {
    width: 10px; height: 10px; background: #4a5568; position: absolute;
    top: 2px; left: 2px; transition: all 0.3s;
}
.retro-toggle.on .toggle-knob { left: 18px; background: var(--green); box-shadow: 0 0 6px rgba(0,255,156,0.5); }

/* Waiting indicator */
.waiting-line { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.waiting-bar {
    display: inline-flex; gap: 3px; align-items: center;
}
.waiting-bar .bar-segment {
    width: 3px; height: 12px; background: var(--green-dim); opacity: 0.2;
    animation: barPulse 1.2s ease-in-out infinite;
}
.waiting-bar .bar-segment:nth-child(1) { animation-delay: 0s; }
.waiting-bar .bar-segment:nth-child(2) { animation-delay: 0.1s; }
.waiting-bar .bar-segment:nth-child(3) { animation-delay: 0.2s; }
.waiting-bar .bar-segment:nth-child(4) { animation-delay: 0.3s; }
.waiting-bar .bar-segment:nth-child(5) { animation-delay: 0.4s; }
.waiting-bar .bar-segment:nth-child(6) { animation-delay: 0.5s; }
.waiting-bar .bar-segment:nth-child(7) { animation-delay: 0.6s; }
.waiting-bar .bar-segment:nth-child(8) { animation-delay: 0.7s; }
@keyframes barPulse {
    0%, 100% { opacity: 0.15; height: 6px; }
    50% { opacity: 1; height: 14px; }
}
.waiting-text { color: var(--green-dim); font-size: 11px; }
.waiting-timer { color: var(--yellow); font-size: 11px; font-variant-numeric: tabular-nums; }

.terminal-output { flex: 1; overflow-y: auto; padding: 12px 16px; }

.output-line { white-space: pre-wrap; word-break: break-all; margin-bottom: 2px; }
.output-line.cmd-line { color: var(--green); margin-top: 8px; }
.output-line.cmd-line .path { color: var(--blue); }
.output-line.cmd-line .cmd-text { color: #fff; }
.output-line.error { color: var(--red); }
.output-line.system { color: var(--yellow); font-style: italic; }
.output-line.info { color: #4a5568; }

.welcome-art { color: var(--green-dim); margin-bottom: 16px; font-size: 11px; line-height: 1.2; }

.terminal-input-area {
    display: flex; align-items: center; padding: 10px 16px;
    background: var(--bg-light); border-top: 1px solid var(--border); flex-shrink: 0;
}

.input-prompt { flex-shrink: 0; margin-right: 8px; white-space: nowrap; }
.input-prompt .path { color: var(--blue); font-size: 12px; }
.input-prompt .char { color: var(--green); font-size: 12px; margin-left: 4px; }

#cmdInput {
    flex: 1; background: transparent; border: none; color: #fff;
    font-family: 'JetBrains Mono', monospace; font-size: 13px; outline: none; caret-color: var(--cursor);
}

#cmdInput::placeholder { color: #2a3a4e; }

.input-spinner { display: none; margin-left: 8px; color: var(--yellow); font-size: 12px; }

@media (max-width: 600px) {
    body { font-size: 12px; }
    .terminal-header { padding: 6px 10px; }
    .terminal-output { padding: 8px 10px; }
    .terminal-input-area { padding: 8px 10px; }
    .terminal-info span { display: none; }
    .welcome-art { font-size: 9px; }
}
</style>
</head>
<body>

<div class="terminal-container">
    <div class="terminal-header">
        <div class="terminal-info">
            <span class="terminal-title">NodePulse Terminal</span>
            <span><span class="dot" id="daemonDot"></span><span id="daemonStatus">checking...</span></span>
        </div>
        <div class="header-actions">
            <div class="retro-toggle on" id="waitToggle" onclick="toggleWaitIndicator()">
                <span class="toggle-label">WAIT</span>
                <div class="toggle-track"><div class="toggle-knob"></div></div>
            </div>
            <button class="header-btn" onclick="clearTerminal()">clear</button>
            <button class="header-btn" onclick="showHelp()">help</button>
            <button class="header-btn danger" onclick="location.href='?gate_logout'">exit</button>
        </div>
    </div>

    <div class="terminal-output" id="output">
        <div class="welcome-art">

        </div>
        <div class="output-line system">Terminal v2.1 — Type 'help' for commands</div>
        <div class="output-line info">────────────────────────────────────────</div>
    </div>

    <div class="terminal-input-area">
        <div class="input-prompt">
            <span class="path" id="promptPath">~</span><span class="char">$</span>
        </div>
        <input type="text" id="cmdInput" autofocus autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Enter command...">
        <div class="input-spinner" id="spinner">⣾</div>
    </div>
</div>

<script>
const output = document.getElementById('output');
const input = document.getElementById('cmdInput');
const promptPath = document.getElementById('promptPath');
const spinner = document.getElementById('spinner');
const daemonDot = document.getElementById('daemonDot');
const daemonStatus = document.getElementById('daemonStatus');
const HOME = '<?php echo $ALLOWED_DIR; ?>';

let cwd = HOME;
let history = [];
let historyIndex = -1;
let running = false;
let showWaitIndicator = true;
let waitTimerInterval = null;
let waitingElement = null;

fetch('?history=1').then(r => r.json()).then(h => {
    history = h.map(x => x.cmd);
}).catch(() => {});

function checkDaemon() {
    fetch('?daemon=1').then(r => r.json()).then(d => {
        daemonDot.className = 'dot ' + (d.running ? 'online' : 'offline');
        daemonStatus.textContent = d.running ? 'daemon: online' : 'daemon: offline (auto-restart enabled)';
    }).catch(() => {
        daemonDot.className = 'dot offline';
        daemonStatus.textContent = 'error';
    });
}
checkDaemon();
setInterval(checkDaemon, 10000);

function shortPath(p) {
    if (p === HOME) return '~';
    if (p.startsWith(HOME + '/')) return '~/' + p.substring(HOME.length + 1);
    return p;
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function addOutput(html, cls = '') {
    const div = document.createElement('div');
    div.className = 'output-line' + (cls ? ' ' + cls : '');
    div.innerHTML = html;
    output.appendChild(div);
    output.scrollTop = output.scrollHeight;
}

function addCommandLine(cmd) {
    addOutput(
        '<span class="path">' + escapeHtml(shortPath(cwd)) + '</span>' +
        ' <span class="prompt-char">$</span>' +
        ' <span class="cmd-text">' + escapeHtml(cmd) + '</span>',
        'cmd-line'
    );
}

function clearTerminal() {
    output.innerHTML = '';
    addOutput('Terminal cleared', 'info');
}

function showHelp() {
    addOutput('', 'info');
    addOutput('━━━ NodePulse Terminal Help ━━━', 'system');
    addOutput('', 'info');
    addOutput('  Built-in commands:', 'info');
    addOutput('    clear          Clear screen', '');
    addOutput('    help           Show this help', '');
    addOutput('    history        Show command history', '');
    addOutput('', 'info');
    addOutput('  Quick aliases:', 'info');
    addOutput('    status         → server-status', '');
    addOutput('    start          → start-server', '');
    addOutput('    stop           → stop-server', '');
    addOutput('    logs           → tail error log', '');
    addOutput('    ll / la        → ls -la', '');
    addOutput('    ..             → cd ..', '');
    addOutput('', 'info');
    addOutput('  Shortcuts:', 'info');
    addOutput('    ↑/↓            Navigate history', '');
    addOutput('    Ctrl+L         Clear screen', '');
    addOutput('    Ctrl+C         Cancel input', '');
    addOutput('', 'info');
    addOutput('  Blocked commands:', 'system');
    addOutput('    The following are blocked because they hang the', '');
    addOutput('    web terminal (no TTY / infinite streaming):', '');
    addOutput('', 'info');
    addOutput('    tail -f, watch, top, htop, vim, vi, nano,', '');
    addOutput('    less, more, man, screen, tmux, ssh,', '');
    addOutput('    python/node/php (REPL), yes, cat (stdin)', '');
    addOutput('', 'info');
    addOutput('  Alternatives:', 'info');
    addOutput('    tail -f FILE       → tail -20 FILE', '');
    addOutput('    watch CMD          → CMD (run once)', '');
    addOutput('    top                → ps aux | head -20', '');
    addOutput('    less FILE          → cat FILE', '');
    addOutput('    python             → python -c "code"', '');
    addOutput('', 'info');
    addOutput('  Daemon:', 'info');
    addOutput('    Auto-restart is enabled. If the daemon crashes,', '');
    addOutput('    the terminal will try to restart it automatically.', '');
    addOutput('    Manual start: php -d opcache.enable=0 ~/www/terminal/daemon.php &', '');
    addOutput('    All commands have a 200s timeout as safety net.', '');
    addOutput('', 'info');
    addOutput('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
}

const aliases = {
    'status': 'server-status',
    'start': 'start-server',
    'stop': 'stop-server',
    'logs': 'tail -20 ~/tmp/lighttpd-error.log',
    'll': 'ls -la',
    'la': 'ls -la',
    '..': 'cd ..',
};

async function executeCommand(cmd) {
    cmd = cmd.trim();
    if (!cmd) return;

    history.unshift(cmd);
    if (history.length > 100) history.pop();
    historyIndex = -1;

    addCommandLine(cmd);

    if (cmd === 'clear') { clearTerminal(); return; }
    if (cmd === 'help') { showHelp(); return; }
    if (cmd === 'history') {
        history.slice(0, 20).forEach((h, i) => {
            addOutput('  ' + String(i + 1).padStart(3) + '  ' + escapeHtml(h), 'info');
        });
        return;
    }

    const parts = cmd.split(' ');
    if (aliases[parts[0]]) {
        cmd = aliases[parts[0]] + (parts.length > 1 ? ' ' + parts.slice(1).join(' ') : '');
    }

    running = true;
    spinner.style.display = 'inline-block';
    input.disabled = true;

    // Show waiting indicator after 3s delay
    let waitDelay = null;
    if (showWaitIndicator) {
        waitDelay = setTimeout(() => startWaitIndicator(cmd), 3000);
    }

    try {
        const formData = new FormData();
        formData.append('cmd', cmd);
        formData.append('cwd', cwd);

        const response = await fetch('index.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        // Remove waiting indicator
        if (waitDelay) clearTimeout(waitDelay);
        stopWaitIndicator();

        if (data.output) {
            const lines = data.output.split('\n');
            lines.forEach(line => {
                let cls = '';
                if (data.code !== 0) cls = 'error';
                addOutput(escapeHtml(line), cls);
            });
        }

        if (data.cwd) {
            cwd = data.cwd;
            promptPath.textContent = shortPath(cwd);
        }

    } catch (err) {
        if (waitDelay) clearTimeout(waitDelay);
        stopWaitIndicator();
        addOutput('Connection error: ' + err.message, 'error');
    }

    running = false;
    spinner.style.display = 'none';
    input.disabled = false;
    input.focus();
}

input.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (!running) { executeCommand(input.value); input.value = ''; }
    }
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (historyIndex < history.length - 1) { historyIndex++; input.value = history[historyIndex]; }
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (historyIndex > 0) { historyIndex--; input.value = history[historyIndex]; }
        else { historyIndex = -1; input.value = ''; }
    }
    if (e.key === 'l' && e.ctrlKey) { e.preventDefault(); clearTerminal(); }
    if (e.key === 'c' && e.ctrlKey) { e.preventDefault(); input.value = ''; addOutput('^C', 'error'); }
});

document.addEventListener('click', () => {
    if (!window.getSelection().toString()) input.focus();
});

const spinChars = ['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'];
let spinIdx = 0;
setInterval(() => {
    if (running) { spinner.textContent = spinChars[spinIdx % spinChars.length]; spinIdx++; }
}, 80);

// Wait indicator toggle
function toggleWaitIndicator() {
    showWaitIndicator = !showWaitIndicator;
    const toggle = document.getElementById('waitToggle');
    toggle.classList.toggle('on', showWaitIndicator);
}

const waitMessages = [
    'Executing',
    'Processing',
    'Computing',
    'Working',
    'Running',
    'Crunching',
    'Loading',
    'Compiling',
    'Fetching',
    'Decoding',
];

function startWaitIndicator(cmd) {
    const msg = waitMessages[Math.floor(Math.random() * waitMessages.length)];
    const shortCmd = cmd.length > 20 ? cmd.substring(0, 20) + '...' : cmd;

    const div = document.createElement('div');
    div.className = 'output-line waiting-line';
    div.innerHTML =
        '<div class="waiting-bar">' +
        '<div class="bar-segment"></div>'.repeat(8) +
        '</div>' +
        '<span class="waiting-text">' + escapeHtml(msg) + ': ' + escapeHtml(shortCmd) + '</span>' +
        '<span class="waiting-timer" id="waitTimer">3.0s</span>';
    output.appendChild(div);
    output.scrollTop = output.scrollHeight;
    waitingElement = div;

    const startMs = performance.now();
    waitTimerInterval = setInterval(() => {
        const elapsed = (3 + (performance.now() - startMs) / 1000).toFixed(1);
        const timerEl = document.getElementById('waitTimer');
        if (timerEl) timerEl.textContent = elapsed + 's';
        output.scrollTop = output.scrollHeight;
    }, 100);
}

function stopWaitIndicator() {
    if (waitTimerInterval) {
        clearInterval(waitTimerInterval);
        waitTimerInterval = null;
    }
    if (waitingElement) {
        waitingElement.remove();
        waitingElement = null;
    }
}

promptPath.textContent = shortPath(cwd);
input.focus();
</script>
<script src="/nodepulse-sw.js"></script>
<?php include __DIR__ . '/../menu_panel.php'; ?>
</body>
</html>
