/**
 * NodePulse — SW Registration + Connectivity Monitor
 * Include in ogni pagina: <script src="/nodepulse-sw.js"></script>
 *
 * 1. Registers the Service Worker with scope: /
 * 2. Periodically pings /beacon/?ping to detect tunnel death
 * 3. After 2 consecutive failures → saves return path → redirects to /beacon/
 */
(function () {
    'use strict';

    // Skip if we are already on the beacon page
    if (window.location.pathname.indexOf('/beacon') === 0) return;

    // ── SW Registration ─────────────────────────────────────────────
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/beacon/?sw', { scope: '/' })
            .catch(function () {});
    }

    // ── Connectivity Monitor ────────────────────────────────────────
    var PING_URL = '/beacon/?ping&_t=';
    var PING_INTERVAL = 5000;    // 5 seconds
    var FAIL_THRESHOLD = 2;      // redirect after 2 consecutive failures
    var PING_TIMEOUT = 5000;     // 5s timeout per ping
    var LS_KEY = 'nodepulse_return_path';

    var failCount = 0;
    var timer = null;

    function doPing() {
        var controller = null;
        var signal = undefined;
        var timeoutId = null;

        // Use AbortController if available (modern browsers)
        if (typeof AbortController !== 'undefined') {
            controller = new AbortController();
            signal = controller.signal;
            timeoutId = setTimeout(function () { controller.abort(); }, PING_TIMEOUT);
        }

        var opts = { method: 'GET', cache: 'no-store' };
        if (signal) opts.signal = signal;

        fetch(PING_URL + Date.now(), opts)
            .then(function (resp) {
                if (timeoutId) clearTimeout(timeoutId);
                if (resp.ok) {
                    failCount = 0;
                    return;
                }
                // 5xx = server/tunnel error
                if (resp.status >= 500) {
                    onFail();
                }
            })
            .catch(function () {
                if (timeoutId) clearTimeout(timeoutId);
                onFail();
            });
    }

    function onFail() {
        failCount++;
        if (failCount >= FAIL_THRESHOLD) {
            goToBeacon();
        }
    }

    function goToBeacon() {
        if (timer) clearInterval(timer);

        // Save current path so beacon/app.js can redirect back after recovery
        var returnPath = window.location.pathname + window.location.search + window.location.hash;
        try {
            localStorage.setItem(LS_KEY, returnPath);
        } catch (e) {}

        // Redirect to beacon
        window.location.href = '/beacon/';
    }

    // Start polling after a short initial delay
    setTimeout(function () {
        doPing();
        timer = setInterval(doPing, PING_INTERVAL);
    }, 3000);

    // Also stop polling if the page is hidden (save battery on mobile)
    if (typeof document.addEventListener === 'function') {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                if (timer) { clearInterval(timer); timer = null; }
            } else {
                if (!timer) {
                    doPing();
                    timer = setInterval(doPing, PING_INTERVAL);
                }
            }
        });
    }
})();
