<?php
/**
 * NodePulse — Main API endpoint
 * Compatible with PHP 5.6+
 *
 * Routing via query string: ?action=announce|publish_url|directory|lookup|peers|heartbeat|update_info
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
    'seeds_origin'  => $NP_DIR . '/seeds_origin.json',
    'seeds_network' => $NP_DIR . '/seeds_network.json',
    'seeds_domain'  => $NP_DIR . '/seeds_domain.json',
    'node_identity' => $NP_DIR . '/node_identity.json',
    'node_config'   => $NP_DIR . '/node_config.json',
    'update'        => $NP_DIR . '/update/update.json',
);

// Gossip TTL and max peers (read from node_config or defaults)
$config = np_read_json($NP_FILES['node_config']);
$NP_TTL_HOURS = ($config && isset($config['ttl_hours'])) ? (int)$config['ttl_hours'] : 24;
$NP_MAX_PEERS = ($config && isset($config['max_peers'])) ? (int)$config['max_peers'] : 50;

// Rate limits: from config or defaults [max_requests, window_seconds]
$NP_LIMITS_DEFAULT = array(
    'announce'    => array(5, 3600),     // 5 per hour per IP
    'publish_url' => array(20, 3600),    // 20 per hour per node_id
    'heartbeat'   => array(120, 3600),   // 120 per hour per node_id
    'gossip'      => array(30, 3600),    // 30 per hour per IP
);
$NP_LIMITS = $NP_LIMITS_DEFAULT;
if ($config && isset($config['rate_limits']) && is_array($config['rate_limits'])) {
    foreach ($config['rate_limits'] as $action => $limit) {
        if (is_array($limit) && count($limit) === 2) {
            $NP_LIMITS[$action] = array((int)$limit[0], (int)$limit[1]);
        }
    }
}

// Lazy cron: schedule maintenance to run after request completes
register_shutdown_function('np_lazy_cron_check');
function np_lazy_cron_check() {
    $maint = __DIR__ . '/maintenance.php';
    if (file_exists($maint)) {
        require_once $maint;
        np_maybe_maintenance();
    }
}

// Lazy cron: schedule self-announce for domain seeds
register_shutdown_function('np_lazy_selfannounce_check');
function np_lazy_selfannounce_check() {
    $sa = __DIR__ . '/selfannounce.php';
    if (file_exists($sa)) {
        require_once $sa;
        np_maybe_selfannounce();
    }
}

// ============================================================
// CORS & SECURITY HEADERS
// ============================================================

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Allow CORS for all requests (browsers in recovery mode need this)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
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
// ROUTING
// ============================================================

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // ----------------------------------------------------------
    // POST announce — New node registration
    // ----------------------------------------------------------
    case 'announce':
        np_require_method('POST');
        $input = np_get_input();
        if (!$input) {
            np_error(400, 'Invalid JSON body');
        }
        handle_announce($input);
        break;

    // ----------------------------------------------------------
    // POST publish_url — Update cloudflared URL
    // ----------------------------------------------------------
    case 'publish_url':
        np_require_method('POST');
        $input = np_get_input();
        if (!$input) {
            np_error(400, 'Invalid JSON body');
        }
        handle_publish_url($input);
        break;

    // ----------------------------------------------------------
    // GET directory — Full directory listing
    // ----------------------------------------------------------
    case 'directory':
        np_require_method('GET');
        handle_directory();
        break;

    // ----------------------------------------------------------
    // GET lookup — Find a single node by node_id
    // ----------------------------------------------------------
    case 'lookup':
        np_require_method('GET');
        handle_lookup();
        break;

    // ----------------------------------------------------------
    // GET peers — Online peers list
    // ----------------------------------------------------------
    case 'peers':
        np_require_method('GET');
        handle_peers();
        break;

    // ----------------------------------------------------------
    // POST heartbeat — Node liveness signal
    // ----------------------------------------------------------
    case 'heartbeat':
        np_require_method('POST');
        $input = np_get_input();
        if (!$input) {
            np_error(400, 'Invalid JSON body');
        }
        handle_heartbeat($input);
        break;

    // ----------------------------------------------------------
    // GET update_info — Software version info
    // ----------------------------------------------------------
    case 'update_info':
        np_require_method('GET');
        handle_update_info();
        break;

    // ----------------------------------------------------------
    // GET seeds — Return seeds_origin.json (for bootstrap)
    // ----------------------------------------------------------
    case 'seeds':
        np_require_method('GET');
        handle_seeds();
        break;

    default:
        np_error(400, 'Unknown action');
}

// ============================================================
// HANDLERS
// ============================================================

/**
 * POST ?action=announce
 * Register a new node in the network.
 */
