<?php
/**
 * NodePulse — Entry Point
 * Query-string routing for Cloudflare Tunnel compatibility
 */

$qs = $_SERVER['QUERY_STRING'] ?? '';

// ─── ROUTE: Ping (online check, bypasses SW cache) ─────────────────
// CORS needed: recovery browser on old tunnel verifies new tunnel cross-origin
if ($qs === 'ping' || isset($_GET['ping'])) {
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo '{"ok":true}';
    exit;
}

// ─── ROUTE: Service Worker ─────────────────────────────────────────
if ($qs === 'sw' || isset($_GET['sw'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Service-Worker-Allowed: /');
    $swFile = __DIR__ . '/sw.js';
    if (file_exists($swFile)) {
        readfile($swFile);
    } else {
        echo '// sw.js not found';
    }
    exit;
}

// ─── ROUTE: Nodes JSON ─────────────────────────────────────────────
if ($qs === 'nodes' || isset($_GET['nodes'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $nodesFile = __DIR__ . '/nodes.json';
    if (file_exists($nodesFile)) {
        readfile($nodesFile);
    } else {
        echo '{"last_id":0,"nodes":[]}';
    }
    exit;
}

// ─── Read nodes.json inline for first load ──────────────────────────
$nodesInline = '{"last_id":0,"nodes":[]}';
$nodesFile = __DIR__ . '/nodes.json';
if (file_exists($nodesFile)) {
    $nodesInline = file_get_contents($nodesFile);
}

// ─── Read seed list for recovery (Fase 3) ────────────────────────────
// Priority: ../nodepulse/seeds_origin.json (deployed alongside), then hardcoded fallback
$seedUrls = array();
$seedsFile = dirname(__DIR__) . '/nodepulse/seeds_origin.json';
if (file_exists($seedsFile)) {
    $seedsData = json_decode(file_get_contents($seedsFile), true);
    if ($seedsData && isset($seedsData['seeds']) && is_array($seedsData['seeds'])) {
        foreach ($seedsData['seeds'] as $s) {
            if (isset($s['url']) && (!isset($s['status']) || $s['status'] === 'active')) {
                $seedUrls[] = $s['url'];
            }
        }
    }
}
// Fallback: hardcoded Original Seeds (always present as safety net)
if (empty($seedUrls)) {
    $seedUrls = array(
        'https://www.plexum.net/nodepulse',
        'https://www.paraplant.com/nodepulse',
        'https://www.n-excelsior.com/nodepulse'
    );
}

// ─── Read this node's identity (node_id) ─────────────────────────────
$nodeId = '';
$identityFile = dirname(__DIR__) . '/nodepulse/node_identity.json';
if (file_exists($identityFile)) {
    $idData = json_decode(file_get_contents($identityFile), true);
    if ($idData && isset($idData['node_id']) && $idData['node_id'] !== '') {
        $nodeId = $idData['node_id'];
    }
}
// Fallback: ~/.nodepulse/node_id
if ($nodeId === '') {
    $homeNodeId = (isset($_SERVER['HOME']) ? $_SERVER['HOME'] : getenv('HOME')) . '/.nodepulse/node_id';
    if (file_exists($homeNodeId)) {
        $nodeId = trim(file_get_contents($homeNodeId));
    }
}

// Build bootstrap JSON for app.js
$returnPath = isset($_GET['return_path']) ? $_GET['return_path'] : '';
$bootstrapData = json_encode(array(
    'seed_urls'   => $seedUrls,
    'node_id'     => $nodeId,
    'return_path' => $returnPath,
), JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NodePulse — Status Monitor</title>
    <link rel="stylesheet" href="/beacon/style.css">
</head>
<body>

    <header>
        <div class="logo">NodePulse Monitor</div>
        <h1 id="status-display">ONLINE</h1>
        <h2 id="last-node">Loading nodes...</h2>
    </header>

    <div id="info-panel">
        <div class="panel-title">Node Info</div>
        <div class="info-row"><span class="info-label">Current domain</span><span id="info-domain">—</span></div>
        <div class="info-row"><span class="info-label">Node ID</span><span id="info-node-id">—</span></div>
        <div class="info-row"><span class="info-label">Protocol</span><span id="info-protocol">—</span></div>
        <div class="info-row"><span class="info-label">Connected at</span><span id="info-connected">—</span></div>
        <div class="info-row"><span class="info-label">Status</span><span id="info-status">—</span></div>
        <div class="info-row"><span class="info-label">Known seeds</span><span id="info-seeds">—</span></div>
        <div class="info-row"><span class="info-label">Known peers</span><span id="info-peers">—</span></div>
        <div class="info-row"><span class="info-label">Cached nodes</span><span id="info-cached">—</span></div>
        <div class="info-row"><span class="info-label">Service Worker</span><span id="info-sw">—</span></div>
        <div class="info-row"><span class="info-label">Last check</span><span id="info-lastcheck">—</span></div>
    </div>

    <div id="recovery-panel">
        <div class="panel-title">Recovery Mode</div>
        <div id="recovery-log"></div>
        <button id="scan-again-btn" style="display:none;margin:12px auto 0;padding:10px 28px;background:#10b981;color:#0a0a0a;border:none;border-radius:6px;font-size:.95rem;font-weight:600;cursor:pointer;">Scan Again</button>
    </div>

    <script id="nodes-bootstrap" type="application/json"><?= $nodesInline ?></script>
    <script id="nodepulse-bootstrap" type="application/json"><?= $bootstrapData ?></script>
    <script src="/beacon/style.js"></script>
    <script src="/beacon/app.js"></script>
</body>
</html>
