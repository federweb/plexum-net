/**
 * NodePulse — App Controller (Fase 3: Recovery Browser)
 *
 * Recovery logic:
 *   1. Read node_id from bootstrap (injected by PHP) or localStorage
 *   2. Build seed list: localStorage seeds > PHP-injected seeds > hardcoded fallback
 *   3. For each seed (random order):
 *      GET {seed}/api.php?action=lookup&node_id=xxx
 *      If found → verify URL (HEAD) → redirect
 *   4. Save discovered peers in localStorage for future recovery
 *
 * Progressive enrichment (when ONLINE):
 *   - Periodically fetch peers_online.json and seeds from current seed
 *   - Merge into localStorage for broader recovery coverage
 */

(function () {
    'use strict';

    // =====================================================================
    // CONFIGURATION
    // =====================================================================
    var CONFIG = {
        ONLINE_CHECK_INTERVAL: 10000,       // 10s — ping polling
        RECOVERY_BASE_INTERVAL: 15000,      // 15s — first recovery wait
        RECOVERY_BACKOFF_FACTOR: 2,         // double each cycle
        RECOVERY_MAX_INTERVAL: 120000,      // 2min — cap
        RECOVERY_MAX_CYCLES: 5,             // stop after 5 full cycles
        ENRICHMENT_INTERVAL: 300000,        // 5min — seed/peer enrichment
        REDIRECT_DELAY: 5000,               // 5s  — delay before redirect
        LOOKUP_TIMEOUT: 8000,               // 8s  — timeout per seed lookup
        HEAD_TIMEOUT: 6000,                 // 6s  — timeout for URL verification
        LS_KEY_NODES: 'nodepulse_nodes_data',
        LS_KEY_FULL: 'nodepulse_full_url',
        LS_KEY_NODE_ID: 'nodepulse_node_id',
        LS_KEY_SEEDS: 'nodepulse_known_seeds',
        LS_KEY_PEERS: 'nodepulse_known_peers',
        LS_KEY_RETURN_PATH: 'nodepulse_return_path',
        // Hardcoded fallback — always available even without PHP or localStorage
        FALLBACK_SEEDS: [
            'https://www.plexum.net/nodepulse',
            'https://www.paraplant.com/nodepulse'
        ],
    };

    // =====================================================================
    // DOM REFERENCES
    // =====================================================================
    var DOM = {
        statusDisplay: document.getElementById('status-display'),
        lastNode: document.getElementById('last-node'),
        infoDomain: document.getElementById('info-domain'),
        infoNodeId: document.getElementById('info-node-id'),
        infoProtocol: document.getElementById('info-protocol'),
        infoConnected: document.getElementById('info-connected'),
        infoStatus: document.getElementById('info-status'),
        infoSeeds: document.getElementById('info-seeds'),
        infoPeers: document.getElementById('info-peers'),
        infoCached: document.getElementById('info-cached'),
        infoSw: document.getElementById('info-sw'),
        infoLastcheck: document.getElementById('info-lastcheck'),
        recoveryPanel: document.getElementById('recovery-panel'),
        recoveryLog: document.getElementById('recovery-log'),
    };

    // =====================================================================
    // STATE
    // =====================================================================
    var state = {
        isOnline: navigator.onLine,
        nodesData: null,
        nodeId: '',                 // this node's identity
        seedList: [],               // merged seed URLs (from all sources)
        currentPath: window.location.origin + window.location.pathname,
        onlineCheckTimer: null,
        recoveryTimer: null,
        enrichmentTimer: null,
        recoveryActive: false,
        recoveryInProgress: false,  // prevents overlapping recovery cycles
        recoveryCycle: 0,           // current cycle number (for backoff)
        returnPath: '',             // path where user was when tunnel died
    };

    // =====================================================================
    // 1. BOOTSTRAP — Load node_id and seed list from all sources
    // =====================================================================
    function loadBootstrap() {
        // --- node_id ---
        // Priority 1: PHP-injected bootstrap
        var bootstrap = parseJsonTag('nodepulse-bootstrap');
        if (bootstrap && bootstrap.node_id) {
            state.nodeId = bootstrap.node_id;
        }
        // Priority 2: localStorage
        if (!state.nodeId) {
            state.nodeId = lsGet(CONFIG.LS_KEY_NODE_ID) || '';
        }
        // Save for future recovery
        if (state.nodeId) {
            lsSet(CONFIG.LS_KEY_NODE_ID, state.nodeId);
            if (DOM.infoNodeId) DOM.infoNodeId.textContent = state.nodeId;
        }

        // --- seed list ---
        // Merge from 3 sources, deduplicated
        var lsSeeds = lsGetJson(CONFIG.LS_KEY_SEEDS) || [];
        var phpSeeds = (bootstrap && bootstrap.seed_urls) ? bootstrap.seed_urls : [];
        var fallbackSeeds = CONFIG.FALLBACK_SEEDS;

        // Priority order: localStorage (most recent) > PHP-injected > hardcoded
        state.seedList = dedup(lsSeeds.concat(phpSeeds).concat(fallbackSeeds));

        updateSeedsPeersUI();
        console.log('[NodePulse] node_id:', state.nodeId || '(none)');
        console.log('[NodePulse] Seeds loaded:', state.seedList.length);
    }

    // =====================================================================
    // 2. SERVICE WORKER
    // =====================================================================
    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            if (DOM.infoSw) DOM.infoSw.textContent = 'Not supported';
            return;
        }

        navigator.serviceWorker.register('/beacon/?sw', { scope: '/' })
            .then(function (reg) {
                console.log('[SW] Registered, scope:', reg.scope);
                if (reg.active) {
                    if (DOM.infoSw) DOM.infoSw.textContent = 'Active';
                } else {
                    if (DOM.infoSw) DOM.infoSw.textContent = 'Installing...';
                    var sw = reg.installing || reg.waiting;
                    if (sw) {
                        sw.addEventListener('statechange', function () {
                            if (sw.state === 'activated') {
                                if (DOM.infoSw) DOM.infoSw.textContent = 'Active';
                            }
                        });
                    }
                }
            })
            .catch(function (err) {
                console.warn('[SW] Registration failed:', err.message);
                if (DOM.infoSw) DOM.infoSw.textContent = 'Error: ' + err.message;
            });
    }

    // =====================================================================
    // 3. ONLINE / OFFLINE STATUS
    // =====================================================================
    function updateStatus(online) {
        state.isOnline = online;
        if (DOM.statusDisplay) DOM.statusDisplay.textContent = online ? 'ONLINE' : 'OFFLINE';
        if (DOM.infoLastcheck) DOM.infoLastcheck.textContent = new Date().toLocaleTimeString();

        if (!online && !state.recoveryActive) {
            startRecoveryMode();
        } else if (online && state.recoveryActive) {
            stopRecoveryMode();
            autoReturnIfNeeded();
        }
    }

    function autoReturnIfNeeded() {
        var returnPath = state.returnPath || lsGet(CONFIG.LS_KEY_RETURN_PATH) || '';
        if (!returnPath || returnPath === '/' || returnPath === '/beacon/') return;

        // Re-show recovery panel for the redirect message
        if (DOM.recoveryPanel) DOM.recoveryPanel.classList.add('active');
        addRecoveryLog('Connection restored!', 'found');
        addRecoveryLog('Returning to ' + returnPath + ' in 3s...', 'redirect');

        setTimeout(function () {
            try { localStorage.removeItem(CONFIG.LS_KEY_RETURN_PATH); } catch(e) {}
            window.location.href = returnPath;
        }, CONFIG.REDIRECT_DELAY);
    }

    function checkOnlineStatus() {
        fetch('/beacon/?ping&_t=' + Date.now(), {
            cache: 'no-store',
            signal: AbortSignal.timeout(5000),
        })
        .then(function (resp) {
            if (!resp.ok) { updateStatus(false); return; }
            return resp.json();
        })
        .then(function (data) {
            if (data) updateStatus(data.ok === true);
        })
        .catch(function () {
            updateStatus(false);
        });
    }

    function startOnlinePolling() {
        checkOnlineStatus();
        state.onlineCheckTimer = setInterval(checkOnlineStatus, CONFIG.ONLINE_CHECK_INTERVAL);
        window.addEventListener('online', function () { checkOnlineStatus(); });
        window.addEventListener('offline', function () { updateStatus(false); });
    }

    // =====================================================================
    // 4. NODES DATA — First load from inline, then localStorage
    // =====================================================================
    function loadNodesData() {
        var data = null;

        // Try from inline tag injected by PHP
        data = parseJsonTag('nodes-bootstrap');
        if (data) {
            console.log('[NODES] Loaded from inline bootstrap');
        }

        // Fallback: localStorage
        if (!data) {
            data = lsGetJson(CONFIG.LS_KEY_NODES);
            if (data) console.log('[NODES] Loaded from localStorage');
        }

        if (data && data.nodes && data.nodes.length > 0) {
            state.nodesData = data;
            displayLastNode(data);
            saveNodesToLocalStorage(data);
        } else {
            if (DOM.lastNode) DOM.lastNode.textContent = 'No nodes available';
            if (DOM.infoCached) DOM.infoCached.textContent = 'Empty';
        }
    }

    function displayLastNode(data) {
        var lastNode = data.nodes[data.nodes.length - 1];
        if (DOM.lastNode) DOM.lastNode.textContent = lastNode.url;
        if (DOM.infoDomain) DOM.infoDomain.textContent = state.currentPath;
        if (DOM.infoProtocol) DOM.infoProtocol.textContent = lastNode.protocol.toUpperCase();
        if (DOM.infoConnected) DOM.infoConnected.textContent = formatDate(lastNode.connected_at);
        if (DOM.infoStatus) DOM.infoStatus.textContent = lastNode.status;
    }

    function saveNodesToLocalStorage(data) {
        try {
            lsSet(CONFIG.LS_KEY_NODES, JSON.stringify(data));
            lsSet(CONFIG.LS_KEY_FULL, state.currentPath);
            var n = data.nodes ? data.nodes.length : 0;
            if (DOM.infoCached) DOM.infoCached.textContent = n + ' nodes saved';
        } catch (err) {
            console.warn('[LS] Save error:', err);
            if (DOM.infoCached) DOM.infoCached.textContent = 'Error';
        }
    }

    // =====================================================================
    // 5. RECOVERY MODE — Multi-seed lookup by node_id
    // =====================================================================
    function startRecoveryMode() {
        state.recoveryActive = true;
        state.recoveryCycle = 0;
        if (DOM.recoveryPanel) DOM.recoveryPanel.classList.add('active');
        if (DOM.recoveryLog) DOM.recoveryLog.innerHTML = '';
        hideScanButton();

        addRecoveryLog('Recovery mode activated', 'requesting');
        if (state.returnPath) {
            addRecoveryLog('Return to: ' + state.returnPath, 'requesting');
        }

        if (!state.nodeId) {
            addRecoveryLog('No node_id available — cannot query seeds', 'unreachable');
            addRecoveryLog('Waiting for node registration...', 'requesting');
            state.recoveryTimer = setTimeout(function checkId() {
                state.nodeId = lsGet(CONFIG.LS_KEY_NODE_ID) || '';
                if (state.nodeId) {
                    startRecoveryMode();
                } else {
                    state.recoveryTimer = setTimeout(checkId, CONFIG.RECOVERY_BASE_INTERVAL);
                }
            }, CONFIG.RECOVERY_BASE_INTERVAL);
            return;
        }

        addRecoveryLog('Node ID: ' + state.nodeId, 'requesting');
        addRecoveryLog('Seeds available: ' + state.seedList.length, 'requesting');

        // First cycle runs immediately
        runRecoveryCycle();
    }

    function scheduleNextRecovery() {
        if (!state.recoveryActive) return;

        if (state.recoveryCycle >= CONFIG.RECOVERY_MAX_CYCLES) {
            addRecoveryLog('Scan paused after ' + state.recoveryCycle + ' cycles', 'unreachable');
            showScanButton();
            return;
        }

        // Backoff: base * factor^(cycle-1), capped at max
        var delay = Math.min(
            CONFIG.RECOVERY_BASE_INTERVAL * Math.pow(CONFIG.RECOVERY_BACKOFF_FACTOR, state.recoveryCycle - 1),
            CONFIG.RECOVERY_MAX_INTERVAL
        );
        var delaySec = Math.round(delay / 1000);
        addRecoveryLog('Next scan in ' + delaySec + 's...', 'requesting');

        state.recoveryTimer = setTimeout(runRecoveryCycle, delay);
    }

    function stopRecoveryMode() {
        state.recoveryActive = false;
        state.recoveryInProgress = false;
        state.recoveryCycle = 0;
        if (DOM.recoveryPanel) DOM.recoveryPanel.classList.remove('active');
        hideScanButton();
        if (state.recoveryTimer) {
            clearTimeout(state.recoveryTimer);
            state.recoveryTimer = null;
        }
    }

    function showScanButton() {
        var btn = document.getElementById('scan-again-btn');
        if (btn) btn.style.display = 'inline-block';
    }

    function hideScanButton() {
        var btn = document.getElementById('scan-again-btn');
        if (btn) btn.style.display = 'none';
    }

    function onScanAgain() {
        hideScanButton();
        state.recoveryCycle = 0;
        state.recoveryInProgress = false;
        addRecoveryLog('── Manual scan restart ──', 'requesting');
        runRecoveryCycle();
    }

    function runRecoveryCycle() {
        if (state.recoveryInProgress) return;
        state.recoveryInProgress = true;
        state.recoveryCycle++;

        // Build lookup targets: seeds + known peers (all can have directory data)
        var targets = shuffleArray(state.seedList.slice());
        // Also add known peers as lookup targets (they have directory.json too)
        var knownPeers = lsGetJson(CONFIG.LS_KEY_PEERS) || [];
        if (knownPeers.length > 0) {
            var peerUrls = [];
            for (var i = 0; i < knownPeers.length; i++) {
                if (knownPeers[i].url) peerUrls.push(knownPeers[i].url);
            }
            targets = targets.concat(shuffleArray(peerUrls));
            targets = dedup(targets);
        }

        addRecoveryLog('Cycle ' + state.recoveryCycle + '/' + CONFIG.RECOVERY_MAX_CYCLES + ' — querying ' + targets.length + ' targets...', 'requesting');
        queryNextSeed(targets, 0);
    }

    function queryNextSeed(targets, index) {
        if (!state.recoveryActive) {
            state.recoveryInProgress = false;
            return;
        }

        if (index >= targets.length) {
            addRecoveryLog('All targets queried — no new URL found', 'unreachable');
            state.recoveryInProgress = false;
            scheduleNextRecovery();
            return;
        }

        var seedUrl = targets[index];
        var lookupUrl = seedUrl + '/api.php?action=lookup&node_id=' + encodeURIComponent(state.nodeId) + '&_t=' + Date.now();

        addRecoveryLog('Asking: ' + shortenUrl(seedUrl), 'requesting');

        fetch(lookupUrl, {
            cache: 'no-store',
            signal: AbortSignal.timeout(CONFIG.LOOKUP_TIMEOUT),
        })
        .then(function (resp) {
            if (!resp.ok) {
                addRecoveryLog(shortenUrl(seedUrl) + ' — HTTP ' + resp.status, 'unreachable');
                queryNextSeed(targets, index + 1);
                return null;
            }
            return resp.json();
        })
        .then(function (data) {
            if (!data) return; // already handled above

            if (data.ok && data.entry && data.entry.url) {
                var newUrl = data.entry.url;

                // Don't redirect to the same URL we're already on
                if (normalizeUrl(newUrl) === normalizeUrl(state.currentPath)) {
                    addRecoveryLog('Found same URL (not moved) — trying next', 'unreachable');
                    queryNextSeed(targets, index + 1);
                    return;
                }

                addRecoveryLog('New URL found: ' + newUrl, 'found');
                verifyAndRedirect(newUrl, targets, index);
            } else {
                addRecoveryLog(shortenUrl(seedUrl) + ' — node not found', 'unreachable');
                queryNextSeed(targets, index + 1);
            }
        })
        .catch(function (err) {
            addRecoveryLog(shortenUrl(seedUrl) + ' — ' + err.message, 'unreachable');
            queryNextSeed(targets, index + 1);
        });
    }

    function verifyAndRedirect(newUrl, targets, lastIndex) {
        // Verify with ?ping — NOT HEAD no-cors (Cloudflare returns 200 even for dead tunnels)
        // ?ping returns {"ok":true} only if NodePulse is actually running
        var baseUrl = newUrl.replace(/\/+$/, '');
        // Strip /nodepulse suffix: directory stores full nodepulse path,
        // but beacon ping and redirect must target the server root URL.
        var rootUrl = baseUrl.replace(/\/nodepulse$/, '');
        var pingUrl = rootUrl + '/beacon/?ping&_t=' + Date.now();
        addRecoveryLog('Pinging: ' + shortenUrl(rootUrl), 'requesting');

        fetch(pingUrl, {
            cache: 'no-store',
            signal: AbortSignal.timeout(CONFIG.HEAD_TIMEOUT),
        })
        .then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        })
        .then(function (data) {
            if (!data || data.ok !== true) {
                throw new Error('Invalid ping response');
            }

            addRecoveryLog('Node alive! Ping OK', 'found');
            addRecoveryLog('Redirecting in 3 seconds...', 'redirect');

            // Stop all polling
            if (state.recoveryTimer) {
                clearInterval(state.recoveryTimer);
                state.recoveryTimer = null;
            }
            state.recoveryInProgress = false;

            // Save root URL (without /nodepulse)
            lsSet(CONFIG.LS_KEY_FULL, rootUrl);

            setTimeout(function () {
                var finalUrl = rootUrl;
                if (state.returnPath && state.returnPath !== '/' && state.returnPath !== '/beacon/') {
                    finalUrl = finalUrl + state.returnPath;
                }
                // Clean return path
                try { localStorage.removeItem(CONFIG.LS_KEY_RETURN_PATH); } catch(e) {}
                window.location.href = finalUrl;
            }, CONFIG.REDIRECT_DELAY);
        })
        .catch(function (err) {
            addRecoveryLog('Tunnel dead (' + err.message + ') — trying next', 'unreachable');
            // Continue to next seed instead of stopping
            if (targets && typeof lastIndex === 'number') {
                queryNextSeed(targets, lastIndex + 1);
            } else {
                state.recoveryInProgress = false;
            }
        });
    }

    // =====================================================================
    // 6. PROGRESSIVE ENRICHMENT — Build seed/peer knowledge when online
    // =====================================================================
    function startEnrichment() {
        // Run immediately, then periodically
        runEnrichment();
        state.enrichmentTimer = setInterval(runEnrichment, CONFIG.ENRICHMENT_INTERVAL);
    }

    function stopEnrichment() {
        if (state.enrichmentTimer) {
            clearInterval(state.enrichmentTimer);
            state.enrichmentTimer = null;
        }
    }

    function runEnrichment() {
        if (!state.isOnline || state.seedList.length === 0) return;

        // Pick a random seed to query
        var seed = state.seedList[Math.floor(Math.random() * state.seedList.length)];

        // Fetch peers_online.json from the seed
        fetch(seed + '/api.php?action=peers&_t=' + Date.now(), {
            cache: 'no-store',
            signal: AbortSignal.timeout(8000),
        })
        .then(function (resp) { return resp.ok ? resp.json() : null; })
        .then(function (data) {
            if (data && data.peers && data.peers.length > 0) {
                mergePeersToLocalStorage(data.peers);
            }
        })
        .catch(function () { /* silent — enrichment is best-effort */ });

        // Fetch seeds list from the seed
        fetch(seed + '/api.php?action=seeds&_t=' + Date.now(), {
            cache: 'no-store',
            signal: AbortSignal.timeout(8000),
        })
        .then(function (resp) { return resp.ok ? resp.json() : null; })
        .then(function (data) {
            if (data && data.seeds && data.seeds.length > 0) {
                mergeSeedsToLocalStorage(data.seeds);
            }
        })
        .catch(function () { /* silent */ });
    }

    function mergePeersToLocalStorage(remotePeers) {
        var existing = lsGetJson(CONFIG.LS_KEY_PEERS) || [];
        var map = {};
        var i;

        // Index existing by node_id
        for (i = 0; i < existing.length; i++) {
            map[existing[i].node_id] = existing[i];
        }

        // Merge remote — keep more recent last_seen
        for (i = 0; i < remotePeers.length; i++) {
            var peer = remotePeers[i];
            if (!peer.node_id || !peer.url) continue;
            var ex = map[peer.node_id];
            if (!ex || (peer.last_seen && (!ex.last_seen || peer.last_seen > ex.last_seen))) {
                map[peer.node_id] = { node_id: peer.node_id, url: peer.url, last_seen: peer.last_seen || '' };
            }
        }

        // Convert back to array, sort by last_seen desc, cap at 50
        var merged = [];
        for (var nid in map) {
            if (map.hasOwnProperty(nid)) merged.push(map[nid]);
        }
        merged.sort(function (a, b) { return (b.last_seen || '').localeCompare(a.last_seen || ''); });
        if (merged.length > 50) merged = merged.slice(0, 50);

        lsSet(CONFIG.LS_KEY_PEERS, JSON.stringify(merged));
        updateSeedsPeersUI();
        console.log('[ENRICHMENT] Peers merged:', merged.length);
    }

    function mergeSeedsToLocalStorage(remoteSeeds) {
        var existing = lsGetJson(CONFIG.LS_KEY_SEEDS) || [];
        var urlSet = {};
        var i;

        for (i = 0; i < existing.length; i++) {
            urlSet[normalizeUrl(existing[i])] = existing[i];
        }

        for (i = 0; i < remoteSeeds.length; i++) {
            var s = remoteSeeds[i];
            var url = s.url || s;
            if (typeof url === 'string' && url.length > 0) {
                var norm = normalizeUrl(url);
                if (!urlSet[norm]) {
                    urlSet[norm] = url;
                }
            }
        }

        var merged = [];
        for (var key in urlSet) {
            if (urlSet.hasOwnProperty(key)) merged.push(urlSet[key]);
        }

        lsSet(CONFIG.LS_KEY_SEEDS, JSON.stringify(merged));

        // Update runtime seed list
        state.seedList = dedup(merged.concat(CONFIG.FALLBACK_SEEDS));
        updateSeedsPeersUI();
        console.log('[ENRICHMENT] Seeds merged:', merged.length);
    }

    function updateSeedsPeersUI() {
        if (DOM.infoSeeds) DOM.infoSeeds.textContent = state.seedList.length;
        var peers = lsGetJson(CONFIG.LS_KEY_PEERS) || [];
        if (DOM.infoPeers) DOM.infoPeers.textContent = peers.length;
    }

    // =====================================================================
    // UTILITY
    // =====================================================================
    function addRecoveryLog(message, type) {
        var entry = document.createElement('div');
        entry.className = 'log-entry ' + (type || '');

        var time = document.createElement('span');
        time.className = 'time';
        time.textContent = new Date().toLocaleTimeString();

        var icons = { requesting: '... ', unreachable: 'x ', found: '+ ', redirect: '> ' };
        var text = document.createElement('span');
        text.textContent = (icons[type] || '') + message;

        entry.appendChild(time);
        entry.appendChild(text);
        if (DOM.recoveryLog) {
            DOM.recoveryLog.appendChild(entry);
            DOM.recoveryLog.scrollTop = DOM.recoveryLog.scrollHeight;
        }
    }

    function parseJsonTag(id) {
        try {
            var el = document.getElementById(id);
            if (el) return JSON.parse(el.textContent);
        } catch (err) {
            console.warn('[PARSE] Failed for #' + id + ':', err);
        }
        return null;
    }

    function normalizeUrl(url) {
        return (url || '').replace(/\/+$/, '').toLowerCase();
    }

    function shortenUrl(url) {
        // Show just the domain for log readability
        try {
            var u = new URL(url);
            return u.hostname;
        } catch (e) {
            return url.substring(0, 40);
        }
    }

    function formatDate(iso) {
        try {
            var d = new Date(iso);
            return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
        } catch (e) { return iso; }
    }

    function shuffleArray(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = arr[i];
            arr[i] = arr[j];
            arr[j] = tmp;
        }
        return arr;
    }

    function dedup(arr) {
        var seen = {};
        var result = [];
        for (var i = 0; i < arr.length; i++) {
            var key = normalizeUrl(arr[i]);
            if (!seen[key]) {
                seen[key] = true;
                result.push(arr[i]);
            }
        }
        return result;
    }

    // localStorage helpers (safe wrappers)
    function lsGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }
    function lsSet(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* ignore */ }
    }
    function lsGetJson(key) {
        try {
            var raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    // =====================================================================
    // RETURN PATH DETECTION
    // =====================================================================
    function detectReturnPath() {
        var currentPath = window.location.pathname;

        // If NOT on /beacon/, the SW served us as fallback
        // window.location.pathname contains the user's original path
        if (currentPath.indexOf('/beacon') !== 0) {
            state.returnPath = currentPath + window.location.search + window.location.hash;
            lsSet(CONFIG.LS_KEY_RETURN_PATH, state.returnPath);
            console.log('[NodePulse] Return path detected:', state.returnPath);
            return;
        }

        // If on /beacon/, check query string ?return_path=
        var params = new URLSearchParams(window.location.search);
        var rp = params.get('return_path');
        if (rp) {
            state.returnPath = rp;
            lsSet(CONFIG.LS_KEY_RETURN_PATH, rp);
            return;
        }

        // Fallback: localStorage
        state.returnPath = lsGet(CONFIG.LS_KEY_RETURN_PATH) || '';
    }

    // =====================================================================
    // INIT
    // =====================================================================
    function init() {
        console.log('[NodePulse] Init — Fase 3 Recovery Browser');
        if (DOM.infoDomain) DOM.infoDomain.textContent = state.currentPath;

        loadBootstrap();
        detectReturnPath();
        registerServiceWorker();
        loadNodesData();
        startOnlinePolling();
        startEnrichment();

        // Scan Again button
        var scanBtn = document.getElementById('scan-again-btn');
        if (scanBtn) scanBtn.addEventListener('click', onScanAgain);

        console.log('[NodePulse] Ready.');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
