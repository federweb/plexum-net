/**
 * NodePulse — Beacon UI Enhancements
 * - Wraps panels in a .panels-row for side-by-side desktop layout
 * - Syncs #status-display class (online/offline) for CSS coloring
 * - Injects descriptive banner about the decentralized network
 * - Appends a blinking cursor inside the recovery terminal
 */

(function () {
    'use strict';

    // ── Wrap panels in a flex row container ───────────────────────────────

    var infoPanel = document.getElementById('info-panel');
    var recoveryPanel = document.getElementById('recovery-panel');

    if (infoPanel && recoveryPanel) {
        var row = document.createElement('div');
        row.className = 'panels-row';
        infoPanel.parentNode.insertBefore(row, infoPanel);
        row.appendChild(recoveryPanel);
        row.appendChild(infoPanel);
    }

    // ── Status display: sync CSS class on text change ────────────────────

    var statusEl = document.getElementById('status-display');

    function syncStatusClass() {
        if (!statusEl) return;
        var isOnline = statusEl.textContent.trim().toUpperCase() === 'ONLINE';
        statusEl.classList.toggle('online', isOnline);
        statusEl.classList.toggle('offline', !isOnline);
    }

    if (statusEl) {
        syncStatusClass();
        new MutationObserver(syncStatusClass)
            .observe(statusEl, { childList: true, characterData: true, subtree: true });
    }

    // ── Network description banner ───────────────────────────────────────

    var header = document.querySelector('header');
    if (header) {
        var desc = document.createElement('p');
        desc.className = 'network-desc';
        desc.textContent =
            'This node is part of a decentralized internet network. ' +
            'If the connection drops, the system will automatically locate ' +
            'and reconnect you to an active node.';
        header.insertAdjacentElement('afterend', desc);
    }

    // ── Terminal blinking cursor ─────────────────────────────────────────

    var recoveryLog = document.getElementById('recovery-log');

    if (recoveryLog) {
        var cursor = document.createElement('span');
        cursor.className = 'terminal-cursor';

        recoveryLog.appendChild(cursor);

        // Keep cursor as the last child when app.js appends log entries
        new MutationObserver(function () {
            if (recoveryLog.lastChild !== cursor) {
                recoveryLog.appendChild(cursor);
            }
        }).observe(recoveryLog, { childList: true });
    }

})();
