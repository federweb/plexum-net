<?php
/**
 * NodePulse — Domain Seed Self-Announce
 * Compatible with PHP 5.6+
 *
 * Lazy cron: called from api.php/gossip.php via register_shutdown_function.
 * Periodically announces this domain seed to the network so it appears
 * in directory.json, peers_online.json and seeds_domain.json of other nodes.
 *
 * Identity is read from identity/ subfolder (not ~/.nodepulse/).
 * Signing uses PHP openssl_sign() (no shell access required).
 */

require_once __DIR__ . '/verify.php';

// ============================================================
// CONFIGURATION (scope-safe: no globals, uses __DIR__ directly)
// ============================================================

function np_sa_files() {
    $dir = __DIR__;
    return array(
        'node_identity' => $dir . '/node_identity.json',
        'node_config'   => $dir . '/node_config.json',
        'seeds_origin'  => $dir . '/seeds_origin.json',
        'peers_online'  => $dir . '/peers_online.json',
        'identity_dir'  => $dir . '/identity/',
        'last_sa'       => $dir . '/data/.last_selfannounce',
    );
}

// ============================================================
// LAZY CRON ENTRY POINT (called from api.php / gossip.php)
// ============================================================

/**
 * Check if enough time has passed since last self-announce.
 * If so, run a full announce cycle.
 */
function np_maybe_selfannounce() {
    $files = np_sa_files();

    // Only run for domain seeds (identity/ folder must exist)
    if (!is_dir($files['identity_dir'])) {
        return;
    }

    $config = np_read_json($files['node_config']);
    $interval = ($config && isset($config['selfannounce_interval_sec']))
        ? (int)$config['selfannounce_interval_sec']
        : 1800; // 30 minutes default

    $last_file = $files['last_sa'];
    $now = time();

    // Ensure data dir exists
    $data_dir = dirname($last_file);
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }

    if (file_exists($last_file)) {
        $last_ts = (int)trim(file_get_contents($last_file));
        if (($now - $last_ts) < $interval) {
            return; // Not yet time
        }
    }

    // Write timestamp BEFORE running to prevent concurrent triggers
    file_put_contents($last_file, (string)$now, LOCK_EX);

    np_run_selfannounce();
}

// ============================================================
// SELF-ANNOUNCE CYCLE
// ============================================================

