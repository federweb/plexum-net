/* style.js — Rendering helpers for NodePulse Monitor
   Loaded before app.js. Exports window.NPStyle. */

window.NPStyle = (function () {

  // Badge HTML
  var TYPE_LABELS = {
    origin:  'origin',
    domain:  'domain',
    network: 'network',
    online:  'peer',
    tunnel:  'tunnel',
  };

  function badge(type) {
    var label = TYPE_LABELS[type] || type;
    return '<span class="badge badge-' + esc(type) + '">' + esc(label) + '</span>';
  }

  // Status dot
  var DOT_MAP = {
    active:   'dot-green',
    online:   'dot-green',
    inactive: 'dot-red',
    error:    'dot-red',
    unknown:  'dot-grey',
    tunnel:   'dot-green',
    domain:   'dot-yellow',
  };

  function dot(status) {
    var cls = DOT_MAP[status] || 'dot-grey';
    return '<span class="dot ' + cls + '" title="' + esc(status) + '"></span>';
  }

  // Absolute timestamp
  function fmtTime(iso) {
    if (!iso) return '—';
    try {
      var d = new Date(iso);
      if (isNaN(d.getTime())) return iso;
      return d.toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, ' UTC');
    } catch (e) { return iso; }
  }

  // Relative time: "3m ago" or "in 5m"
  function relTime(iso) {
    if (!iso) return '';
    try {
      var d   = new Date(iso);
      var now = new Date();
      if (isNaN(d.getTime())) return '';
      var diff = d - now; // positive = future
      var str  = _dur(Math.abs(diff));
      return diff > 0 ? 'in ' + str : str + ' ago';
    } catch (e) { return ''; }
  }

  function _dur(ms) {
    var s = Math.floor(ms / 1000);
    if (s < 60)   return s + 's';
    var m = Math.floor(s / 60);
    if (m < 60)   return m + 'm';
    var h = Math.floor(m / 60);
    if (h < 24)   return h + 'h';
    return Math.floor(h / 24) + 'd';
  }

  // Key/value row — cls: 'ok' | 'warn' | 'error' | 'muted' | ''
  function row(key, val, cls) {
    var valClass = 'json-val' + (cls ? ' ' + cls : '');
    return '<div class="json-row">' +
           '<span class="json-key">' + esc(key) + '</span>' +
           '<span class="' + valClass + '">' + esc(String(val == null ? '—' : val)) + '</span>' +
           '</div>';
  }

  // Timestamp row: key + absolute + relative
  function tsRow(key, iso) {
    if (!iso) return row(key, '—', 'muted');
    var rel = relTime(iso);
    return row(key, fmtTime(iso) + (rel ? '  (' + rel + ')' : ''));
  }

  // Estimated next-gossip row
  function gossipRow(updatedAt, intervalSec) {
    if (!updatedAt) return row('Next gossip (est.)', '—', 'muted');
    try {
      var d = new Date(updatedAt);
      d.setSeconds(d.getSeconds() + (intervalSec || 300));
      var nextIso = d.toISOString();
      var isPast  = d < new Date();
      var cls     = isPast ? 'warn' : 'ok';
      return row('Next gossip (est.)', fmtTime(nextIso) + '  (' + relTime(nextIso) + ')', cls);
    } catch (e) { return ''; }
  }

  // HTML escape
  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  return { badge: badge, dot: dot, fmtTime: fmtTime, relTime: relTime,
           row: row, tsRow: tsRow, gossipRow: gossipRow, esc: esc };

})();
