// Service Worker — SVJ Od Vysoké – Rozhled
const CACHE = 'svj-v1';
const OFFLINE_URL = '/offline.php';

// Při instalaci — cachuj základní soubory
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(cache => cache.addAll([
            '/',
            '/assets/style.css',
            OFFLINE_URL,
        ]))
    );
    self.skipWaiting();
});

// Aktivace — vymaž staré cache
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Fetch — network first, fallback na cache
self.addEventListener('fetch', e => {
    if (e.request.method !== 'GET') return;
    e.respondWith(
        fetch(e.request)
            .then(response => {
                const clone = response.clone();
                caches.open(CACHE).then(cache => cache.put(e.request, clone));
                return response;
            })
            .catch(() => caches.match(e.request).then(r => r || caches.match(OFFLINE_URL)))
    );
});
