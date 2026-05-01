/* =========================================================================
   Pinboard · NodePulse
   Client logic: create pins, list pins, expand on click, sort, countdown.
   All rendering uses textContent / DOM APIs to stay XSS-safe — the server
   stores content encrypted but also does not trust client input.
   ========================================================================= */

(function () {
    'use strict';

    const API_URL = 'api.php';

    const els = {
        form:        document.getElementById('pinForm'),
        title:       document.getElementById('pinTitle'),
        text:        document.getElementById('pinText'),
        titleCount:  document.getElementById('titleCount'),
        textCount:   document.getElementById('textCount'),
        ttlGrid:     document.getElementById('ttlGrid'),
        submitBtn:   document.getElementById('submitBtn'),
        formMsg:     document.getElementById('formMsg'),
        list:        document.getElementById('pinList'),
        empty:       document.getElementById('emptyState'),
        count:       document.getElementById('pinCount'),
        refreshBtn:  document.getElementById('refreshBtn'),
        sortBtns:    document.querySelectorAll('.pb-sort-btn'),
    };

    const state = {
        pins: [],
        serverOffset: 0,     // serverNow - clientNow, to keep countdowns stable
        sort: 'newest',      // 'newest' | 'soonest'
    };

    // ======== Utilities ===================================================

    function nowServer() {
        return Math.floor(Date.now() / 1000) + state.serverOffset;
    }

    function formatRemaining(seconds) {
        if (seconds <= 0)         return 'expired';
        if (seconds < 60)         return seconds + 's';
        if (seconds < 3600)       return Math.floor(seconds / 60) + 'm';
        if (seconds < 86400)      return Math.floor(seconds / 3600) + 'h';
        return Math.floor(seconds / 86400) + 'd';
    }

    function formatAbsolute(unix) {
        const d = new Date(unix * 1000);
        return d.toLocaleString();
    }

    function setMsg(text, kind) {
        els.formMsg.textContent = text || '';
        els.formMsg.classList.remove('is-ok', 'is-error');
        if (kind === 'ok')    els.formMsg.classList.add('is-ok');
        if (kind === 'error') els.formMsg.classList.add('is-error');
    }

    function getSelectedTtl() {
        const boxes = els.ttlGrid.querySelectorAll('input[type="checkbox"]');
        for (const b of boxes) if (b.checked) return parseInt(b.value, 10);
        return null;
    }

    // Chips behave like radios: ticking one unticks the others.
    function initTtl() {
        const boxes = els.ttlGrid.querySelectorAll('input[type="checkbox"]');
        boxes.forEach(box => {
            if (box.dataset.default) box.checked = true;
            box.addEventListener('change', () => {
                if (!box.checked) return;
                boxes.forEach(o => { if (o !== box) o.checked = false; });
            });
        });
    }

    function updateCounter(input, counterEl, max) {
        const len = input.value.length;
        counterEl.textContent = len;
        const parent = counterEl.parentElement;
        parent.classList.toggle('is-warn', len > max * 0.85 && len <= max);
        parent.classList.toggle('is-over', len > max);
    }

    // ======== URL linkifier (XSS-safe: builds nodes, no innerHTML) =======

    const URL_RE = /\b((?:https?:\/\/|www\.)[^\s<>"']+)/gi;

    function linkify(container, rawText) {
        container.textContent = '';
        let lastIndex = 0;
        let m;
        URL_RE.lastIndex = 0;
        while ((m = URL_RE.exec(rawText)) !== null) {
            const start = m.index;
            if (start > lastIndex) {
                container.appendChild(document.createTextNode(rawText.slice(lastIndex, start)));
            }
            let url = m[1];
            // Trim trailing punctuation commonly glued to URLs
            let trailing = '';
            const trailMatch = url.match(/[),.!?;:]+$/);
            if (trailMatch) {
                trailing = trailMatch[0];
                url = url.slice(0, -trailing.length);
            }
            const href = url.startsWith('www.') ? 'https://' + url : url;
            // Only allow http/https schemes
            if (/^https?:\/\//i.test(href)) {
                const a = document.createElement('a');
                a.href = href;
                a.textContent = url;
                a.target = '_blank';
                a.rel = 'noopener noreferrer ugc';
                container.appendChild(a);
            } else {
                container.appendChild(document.createTextNode(url));
            }
            if (trailing) container.appendChild(document.createTextNode(trailing));
            lastIndex = start + m[0].length;
        }
        if (lastIndex < rawText.length) {
            container.appendChild(document.createTextNode(rawText.slice(lastIndex)));
        }
    }

    // ======== API =========================================================

    async function apiCall(payload) {
        const res = await fetch(API_URL, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify(payload),
            credentials: 'same-origin',
        });
        let data;
        try { data = await res.json(); }
        catch (e) { throw new Error('Invalid server response'); }
        if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
        return data;
    }

    async function fetchPins() {
        const data = await apiCall({ action: 'list' });
        if (!data.success) throw new Error(data.message || 'List failed');
        state.pins = Array.isArray(data.pins) ? data.pins : [];
        if (typeof data.now === 'number') {
            state.serverOffset = data.now - Math.floor(Date.now() / 1000);
        }
        return data;
    }

    async function createPin(title, text, ttl) {
        return apiCall({ action: 'create', title: title, text: text, expiration: ttl });
    }

    // ======== Rendering ===================================================

    function sortPins(pins) {
        const copy = pins.slice();
        if (state.sort === 'soonest') {
            copy.sort((a, b) => a.expires - b.expires);
        } else {
            copy.sort((a, b) => b.created - a.created);
        }
        return copy;
    }

    function renderPins() {
        els.list.textContent = '';
        const sorted = sortPins(state.pins);
        els.count.textContent = sorted.length;
        els.empty.classList.toggle('hidden', sorted.length > 0);

        const frag = document.createDocumentFragment();
        sorted.forEach(pin => frag.appendChild(buildPinNode(pin)));
        els.list.appendChild(frag);
    }

    function buildPinNode(pin) {
        const li = document.createElement('li');
        li.className = 'pin';
        li.dataset.id      = pin.id;
        li.dataset.expires = pin.expires;

        // ----- head -----
        const head = document.createElement('div');
        head.className = 'pin-head';
        head.setAttribute('role', 'button');
        head.setAttribute('tabindex', '0');
        head.setAttribute('aria-expanded', 'false');

        const dot = document.createElement('span');
        dot.className = 'pin-dot';
        head.appendChild(dot);

        const title = document.createElement('span');
        title.className = 'pin-title';
        title.textContent = pin.title;
        head.appendChild(title);

        const meta = document.createElement('span');
        meta.className = 'pin-meta';
        const ttl = document.createElement('span');
        ttl.className = 'pin-ttl';
        meta.appendChild(ttl);
        head.appendChild(meta);

        const caret = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        caret.setAttribute('viewBox', '0 0 24 24');
        caret.setAttribute('fill', 'none');
        caret.setAttribute('stroke', 'currentColor');
        caret.setAttribute('stroke-width', '2');
        caret.setAttribute('stroke-linecap', 'round');
        caret.setAttribute('stroke-linejoin', 'round');
        caret.classList.add('pin-caret');
        const p = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        p.setAttribute('points', '6 9 12 15 18 9');
        caret.appendChild(p);
        head.appendChild(caret);

        // ----- preview (visible only when collapsed) -----
        const preview = document.createElement('div');
        preview.className = 'pin-preview';
        preview.textContent = pin.text.length > 140 ? pin.text.slice(0, 140) + '…' : pin.text;

        // ----- body (expandable) -----
        const body = document.createElement('div');
        body.className = 'pin-body';

        const inner = document.createElement('div');
        inner.className = 'pin-body-inner';

        const textEl = document.createElement('div');
        textEl.className = 'pin-text';
        linkify(textEl, pin.text);
        inner.appendChild(textEl);

        const foot = document.createElement('div');
        foot.className = 'pin-foot';
        const createdEl = document.createElement('span');
        createdEl.className = 'pin-created';
        createdEl.textContent = 'created ' + formatAbsolute(pin.created);
        const expiresEl = document.createElement('span');
        expiresEl.className = 'pin-expires';
        expiresEl.textContent = 'expires ' + formatAbsolute(pin.expires);
        foot.appendChild(createdEl);
        foot.appendChild(expiresEl);
        inner.appendChild(foot);

        body.appendChild(inner);

        li.appendChild(head);
        li.appendChild(preview);
        li.appendChild(body);

        // Toggle on click or keyboard
        const toggle = () => {
            const open = li.classList.toggle('is-open');
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        head.addEventListener('click', toggle);
        head.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
        });

        updatePinCountdown(li);
        return li;
    }

    // Keeps the TTL chip + urgency color fresh without re-rendering the list.
    function updatePinCountdown(li) {
        const expires   = parseInt(li.dataset.expires, 10);
        const remaining = expires - nowServer();
        const ttlEl     = li.querySelector('.pin-ttl');
        if (ttlEl) ttlEl.textContent = formatRemaining(remaining);

        li.classList.toggle('urgent',   remaining > 0 && remaining <= 120);
        li.classList.toggle('expiring', remaining > 120 && remaining <= 900);
    }

    function tickCountdowns() {
        let anyDead = false;
        els.list.querySelectorAll('.pin').forEach(li => {
            const expires = parseInt(li.dataset.expires, 10);
            if (expires - nowServer() <= 0) {
                anyDead = true;
                li.style.opacity = '0.4';
                return;
            }
            updatePinCountdown(li);
        });
        if (anyDead) refresh();
    }

    // ======== Events ======================================================

    function bindEvents() {
        els.title.addEventListener('input', () => updateCounter(els.title, els.titleCount, 100));
        els.text.addEventListener('input',  () => updateCounter(els.text,  els.textCount,  1500));

        els.form.addEventListener('submit', async (e) => {
            e.preventDefault();
            setMsg('');

            const title = els.title.value.trim();
            const text  = els.text.value.trim();
            const ttl   = getSelectedTtl();

            if (!title) return setMsg('Title is required', 'error');
            if (!text)  return setMsg('Message is required', 'error');
            if (text.length > 1500) return setMsg('Message too long (max 1500)', 'error');
            if (!ttl)   return setMsg('Choose an expiration', 'error');

            els.submitBtn.disabled = true;
            els.submitBtn.classList.add('is-loading');
            try {
                const res = await createPin(title, text, ttl);
                if (!res.success) throw new Error(res.message || 'Failed');
                setMsg('Pinned.', 'ok');
                els.form.reset();
                // Re-tick the default TTL checkbox after reset
                initTtlDefault();
                updateCounter(els.title, els.titleCount, 100);
                updateCounter(els.text,  els.textCount,  1500);
                await refresh();
            } catch (err) {
                setMsg(err.message || 'Error', 'error');
            } finally {
                els.submitBtn.disabled = false;
                els.submitBtn.classList.remove('is-loading');
            }
        });

        els.refreshBtn.addEventListener('click', () => {
            els.refreshBtn.classList.add('is-spinning');
            refresh().finally(() => {
                setTimeout(() => els.refreshBtn.classList.remove('is-spinning'), 450);
            });
        });

        els.sortBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                els.sortBtns.forEach(b => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                state.sort = btn.dataset.sort;
                renderPins();
            });
        });
    }

    function initTtlDefault() {
        const boxes = els.ttlGrid.querySelectorAll('input[type="checkbox"]');
        boxes.forEach(b => { b.checked = !!b.dataset.default; });
    }

    // ======== Boot ========================================================

    async function refresh() {
        try {
            await fetchPins();
            renderPins();
        } catch (err) {
            setMsg(err.message || 'Could not load pins', 'error');
        }
    }

    function boot() {
        initTtl();
        bindEvents();
        updateCounter(els.title, els.titleCount, 100);
        updateCounter(els.text,  els.textCount,  1500);
        refresh();
        setInterval(tickCountdowns, 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
