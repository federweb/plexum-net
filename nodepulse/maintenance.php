<?php
/**
 * NodePulse — Maintenance & Gossip Engine
 * Compatible with PHP 5.6+
 *
 * Two invocation modes:
 *   CLI:  php maintenance.php          — runs full cycle unconditionally
 *   Lazy: np_maybe_maintenance()       — runs only if interval has elapsed
 *
 * Maintenance cycle:
 *   1. Select gossip_fanout random peers
 *   2. For each: POST gossip with local data, merge response
 *   3. Send heartbeats to a few peers
 *   4. Rebuild seeds_network.json from directory.json
 *   5. Clean stale peers
 */

require_once __DIR__ . '/verify.php';

// ============================================================
// CONFIGURATION (scope-safe: no globals, uses __DIR__ directly)
// ============================================================

function np_maint_files() {
    $dir = __DIR__;
    return array(
        'directory'     => $dir . '/directory.json',
        'peers_online'  => $dir . '/peers_online.json',
        'seeds_network' => $dir . '/seeds_network.json',
        'seeds_domain'  => $dir . '/seeds_domain.json',
        'seeds_origin'  => $dir . '/seeds_origin.json',
        'node_config'   => $dir . '/node_config.json',
        'node_identity' => $dir . '/node_identity.json',
        'last_maint'    => $dir . '/data/.last_maintenance',
    );
}

// ============================================================
// LAZY CRON ENTRY POINT (called from api.php / gossip.php)
// ============================================================

/**
 * Check if enough time has passed since last maintenance.
 * If so, run a full maintenance cycle.
 */
function np_maybe_maintenance() {
    $files = np_maint_files();

    $config = np_read_json($files['node_config']);
    $interval = ($config && isset($config['maintenance_interval_sec']))
        ? (int)$config['maintenance_interval_sec']
        : 100;

    $last_file = $files['last_maint'];
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

    np_run_maintenance_cycle();
}

// ============================================================
// FULL MAINTENANCE CYCLE
// ============================================================

function np_run_maintenance_cycle() {
    $files = np_maint_files();

    $config = np_read_json($files['node_config']);
    $identity = np_read_json($files['node_identity']);

    if (!$identity || !isset($identity['node_id'])) {
        np_maint_log('ERROR', 'No node identity found');
        return;
    }

    $node_id = $identity['node_id'];
    $fanout = ($config && isset($config['gossip_fanout'])) ? (int)$config['gossip_fanout'] : 3;
    $ttl_hours = ($config && isset($config['ttl_hours'])) ? (int)$config['ttl_hours'] : 24;
    $max_peers = ($config && isset($config['max_peers'])) ? (int)$config['max_peers'] : 50;

    np_maint_log('INFO', 'Maintenance cycle starting (node=' . $node_id . ', fanout=' . $fanout . ')');

    // --- Step 1: Load local data ---
    $local_dir = np_read_json($files['directory']);
    if (!$local_dir) {
        $local_dir = array('updated_at' => np_now(), 'entries' => array());
    }

    $local_peers = np_read_json($files['peers_online']);
    if (!$local_peers) {
        $local_peers = array('updated_at' => np_now(), 'ttl_hours' => $ttl_hours, 'peers' => array());
    }

    // --- Step 2: Build gossip targets (peers + seeds fallback) ---
    $targets = np_select_gossip_targets($local_peers['peers'], $fanout, $node_id);

    // If no peers available, fallback to seeds_origin
    if (empty($targets)) {
        $seeds_origin = np_read_json($files['seeds_origin']);
        if ($seeds_origin && isset($seeds_origin['seeds']) && is_array($seeds_origin['seeds'])) {
            $seed_peers = array();
            foreach ($seeds_origin['seeds'] as $seed) {
                if (isset($seed['url']) && !empty($seed['url'])) {
                    $seed_peers[] = array(
                        'node_id'   => isset($seed['node_id']) ? $seed['node_id'] : 'seed',
                        'url'       => $seed['url'],
                        'last_seen' => np_now(),
                    );
                }
            }
            $targets = np_select_gossip_targets($seed_peers, $fanout, $node_id);
        }
    }

    if (empty($targets)) {
        np_maint_log('WARN', 'No gossip targets available (no peers, no seeds)');
    }

    // --- Step 3: Gossip with each target ---
    $gossip_success = 0;
    foreach ($targets as $target) {
        $result = np_do_gossip($target, $node_id, $local_dir, $local_peers);
        if ($result !== null) {
            // Merge received directory entries
            if (isset($result['directory_entries']) && is_array($result['directory_entries'])) {
                $local_dir['entries'] = np_merge_directory(
                    $local_dir['entries'],
                    $result['directory_entries']
                );
            }
            // Merge received peers
            if (isset($result['peers']) && is_array($result['peers'])) {
                $local_peers['peers'] = np_merge_peers(
                    $local_peers['peers'],
                    $result['peers'],
                    $ttl_hours,
                    $max_peers
                );
            }
            $gossip_success++;
            np_maint_log('INFO', 'Gossip OK: ' . $target['url']);
        } else {
            np_maint_log('WARN', 'Gossip FAIL: ' . $target['url']);
        }
    }

    // --- Step 4: Save updated local data ---
    $local_dir['updated_at'] = np_now();
    np_write_json($files['directory'], $local_dir);

    // Clean stale peers and filter by verified directory
    $local_peers['peers'] = np_merge_peers(
        $local_peers['peers'], array(), $ttl_hours, $max_peers
    );
    $local_peers['peers'] = np_filter_peers_by_directory($local_peers['peers'], $local_dir['entries']);
    $local_peers['updated_at'] = np_now();
    np_write_json($files['peers_online'], $local_peers);

    // --- Step 5: Send heartbeats ---
    np_send_heartbeats($local_peers['peers'], $node_id, $fanout);

    // --- Step 6: Rebuild seeds_network.json ---
    np_rebuild_seeds_network($local_dir, $local_peers, $ttl_hours);

    // --- Step 7: Rebuild seeds_domain.json from directory (append/update only) ---
    np_rebuild_seeds_domain($local_dir);

    np_maint_log('INFO', 'Maintenance cycle complete (gossip=' . $gossip_success . '/' . count($targets) . ')');
}

