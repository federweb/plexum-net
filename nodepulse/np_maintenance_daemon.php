<?php
/**
 * NodePulse — Standalone Maintenance Daemon
 * Runs maintenance.php periodically (gossip, peer sync, etc.)
 *
 * Usage: php np_maintenance_daemon.php <tunnel_url>
 *   e.g. php np_maintenance_daemon.php https://xyz.trycloudflare.com/nodepulse
 *
 * Crash recovery is handled by nodepulse.sh (built-in restart loop).
 * This daemon only runs maintenance cycles — no health checks, no restarts.
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

$tunnel_url = isset($argv[1]) ? $argv[1] : null;
if (!$tunnel_url) {
    fwrite(STDERR, "[Maintenance] ERROR: tunnel URL required as argument\n");
    exit(1);
}

$interval        = 300;
$maintenance_php = __DIR__ . '/maintenance.php';
$php_bin         = PHP_BINARY;

// Graceful shutdown via signals (if pcntl available)
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function () use (&$running) { $running = false; });
}

echo "[Maintenance] Daemon started (interval {$interval}s, tunnel: {$tunnel_url})\n";

while ($running) {
    sleep($interval);

    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if (!$running) {
        break;
    }

    // --- Run maintenance cycle ---
    if (file_exists($maintenance_php)) {
        $cmd = escapeshellarg($php_bin) . ' -d opcache.enable=0 ' . escapeshellarg($maintenance_php) . ' 2>&1';
        exec($cmd, $output, $ret);
        if ($ret !== 0 && !empty($output)) {
            echo "[Maintenance] maintenance.php error (exit {$ret}):\n";
            foreach ($output as $line) {
                echo "  {$line}\n";
            }
        }
        $output = array();
    }
}

echo "[Maintenance] Daemon stopped\n";