function np_run_selfannounce() {
    $files = np_sa_files();

    // Load identity
    $identity = np_read_json($files['node_identity']);
    if (!$identity || !isset($identity['node_id'])) {
        np_sa_log('ERROR', 'No node identity found');
        return;
    }

    $node_id = $identity['node_id'];

    // Read private key from identity/ subfolder
    $privkey_path = $files['identity_dir'] . 'private.pem';
    if (!file_exists($privkey_path)) {
        np_sa_log('ERROR', 'No private key at ' . $privkey_path);
        return;
    }
    $privkey_pem = file_get_contents($privkey_path);
    $privkey = openssl_pkey_get_private($privkey_pem);
    if ($privkey === false) {
        np_sa_log('ERROR', 'Cannot load private key');
        return;
    }

    // Read public key
    $pubkey_path = $files['identity_dir'] . 'public.pem';
    if (!file_exists($pubkey_path)) {
        np_sa_log('ERROR', 'No public key at ' . $pubkey_path);
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privkey);
        }
        return;
    }
    $pubkey_pem = file_get_contents($pubkey_path);

    // Determine own URL
    $config = np_read_json($files['node_config']);
    $own_url = '';
    if ($config && isset($config['domain_url'])) {
        $own_url = $config['domain_url'];
    }
    // Fallback: auto-detect from request
    if (empty($own_url)) {
        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $scheme = 'https';
        }
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if (!empty($host)) {
            $own_url = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        }
    }

    if (empty($own_url)) {
        np_sa_log('ERROR', 'Cannot determine own URL');
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privkey);
        }
        return;
    }
    $own_url = rtrim($own_url, '/');

    // Build and sign announce payload
    $signed_at = np_now();
    $payload = np_build_payload($node_id, $own_url, $signed_at);

    $sig_raw = '';
    if (!openssl_sign($payload, $sig_raw, $privkey, OPENSSL_ALGO_SHA256)) {
        np_sa_log('ERROR', 'Failed to sign announce payload');
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privkey);
        }
        return;
    }
    if (PHP_VERSION_ID < 80000) {
        openssl_free_key($privkey);
    }
    $signature = base64_encode($sig_raw);

    // Build announce body
    $body = json_encode(array(
        'node_id'    => $node_id,
        'public_key' => $pubkey_pem,
        'url'        => $own_url,
        'type'       => 'domain',
        'signature'  => $signature,
        'signed_at'  => $signed_at,
    ), JSON_UNESCAPED_SLASHES);

    // Collect targets: seeds + peers, excluding self
    $targets = array();

    // Seeds from seeds_origin.json
    $seeds = np_read_json($files['seeds_origin']);
    if ($seeds && isset($seeds['seeds']) && is_array($seeds['seeds'])) {
        foreach ($seeds['seeds'] as $s) {
            if (isset($s['url']) && !empty($s['url'])) {
                $seed_url = rtrim($s['url'], '/');
                if ($seed_url === $own_url) {
                    continue; // Skip self
                }
                $targets[] = $seed_url . '/api.php?action=announce';
            }
        }
    }

    // Peers from peers_online.json
    $peers = np_read_json($files['peers_online']);
    if ($peers && isset($peers['peers']) && is_array($peers['peers'])) {
        foreach ($peers['peers'] as $p) {
            if (isset($p['url']) && !empty($p['url'])) {
                $peer_url = rtrim($p['url'], '/');
                if ($peer_url === $own_url) {
                    continue; // Skip self
                }
                $targets[] = $peer_url . '/api.php?action=announce';
            }
        }
    }

    // Deduplicate
    $targets = array_values(array_unique($targets));

    if (empty($targets)) {
        np_sa_log('WARN', 'No announce targets available');
        return;
    }

    // Shuffle and limit to 8
    shuffle($targets);
    if (count($targets) > 8) {
        $targets = array_slice($targets, 0, 8);
    }

    np_sa_log('INFO', 'Self-announce starting (node=' . $node_id . ', targets=' . count($targets) . ')');

    // POST to each target
    $success = 0;
    foreach ($targets as $target_url) {
        $result = np_sa_http_post($target_url, $body, 15);
        if ($result !== null) {
            $parsed = json_decode($result, true);
            if (is_array($parsed) && isset($parsed['ok']) && $parsed['ok'] === true) {
                $success++;
                np_sa_log('INFO', 'Announce OK: ' . $target_url);
            } else {
                $msg = (is_array($parsed) && isset($parsed['message'])) ? $parsed['message'] : 'unknown';
                np_sa_log('WARN', 'Announce REJECTED: ' . $target_url . ' (' . $msg . ')');
            }
        } else {
            np_sa_log('WARN', 'Announce FAIL: ' . $target_url);
        }
    }

    np_sa_log('INFO', 'Self-announce complete: ' . $success . '/' . count($targets));
}

// ============================================================
// HTTP CLIENT (PHP 5.6 compatible)
// ============================================================

/**
 * HTTP POST using curl (preferred) or file_get_contents fallback.
 * Prefixed np_sa_ to avoid collision with maintenance.php's np_http_post.
 *
 * @param string $url      Target URL
 * @param string $body     JSON body
 * @param int    $timeout  Seconds
 * @return string|null     Response body, or null on failure
 */
function np_sa_http_post($url, $body, $timeout) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($result === false || $code < 200 || $code >= 300) {
            return null;
        }
        return $result;
    }

    // Fallback: file_get_contents with stream context
    $opts = array(
        'http' => array(
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
        ),
    );
    $ctx = stream_context_create($opts);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        return null;
    }
    return $result;
}

// ============================================================
// LOGGING
// ============================================================

/**
 * Log a selfannounce message.
 * Prefixed np_sa_ to avoid collision with maintenance.php's np_maint_log.
 *
 * @param string $level   INFO, WARN, ERROR
 * @param string $message Log message
 */
function np_sa_log($level, $message) {
    $log_dir = __DIR__ . '/data/logs/';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $line = np_now() . ' [' . $level . '] ' . $message . "\n";

    // Output to stderr if running from CLI
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, '[SelfAnnounce] ' . $line);
    }

    $log_file = $log_dir . 'selfannounce.log';
    @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);

    // Simple rotation: keep last 500 lines when file exceeds 100KB
    if (file_exists($log_file) && filesize($log_file) > 100000) {
        $lines = file($log_file);
        if (is_array($lines) && count($lines) > 1000) {
            $lines = array_slice($lines, -500);
            file_put_contents($log_file, implode('', $lines), LOCK_EX);
        }
    }
}
