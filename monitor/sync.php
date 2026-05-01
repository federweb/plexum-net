<?php
/**
 * monitor/sync.php — Gossip proxy for NodePulse Monitor
 * Sends our local directory+peers to a remote peer via gossip.php,
 * merges the response into local files, and returns a summary.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Reuse verify.php merge functions and file I/O
require dirname(__FILE__) . '/../nodepulse/verify.php';

$peer_url = isset($_GET['url']) ? trim($_GET['url']) : '';

if (!preg_match('#^https?://#i', $peer_url)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Invalid URL'));
    exit;
}

$base = dirname(__FILE__) . '/../nodepulse/';

$config    = np_read_json($base . 'node_config.json');
$directory = np_read_json($base . 'directory.json');
$peers_obj = np_read_json($base . 'peers_online.json');

$node_id    = ($config    && isset($config['node_id']))          ? $config['node_id']          : '';
$ttl_hours  = ($config    && isset($config['ttl_hours']))        ? (int)$config['ttl_hours']   : 24;
$max_peers  = ($config    && isset($config['max_peers']))        ? (int)$config['max_peers']   : 50;
$entries    = ($directory && isset($directory['entries']))       ? $directory['entries']       : array();
$peers_list = ($peers_obj && isset($peers_obj['peers']))         ? $peers_obj['peers']         : array();

if (!$node_id) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Local node_id not found in node_config.json'));
    exit;
}

// Build gossip payload
$body = json_encode(array(
    'node_id'           => $node_id,
    'directory_entries' => $entries,
    'peers'             => $peers_list,
));

$gossip_url = rtrim($peer_url, '/') . '/gossip.php';

// POST via cURL
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'cURL not available'));
    exit;
}

$ch = curl_init($gossip_url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => array(
        'Content-Type: application/json',
        'User-Agent: NodePulse-Monitor/1.0',
    ),
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
));

$raw   = curl_exec($ch);
$errno = curl_errno($ch);
$errmsg = curl_strerror($errno);
curl_close($ch);

if ($errno || $raw === false) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'Gossip failed: ' . $errmsg . ' (' . $errno . ')'));
    exit;
}

// Strip any PHP errors prepended before JSON
for ($i = 0; $i < strlen($raw); $i++) {
    if ($raw[$i] === '{' || $raw[$i] === '[') {
        $raw = substr($raw, $i);
        break;
    }
}

$response = json_decode($raw, true);

if ($response === null || !isset($response['ok']) || !$response['ok']) {
    $err = isset($response['message']) ? $response['message'] : substr($raw, 0, 120);
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => 'Remote gossip error: ' . $err));
    exit;
}

// Merge response into local files
$remote_entries = isset($response['directory_entries']) ? $response['directory_entries'] : array();
$remote_peers   = isset($response['peers'])             ? $response['peers']             : array();

// Merge directory
if (!$directory) {
    $directory = array('updated_at' => np_now(), 'entries' => array());
}
$directory['entries']    = np_merge_directory($directory['entries'], $remote_entries);
$directory['updated_at'] = np_now();
np_write_json($base . 'directory.json', $directory);

// Merge peers_online
if (!$peers_obj) {
    $peers_obj = array('updated_at' => np_now(), 'ttl_hours' => $ttl_hours, 'peers' => array());
}
$peers_obj['peers']      = np_merge_peers($peers_obj['peers'], $remote_peers, $ttl_hours, $max_peers);
$peers_obj['updated_at'] = np_now();
np_write_json($base . 'peers_online.json', $peers_obj);

// Build summary
$peer_host   = parse_url($peer_url, PHP_URL_HOST);
$peer_domain = $peer_host ? $peer_host : $peer_url;

echo json_encode(array(
    'ok'              => true,
    'gossip_url'      => $gossip_url,
    'sent_entries'    => count($entries),
    'sent_peers'      => count($peers_list),
    'received_entries'=> count($remote_entries),
    'received_peers'  => count($remote_peers),
    'merged_at'       => np_now(),
));