// ============================================================
// GOSSIP CLIENT
// ============================================================

/**
 * Select N random peers for gossip, excluding self.
 *
 * @param array  $peers     Array of peer entries
 * @param int    $fanout    Number of peers to select
 * @param string $self_id   Our own node_id to exclude
 * @return array            Selected peer entries
 */
function np_select_gossip_targets($peers, $fanout, $self_id) {
    $candidates = array();
    foreach ($peers as $peer) {
        if (!isset($peer['url']) || empty($peer['url'])) {
            continue;
        }
        if (isset($peer['node_id']) && $peer['node_id'] === $self_id) {
            continue;
        }
        $candidates[] = $peer;
    }

    if (empty($candidates)) {
        return array();
    }

    shuffle($candidates);
    return array_slice($candidates, 0, $fanout);
}

/**
 * Perform gossip exchange with a single peer.
 * POST our directory entries + peers, receive theirs.
 *
 * @param array  $target      Peer entry with 'url' key
 * @param string $node_id     Our node_id
 * @param array  $local_dir   Our directory data
 * @param array  $local_peers Our peers data
 * @return array|null         Parsed response, or null on failure
 */
function np_do_gossip($target, $node_id, $local_dir, $local_peers) {
    $gossip_url = rtrim($target['url'], '/') . '/gossip.php';

    $body = json_encode(array(
        'node_id'           => $node_id,
        'directory_entries'  => isset($local_dir['entries']) ? $local_dir['entries'] : array(),
        'peers'             => isset($local_peers['peers']) ? $local_peers['peers'] : array(),
    ), JSON_UNESCAPED_SLASHES);

    $response = np_http_post($gossip_url, $body, 15);

    if ($response === null) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['ok']) || $data['ok'] !== true) {
        return null;
    }

    return $data;
}

// ============================================================
// HEARTBEAT CLIENT
// ============================================================

/**
 * Send heartbeats to N random peers.
 * Reads private key from ~/.nodepulse/ for signing.
 *
 * @param array  $peers    Array of peer entries
 * @param string $node_id  Our node_id
 * @param int    $count    Number of peers to heartbeat
 */