function handle_announce($input) {
    global $NP_FILES, $NP_LIMITS, $NP_TTL_HOURS, $NP_MAX_PEERS;

    // Rate limit by IP
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $lim = $NP_LIMITS['announce'];
    if (!np_rate_limit_check('announce', $ip, $lim[0], $lim[1])) {
        np_error(429, 'Rate limit exceeded');
    }

    // Validate required fields
    $required = array('node_id', 'public_key', 'url', 'type', 'signature', 'signed_at');
    if (!np_require_fields($input, $required)) {
        np_error(400, 'Missing required fields: node_id, public_key, url, type, signature, signed_at');
    }

    $node_id   = $input['node_id'];
    $pubkey    = $input['public_key'];
    $url       = $input['url'];
    $type      = $input['type'];
    $signature = $input['signature'];
    $signed_at = $input['signed_at'];

    // Validate formats
    if (!np_valid_node_id($node_id)) {
        np_error(400, 'Invalid node_id format (expected 12 hex chars)');
    }
    if (!np_valid_pubkey($pubkey)) {
        np_error(400, 'Invalid public_key format');
    }
    if (!np_valid_url($url)) {
        np_error(400, 'Invalid URL');
    }
    if ($type !== 'tunnel' && $type !== 'domain') {
        np_error(400, 'Invalid type (expected tunnel or domain)');
    }
    if (!np_valid_timestamp($signed_at)) {
        np_error(400, 'Invalid signed_at timestamp format');
    }

    // Verify node_id matches public key
    if (!np_verify_node_id($node_id, $pubkey)) {
        np_error(403, 'node_id does not match public key');
    }

    // Verify signature
    $payload = np_build_payload($node_id, $url, $signed_at);
    if (!np_verify_signature($payload, $signature, $pubkey)) {
        np_error(403, 'Invalid signature');
    }

    // Write to directory.json
    $dir_data = np_read_json($NP_FILES['directory']);
    if (!$dir_data) {
        $dir_data = array('updated_at' => np_now(), 'entries' => array());
    }

    $found = false;
    foreach ($dir_data['entries'] as $i => $entry) {
        if ($entry['node_id'] === $node_id) {
            // Update existing entry
            $dir_data['entries'][$i] = array(
                'node_id'    => $node_id,
                'public_key' => $pubkey,
                'url'        => $url,
                'type'       => $type,
                'signature'  => $signature,
                'signed_at'  => $signed_at,
                'last_seen'  => np_now(),
            );
            $found = true;
            break;
        }
    }
    if (!$found) {
        $dir_data['entries'][] = array(
            'node_id'    => $node_id,
            'public_key' => $pubkey,
            'url'        => $url,
            'type'       => $type,
            'signature'  => $signature,
            'signed_at'  => $signed_at,
            'last_seen'  => np_now(),
        );
    }
    $dir_data['updated_at'] = np_now();
    np_write_json($NP_FILES['directory'], $dir_data);

    // Write to peers_online.json
    $peers_data = np_read_json($NP_FILES['peers_online']);
    if (!$peers_data) {
        $peers_data = array('updated_at' => np_now(), 'ttl_hours' => $NP_TTL_HOURS, 'peers' => array());
    }

    $pfound = false;
    foreach ($peers_data['peers'] as $i => $peer) {
        if ($peer['node_id'] === $node_id) {
            $peers_data['peers'][$i]['url'] = $url;
            $peers_data['peers'][$i]['last_seen'] = np_now();
            $pfound = true;
            break;
        }
    }
    if (!$pfound) {
        $peers_data['peers'][] = array(
            'node_id'   => $node_id,
            'url'       => $url,
            'type'      => $type,
            'last_seen' => np_now(),
        );
    }
    // Trim stale peers
    $peers_data['peers'] = np_merge_peers($peers_data['peers'], array(), $NP_TTL_HOURS, $NP_MAX_PEERS);
    $peers_data['updated_at'] = np_now();
    np_write_json($NP_FILES['peers_online'], $peers_data);

    // If this is a domain type, also add to seeds_domain.json
    if ($type === 'domain') {
        $sd = np_read_json($NP_FILES['seeds_domain']);
        if (!$sd) {
            $sd = array('updated_at' => np_now(), 'domains' => array());
        }
        $dfound = false;
        foreach ($sd['domains'] as $i => $d) {
            if ($d['node_id'] === $node_id) {
                $sd['domains'][$i]['url'] = $url;
                $sd['domains'][$i]['public_key'] = $pubkey;
                $sd['domains'][$i]['last_seen'] = np_now();
                $sd['domains'][$i]['status'] = 'active';
                $dfound = true;
                break;
            }
        }
        if (!$dfound) {
            $sd['domains'][] = array(
                'node_id'       => $node_id,
                'url'           => $url,
                'public_key'    => $pubkey,
                'registered_at' => np_now(),
                'last_seen'     => np_now(),
                'status'        => 'active',
            );
        }
        $sd['updated_at'] = np_now();
        np_write_json($NP_FILES['seeds_domain'], $sd);
    }

    np_response(true, 'Node registered', array('node_id' => $node_id));
}

