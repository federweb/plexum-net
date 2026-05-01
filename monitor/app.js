/* app.js — NodePulse Monitor core logic
   Requires style.js (NPStyle) to be loaded first. */

(function () {
  'use strict';

  var S = window.NPStyle;
  var DEFAULT_GOSSIP = 300; // seconds

  var state = {
    data:           null,
    gossipInterval: DEFAULT_GOSSIP,
    currentPeer:    null,   // peer shown in the open modal
  };

  // ── Bootstrap ────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('btn-refresh').addEventListener('click', loadData);
    document.getElementById('modal-close').addEventListener('click', closeModal);
    document.getElementById('modal-overlay').addEventListener('click', function (e) {
      if (e.target === this) closeModal();
    });
    loadData();
  });

  // ── Data loading ─────────────────────────────────────────────

  function loadData() {
    var main = document.getElementById('main-content');
    main.innerHTML = '<div class="loading-msg">Loading network...</div>';

    fetch('api.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.data = data;
        if (data.node_config && data.node_config.gossip_interval_sec) {
          state.gossipInterval = data.node_config.gossip_interval_sec;
        }
        document.getElementById('generated-at').textContent =
          'Updated: ' + S.fmtTime(data.generated_at);
        renderAll(data);
      })
      .catch(function (err) {
        main.innerHTML = '<div class="detail-error">Load error: ' + S.esc(err.message) + '</div>';
      });
  }

  // ── Render all sections ──────────────────────────────────────

  function renderAll(data) {
    var main = document.getElementById('main-content');
    main.innerHTML = '';

    var bestTs = buildBestTsMap(data);

    var origins = sortByTs(normalizeOrigins(
      data.seeds_origin  && data.seeds_origin.seeds   ? data.seeds_origin.seeds   : [], bestTs
    ));
    var domains = sortByTs(normalizeDomains(
      data.seeds_domain  && data.seeds_domain.domains ? data.seeds_domain.domains : [], bestTs
    ));
    var network = sortByTs(normalizeNetwork(
      data.seeds_network && data.seeds_network.seeds  ? data.seeds_network.seeds  : [], bestTs
    ));
    var online = sortByTs(normalizeOnline(
      data.peers_online  && data.peers_online.peers   ? data.peers_online.peers   : [], bestTs
    ));

    main.appendChild(buildSection('Origin Seeds',  origins));
    main.appendChild(buildSection('Domain Seeds',  domains));
    main.appendChild(buildSection('Network Seeds', network));
    main.appendChild(buildSection('Online Peers',  online));
  }

  // ── Sort by ts (newest first) ────────────────────────────────

  function sortByTs(arr) {
    return arr.sort(function (a, b) {
      if (a.ts > b.ts) return -1;
      if (a.ts < b.ts) return  1;
      return 0;
    });
  }

  // ── Normalizers ──────────────────────────────────────────────

  function bestTsFor(bestTs, node_id, url) {
    return (node_id && bestTs[node_id]) || bestTs[url] || '';
  }

  function normalizeOrigins(arr, bestTs) {
    return arr.map(function (s) {
      return { url: s.url || '', label: s.label || s.url || '?',
               status: s.status || 'unknown', ts: bestTsFor(bestTs, '', s.url),
               node_id: '', _type: 'origin', _raw: s };
    });
  }
  function normalizeDomains(arr, bestTs) {
    return arr.map(function (s) {
      return { url: s.url || '', label: s.node_id || s.url || '?',
               status: s.status || 'domain', ts: bestTsFor(bestTs, s.node_id, s.url),
               node_id: s.node_id || '', _type: 'domain', _raw: s };
    });
  }
  function normalizeNetwork(arr, bestTs) {
    return arr.map(function (s) {
      return { url: s.url || '', label: s.node_id || s.url || '?',
               status: s.status || 'active', ts: bestTsFor(bestTs, s.node_id, s.url),
               node_id: s.node_id || '', _type: 'network', _raw: s };
    });
  }
  function normalizeOnline(arr, bestTs) {
    return arr.map(function (p) {
      return { url: p.url || '', label: p.node_id || p.url || '?',
               status: p.type || 'tunnel', ts: bestTsFor(bestTs, p.node_id, p.url),
               node_id: p.node_id || '', _type: 'online', _raw: p };
    });
  }

  // ── Best-timestamp map ──────────────────────────────────

  function buildBestTsMap(data) {
    var map = {};

    function track(key, iso) {
      if (!key || !iso) return;
      if (!map[key] || iso > map[key]) map[key] = iso;
    }

    function trackEntry(e, timestamps) {
      for (var i = 0; i < timestamps.length; i++) {
        if (e.node_id) track(e.node_id, timestamps[i]);
        if (e.url)     track(e.url, timestamps[i]);
      }
    }

    var arr, j;

    arr = data.seeds_origin && data.seeds_origin.seeds ? data.seeds_origin.seeds : [];
    for (j = 0; j < arr.length; j++) trackEntry(arr[j], [arr[j].added_at]);

    arr = data.seeds_domain && data.seeds_domain.domains ? data.seeds_domain.domains : [];
    for (j = 0; j < arr.length; j++) trackEntry(arr[j], [arr[j].last_seen, arr[j].added_at]);

    arr = data.seeds_network && data.seeds_network.seeds ? data.seeds_network.seeds : [];
    for (j = 0; j < arr.length; j++) trackEntry(arr[j], [arr[j].last_seen]);

    arr = data.peers_online && data.peers_online.peers ? data.peers_online.peers : [];
    for (j = 0; j < arr.length; j++) trackEntry(arr[j], [arr[j].last_seen]);

    arr = data.directory && data.directory.entries ? data.directory.entries : [];
    for (j = 0; j < arr.length; j++) trackEntry(arr[j], [arr[j].signed_at, arr[j].last_seen]);

    return map;
  }

  // ── Section builder ──────────────────────────────────────────

  function buildSection(title, peers) {
    var section = el('div', 'section');

    var header = el('div', 'section-header');
    var h = el('h2', 'section-title');
    h.textContent = title;
    var count = el('span', 'section-count');
    count.textContent = peers.length;
    header.appendChild(h);
    header.appendChild(count);
    section.appendChild(header);

    var list = el('div', 'peers-list');
    if (peers.length === 0) {
      var empty = el('div', 'empty-msg');
      empty.textContent = 'No peers in this category.';
      list.appendChild(empty);
    } else {
      peers.forEach(function (peer) { list.appendChild(buildCard(peer)); });
    }
    section.appendChild(list);
    return section;
  }

  // ── Peer card ────────────────────────────────────────────────

  function buildCard(peer) {
    var card = el('div', 'peer-card');

    var info = el('div', 'peer-info');
    var label = el('div', 'peer-label');
    label.textContent = peer.label;
    var url = el('div', 'peer-url');
    url.textContent = peer.url;
    var meta = el('div', 'peer-meta');
    meta.innerHTML =
      S.badge(peer._type) +
      S.dot(peer.status) +
      (peer.ts ? '<span class="peer-ts">' + S.esc(S.relTime(peer.ts)) + '</span>' : '');
    info.appendChild(label);
    info.appendChild(url);
    info.appendChild(meta);
    card.appendChild(info);

    var actions = el('div', 'peer-actions');

    var btnDetail = el('button', 'btn-detail');
    btnDetail.textContent = 'Detail';
    btnDetail.addEventListener('click', function () { openDetail(peer); });
    actions.appendChild(btnDetail);

    if (peer.url) {
      var btnNav = el('button', 'btn-navigate');
      btnNav.textContent = 'Open URL';
      btnNav.addEventListener('click', function () { window.open(peer.url, '_blank'); });
      actions.appendChild(btnNav);
    }

    card.appendChild(actions);
    return card;
  }

  // ── Modal ────────────────────────────────────────────────────

  function openDetail(peer) {
    state.currentPeer = peer;
    document.getElementById('modal-title').textContent = peer.label;
    document.getElementById('modal-subtitle').textContent = peer.url;
    document.getElementById('modal-body').innerHTML = renderDetailBody(peer);
    document.getElementById('modal-overlay').classList.remove('hidden');
  }

  function closeModal() {
    state.currentPeer = null;
    document.getElementById('modal-overlay').classList.add('hidden');
  }

  // ── Gossip sync ──────────────────────────────────────────────

  function syncPeer(peer, btn) {
    if (!peer || !peer.url) return;
    btn.disabled = true;
    btn.textContent = '⟳ Syncing...';

    fetch('sync.php?url=' + encodeURIComponent(peer.url))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        btn.textContent = '⟳ Sync';

        var resultEl = document.getElementById('sync-result');
        if (!resultEl) return;

        if (!res.ok) {
          resultEl.className = 'sync-result error';
          resultEl.textContent = '✗ ' + (res.error || 'Sync failed');
        } else {
          resultEl.className = 'sync-result ok';
          resultEl.textContent =
            '✓ Synced — received ' + res.received_entries + ' entries, ' +
            res.received_peers + ' peers. Local files updated.';
          // Reload local data to reflect merge
          loadDataSilent();
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        btn.textContent = '⟳ Sync';
        var resultEl = document.getElementById('sync-result');
        if (resultEl) {
          resultEl.className = 'sync-result error';
          resultEl.textContent = '✗ ' + err.message;
        }
      });
  }

  // Reload local data without rebuilding the full UI (just updates state.data)
  function loadDataSilent() {
    fetch('api.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.data = data;
        if (data.node_config && data.node_config.gossip_interval_sec) {
          state.gossipInterval = data.node_config.gossip_interval_sec;
        }
        document.getElementById('generated-at').textContent =
          'Updated: ' + S.fmtTime(data.generated_at);
      });
  }

  // ── Detail body — this peer across all local data sources ──

  function renderDetailBody(peer) {
    var d    = state.data;
    var html = '';

    var originEntry  = findInOrigins(peer, d.seeds_origin);
    var domainEntry  = findInDomains(peer, d.seeds_domain);
    var networkEntry = findInNetwork(peer, d.seeds_network);
    var onlineEntry  = findInPeers(peer, d.peers_online);
    var dirEntry     = findInDirectory(peer, d.directory);

    // best-ts summary
    var bestTs = bestTsFor(buildBestTsMap(d), peer.node_id, peer.url);
    if (bestTs) {
      html += '<div class="json-card">';
      html += '<div class="json-card-title">Best known timestamp</div>';
      html += S.row('timestamp', S.fmtTime(bestTs) + '  (' + S.relTime(bestTs) + ')');
      html += '</div>';
    }

    // seeds_origin.json
    html += '<div class="json-card">';
    html += '<div class="json-card-title">seeds_origin.json</div>';
    if (originEntry) {
      html += S.row('status', 'found', 'ok');
      html += S.row('node_id', originEntry.node_id || '—', 'muted');
      html += S.row('url', originEntry.url || '—', 'muted');
    } else {
      html += S.row('status', 'not present', 'muted');
    }
    html += '</div>';

    // seeds_domain.json
    html += '<div class="json-card">';
    html += '<div class="json-card-title">seeds_domain.json</div>';
    if (domainEntry) {
      html += S.row('status', domainEntry.status || 'found', 'ok');
      html += S.row('node_id', domainEntry.node_id || '—', 'muted');
      html += S.row('url', domainEntry.url || '—', 'muted');
      html += S.row('public_key', domainEntry.public_key ? 'present' : '—',
                    domainEntry.public_key ? 'ok' : 'muted');
      html += S.tsRow('registered_at', domainEntry.registered_at);
      html += S.tsRow('last_seen', domainEntry.last_seen);
    } else {
      html += S.row('status', 'not present', 'muted');
    }
    html += '</div>';

    // seeds_network.json
    html += '<div class="json-card">';
    html += '<div class="json-card-title">seeds_network.json</div>';
    if (networkEntry) {
      html += S.row('status', 'found', 'ok');
      html += S.row('node_id', networkEntry.node_id || '—', 'muted');
      html += S.row('url', networkEntry.url || '—', 'muted');
      html += S.tsRow('last_seen', networkEntry.last_seen);
      html += S.row('reliability', networkEntry.reliability != null ? networkEntry.reliability : '—', 'muted');
    } else {
      html += S.row('status', 'not present', 'muted');
    }
    html += '</div>';

    // peers_online.json
    html += '<div class="json-card">';
    html += '<div class="json-card-title">peers_online.json</div>';
    if (onlineEntry) {
      html += S.row('status', 'online', 'ok');
      html += S.row('node_id', onlineEntry.node_id || '—', 'muted');
      html += S.row('url', onlineEntry.url || '—', 'muted');
      html += S.row('type', onlineEntry.type || '—', 'muted');
      html += S.tsRow('last_seen', onlineEntry.last_seen);
    } else {
      html += S.row('status', 'not present', 'muted');
    }
    html += '</div>';

    // directory.json
    html += '<div class="json-card">';
    html += '<div class="json-card-title">directory.json</div>';
    if (dirEntry) {
      html += S.row('status', 'found', 'ok');
      html += S.row('node_id', dirEntry.node_id || '—', 'muted');
      html += S.row('url', dirEntry.url || '—', 'muted');
      html += S.row('type', dirEntry.type || '—', 'muted');
      html += S.row('public_key', dirEntry.public_key ? 'present' : '—',
                    dirEntry.public_key ? 'ok' : 'muted');
      html += S.row('signature', dirEntry.signature ? 'present' : '—',
                    dirEntry.signature ? 'ok' : 'muted');
      html += S.tsRow('signed_at', dirEntry.signed_at);
      html += S.tsRow('last_seen', dirEntry.last_seen);
    } else {
      html += S.row('status', 'not present', 'muted');
    }
    html += '</div>';

    return html;
  }

  // ── Lookup helpers ───────────────────────────────────────────

  function findInList(peer, arr) {
    for (var i = 0; i < arr.length; i++) {
      var e = arr[i];
      if (peer.node_id && e.node_id === peer.node_id) return e;
      if (peer.url     && e.url     === peer.url)     return e;
    }
    return null;
  }

  function findInOrigins(peer, seeds_origin) {
    if (!seeds_origin || !seeds_origin.seeds) return null;
    return findInList(peer, seeds_origin.seeds);
  }

  function findInDomains(peer, seeds_domain) {
    if (!seeds_domain || !seeds_domain.domains) return null;
    return findInList(peer, seeds_domain.domains);
  }

  function findInNetwork(peer, seeds_network) {
    if (!seeds_network || !seeds_network.seeds) return null;
    return findInList(peer, seeds_network.seeds);
  }

  function findInPeers(peer, peers_online) {
    if (!peers_online || !peers_online.peers) return null;
    return findInList(peer, peers_online.peers);
  }

  function findInDirectory(peer, directory) {
    if (!directory || !directory.entries) return null;
    return findInList(peer, directory.entries);
  }

  // ── Utility ──────────────────────────────────────────────────

  function el(tag, className) {
    var e = document.createElement(tag);
    if (className) e.className = className;
    return e;
  }

})();
