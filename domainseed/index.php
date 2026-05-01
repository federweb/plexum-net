<?php
/**
 * NodePulse — Domain Seed Registration Wizard
 * Compatible with PHP 5.6+
 *
 * Runs on the LOCAL NodePulse instance (MSYS2/Termux).
 * Generates RSA-2048 identity and packages a complete NodePulse
 * deployment ZIP for any domain.
 *
 * Routes:
 *   GET  /domainseed/             — Registration form
 *   POST /domainseed/?action=generate  — Generate keys + ZIP
 *   GET  /domainseed/?action=download  — Serve ZIP file
 */

// ============================================================
// CONFIGURATION
// ============================================================

$NP_SOURCE_DIR = dirname(__DIR__) . '/nodepulse';
$NP_DOMAINSEED_DIR = __DIR__;
$NP_TMP_DIR = dirname(__DIR__) . '/domainseed/tmp';

// Ensure tmp dir exists
if (!is_dir($NP_TMP_DIR)) {
    mkdir($NP_TMP_DIR, 0755, true);
}

// ============================================================
// ROUTING
// ============================================================

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    np_ds_handle_generate();
    exit;
}

if ($action === 'download' && isset($_GET['file'])) {
    np_ds_handle_download();
    exit;
}

if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    np_ds_handle_verify();
    exit;
}

// Default: render HTML page (below PHP section)

// ============================================================
// GENERATE HANDLER
// ============================================================

