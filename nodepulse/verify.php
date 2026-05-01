<?php
/**
 * NodePulse — Shared signature verification library
 * Compatible with PHP 5.6+
 *
 * Provides RSA-SHA256 signature verification, JSON file I/O with flock,
 * directory/peers merge logic, and rate limiting.
 */

// ============================================================
// SIGNATURE & IDENTITY
// ============================================================

/**
 * Verify RSA-SHA256 signature.
 *
 * @param string $payload    The original signed string
 * @param string $sig_b64    Base64-encoded signature
 * @param string $pubkey_pem PEM-formatted public key
 * @return bool
 */
function np_verify_signature($payload, $sig_b64, $pubkey_pem) {
    $pubkey = openssl_pkey_get_public($pubkey_pem);
    if ($pubkey === false) {
        return false;
    }
    $sig = base64_decode($sig_b64, true);
    if ($sig === false) {
        return false;
    }
    $result = openssl_verify($payload, $sig, $pubkey, OPENSSL_ALGO_SHA256);
    // PHP 5.6 compatibility: free key resource
    if (PHP_VERSION_ID < 80000) {
        openssl_free_key($pubkey);
    }
    return ($result === 1);
}

/**
 * Verify that node_id matches the SHA-256 hash of the public key (DER-encoded).
 *
 * @param string $node_id    Expected 12-char hex node ID
 * @param string $pubkey_pem PEM-formatted public key
 * @return bool
 */
function np_verify_node_id($node_id, $pubkey_pem) {
    // Extract the DER-encoded binary from PEM
    $lines = explode("\n", trim($pubkey_pem));
    $der_b64 = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '-----') === 0) {
            continue;
        }
        $der_b64 .= $line;
    }
    $der = base64_decode($der_b64, true);
    if ($der === false) {
        return false;
    }
    $expected = substr(hash('sha256', $der), 0, 12);
    return hash_equals($expected, $node_id);
}

/**
 * Build the payload string used for signing.
 *
 * @param string $node_id
 * @param string $url
 * @param string $signed_at  ISO 8601 timestamp
 * @return string
 */
function np_build_payload($node_id, $url, $signed_at) {
    return $node_id . '|' . $url . '|' . $signed_at;
}

/**
 * Build the heartbeat payload string.
 *
 * @param string $node_id
 * @param string $signed_at
 * @return string
 */
function np_build_heartbeat_payload($node_id, $signed_at) {
    return $node_id . '|heartbeat|' . $signed_at;
}

// ============================================================
// JSON FILE I/O (with flock)
// ============================================================

/**
 * Read and decode a JSON file with shared lock.
 *
 * @param string $filepath
 * @return array|null  Returns null on failure
 */
function np_read_json($filepath) {
    if (!file_exists($filepath)) {
        return null;
    }
    $fp = fopen($filepath, 'r');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return null;
    }
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Encode and write a JSON file with exclusive lock.
 *
 * @param string $filepath
 * @param array  $data
 * @return bool
 */
