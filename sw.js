// Service Worker – Papiersammlung
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

// GPS-Update vom Hauptthread empfangen und per keepalive ans Backend senden
self.addEventListener('message', event => {
    if (event.data?.type !== 'GPS_UPDATE') return;
    const { token, lat, lng, collection_id } = event.data;
    fetch('api.php?action=vehicle_position', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, lat, lng, collection_id }),
        keepalive: true
    }).catch(() => {});
});

// Periodic Sync (Android Chrome PWA) – fragt Hauptthread nach GPS
self.addEventListener('periodicsync', event => {
    if (event.tag === 'gps-keepalive') {
        event.waitUntil(
            self.clients.matchAll({ includeUncontrolled: true, type: 'window' })
                .then(clients => clients.forEach(c => c.postMessage({ type: 'REQUEST_GPS' })))
        );
    }
});
