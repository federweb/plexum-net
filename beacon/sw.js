/**
 * NodePulse — Service Worker v6
 *
 * Strategy: Cache-First with network fallback.
 * Cache duration: 365 days.
 * Scope: / (site-wide) — serves beacon/ as fallback for all pages.
 *
 * Caches:
 * - nodepulse-static-v6: HTML, JS, static assets
 * - nodepulse-data-v6: nodes.json (updated on every online fetch)
 */

const CACHE_STATIC = 'nodepulse-static-v6';
const CACHE_DATA = 'nodepulse-data-v6';
const CACHE_MAX_AGE = 365 * 24 * 60 * 60 * 1000; // 365 days in ms

const BEACON_PATH = '/beacon/';

// Files to pre-cache (absolute paths — scope is /)
const PRECACHE_URLS = [
    '/beacon/',
    '/beacon/index.php',
    '/beacon/style.css',
    '/beacon/style.js',
    '/beacon/app.js',
];

// =========================================================================
// INSTALL — Pre-cache static files
// =========================================================================
self.addEventListener('install', (event) => {
    console.log('[SW] Install v6 — scope: /');

    event.waitUntil(
        caches.open(CACHE_STATIC).then((cache) => {
            console.log('[SW] Pre-caching static assets');
            return cache.addAll(PRECACHE_URLS);
        }).then(() => {
            return self.skipWaiting();
        })
    );
});

// =========================================================================
// ACTIVATE — Clean up old caches
// =========================================================================
self.addEventListener('activate', (event) => {
    console.log('[SW] Activate v6');

    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_STATIC && name !== CACHE_DATA)
                    .map((name) => {
                        console.log('[SW] Removing old cache:', name);
                        return caches.delete(name);
                    })
            );
        }).then(() => {
            return self.clients.claim();
        })
    );
});

// =========================================================================
// BEACON FALLBACK — serve beacon/index.php from cache
// =========================================================================
function serveBeaconFallback() {
    return caches.open(CACHE_STATIC).then(function (cache) {
        return cache.match('/beacon/index.php').then(function (resp) {
            if (resp) return resp;
            return cache.match('/beacon/');
        });
    }).then(function (cached) {
        if (cached) {
            console.log('[SW] Serving beacon fallback from cache');
            return cached;
        }
        return new Response('Offline — beacon not cached yet', { status: 503 });
    });
}

// =========================================================================
// FETCH — Two-zone caching strategy
// =========================================================================
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Cross-origin: pass through (seed lookups etc.)
    if (url.origin !== self.location.origin) return;

    // Never cache: SW and ping
    if (url.search.includes('sw') || url.search.includes('ping')) return;

    // ── ZONA 1: BEACON ASSETS ──────────────────────────────
    if (url.pathname.startsWith(BEACON_PATH)) {
        const relPath = url.pathname.slice(BEACON_PATH.length);

        // nodes → network-first
        if (url.search.includes('nodes') || relPath === 'nodes.json') {
            event.respondWith(networkFirstStrategy(event.request, CACHE_DATA));
            return;
        }
        // static files → cache-first
        const staticFiles = ['', 'index.php', 'style.css', 'style.js', 'app.js'];
        if (staticFiles.includes(relPath) && !url.search) {
            event.respondWith(cacheFirstStrategy(event.request, CACHE_STATIC));
            return;
        }
        // navigations to beacon → cache-first
        if (event.request.mode === 'navigate') {
            event.respondWith(cacheFirstStrategy(event.request, CACHE_STATIC));
            return;
        }
        return;
    }

    // ── ZONA 2: TUTTO IL RESTO DEL SITO ─────────────────────
    // Navigations (user goes to /terminal/, /browser/, etc.):
    // try network; if network error OR server error (5xx) → serve beacon
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).then(function (response) {
                // Cloudflare returns 502/522/524 when tunnel is dead
                // Serve beacon fallback on any 5xx server error
                if (response.status >= 500) {
                    console.log('[SW] Server error', response.status, '— serving beacon fallback');
                    return serveBeaconFallback();
                }
                return response;
            }).catch(function () {
                // Network error (device offline, DNS fail, etc.)
                console.log('[SW] Network error — serving beacon fallback');
                return serveBeaconFallback();
            })
        );
        return;
    }

    // Sub-resources of other apps → pass through
});

// =========================================================================
// CACHE STRATEGIES
// =========================================================================

/**
 * Cache-First: look in cache, fall back to network.
 */
async function cacheFirstStrategy(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        const cachedDate = cached.headers.get('sw-cached-at');
        if (cachedDate) {
            const age = Date.now() - parseInt(cachedDate, 10);
            if (age > CACHE_MAX_AGE) {
                console.log('[SW] Cache expired for:', request.url);
                try {
                    return await fetchAndCache(request, cache);
                } catch {
                    return cached;
                }
            }
        }
        return cached;
    }

    // Not in cache → fetch and store
    try {
        return await fetchAndCache(request, cache);
    } catch (err) {
        console.warn('[SW] Fetch failed and nothing in cache:', request.url);
        // Navigation fallback: serve beacon index from cache
        if (request.mode === 'navigate') {
            const fb1 = await cache.match('/beacon/index.php');
            if (fb1) return fb1;
            const fb2 = await cache.match('/beacon/');
            if (fb2) return fb2;
        }
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    }
}

/**
 * Network-First: try network, fall back to cache.
 */
async function networkFirstStrategy(request, cacheName) {
    const cache = await caches.open(cacheName);

    try {
        const response = await fetchAndCache(request, cache);
        return response;
    } catch {
        console.log('[SW] Network failed, serving from cache:', request.url);
        const cached = await cache.match(request);
        if (cached) return cached;
        return new Response('{}', {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

/**
 * Fetch and store in cache with timestamp.
 */
async function fetchAndCache(request, cache) {
    const response = await fetch(request);

    if (response.ok) {
        const cloned = response.clone();
        const headers = new Headers(cloned.headers);
        headers.set('sw-cached-at', Date.now().toString());

        const body = await cloned.blob();
        const cachedResponse = new Response(body, {
            status: cloned.status,
            statusText: cloned.statusText,
            headers: headers,
        });

        await cache.put(request, cachedResponse);
        console.log('[SW] Cached:', request.url);
    }

    return response;
}