function np_write_json($filepath, $data) {
    $dir = dirname($filepath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return (file_put_contents($filepath, $json, LOCK_EX) !== false);
}

// ============================================================
// MERGE LOGIC
// ============================================================

/**
 * Merge remote directory entries into local ones.
 * For each entry: keep the one with the most recent signed_at.
 * New entries are added only if their signature is valid.
 *
 * @param array $local   Array of directory entries
 * @param array $remote  Array of directory entries
 * @return array         Merged entries
 */
function np_merge_directory($local, $remote) {
    // Index local by node_id
    $index = array();
    foreach ($local as $i => $entry) {
        $index[$entry['node_id']] = $i;
    }

    foreach ($remote as $rentry) {
        if (!isset($rentry['node_id']) || !isset($rentry['signature']) || !isset($rentry['signed_at'])) {
            continue;
        }
        if (!isset($rentry['public_key']) || !isset($rentry['url'])) {
            continue;
        }

        // Verify signature before accepting
        $payload = np_build_payload($rentry['node_id'], $rentry['url'], $rentry['signed_at']);
        if (!np_verify_signature($payload, $rentry['signature'], $rentry['public_key'])) {
            continue;
        }
        // Verify node_id matches public key
        if (!np_verify_node_id($rentry['node_id'], $rentry['public_key'])) {
            continue;
        }

        if (isset($index[$rentry['node_id']])) {
            $li = $index[$rentry['node_id']];
            // Keep the more recent one
            if (strcmp($rentry['signed_at'], $local[$li]['signed_at']) > 0) {
                $local[$li] = $rentry;
            }
        } else {
            $local[] = $rentry;
            $index[$rentry['node_id']] = count($local) - 1;
        }
    }

    return $local;
}

/**
 * Merge remote peers into local ones.
 * For each peer: keep the one with the most recent last_seen.
 * Remove peers older than $ttl_hours.
 * Truncate to $max_peers.
 *
 * @param array $local      Array of peer entries
 * @param array $remote     Array of peer entries
 * @param int   $ttl_hours  Hours before a peer is considered stale
 * @param int   $max_peers  Maximum number of peers to keep
 * @return array            Merged peers
 */
function np_merge_peers($local, $remote, $ttl_hours, $max_peers) {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $cutoff_ts = time() - ($ttl_hours * 3600);

    // Index local by node_id
    $index = array();
    foreach ($local as $i => $peer) {
        $index[$peer['node_id']] = $i;
    }

    foreach ($remote as $rpeer) {
        if (!isset($rpeer['node_id']) || !isset($rpeer['last_seen'])) {
            continue;
        }
        // Skip stale peers
        if (strtotime($rpeer['last_seen']) < $cutoff_ts) {
            continue;
        }

        if (isset($index[$rpeer['node_id']])) {
            $li = $index[$rpeer['node_id']];
            if (strcmp($rpeer['last_seen'], $local[$li]['last_seen']) > 0) {
                $local[$li]['last_seen'] = $rpeer['last_seen'];
                if (isset($rpeer['url'])) {
                    $local[$li]['url'] = $rpeer['url'];
                }
            }
        } else {
            $local[] = $rpeer;
            $index[$rpeer['node_id']] = count($local) - 1;
        }
    }

    // Remove stale peers
    $filtered = array();
    foreach ($local as $peer) {
        if (strtotime($peer['last_seen']) >= $cutoff_ts) {
            $filtered[] = $peer;
        }
    }

    // Sort by last_seen descending, truncate
    usort($filtered, function($a, $b) {
        return strcmp($b['last_seen'], $a['last_seen']);
    });

    if (count($filtered) > $max_peers) {
        $filtered = array_slice($filtered, 0, $max_peers);
    }

    return $filtered;
}

/**
 * Filter peers to only keep those whose node_id exists in the verified directory.
 * Prevents injection of fake node_ids via gossip (attacker would need a valid RSA key).
 *
 * @param array $peers        Array of peer entries
 * @param array $dir_entries  Array of verified directory entries
 * @return array              Filtered peers
 */
function np_filter_peers_by_directory($peers, $dir_entries) {
    // Build lookup: node_id => true
    $known = array();
    foreach ($dir_entries as $entry) {
        if (isset($entry['node_id'])) {
            $known[$entry['node_id']] = true;
        }
    }

    $filtered = array();
    foreach ($peers as $peer) {
        if (isset($peer['node_id']) && isset($known[$peer['node_id']])) {
            $filtered[] = $peer;
        }
    }

    return $filtered;
}

// ============================================================
// RATE LIMITING
// ============================================================

/**
 * File-based rate limiter.
 *
 * @param string $action      Action name (e.g. "announce", "publish_url")
 * @param string $identifier  IP address or node_id
 * @param int    $max         Maximum requests allowed in window
 * @param int    $window_sec  Time window in seconds
 * @return bool  true if allowed, false if rate limited
 */
function np_rate_limit_check($action, $identifier, $max, $window_sec) {
    $dir = __DIR__ . '/data/ratelimit/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $hash = hash('sha256', $action . ':' . $identifier);
    $file = $dir . $hash . '.json';

    $now = time();
    $requests = array();

    if (file_exists($file)) {
        $raw = json_decode(file_get_contents($file), true);
        if (is_array($raw) && isset($raw['r'])) {
            $requests = $raw['r'];
        }
    }

    // Remove entries older than window
    $fresh = array();
    foreach ($requests as $t) {
        if (($now - $t) < $window_sec) {
            $fresh[] = $t;
        }
    }
    $requests = $fresh;

    if (count($requests) >= $max) {
        return false;
    }

    $requests[] = $now;
    file_put_contents($file, json_encode(array('r' => $requests)), LOCK_EX);

    return true;
}

// ============================================================
// RESPONSE HELPERS
// ============================================================

/**
 * Send a JSON response and exit.
 *
 * @param bool   $ok
 * @param string $message
 * @param array  $extra  Additional fields to merge
 */
function np_response($ok, $message, $extra = array()) {
    $resp = array('ok' => $ok, 'message' => $message);
    if (!empty($extra)) {
        $resp = array_merge($resp, $extra);
    }
    header('Content-Type: application/json');
    echo json_encode($resp, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send an error response with HTTP status code and exit.
 *
 * @param int    $code
 * @param string $message
 */
function np_error($code, $message) {
    http_response_code($code);
    np_response(false, $message);
}

// ============================================================
// INPUT HELPERS
// ============================================================

/**
 * Get the JSON request body as an associative array.
 *
 * @return array|null
 */
function np_get_input($max_bytes = 5242880) {
    // Check Content-Length header first (fast reject)
    if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $max_bytes) {
        return null;
    }
    $raw = file_get_contents('php://input', false, null, 0, $max_bytes + 1);
    if ($raw === false || $raw === '') {
        return null;
    }
    if (strlen($raw) > $max_bytes) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Validate that required fields exist in input.
 *
 * @param array $input
 * @param array $fields  List of required field names
 * @return bool
 */
function np_require_fields($input, $fields) {
    foreach ($fields as $f) {
        if (!isset($input[$f]) || $input[$f] === '') {
            return false;
        }
    }
    return true;
}

/**
 * Get current UTC timestamp in ISO 8601 format.
 *
 * @return string
 */
function np_now() {
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Validate that a string looks like a valid ISO 8601 UTC timestamp.
 *
 * @param string $ts
 * @return bool
 */
function np_valid_timestamp($ts) {
    if (!is_string($ts)) {
        return false;
    }
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $ts);
}

/**
 * Validate that a string looks like a 12-char hex node_id.
 *
 * @param string $id
 * @return bool
 */
function np_valid_node_id($id) {
    if (!is_string($id)) {
        return false;
    }
    return (bool)preg_match('/^[a-f0-9]{12}$/', $id);
}

/**
 * Validate that a string is a valid URL.
 *
 * @param string $url
 * @return bool
 */
function np_valid_url($url) {
    if (!is_string($url)) {
        return false;
    }
    return (bool)preg_match('#^https?://.{4,}#', $url);
}

/**
 * Validate that a string looks like a PEM public key.
 *
 * @param string $pem
 * @return bool
 */
function np_valid_pubkey($pem) {
    if (!is_string($pem)) {
        return false;
    }
    return (strpos($pem, '-----BEGIN PUBLIC KEY-----') === 0);
}