function np_ds_handle_generate() {
    global $NP_SOURCE_DIR, $NP_DOMAINSEED_DIR, $NP_TMP_DIR;

    // Cleanup old temp files (>1 hour)
    np_ds_cleanup_tmp($NP_TMP_DIR, 3600);

    // Validate input
    $domain_url = isset($_POST['domain_url']) ? trim($_POST['domain_url']) : '';
    if (empty($domain_url)) {
        echo json_encode(array('ok' => false, 'message' => 'Domain URL is required'));
        return;
    }
    // Must start with https://
    if (strpos($domain_url, 'https://') !== 0) {
        echo json_encode(array('ok' => false, 'message' => 'URL must start with https://'));
        return;
    }
    if (strlen($domain_url) < 15) {
        echo json_encode(array('ok' => false, 'message' => 'URL is too short'));
        return;
    }
    // Remove trailing slash
    $domain_url = rtrim($domain_url, '/');

    // Check ZipArchive
    if (!class_exists('ZipArchive')) {
        echo json_encode(array('ok' => false, 'message' => 'PHP zip extension not available. Install: pacman -S mingw-w64-ucrt-x86_64-libzip (MSYS2) or pkg install php-zip (Termux)'));
        return;
    }

    // Check openssl
    if (!function_exists('openssl_pkey_new')) {
        echo json_encode(array('ok' => false, 'message' => 'PHP openssl extension not available'));
        return;
    }

    // ---- Generate RSA-2048 keypair ----
    $key_config = array(
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    );

    // Auto-detect openssl.cnf (needed on MSYS2/Windows)
    $cnf_candidates = array(
        dirname($NP_SOURCE_DIR) . '/nodepulse-bin/php/extras/ssl/openssl.cnf', // MSYS2 dev
        dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',                       // PHP binary relative
        getenv('OPENSSL_CONF'),                                                // env var
    );
    foreach ($cnf_candidates as $cnf) {
        if ($cnf && file_exists($cnf)) {
            $key_config['config'] = $cnf;
            break;
        }
    }

    $res = openssl_pkey_new($key_config);
    if ($res === false) {
        $err = '';
        while ($msg = openssl_error_string()) {
            $err .= $msg . ' ';
        }
        echo json_encode(array('ok' => false, 'message' => 'Failed to generate RSA key: ' . $err));
        return;
    }

    // Export private key PEM (pass same config for openssl.cnf)
    $privkey_pem = '';
    if (!openssl_pkey_export($res, $privkey_pem, null, $key_config)) {
        echo json_encode(array('ok' => false, 'message' => 'Failed to export private key'));
        return;
    }

    // Export public key PEM
    $details = openssl_pkey_get_details($res);
    if ($details === false || !isset($details['key'])) {
        echo json_encode(array('ok' => false, 'message' => 'Failed to export public key'));
        return;
    }
    $pubkey_pem = $details['key'];

    // Free key resource (PHP 5.6-7.x)
    if (PHP_VERSION_ID < 80000) {
        openssl_free_key($res);
    }

    // ---- Compute node_id (same logic as verify.php np_verify_node_id) ----
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
        echo json_encode(array('ok' => false, 'message' => 'Failed to decode public key DER'));
        return;
    }
    $node_id = substr(hash('sha256', $der), 0, 12);

    // ---- Build ZIP ----
    $zip_filename = 'nodepulse-domain-' . $node_id . '.zip';
    $zip_path = $NP_TMP_DIR . '/' . $zip_filename;

    $zip = new ZipArchive();
    $zip_ret = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($zip_ret !== true) {
        echo json_encode(array('ok' => false, 'message' => 'Failed to create ZIP file (code: ' . $zip_ret . ')'));
        return;
    }

    // -- PHP source files --
    $php_files = array('api.php', 'verify.php', 'gossip.php', 'maintenance.php');
    foreach ($php_files as $f) {
        $src = $NP_SOURCE_DIR . '/' . $f;
        if (file_exists($src)) {
            $zip->addFile($src, 'nodepulse/' . $f);
        }
    }

    // -- .htaccess files --
    $htaccess_root = $NP_SOURCE_DIR . '/.htaccess';
    if (file_exists($htaccess_root)) {
        $zip->addFile($htaccess_root, 'nodepulse/.htaccess');
    }
    $htaccess_data = $NP_SOURCE_DIR . '/data/.htaccess';
    if (file_exists($htaccess_data)) {
        $zip->addFile($htaccess_data, 'nodepulse/data/.htaccess');
    }

    // -- favicon.ico --
    $favicon = $NP_SOURCE_DIR . '/favicon.ico';
    if (file_exists($favicon)) {
        $zip->addFile($favicon, 'nodepulse/favicon.ico');
    }

    // -- selfannounce.php (from domainseed/ template) --
    $sa_src = $NP_DOMAINSEED_DIR . '/selfannounce.php';
    if (file_exists($sa_src)) {
        $zip->addFile($sa_src, 'nodepulse/selfannounce.php');
    }

    // -- index.php (domain seed entry point + health check) --
    $index_php = <<<'DSINDEX'
<?php
/**
 * NodePulse — Domain Seed Entry Point
 * PHP 5.6+
 *
 * Normal visit: status page
 * ?check=1: JSON health check (file presence)
 * ?check=announce: health check + trigger first self-announce
 */

