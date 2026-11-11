// Service worker del kiosco: permite abrir /kiosco sin red.
// - La página /kiosco: primero red, si falla la copia guardada.
// - Recursos de /build (con hash en el nombre, no cambian): primero caché.
// - La API nunca se guarda en caché (la cola offline vive en IndexedDB).
const CACHE = 'marcaje-kiosco-v1';
const SHELL = ['/kiosco', '/kiosco/manifest.webmanifest', '/kiosco-icon-192.png', '/kiosco-icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== location.origin || url.pathname.startsWith('/api/')) return;

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(event.request).then((hit) => hit || fetch(event.request).then((res) => {
                if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(event.request, copy)); }
                return res;
            })),
        );
        return;
    }

    if (SHELL.includes(url.pathname)) {
        event.respondWith(
            fetch(event.request).then((res) => {
                if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(url.pathname, copy)); }
                return res;
            }).catch(() => caches.match(url.pathname)),
        );
    }
});