function np_send_heartbeats($peers, $node_id, $count) {
    $candidates = array();
    foreach ($peers as $peer) {
        if (!isset($peer['url']) || empty($peer['url'])) {
            continue;
        }
        if (isset($peer['node_id']) && $peer['node_id'] === $node_id) {
            continue;
        }
        $candidates[] = $peer;
    }

    if (empty($candidates)) {
        return;
    }

    shuffle($candidates);
    $selected = array_slice($candidates, 0, $count);

    // Find private key
    $home = getenv('HOME');
    if (empty($home) && isset($_SERVER['HOME'])) {
        $home = $_SERVER['HOME'];
    }
    if (empty($home)) {
        np_maint_log('WARN', 'Cannot determine HOME for private key');
        return;
    }
    $privkey_path = $home . '/.nodepulse/private.pem';
    if (!file_exists($privkey_path)) {
        np_maint_log('WARN', 'No private key found at ' . $privkey_path);
        return;
    }
    $privkey_pem = file_get_contents($privkey_path);
    $privkey = openssl_pkey_get_private($privkey_pem);
    if ($privkey === false) {
        np_maint_log('WARN', 'Cannot load private key');
        return;
    }

    $signed_at = np_now();
    $payload = np_build_heartbeat_payload($node_id, $signed_at);

    $sig_raw = '';
    if (!openssl_sign($payload, $sig_raw, $privkey, OPENSSL_ALGO_SHA256)) {
        np_maint_log('WARN', 'Failed to sign heartbeat');
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privkey);
        }
        return;
    }
    if (PHP_VERSION_ID < 80000) {
        openssl_free_key($privkey);
    }
    $signature = base64_encode($sig_raw);

    $body = json_encode(array(
        'node_id'   => $node_id,
        'signature' => $signature,
        'signed_at' => $signed_at,
    ), JSON_UNESCAPED_SLASHES);

    $hb_success = 0;
    foreach ($selected as $peer) {
        $hb_url = rtrim($peer['url'], '/') . '/api.php?action=heartbeat';
        $result = np_http_post($hb_url, $body, 10);
        if ($result !== null) {
            $hb_success++;
            np_maint_log('INFO', 'Heartbeat OK: ' . $peer['url']);
        } else {
            np_maint_log('WARN', 'Heartbeat FAIL: ' . $peer['url']);
        }
    }
    np_maint_log('INFO', 'Heartbeats sent: ' . $hb_success . '/' . count($selected));
}

// ============================================================
// SEEDS NETWORK BUILDER
// ============================================================

/**
 * Rebuild seeds_network.json from directory.json entries.
 * Include tunnel nodes that have a recent last_seen (within TTL).
 *
 * @param array $local_dir   Directory data
 * @param array $local_peers Peers data
 * @param int   $ttl_hours   TTL in hours
 */
function np_rebuild_seeds_network($local_dir, $local_peers, $ttl_hours) {
    $files = np_maint_files();

    $cutoff = time() - ($ttl_hours * 3600);
    $seeds = array();

    // Build a lookup of online peers by node_id for last_seen
    $peer_last_seen = array();
    if (isset($local_peers['peers'])) {
        foreach ($local_peers['peers'] as $peer) {
            if (isset($peer['node_id']) && isset($peer['last_seen'])) {
                $peer_last_seen[$peer['node_id']] = $peer['last_seen'];
            }
        }
    }

    if (isset($local_dir['entries'])) {
        foreach ($local_dir['entries'] as $entry) {
            // Only tunnel nodes become network seeds
            $type = isset($entry['type']) ? $entry['type'] : 'tunnel';
            if ($type !== 'tunnel') {
                continue;
            }
            if (!isset($entry['url']) || empty($entry['url'])) {
                continue;
            }
            if (!isset($entry['node_id'])) {
                continue;
            }

            // Check freshness: use last_seen from peers if available, else from directory
            $last_seen = '';
            if (isset($peer_last_seen[$entry['node_id']])) {
                $last_seen = $peer_last_seen[$entry['node_id']];
            } elseif (isset($entry['last_seen'])) {
                $last_seen = $entry['last_seen'];
            } elseif (isset($entry['signed_at'])) {
                $last_seen = $entry['signed_at'];
            }

            if (empty($last_seen) || strtotime($last_seen) < $cutoff) {
                continue;
            }

            $seeds[] = array(
                'node_id'     => $entry['node_id'],
                'url'         => $entry['url'],
                'last_seen'   => $last_seen,
                'reliability' => 0.5,
            );
        }
    }

    // Sort by last_seen descending
    usort($seeds, function($a, $b) {
        return strcmp($b['last_seen'], $a['last_seen']);
    });

    $data = array(
        'updated_at' => np_now(),
        'seeds'      => $seeds,
    );

    np_write_json($files['seeds_network'], $data);
    np_maint_log('INFO', 'seeds_network.json rebuilt: ' . count($seeds) . ' tunnel seeds');
}

