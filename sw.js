// ============================================================
// sw.js — Service Worker for Aandavar FieldApp
// Subfolder install: /aandavar_fixed/
// PLACE THIS FILE AT: /aandavar_fixed/sw.js
// ============================================================
const CACHE_VERSION = 'aandavar-v1';
const SUBFOLDER = '/aandavar_fixed';

const PRECACHE_URLS = [
  SUBFOLDER + '/index.php',
  SUBFOLDER + '/offline.html',
  SUBFOLDER + '/assets/logo.png',
  SUBFOLDER + '/assets/pwa/icon-192x192.png',
  SUBFOLDER + '/assets/pwa/icon-512x512.png',
];

// These paths always go to network — never cached
const NEVER_CACHE = [
  SUBFOLDER + '/includes/',
  SUBFOLDER + '/admin/',
  SUBFOLDER + '/office_admin/',
  SUBFOLDER + '/subadmin/',
  SUBFOLDER + '/logout.php',
  SUBFOLDER + '/session_ping.php',
  SUBFOLDER + '/NEW/',
];

// ── Install ───────────────────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => {
      return cache.addAll(PRECACHE_URLS).catch((err) => {
        console.warn('[SW] Pre-cache partial failure:', err);
      });
    })
  );
  self.skipWaiting();
});

// ── Activate: clear old caches ────────────────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// ── Fetch ─────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Only handle same-origin GET requests
  if (url.origin !== self.location.origin) return;
  if (event.request.method !== 'GET') return;

  const path = url.pathname;

  // Never-cache: always live network
  const isNeverCache = NEVER_CACHE.some((p) => path.startsWith(p));
  if (isNeverCache) {
    event.respondWith(
      fetch(event.request).catch(() =>
        caches.match(SUBFOLDER + '/offline.html')
      )
    );
    return;
  }

  // Static assets: cache-first, refresh in background
  const isStatic = /\.(png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|css|js)$/.test(path);
  if (isStatic) {
    event.respondWith(
      caches.match(event.request).then((cached) => {
        const networkFetch = fetch(event.request).then((response) => {
          if (response && response.status === 200) {
            caches.open(CACHE_VERSION).then((cache) =>
              cache.put(event.request, response.clone())
            );
          }
          return response;
        });
        return cached || networkFetch;
      })
    );
    return;
  }

  // PHP pages: network-first, fallback to offline page
  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (path === SUBFOLDER + '/index.php' && response.status === 200) {
          caches.open(CACHE_VERSION).then((cache) =>
            cache.put(event.request, response.clone())
          );
        }
        return response;
      })
      .catch(() =>
        caches.match(SUBFOLDER + '/offline.html').then(
          (offline) =>
            offline ||
            new Response('<h1>Offline</h1>', {
              headers: { 'Content-Type': 'text/html' },
            })
        )
      )
  );
});
