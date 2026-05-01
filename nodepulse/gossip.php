<?php
/**
 * NodePulse — Gossip synchronization endpoint
 * Compatible with PHP 5.6+
 *
 * POST /nodepulse/gossip.php
 *
 * Receives directory entries and peers from another node,
 * merges them with local data, and returns local data for
 * bidirectional synchronization.
 */

require __DIR__ . '/verify.php';

// ============================================================
// CONFIGURATION
// ============================================================

$NP_DIR   = __DIR__;
$NP_DATA  = __DIR__ . '/data/';

$NP_FILES = array(
    'directory'     => $NP_DIR . '/directory.json',
    'peers_online'  => $NP_DIR . '/peers_online.json',
    'seeds_domain'  => $NP_DIR . '/seeds_domain.json',
    'node_config'   => $NP_DIR . '/node_config.json',
);

$config = np_read_json($NP_FILES['node_config']);
$NP_TTL_HOURS = ($config && isset($config['ttl_hours'])) ? (int)$config['ttl_hours'] : 24;
$NP_MAX_PEERS = ($config && isset($config['max_peers'])) ? (int)$config['max_peers'] : 50;

// Rate limit: from config or default (30 per hour per IP)
$NP_GOSSIP_LIMIT = array(30, 3600);
if ($config && isset($config['rate_limits']) && is_array($config['rate_limits'])) {
    if (isset($config['rate_limits']['gossip']) && is_array($config['rate_limits']['gossip'])) {
        $rl = $config['rate_limits']['gossip'];
        if (count($rl) === 2) {
            $NP_GOSSIP_LIMIT = array((int)$rl[0], (int)$rl[1]);
        }
    }
}

// Lazy cron: schedule maintenance to run after request completes
register_shutdown_function('np_lazy_cron_check_gossip');
function np_lazy_cron_check_gossip() {
    $maint = __DIR__ . '/maintenance.php';
    if (file_exists($maint)) {
        require_once $maint;
        np_maybe_maintenance();
    }
}

// Lazy cron: schedule self-announce for domain seeds
register_shutdown_function('np_lazy_selfannounce_check_gossip');
function np_lazy_selfannounce_check_gossip() {
    $sa = __DIR__ . '/selfannounce.php';
    if (file_exists($sa)) {
        require_once $sa;
        np_maybe_selfannounce();
    }
}

// ============================================================
// CORS & HEADERS
// ============================================================

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json');

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!empty($origin)) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================
// ENSURE DATA DIRS EXIST
// ============================================================

if (!is_dir($NP_DATA)) {
    mkdir($NP_DATA, 0755, true);
}
if (!is_dir($NP_DATA . 'ratelimit/')) {
    mkdir($NP_DATA . 'ratelimit/', 0755, true);
}

// ============================================================
// ONLY POST ALLOWED
// ============================================================

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'POST') {
    np_error(405, 'Method not allowed. Use POST.');
}

// Rate limit by IP
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
if (!np_rate_limit_check('gossip', $ip, $NP_GOSSIP_LIMIT[0], $NP_GOSSIP_LIMIT[1])) {
    np_error(429, 'Rate limit exceeded');
}

// ============================================================
// PROCESS REQUEST
// ============================================================

$input = np_get_input();
if (!$input) {
    np_error(400, 'Invalid JSON body');
}

// Validate sender identification
if (!isset($input['node_id']) || !np_valid_node_id($input['node_id'])) {
    np_error(400, 'Missing or invalid node_id');
}

$remote_dir_entries = array();
if (isset($input['directory_entries']) && is_array($input['directory_entries'])) {
    $remote_dir_entries = array_slice($input['directory_entries'], 0, 200);
}

$remote_peers = array();
if (isset($input['peers']) && is_array($input['peers'])) {
    $remote_peers = array_slice($input['peers'], 0, 200);
}

// --- Merge directory ---
$local_dir = np_read_json($NP_FILES['directory']);
if (!$local_dir) {
    $local_dir = array('updated_at' => np_now(), 'entries' => array());
}

$local_dir['entries'] = np_merge_directory($local_dir['entries'], $remote_dir_entries);
$local_dir['updated_at'] = np_now();
np_write_json($NP_FILES['directory'], $local_dir);

// --- Derive seeds_domain from directory entries with type=domain (append/update only) ---
$sd = np_read_json($NP_FILES['seeds_domain']);
if (!$sd) {
    $sd = array('updated_at' => '', 'domains' => array());
}
$sd_changed = false;
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
if ($sd_changed) {
    $sd['updated_at'] = np_now();
    np_write_json($NP_FILES['seeds_domain'], $sd);
}

// --- Merge peers ---
$local_peers = np_read_json($NP_FILES['peers_online']);
if (!$local_peers) {
    $local_peers = array('updated_at' => np_now(), 'ttl_hours' => $NP_TTL_HOURS, 'peers' => array());
}

$local_peers['peers'] = np_merge_peers($local_peers['peers'], $remote_peers, $NP_TTL_HOURS, $NP_MAX_PEERS);
$local_peers['peers'] = np_filter_peers_by_directory($local_peers['peers'], $local_dir['entries']);
$local_peers['updated_at'] = np_now();
np_write_json($NP_FILES['peers_online'], $local_peers);

// --- Respond with our data for bidirectional sync ---
// Strip public_key from directory entries to reduce payload size in gossip
// (the full directory with keys is available via api.php?action=directory)
$stripped_entries = array();
foreach ($local_dir['entries'] as $entry) {
    $stripped_entries[] = array(
        'node_id'    => $entry['node_id'],
        'public_key' => $entry['public_key'],
        'url'        => $entry['url'],
        'type'       => isset($entry['type']) ? $entry['type'] : 'tunnel',
        'signature'  => $entry['signature'],
        'signed_at'  => $entry['signed_at'],
        'last_seen'  => isset($entry['last_seen']) ? $entry['last_seen'] : $entry['signed_at'],
    );
}

echo json_encode(array(
    'ok'                => true,
    'directory_entries' => $stripped_entries,
    'peers'             => $local_peers['peers'],
    'timestamp'         => np_now(),
), JSON_UNESCAPED_SLASHES);
exit;