/**
 * POST ?action=publish_url
 * Update a node's cloudflared URL.
 */
function handle_publish_url($input) {
    global $NP_FILES, $NP_LIMITS, $NP_TTL_HOURS, $NP_MAX_PEERS;

    // Validate required fields
    $required = array('node_id', 'public_key', 'url', 'signature', 'signed_at');
    if (!np_require_fields($input, $required)) {
        np_error(400, 'Missing required fields: node_id, public_key, url, signature, signed_at');
    }

    $node_id   = $input['node_id'];
    $pubkey    = $input['public_key'];
    $url       = $input['url'];
    $signature = $input['signature'];
    $signed_at = $input['signed_at'];

    // Rate limit by node_id
    $lim = $NP_LIMITS['publish_url'];
    if (!np_rate_limit_check('publish_url', $node_id, $lim[0], $lim[1])) {
        np_error(429, 'Rate limit exceeded');
    }

    // Validate formats
    if (!np_valid_node_id($node_id)) {
        np_error(400, 'Invalid node_id format');
    }
    if (!np_valid_pubkey($pubkey)) {
        np_error(400, 'Invalid public_key format');
    }
    if (!np_valid_url($url)) {
        np_error(400, 'Invalid URL');
    }
    if (!np_valid_timestamp($signed_at)) {
        np_error(400, 'Invalid signed_at timestamp format');
    }

    // Verify node_id matches public key
    if (!np_verify_node_id($node_id, $pubkey)) {
        np_error(403, 'node_id does not match public key');
    }

    // Verify signature
    $payload = np_build_payload($node_id, $url, $signed_at);
    if (!np_verify_signature($payload, $signature, $pubkey)) {
        np_error(403, 'Invalid signature');
    }

    // Read directory and check signed_at is newer
    $dir_data = np_read_json($NP_FILES['directory']);
    if (!$dir_data) {
        $dir_data = array('updated_at' => np_now(), 'entries' => array());
    }

    $previous_url = null;
    $found = false;
    foreach ($dir_data['entries'] as $i => $entry) {
        if ($entry['node_id'] === $node_id) {
            // Reject if not newer
            if (strcmp($signed_at, $entry['signed_at']) <= 0) {
                np_error(409, 'signed_at must be more recent than existing entry');
            }
            $previous_url = $entry['url'];
            $dir_data['entries'][$i] = array(
                'node_id'    => $node_id,
                'public_key' => $pubkey,
                'url'        => $url,
                'type'       => isset($entry['type']) ? $entry['type'] : 'tunnel',
                'signature'  => $signature,
                'signed_at'  => $signed_at,
                'last_seen'  => np_now(),
            );
            $found = true;
            break;
        }
    }

    if (!$found) {
        // Node not known yet — accept as new registration
        $dir_data['entries'][] = array(
            'node_id'    => $node_id,
            'public_key' => $pubkey,
            'url'        => $url,
            'type'       => 'tunnel',
            'signature'  => $signature,
            'signed_at'  => $signed_at,
            'last_seen'  => np_now(),
        );
    }

    $dir_data['updated_at'] = np_now();
    np_write_json($NP_FILES['directory'], $dir_data);

    // Update peers_online
    $peers_data = np_read_json($NP_FILES['peers_online']);
    if (!$peers_data) {
        $peers_data = array('updated_at' => np_now(), 'ttl_hours' => $NP_TTL_HOURS, 'peers' => array());
    }

    $pfound = false;
    foreach ($peers_data['peers'] as $i => $peer) {
        if ($peer['node_id'] === $node_id) {
            $peers_data['peers'][$i]['url'] = $url;
            $peers_data['peers'][$i]['last_seen'] = np_now();
            $pfound = true;
            break;
        }
    }
    if (!$pfound) {
        $peers_data['peers'][] = array(
            'node_id'   => $node_id,
            'url'       => $url,
            'type'      => 'tunnel',
            'last_seen' => np_now(),
        );
    }
    $peers_data['peers'] = np_merge_peers($peers_data['peers'], array(), $NP_TTL_HOURS, $NP_MAX_PEERS);
    $peers_data['updated_at'] = np_now();
    np_write_json($NP_FILES['peers_online'], $peers_data);

    $extra = array('node_id' => $node_id);
    if ($previous_url !== null) {
        $extra['previous_url'] = $previous_url;
    }
    np_response(true, 'URL updated', $extra);
}

/**
 * GET ?action=directory
 * Return full directory.json.
 */
