<?php
/**
 * NODEPULSE TERMINAL DAEMON v2.0
 * PHP CLI process that executes commands for the web terminal.
 *
 * php-cgi (nginx) cannot execute shell commands due to SELinux restrictions.
 * This daemon runs as PHP CLI with full permissions.
 *
 * START: php -d opcache.enable=0 ~/www/terminal/daemon.php &
 * STOP:  pkill -f "terminal/daemon"
 */

// Auto-detect platform: Termux (Android) or MSYS2/Windows
if (PHP_OS_FAMILY !== 'Windows') {
    $ALLOWED_DIR = '/data/data/com.termux/files/home';
} else {
    // MSYS2/Windows: derive home from script location (~/www/terminal/)
    $ALLOWED_DIR = str_replace('\\', '/', dirname(dirname(__DIR__)));
}
// Must match the QUEUE_DIR in index.php
$QUEUE_DIR   = $ALLOWED_DIR . '/tmp/.terminal';
$PID_FILE    = $QUEUE_DIR . '/daemon.pid';

if (!is_dir($QUEUE_DIR)) mkdir($QUEUE_DIR, 0700, true);

// Write PID file for auto-restart detection
file_put_contents($PID_FILE, getmypid());

// Cleanup PID file on exit
register_shutdown_function(function() use ($PID_FILE) {
    @unlink($PID_FILE);
});

echo "[NodePulse Daemon] Started - PID: " . getmypid() . "\n";
echo "[NodePulse Daemon] Queue: $QUEUE_DIR\n";
echo "[NodePulse Daemon] Listening...\n\n";

// Main loop
while (true) {
    // Look for .cmd files in the queue
    $files = glob($QUEUE_DIR . '/*.cmd');

    foreach ($files as $cmd_file) {
        $job = json_decode(file_get_contents($cmd_file), true);
        if (!$job) {
            @unlink($cmd_file);
            continue;
        }

        $job_id = $job['id'];
        $cmd    = $job['cmd'];
        $cwd    = $job['cwd'] ?? $ALLOWED_DIR;
        if (PHP_OS_FAMILY === 'Windows') $cwd = str_replace('\\', '/', $cwd);

        echo "[" . date('H:i:s') . "] Executing: $cmd (in $cwd)\n";

        // Execute the command
        // Apply timeout to prevent blocking commands from hanging the daemon
        $cmd_timeout = 60; // seconds
        $output = '';

        putenv("HOME=$ALLOWED_DIR");
        putenv("TMPDIR=$ALLOWED_DIR/tmp");

        if (PHP_OS_FAMILY === 'Windows') {
            // php.exe uses cmd.exe by default — Unix commands (ls, grep, etc.)
            // don't exist there. Route everything through MSYS2 bash.
            $msys_bash = 'C:/msys64/usr/bin/bash.exe';
            $inner_cmd = "cd " . escapeshellarg($cwd) . " && " . $cmd;
            $full_cmd = '"' . $msys_bash . '" --login -c ' . escapeshellarg($inner_cmd) . ' 2>&1';
        } else {
            putenv("PATH=/data/data/com.termux/files/usr/bin:/data/data/com.termux/files/home/bin");
            putenv("PREFIX=/data/data/com.termux/files/usr");
            $full_cmd = "cd " . escapeshellarg($cwd) . " && timeout $cmd_timeout " . $cmd . " 2>&1";
        }

        $handle = popen($full_cmd, 'r');
        if ($handle) {
            while (!feof($handle)) {
                $output .= fread($handle, 8192);
                // Limit output to 512KB
                if (strlen($output) > 524288) {
                    $output .= "\n[Output truncated at 512KB]\n";
                    break;
                }
            }
            $code = pclose($handle);
        } else {
            $output = "Error: could not execute command\n";
            $code   = -1;
        }

        // Write the result
        $result = [
            'output' => $output,
            'cwd'    => $cwd,
            'code'   => $code
        ];

        $result_file = $QUEUE_DIR . '/' . $job_id . '.result';
        file_put_contents($result_file, json_encode($result));

        // Remove the command file
        @unlink($cmd_file);

        echo "[" . date('H:i:s') . "] Done (code: $code)\n";
    }

    // 50ms pause between cycles
    usleep(50000);
}