// ============================================================
// SEEDS DOMAIN BUILDER
// ============================================================

/**
 * Rebuild seeds_domain.json from directory.json entries with type=domain.
 * Append/update only — entries are never removed here.
 *
 * @param array $local_dir  Directory data
 */
function np_rebuild_seeds_domain($local_dir) {
    $files = np_maint_files();

    $sd = np_read_json($files['seeds_domain']);
    if (!$sd) {
        $sd = array('updated_at' => '', 'domains' => array());
    }

    $sd_changed = false;
    if (isset($local_dir['entries'])) {
        foreach ($local_dir['entries'] as $entry) {
            if (!isset($entry['type']) || $entry['type'] !== 'domain') {
                continue;
            }
            $dfound = false;
            foreach ($sd['domains'] as $i => $d) {
                if ($d['node_id'] === $entry['node_id']) {
                    $sd['domains'][$i]['url']        = $entry['url'];
                    $sd['domains'][$i]['public_key'] = $entry['public_key'];
                    $sd['domains'][$i]['last_seen']  = isset($entry['last_seen']) ? $entry['last_seen'] : $entry['signed_at'];
                    $sd['domains'][$i]['status']     = 'active';
                    $dfound     = true;
                    $sd_changed = true;
                    break;
                }
            }
            if (!$dfound) {
                $sd['domains'][] = array(
                    'node_id'       => $entry['node_id'],
                    'url'           => $entry['url'],
                    'public_key'    => $entry['public_key'],
                    'registered_at' => $entry['signed_at'],
                    'last_seen'     => isset($entry['last_seen']) ? $entry['last_seen'] : $entry['signed_at'],
                    'status'        => 'active',
                );
                $sd_changed = true;
            }
        }
    }

    if ($sd_changed) {
        $sd['updated_at'] = np_now();
        np_write_json($files['seeds_domain'], $sd);
        np_maint_log('INFO', 'seeds_domain.json rebuilt: ' . count($sd['domains']) . ' domain seed(s)');
    }
}

// ============================================================
// HTTP CLIENT (PHP 5.6 compatible)
// ============================================================

/**
 * HTTP POST using curl (preferred) or file_get_contents fallback.
 *
 * @param string $url      Target URL
 * @param string $body     JSON body
 * @param int    $timeout  Seconds
 * @return string|null     Response body, or null on failure
 */
function np_http_post($url, $body, $timeout) {
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
 * Log a maintenance message.
 *
 * @param string $level   INFO, WARN, ERROR
 * @param string $message Log message
 */
function np_maint_log($level, $message) {
    $log_dir = __DIR__ . '/data/logs/';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $line = np_now() . ' [' . $level . '] ' . $message . "\n";

    // Output to stderr if running from CLI
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, '[Maintenance] ' . $line);
    }

    $log_file = $log_dir . 'maintenance.log';
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

// ============================================================
// CLI ENTRY POINT
// ============================================================

if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $files = np_maint_files();
    // Ensure data dir exists
    $data_dir = dirname($files['last_maint']);
    if (!is_dir($data_dir)) {
        mkdir($data_dir, 0755, true);
    }
    // Update timestamp to prevent concurrent lazy cron
    file_put_contents($files['last_maint'], (string)time(), LOCK_EX);
    np_run_maintenance_cycle();
}