function handle_directory() {
    global $NP_FILES;

    $data = np_read_json($NP_FILES['directory']);
    if (!$data) {
        $data = array('updated_at' => np_now(), 'entries' => array());
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * GET ?action=lookup&node_id=xxx
 * Find a single node by ID.
 */
function handle_lookup() {
    global $NP_FILES;

    $node_id = isset($_GET['node_id']) ? $_GET['node_id'] : '';
    if (!np_valid_node_id($node_id)) {
        np_error(400, 'Invalid or missing node_id parameter');
    }

    $data = np_read_json($NP_FILES['directory']);
    if (!$data || !isset($data['entries'])) {
        np_error(404, 'Node not found');
    }

    foreach ($data['entries'] as $entry) {
        if ($entry['node_id'] === $node_id) {
            np_response(true, 'Node found', array(
                'entry' => array(
                    'node_id'   => $entry['node_id'],
                    'url'       => $entry['url'],
                    'type'      => isset($entry['type']) ? $entry['type'] : 'tunnel',
                    'signed_at' => $entry['signed_at'],
                ),
            ));
        }
    }

    np_error(404, 'Node not found');
}

/**
 * GET ?action=peers
 * Return peers_online.json.
 */
function handle_peers() {
    global $NP_FILES;

    $data = np_read_json($NP_FILES['peers_online']);
    if (!$data) {
        $data = array('updated_at' => np_now(), 'ttl_hours' => 24, 'peers' => array());
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * POST ?action=heartbeat
 * Node liveness signal.
 */
function handle_heartbeat($input) {
    global $NP_FILES, $NP_LIMITS, $NP_TTL_HOURS, $NP_MAX_PEERS;

    $required = array('node_id', 'signature', 'signed_at');
    if (!np_require_fields($input, $required)) {
        np_error(400, 'Missing required fields: node_id, signature, signed_at');
    }

    $node_id   = $input['node_id'];
    $signature = $input['signature'];
    $signed_at = $input['signed_at'];

    // Rate limit by node_id
    $lim = $NP_LIMITS['heartbeat'];
    if (!np_rate_limit_check('heartbeat', $node_id, $lim[0], $lim[1])) {
        np_error(429, 'Rate limit exceeded');
    }

    if (!np_valid_node_id($node_id)) {
        np_error(400, 'Invalid node_id format');
    }
    if (!np_valid_timestamp($signed_at)) {
        np_error(400, 'Invalid signed_at timestamp format');
    }

    // Lookup public key from directory
    $dir_data = np_read_json($NP_FILES['directory']);
    if (!$dir_data || !isset($dir_data['entries'])) {
        np_error(404, 'Node not found in directory');
    }

    $pubkey = null;
    $entry_idx = null;
    foreach ($dir_data['entries'] as $i => $entry) {
        if ($entry['node_id'] === $node_id) {
            $pubkey = $entry['public_key'];
            $entry_idx = $i;
            break;
        }
    }

    if ($pubkey === null) {
        np_error(404, 'Node not found in directory');
    }

    // Verify heartbeat signature
    $payload = np_build_heartbeat_payload($node_id, $signed_at);
    if (!np_verify_signature($payload, $signature, $pubkey)) {
        np_error(403, 'Invalid signature');
    }

    // Update last_seen in directory
    $dir_data['entries'][$entry_idx]['last_seen'] = np_now();
    $dir_data['updated_at'] = np_now();
    np_write_json($NP_FILES['directory'], $dir_data);

    // Update last_seen in peers_online
    $peers_data = np_read_json($NP_FILES['peers_online']);
    if ($peers_data && isset($peers_data['peers'])) {
        foreach ($peers_data['peers'] as $i => $peer) {
            if ($peer['node_id'] === $node_id) {
                $peers_data['peers'][$i]['last_seen'] = np_now();
                break;
            }
        }
        $peers_data['peers'] = np_merge_peers($peers_data['peers'], array(), $NP_TTL_HOURS, $NP_MAX_PEERS);
        $peers_data['updated_at'] = np_now();
        np_write_json($NP_FILES['peers_online'], $peers_data);
    }

    np_response(true, 'Heartbeat received');
}

/**
 * GET ?action=update_info
 * Return update/update.json.
 */
function handle_update_info() {
    global $NP_FILES;

    $data = np_read_json($NP_FILES['update']);
    if (!$data) {
        np_error(404, 'No update information available');
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * GET ?action=seeds
 * Return seeds_origin.json (bootstrap seed list).
 */
function handle_seeds() {
    global $NP_FILES;

    $data = np_read_json($NP_FILES['seeds_origin']);
    if (!$data) {
        $data = array('version' => 1, 'updated_at' => np_now(), 'seeds' => array());
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// UTILITY
// ============================================================

/**
 * Enforce HTTP method.
 *
 * @param string $method  Expected method (GET or POST)
 */
function np_require_method($method) {
    $actual = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    if ($actual !== $method) {
        np_error(405, 'Method not allowed. Expected ' . $method);
    }
}
