// Service Worker – Papiersammlung v5
// Background-GPS + Web Push (auch bei geschlossenem Browser)

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

// ── GPS-Cache (letzter bekannter Stand) ───────────────────────────────────────
let lastGpsData = null;
let bgTimer     = null;

function sendGpsToApi(data) {
    if (!data?.token || !data?.lat) return;
    const { token, lat, lng, collection_id, snap_lat, snap_lng, speed } = data;
    const body = { token, lat, lng, collection_id, speed };
    if (snap_lat != null) { body.snap_lat = snap_lat; body.snap_lng = snap_lng; }
    fetch('api.php?action=vehicle_position', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true,
    }).catch(() => {});
}

self.addEventListener('message', event => {
    const type = event.data?.type;

    if (type === 'GPS_UPDATE') {
        lastGpsData = event.data;
        sendGpsToApi(event.data);
    }

    if (type === 'BG_START') {
        if (bgTimer) { clearInterval(bgTimer); bgTimer = null; }
        bgTimer = setInterval(async () => {
            const cls = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
            if (cls.length > 0) {
                cls.forEach(c => c.postMessage({ type: 'REQUEST_GPS' }));
            } else if (lastGpsData) {
                sendGpsToApi(lastGpsData);
            }
        }, 5000);
    }

    if (type === 'BG_STOP') {
        if (bgTimer) { clearInterval(bgTimer); bgTimer = null; }
        self.registration.getNotifications({ tag: 'gps-bg' })
            .then(ns => ns.forEach(n => n.close())).catch(() => {});
    }
});

// ── Web Push: Weckt User wenn GPS pausiert (auch bei geschlossenem Browser) ───
self.addEventListener('push', event => {
    event.waitUntil(
        self.registration.showNotification('Papiersammlung – GPS pausiert', {
            body: '📍 GPS-Tracking unterbrochen. Antippen zum Fortsetzen.',
            icon: '/favicon.png',
            badge: '/favicon.png',
            tag: 'gps-pause',
            requireInteraction: true,
            vibrate: [200, 100, 200],
            data: { url: '/index.php' },
        })
    );
    if (lastGpsData) sendGpsToApi(lastGpsData);
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data?.url || '/index.php';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(cls => {
                const ex = cls.find(c => c.url.includes('index.php'));
                if (ex) { ex.focus(); return; }
                return self.clients.openWindow(url);
            })
    );
});

self.addEventListener('pushsubscriptionchange', event => {
    event.waitUntil(
        self.registration.pushManager.subscribe(event.oldSubscription.options)
            .then(sub => {
                const j = sub.toJSON();
                return fetch('api.php?action=push_subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth }),
                    keepalive: true,
                });
            }).catch(() => {})
    );
});

self.addEventListener('periodicsync', event => {
    if (event.tag === 'gps-keepalive') {
        event.waitUntil(
            self.clients.matchAll({ includeUncontrolled: true, type: 'window' })
                .then(cls => cls.forEach(c => c.postMessage({ type: 'REQUEST_GPS' })))
        );
    }
});
