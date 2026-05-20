// Service Worker – Papiersammlung v4
// Hintergrund-GPS via persistente Notification (Android Chrome PWA)
// iOS Safari: kein Hintergrund-Support möglich (Platform-Einschränkung)

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

// ── Hintergrund-Timer ─────────────────────────────────────────────────────────
// Wenn die App in den Hintergrund geht, tickt dieser Timer und fordert
// den Hauptthread per Nachricht auf, seinen letzten GPS-Stand zu senden.
let bgTimer = null;
let lastGpsData = null; // Letzter bekannter GPS-Stand (im SW gecacht)

function startBgTimer() {
    if (bgTimer) return;
    bgTimer = setInterval(async () => {
        const clients = await self.clients.matchAll({ includeUncontrolled: true, type: 'window' });
        if (clients.length > 0) {
            // Hauptthread noch aktiv → GPS anfordern
            clients.forEach(c => c.postMessage({ type: 'REQUEST_GPS' }));
        } else if (lastGpsData) {
            // Kein Hauptthread mehr → letzten bekannten Stand direkt senden
            sendGpsToApi(lastGpsData);
        }
    }, 5000);
}

function stopBgTimer() {
    if (bgTimer) { clearInterval(bgTimer); bgTimer = null; }
}

function sendGpsToApi(data) {
    const { token, lat, lng, collection_id, snap_lat, snap_lng } = data;
    const body = { token, lat, lng, collection_id };
    if (snap_lat != null) { body.snap_lat = snap_lat; body.snap_lng = snap_lng; }
    fetch('api.php?action=vehicle_position', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        keepalive: true
    }).catch(() => {});
}

// ── SW Message Handler ────────────────────────────────────────────────────────
self.addEventListener('message', event => {
    const { type } = event.data ?? {};

    if (type === 'GPS_UPDATE') {
        // Direkter GPS-Send aus dem Hauptthread (immer bevorzugt)
        lastGpsData = event.data;
        sendGpsToApi(event.data);
    }

    if (type === 'BG_START') {
        // App geht in Hintergrund – Timer starten + Notification anzeigen
        startBgTimer();
        self.registration.showNotification('Papiersammlung – GPS aktiv', {
            body: '📍 Fahrzeug wird im Hintergrund getrackt. Antippen zum Öffnen.',
            icon: '/favicon.png',
            badge: '/favicon.png',
            tag: 'gps-bg',
            requireInteraction: true, // bleibt bis zur Interaktion sichtbar
            silent: true,
            vibrate: [],
        });
    }

    if (type === 'BG_STOP') {
        // App wieder im Vordergrund
        stopBgTimer();
        self.registration.getNotifications({ tag: 'gps-bg' })
            .then(ns => ns.forEach(n => n.close()));
    }
});

// ── Notification Click → App in Vordergrund bringen ──────────────────────────
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(clients => {
                if (clients.length > 0) { clients[0].focus(); return; }
                return self.clients.openWindow('/index.php');
            })
    );
});

// ── Periodic Sync (Android Chrome PWA, falls vom Browser gewährt) ─────────────
// Minimales Intervall ist 12h – nur als Fallback, nicht für Live-Tracking.
self.addEventListener('periodicsync', event => {
    if (event.tag === 'gps-keepalive') {
        event.waitUntil(
            self.clients.matchAll({ includeUncontrolled: true, type: 'window' })
                .then(clients => clients.forEach(c => c.postMessage({ type: 'REQUEST_GPS' })))
        );
    }
});