if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    $dir = __DIR__;
    $required = array(
        'api.php', 'verify.php', 'gossip.php', 'maintenance.php',
        'selfannounce.php', 'index.php',
        'node_identity.json', 'node_config.json', 'seeds_origin.json',
        'identity/private.pem', 'identity/public.pem', 'identity/node_id'
    );
    $checks = array();
    $all_ok = true;
    foreach ($required as $f) {
        $exists = file_exists($dir . '/' . $f);
        $checks[] = array('file' => $f, 'ok' => $exists);
        if (!$exists) { $all_ok = false; }
    }
    $identity = null;
    $id_path = $dir . '/node_identity.json';
    if (file_exists($id_path)) {
        $raw = file_get_contents($id_path);
        if ($raw !== false) { $identity = json_decode($raw, true); }
    }
    $result = array(
        'ok' => $all_ok,
        'node_id' => ($identity && isset($identity['node_id'])) ? $identity['node_id'] : null,
        'checks' => $checks,
    );
    if ($_GET['check'] === 'announce' && $all_ok) {
        require_once $dir . '/selfannounce.php';
        np_run_selfannounce();
        $log_file = $dir . '/data/logs/selfannounce.log';
        $log_lines = array();
        if (file_exists($log_file)) {
            $lines = file($log_file);
            if (is_array($lines)) {
                $log_lines = array_slice($lines, -15);
                $log_lines = array_map('trim', $log_lines);
            }
        }
        $result['announce'] = array('done' => true, 'log' => $log_lines);
    }
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$identity = null;
$id_path = __DIR__ . '/node_identity.json';
if (file_exists($id_path)) {
    $raw = file_get_contents($id_path);
    if ($raw !== false) { $identity = json_decode($raw, true); }
}
$node_id = ($identity && isset($identity['node_id'])) ? htmlspecialchars($identity['node_id']) : 'unknown';
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NodePulse Seed</title>
<style>body{background:#0d0f14;color:#e2e8f0;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{text-align:center;padding:40px}h1{color:#10b981;font-size:1.4rem;margin-bottom:16px;letter-spacing:.08em}
.nid{font-family:monospace;color:#10b981;background:#151820;padding:6px 12px;border-radius:6px;font-size:1rem;display:inline-block;margin:8px 0}
p{color:#64748b;font-size:.85rem;margin:6px 0}a{color:#10b981}</style>
</head><body><div class="box">
<h1>NODEPULSE SEED</h1>
<div class="nid"><?php echo $node_id; ?></div>
<p>Type: Domain Seed</p>
<p><a href="api.php?action=peers">API</a></p>
</div></body></html>
DSINDEX;
    $zip->addFromString('nodepulse/index.php', $index_php);

    // -- Identity files --
    $zip->addEmptyDir('nodepulse/identity');
    $zip->addFromString('nodepulse/identity/private.pem', $privkey_pem);
    $zip->addFromString('nodepulse/identity/public.pem', $pubkey_pem);
    $zip->addFromString('nodepulse/identity/node_id', $node_id);
    $zip->addFromString('nodepulse/identity/index.php', "<?php http_response_code(403); exit;\n");
    $zip->addFromString('nodepulse/identity/.htaccess', "<Files \"private.pem\">\n    Order deny,allow\n    Deny from all\n</Files>\n");

    // -- Data directory --
    $zip->addEmptyDir('nodepulse/data');
    $zip->addFromString('nodepulse/data/index.php', "<?php http_response_code(403); exit;\n");
    $zip->addEmptyDir('nodepulse/data/logs');
    $zip->addFromString('nodepulse/data/logs/index.php', "<?php http_response_code(403); exit;\n");
    $zip->addEmptyDir('nodepulse/data/ratelimit');
    $zip->addFromString('nodepulse/data/ratelimit/index.php', "<?php http_response_code(403); exit;\n");

    // -- Update directory --
    $zip->addEmptyDir('nodepulse/update');
    $update_src = $NP_SOURCE_DIR . '/update/update.json';
    if (file_exists($update_src)) {
        $zip->addFile($update_src, 'nodepulse/update/update.json');
    }

    // -- JSON schemas (empty) --
    $zip->addFromString('nodepulse/directory.json',
        json_encode(array('updated_at' => '', 'entries' => array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('nodepulse/peers_online.json',
        json_encode(array('updated_at' => '', 'ttl_hours' => 24, 'peers' => array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('nodepulse/seeds_network.json',
        json_encode(array('updated_at' => '', 'seeds' => array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('nodepulse/seeds_domain.json',
        json_encode(array('updated_at' => '', 'domains' => array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // -- seeds_origin.json (copy from source) --
    $seeds_src = $NP_SOURCE_DIR . '/seeds_origin.json';
    if (file_exists($seeds_src)) {
        $zip->addFile($seeds_src, 'nodepulse/seeds_origin.json');
    }

    // -- node_identity.json (generated) --
    $created_at = gmdate('Y-m-d\TH:i:s\Z');
    $identity = array(
        'node_id'    => $node_id,
        'type'       => 'domain',
        'public_key' => $pubkey_pem,
        'created_at' => $created_at,
        'version'    => '1.0.0',
        'platform'   => 'domain',
    );
    $zip->addFromString('nodepulse/node_identity.json',
        json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // -- node_config.json (generated with domain_url) --
    $config = array(
        'node_id'                   => $node_id,
        'domain_url'                => $domain_url,
        'gossip_interval_sec'       => 300,
        'heartbeat_interval_sec'    => 60,
        'maintenance_interval_sec'  => 100,
        'selfannounce_interval_sec' => 1800,
        'gossip_fanout'             => 3,
        'max_peers'                 => 50,
        'ttl_hours'                 => 24,
        'serve_downloads'           => true,
        'auto_update'               => true,
        'log_level'                 => 'info',
        'rate_limits'               => array(
            'announce'    => array(5, 3600),
            'publish_url' => array(20, 3600),
            'heartbeat'   => array(120, 3600),
            'gossip'      => array(30, 3600),
        ),
    );
    $zip->addFromString('nodepulse/node_config.json',
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $zip->close();

    if (!file_exists($zip_path)) {
        echo json_encode(array('ok' => false, 'message' => 'ZIP file was not created'));
        return;
    }

    echo json_encode(array(
        'ok'           => true,
        'node_id'      => $node_id,
        'domain_url'   => $domain_url,
        'download_url' => '?action=download&file=' . urlencode($zip_filename),
        'filename'     => $zip_filename,
    ));
}

// ============================================================
// DOWNLOAD HANDLER
// ============================================================

function np_ds_handle_download() {
    global $NP_TMP_DIR;

    $file = isset($_GET['file']) ? $_GET['file'] : '';
    // Sanitize: only allow alphanumeric, dash, dot
    if (!preg_match('/^nodepulse-domain-[a-f0-9]{12}\.zip$/', $file)) {
        http_response_code(400);
        echo 'Invalid file name';
        exit;
    }

    $path = $NP_TMP_DIR . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'File not found. It may have expired. Please generate again.';
        exit;
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($path);
    exit;
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Clean temp files older than $max_age seconds.
 */
function np_ds_cleanup_tmp($dir, $max_age) {
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $files = scandir($dir);
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $path = $dir . '/' . $f;
        if (is_file($path) && ($now - filemtime($path)) > $max_age) {
            @unlink($path);
        }
    }
}

// ============================================================
// VERIFY HANDLER
// ============================================================

/**
 * Verify remote deployment and trigger first self-announce.
 * POST domain_url => calls remote index.php?check=announce
 */
function np_ds_handle_verify() {
    $domain_url = isset($_POST['domain_url']) ? trim($_POST['domain_url']) : '';
    if (empty($domain_url)) {
        echo json_encode(array('ok' => false, 'message' => 'Domain URL is required'));
        return;
    }
    $domain_url = rtrim($domain_url, '/');

    // Step 1: call remote health check + announce
    $check_url = $domain_url . '/index.php?check=announce';
    $result = np_ds_http_get($check_url, 30);

    if ($result === null) {
        echo json_encode(array(
            'ok' => false,
            'message' => 'Cannot reach ' . $domain_url . ' — ensure files are uploaded and PHP is running',
        ));
        return;
    }

    $data = json_decode($result, true);
    if (!is_array($data)) {
        echo json_encode(array(
            'ok' => false,
            'message' => 'Invalid response from remote node. Got: ' . substr($result, 0, 300),
        ));
        return;
    }

    // Forward remote response
    echo json_encode($data);
}

/**
 * HTTP GET using curl or file_get_contents.
 *
 * @param string $url     Target URL
 * @param int    $timeout Seconds
 * @return string|null    Response body, or null on failure
 */
function np_ds_http_get($url, $timeout) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NodePulse-DomainSeed/1.0');
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($result === false || $code < 200 || $code >= 300) {
            return null;
        }
        return $result;
    }

    $opts = array(
        'http' => array(
            'method'  => 'GET',
            'timeout' => $timeout,
            'header'  => 'User-Agent: NodePulse-DomainSeed/1.0',
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
// HTML PAGE
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>NodePulse — Domain Seed Registration</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #0d0f14;
            --surface:  #151820;
            --border:   #1e2330;
            --accent:   #10b981;
            --accent-dim: #059669;
            --text:     #e2e8f0;
            --muted:    #64748b;
            --red:      #ef4444;
            --radius:   16px;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px 40px;
        }

        header {
            text-align: center;
            margin-bottom: 36px;
            user-select: none;
        }

        header .logo {
            font-size: clamp(1.4rem, 4vw, 2rem);
            font-weight: 800;
            letter-spacing: 0.12em;
            background: linear-gradient(135deg, var(--accent), #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        header .subtitle {
            margin-top: 6px;
            font-size: .85rem;
            color: var(--muted);
            letter-spacing: .06em;
        }

        .back-link {
            position: absolute;
            top: 16px;
            left: 16px;
            color: var(--muted);
            text-decoration: none;
            font-size: .85rem;
            transition: color .2s;
        }
        .back-link:hover { color: var(--accent); }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px 28px;
            width: 100%;
            max-width: 520px;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text);
        }

        .card-desc {
            font-size: .82rem;
            color: var(--muted);
            line-height: 1.55;
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-size: .78rem;
            color: var(--muted);
            margin-bottom: 6px;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: .9rem;
            outline: none;
            transition: border-color .2s;
        }

        input[type="text"]:focus {
            border-color: var(--accent-dim);
            box-shadow: 0 0 0 2px rgba(16,185,129,.1);
        }

        input[type="text"]::placeholder { color: #2a3a4e; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 20px;
            margin-top: 18px;
            background: linear-gradient(135deg, var(--accent), var(--accent-dim));
            border: none;
            border-radius: 8px;
            color: #fff;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: .03em;
            transition: opacity .2s, transform .1s;
        }

        .btn:hover { opacity: .9; }
        .btn:active { transform: scale(.98); }
        .btn:disabled { opacity: .4; cursor: not-allowed; }

        .btn-download {
            background: linear-gradient(135deg, #0a7cff, #0059b3);
            margin-top: 12px;
            text-decoration: none;
        }

        /* States */
        .state { display: none; }
        .state.active { display: block; }

        /* Error */
        .error-box {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.3);
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
            color: var(--red);
            font-size: .85rem;
        }

        /* Spinner */
        .spinner-area {
            text-align: center;
            padding: 20px 0;
        }

        .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .spinner-text {
            margin-top: 12px;
            font-size: .85rem;
            color: var(--muted);
        }

        /* Result */
        .result-success {
            text-align: center;
        }

        .result-check {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .result-nodeid {
            font-family: 'Courier New', monospace;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent);
            letter-spacing: .1em;
            margin: 8px 0;
        }

        .result-url {
            font-size: .82rem;
            color: var(--muted);
            word-break: break-all;
            margin-bottom: 16px;
        }

        .instructions {
            margin-top: 24px;
            text-align: left;
        }

        .instructions summary {
            cursor: pointer;
            font-size: .85rem;
            color: var(--accent);
            font-weight: 600;
            margin-bottom: 12px;
        }

        .instructions ol {
            padding-left: 20px;
            font-size: .8rem;
            color: var(--muted);
            line-height: 1.8;
        }

        .instructions code {
            background: var(--bg);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: .78rem;
            color: var(--text);
        }

        .btn-again {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--muted);
            margin-top: 16px;
        }
        .btn-again:hover { border-color: var(--accent); color: var(--accent); }

        .btn-verify {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            margin-top: 16px;
        }

        /* Verify section */
        .verify-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 28px 0 20px;
        }

        .verify-title {
            font-size: .95rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--text);
            text-align: left;
        }

        .verify-desc {
            font-size: .78rem;
            color: var(--muted);
            margin-bottom: 16px;
            text-align: left;
        }

        .checklist {
            list-style: none;
            padding: 0;
            text-align: left;
            margin: 16px 0;
        }

        .checklist li {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            font-size: .82rem;
            color: var(--muted);
            border-bottom: 1px solid rgba(30,35,48,.5);
        }

        .checklist li:last-child { border-bottom: none; }

        .check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }

        .check-ok {
            background: rgba(16,185,129,.15);
            color: var(--accent);
        }

        .check-fail {
            background: rgba(239,68,68,.15);
            color: var(--red);
        }

        .check-file {
            font-family: 'Courier New', monospace;
            font-size: .78rem;
            color: var(--text);
        }

        .announce-result {
            margin-top: 16px;
            padding: 14px;
            background: var(--bg);
            border-radius: 8px;
            text-align: left;
        }

        .announce-result .log-title {
            font-size: .82rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .announce-log {
            font-family: 'Courier New', monospace;
            font-size: .72rem;
            color: var(--muted);
            line-height: 1.7;
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .announce-log .log-ok { color: var(--accent); }
        .announce-log .log-warn { color: #f59e0b; }
        .announce-log .log-err { color: var(--red); }

        .verify-summary {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: .85rem;
            font-weight: 600;
            text-align: center;
        }

        .verify-summary.ok {
            background: rgba(16,185,129,.1);
            border: 1px solid rgba(16,185,129,.3);
            color: var(--accent);
        }

        .verify-summary.fail {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.3);
            color: var(--red);
        }

        footer {
            margin-top: 44px;
            font-size: .75rem;
            color: var(--muted);
            text-align: center;
            letter-spacing: .04em;
            opacity: .6;
        }

        @media (max-width: 480px) {
            .back-link { display: none; }
            body { padding: 18px 12px 32px; }
            .card { padding: 24px 18px; }
        }
    </style>
</head>
<body>

    <a href="/" class="back-link">&larr; Dashboard</a>

    <header>
        <div class="logo">NODEPULSE</div>
        <p class="subtitle">Domain Seed Registration</p>
    </header>

    <div class="card">

        <!-- STATE: Input Form -->
        <div id="state-form" class="state active">
            <div class="card-title">Register your domain</div>
            <div class="card-desc">
                Generate a complete NodePulse package for your domain.
                Upload the ZIP contents to your hosting and your site becomes
                a seed node in the network.The last folder path must be named <b>nodepulse</b> otherwise it will not work.
            </div>

            <form id="generate-form" autocomplete="off">
                <label for="domain-url">Your domain URL</label>
                <input type="text" id="domain-url" name="domain_url"
                       placeholder="https://www.example.com/nodepulse"
                       spellcheck="false" autocapitalize="off" required>
                <button type="submit" class="btn" id="btn-generate">
                    Generate Package
                </button>
            </form>

            <div id="error-box" class="error-box" style="display:none;"></div>
        </div>

        <!-- STATE: Generating -->
        <div id="state-generating" class="state">
            <div class="spinner-area">
                <div class="spinner"></div>
                <div class="spinner-text">Generating RSA-2048 keypair and building package...</div>
            </div>
        </div>

        <!-- STATE: Result -->
        <div id="state-result" class="state">
            <div class="result-success">
                <div class="result-check">&#x2705;</div>
                <div class="card-title">Package ready!</div>
                <div class="result-nodeid" id="result-nodeid"></div>
                <div class="result-url" id="result-url"></div>

                <a href="#" id="btn-download" class="btn btn-download">
                    Download ZIP
                </a>

                <details class="instructions">
                    <summary>Deployment instructions</summary>
                    <ol>
                        <li>Extract the ZIP on your hosting at the URL you specified</li>
                        <li>The folder must be named <code>nodepulse</code></li>
                        <li>Ensure PHP 5.6+ is running on your hosting</li>
                        <li>Test: visit <code id="test-url"></code></li>
                        <li>Click <strong>Verify &amp; Announce</strong> below to check</li>
                    </ol>
                </details>

                <hr class="verify-divider">

                <div class="verify-title">Step 2 — Verify &amp; First Announce</div>
                <div class="verify-desc">
                    After uploading the ZIP contents to your hosting, click below to verify
                    all files are in place and perform the first network announcement.
                </div>

                <button class="btn btn-verify" id="btn-verify" onclick="runVerify()">
                    Verify &amp; Announce
                </button>

                <!-- Verify spinner -->
                <div id="verify-spinner" style="display:none;">
                    <div class="spinner-area">
                        <div class="spinner"></div>
                        <div class="spinner-text">Contacting remote node...</div>
                    </div>
                </div>

                <!-- Verify results -->
                <div id="verify-results" style="display:none;">
                    <ul class="checklist" id="verify-checklist"></ul>

                    <div id="announce-result" class="announce-result" style="display:none;">
                        <div class="log-title">Self-Announce Log</div>
                        <div class="announce-log" id="announce-log"></div>
                    </div>

                    <div id="verify-summary" class="verify-summary" style="display:none;"></div>
                </div>

                <button class="btn btn-again" onclick="resetForm()">Generate another</button>
            </div>
        </div>

    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> NODEPULSE
    </footer>

<script>
(function() {
    'use strict';

    var form = document.getElementById('generate-form');
    var input = document.getElementById('domain-url');
    var errorBox = document.getElementById('error-box');
    var btnGenerate = document.getElementById('btn-generate');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        hideError();

        var url = input.value.trim();

        // Validate
        if (url.indexOf('https://') !== 0) {
            showError('URL must start with https://');
            return;
        }
        if (url.length < 15) {
            showError('URL is too short. Example: https://www.example.com/nodepulse');
            return;
        }

        showState('generating');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?action=generate', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.ok) {
                        showResult(data);
                    } else {
                        showState('form');
                        showError(data.message || 'Unknown error');
                    }
                } catch (err) {
                    showState('form');
                    showError('Invalid response from server');
                }
            } else {
                showState('form');
                showError('Server error: HTTP ' + xhr.status);
            }
        };
        xhr.onerror = function() {
            showState('form');
            showError('Network error — check your connection');
        };
        xhr.send('domain_url=' + encodeURIComponent(url));
    });

    function showState(name) {
        var states = document.querySelectorAll('.state');
        for (var i = 0; i < states.length; i++) {
            states[i].classList.remove('active');
        }
        var el = document.getElementById('state-' + name);
        if (el) el.classList.add('active');
    }

    function showResult(data) {
        document.getElementById('result-nodeid').textContent = data.node_id;
        document.getElementById('result-url').textContent = data.domain_url;
        document.getElementById('btn-download').href = data.download_url;
        document.getElementById('btn-download').download = data.filename;
        document.getElementById('test-url').textContent = data.domain_url + '/api.php?action=peers';
        showState('result');
    }

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
    }

    function hideError() {
        errorBox.style.display = 'none';
    }

    window.resetForm = function() {
        input.value = '';
        hideError();
        // Reset verify section
        document.getElementById('verify-spinner').style.display = 'none';
        document.getElementById('verify-results').style.display = 'none';
        document.getElementById('btn-verify').style.display = '';
        showState('form');
        input.focus();
    };

    // ---- Verify & Announce ----

    var _verifyDomainUrl = '';

    // Store domain_url when result is shown
    var origShowResult = showResult;
    showResult = function(data) {
        _verifyDomainUrl = data.domain_url;
        origShowResult(data);
    };

    window.runVerify = function() {
        if (!_verifyDomainUrl) return;

        var btn = document.getElementById('btn-verify');
        var spinner = document.getElementById('verify-spinner');
        var results = document.getElementById('verify-results');

        btn.style.display = 'none';
        spinner.style.display = 'block';
        results.style.display = 'none';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?action=verify', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            spinner.style.display = 'none';

            if (xhr.status === 200) {
                var data = null;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (parseErr) {
                    renderVerifyError('JSON parse error: ' + xhr.responseText.substring(0, 200));
                    return;
                }
                try {
                    renderVerifyResults(data);
                } catch (renderErr) {
                    renderVerifyError('Render error: ' + renderErr.message);
                }
            } else {
                renderVerifyError('Server error: HTTP ' + xhr.status + ' — ' + xhr.responseText.substring(0, 200));
            }
        };
        xhr.onerror = function() {
            spinner.style.display = 'none';
            renderVerifyError('Network error — check your connection');
        };
        xhr.send('domain_url=' + encodeURIComponent(_verifyDomainUrl));
    };

    function renderVerifyResults(data) {
        var results = document.getElementById('verify-results');
        var checklist = document.getElementById('verify-checklist');
        var announceDiv = document.getElementById('announce-result');
        var announceLog = document.getElementById('announce-log');
        var summary = document.getElementById('verify-summary');
        var btn = document.getElementById('btn-verify');

        results.style.display = 'block';
        checklist.innerHTML = '';

        // File checks
        if (data.checks && data.checks.length) {
            for (var i = 0; i < data.checks.length; i++) {
                var c = data.checks[i];
                var li = document.createElement('li');
                var icon = document.createElement('span');
                icon.className = 'check-icon ' + (c.ok ? 'check-ok' : 'check-fail');
                icon.textContent = c.ok ? '\u2713' : '\u2717';
                var fname = document.createElement('span');
                fname.className = 'check-file';
                fname.textContent = c.file;
                li.appendChild(icon);
                li.appendChild(fname);
                checklist.appendChild(li);
            }
        }

        // Announce log
        if (data.announce && data.announce.log && data.announce.log.length) {
            announceDiv.style.display = 'block';
            announceLog.innerHTML = '';
            for (var j = 0; j < data.announce.log.length; j++) {
                var line = data.announce.log[j];
                var span = document.createElement('div');
                if (line.indexOf('[INFO]') !== -1 && line.indexOf(' OK:') !== -1) {
                    span.className = 'log-ok';
                } else if (line.indexOf('[WARN]') !== -1) {
                    span.className = 'log-warn';
                } else if (line.indexOf('[ERROR]') !== -1) {
                    span.className = 'log-err';
                }
                span.textContent = line;
                announceLog.appendChild(span);
            }
        } else {
            announceDiv.style.display = 'none';
        }

        // Summary
        summary.style.display = 'block';
        if (data.ok) {
            var msg = 'All files present';
            if (data.announce && data.announce.done) {
                msg += ' — First announce completed!';
            }
            if (data.node_id) {
                msg += ' (node: ' + data.node_id + ')';
            }
            summary.className = 'verify-summary ok';
            summary.textContent = msg;
        } else if (data.message) {
            summary.className = 'verify-summary fail';
            summary.textContent = data.message;
            btn.style.display = '';
            btn.textContent = 'Retry';
        } else {
            summary.className = 'verify-summary fail';
            summary.textContent = 'Some files are missing — check the list above';
            btn.style.display = '';
            btn.textContent = 'Retry';
        }
    }

    function renderVerifyError(msg) {
        var results = document.getElementById('verify-results');
        var summary = document.getElementById('verify-summary');
        var btn = document.getElementById('btn-verify');

        results.style.display = 'block';
        document.getElementById('verify-checklist').innerHTML = '';
        document.getElementById('announce-result').style.display = 'none';

        summary.style.display = 'block';
        summary.className = 'verify-summary fail';
        summary.textContent = msg;

        btn.style.display = '';
        btn.textContent = 'Retry';
    }

})();
</script>
<script src="/nodepulse-sw.js"></script>
</body>
</html>
