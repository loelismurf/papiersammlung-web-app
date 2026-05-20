Papiersammlung - KI-Kontext fuer neue Chats

Du hilfst mir eine PHP/MySQL Web-App namens Papiersammlung weiterzuentwickeln.
Ich lade jeweils die aktuellen Dateien als ZIP-Anhang hoch.

---

App-Zweck
Echtzeit-Routenverfolgung fuer Pfadfinder-Papiersammlungen.
Fahrzeuge fahren vordefinierte Routen ab, GPS trackt den Fortschritt segment-genau.

---

Tech-Stack (unveraenderlich)
- Hosting: Netcup Shared Hosting - kein Node.js, kein background-PHP, kein JSON_LENGTH()
- Backend: PHP 8+ / MySQL / PDO
- Frontend: Leaflet.js (OpenStreetMap) / Vanilla JS / Browser Polling alle 2.5s
- GPS: Browser Geolocation API (liefert auch speed in m/s) / OSRM fuer Strassensnapping
- PWA: Service Worker (sw.js) + manifest.json
- Design: Dunkles Theme (#0a0c0f, Akzent #00d4ff) / Rajdhani + JetBrains Mono
- NoSleep.js (cdnjs, 0.12.0): verhindert Bildschirm-Dunkel auf iOS

---

Dateistruktur
config.php          <- NIE ueberschreiben
db.php              <- DB-Helfer, Geo-Algorithmen, Gap-Fill
auth.php            <- Session/Auth
api.php             <- Alle API-Endpunkte
index.php           <- Karte + Sidebar (Fahreransicht)
admin.php           <- Admin-Panel
sw.js               <- Service Worker (Background-GPS)
login.php / logout.php
manifest.json / favicon.svg
tmp/install.php     <- DB-Schema v5 (IMMER mitpflegen!)

---

Datenbank-Schema (v5)

users              (id, username, password_hash, role[admin/user], created_at)
route_templates    (id, name, color, coordinates[JSON], description, created_at)
collections        (id, name, collection_date, status[draft/active/completed], created_at)
collection_routes  (id, collection_id, template_id, name, color,
                   coordinates[JSON [lat,lng][]],
                   status[pending/active/completed/paused] DEFAULT 'active',
                   assigned_token, progress[0-100], visible, sort_order,
                   driven_segments[JSON bool[]])
vehicles           (token, name, user_id, lat, lng,
                   status[idle/driving/paused/offline],
                   active_collection_id, active_route_id,
                   collecting[0/1],
                   last_seen)
                   INDEX idx_user (user_id)
vehicle_tracks     (id BIGINT AUTO_INCREMENT,
                   token, collection_id,
                   lat, lng, speed[m/s float],
                   recorded_at DATETIME)
                   INDEX idx_track (token, collection_id, recorded_at)

Koordinaten immer als [lat, lng]. OSRM gibt [lng, lat] -> admin.php wandelt um.

---

Route-Konzept (v5 - vereinfacht)

- Routen haben keinen manuellen Start/Stop mehr
- Neue Routen werden direkt mit status='active' angelegt
- Alle bestehenden 'pending' Routen wurden per Migration auf 'active' gesetzt
- Automatisches Tracking: vehicle_position iteriert ALLE non-completed Routen
  der Collection und markiert Segmente wo das Fahrzeug faehrt
- Auto-assign: assigned_token wird auf das zuletzt fahrende Fahrzeug gesetzt
- Auto-complete: wenn alle Segmente true -> status='completed'
- Einzige manuelle Aktion: Route zuruecksetzen (Admin, setzt auf status='active' wieder)
- Keine route_start / route_pause / route_resume / route_complete Endpunkte mehr

---

Fahrzeug-Konzept (v4+)

- 1 Fahrzeug pro User (via user_id)
- Auto-Join beim Seitenstart (vehicle_join findet Fahrzeug via user_id)
- Fahrzeugname: Default=Benutzername, umbenennbar via vehicle_rename
- Sammelmodus (collecting):
    collecting=0, status='idle'  -> sichtbar, kein Tracking, kein Track-Punkt
    collecting=1, status='driving' -> GPS wird aufgezeichnet, Segmente markiert
- vehicle_set_collecting setzt collecting UND status gleichzeitig

---

API-Endpunkte (api.php?action=...)

state                   - Routen + Fahrzeuge (inkl. collecting bool)
collections_active / collections_all
collection_create / collection_update / collection_delete
col_routes_list / col_route_add / col_route_delete
templates_list / template_detail / template_create / template_update / template_delete
users_list / user_create / user_delete / user_change_password
vehicle_join            - Fahrzeug finden/erstellen (1 pro User, by user_id)
vehicle_rename          - Umbenennen (nur eigenes)
vehicle_set_collecting  - Sammelmodus {token, collecting:bool}
vehicle_position        - GPS senden; trackt alle Routen wenn collecting=1
vehicle_track           - GET: Fahrspur abrufen {token, collection_id}
route_reset             - Admin: Route zuruecksetzen (-> status='active', segments=null)
route_toggle            - Admin: Sichtbarkeit umschalten

---

Background-GPS (sw.js + index.php) - 3-Schichten-Architektur

Schicht 1: fetch(..., keepalive:true)
  Laeuft auch nach Tab-Wechsel weiter. Unterstuetzt in ALLEN modernen Browsern
  (Chrome, Samsung Internet, Ecosia, Edge, Brave, Opera, Firefox).
  sendGPS() verwendet immer keepalive:true, unabhaengig von Sichtbarkeit.

Schicht 2: bgPageTimer (setInterval im Hauptthread, alle 6s)
  Startet wenn App in HG geht (visibilitychange='hidden') UND collecting=1.
  Feuert sendGPS() wenn watchPosition seit >8s keine Daten geliefert hat.
  Laeuft auf Android auch im Hintergrund-Tab weiter.
  Variable: bgPageTimer, lastGpsSentAt

Schicht 3: SW-Notification (sw.js)
  SW zeigt persistente Notification -> haelt Browser-Prozess am Leben (Android).
  SW-interne setInterval als weiterer Fallback (REQUEST_GPS -> Client).
  SW Message Types:
    BG_START   <- index.php wenn App in HG + collecting=1
    BG_STOP    <- index.php wenn App wieder im VG
    GPS_UPDATE <- index.php: letzter GPS-Stand fuer SW-Fallback
    REQUEST_GPS -> SW an Client

Browser-Kompatibilitaet:
  Android Chrome, Samsung Internet, Ecosia, Edge, Brave, Opera: alle 3 Schichten
  Firefox Android: Schicht 1+2 (SW-Notification variiert)
  iOS Safari/Chrome/Ecosia: kein Hintergrund moeglich (WebKit-Einschraenkung)

iOS-Workaround: NoSleep.js
  Verhindert Bildschirm-Dunkel auf iOS via stilles Video-Loop.
  WICHTIG: noSleep.enable() NUR aus User-Geste aufrufen (z.B. toggleCollecting()).
  NIEMALS aus autoJoin(), loadCollections() oder anderen Auto-Funktionen aufrufen!
  Grund: Video-Autoplay braucht zwingend eine User-Geste, sonst blockiert Browser.
  noSleep.enable() wird aufgerufen in: toggleCollecting() wenn collecting=true
  noSleep.disable() wird aufgerufen in: releaseWakeLock()
  WakeLock API (Android) braucht keine User-Geste und bleibt in requestWakeLock().

isIOS-Erkennung:
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
             || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

---

Fahrspur (vehicle_tracks)

- Jeder GPS-Punkt bei collecting=1 wird in vehicle_tracks gespeichert (inkl. speed m/s)
- API: vehicle_track GET {token, collection_id} -> letzte 800 Punkte als [[lat,lng],...]
- Index.php: Schaltflaeche pro Fahrzeug in der Fahrzeugliste toggelt Fahrspur
- Fahrspur = gestrichelte Polyline in Fahrzeugfarbe, wird alle ~10s aktualisiert
- Tracks werden beim Loeschen einer Collection mitgeloescht

---

Geschwindigkeitsanzeige

- GPS-Position liefert coords.speed (m/s, kann null sein)
- Angezeigt im GPS-Bar als "X.X km/h" Badge
- Im Fahrzeug-Marker: speed > 5 km/h -> kleine Zahl unter dem Marker-Kreis
- Im Tooltip: Geschwindigkeit wenn verfuegbar

---

Fahrzeug-Marker-Animation

- CSS-Klasse 'animated' auf .leaflet-marker-icon: transition:transform 2200ms linear
- Sprung >100m: 'animated' kurz entfernen -> Position setzen -> 'animated' wieder hinzufuegen
- Funktion distM(lat1,lng1,lat2,lng2): Haversine-Distanz in Metern (JS-seitig)
- Verhindert dass GPS-Spruenge (z.B. nach Tunnels) animiert werden

---

Nicht-Sammeln-Warnung

- Wenn Geschwindigkeit > 2 km/h UND collecting=0:
  -> Oranges blinkendes Banner auf der Karte
- Antippen: oeffnet Sidebar (falls Mobile-kollabiert) + aktiviert Sammelmodus direkt

---

Benachrichtigungen (notify)

- Anzeigedauer: 7000ms (7 Sekunden)
- Typen: '' (accent/blau), 'g' (gruen), 'w' (orange/Warnung)

---

Mobile Sidebar

- Handle-Leiste (44px) ist als erstes Kind von #sidebar, AUSSERHALB von #sidebar-inner
- Beim Kollabieren (height:44px): Handle sichtbar, sidebar-inner versteckt
- Floating-Button #mob-open-btn auf der Karte erscheint wenn kollabiert
- Handle zeigt Sammelmodus-Indikator: Emoji 'grüner Kreis' wenn sammeln, Hamburger sonst

---

Kern-Algorithmus: Segment-Erkennung (db.php)

Datenmodell
- Route hat N Koordinaten -> N-1 Segmente
- driven_segments: bool-Array der Laenge N-1
- progress: 0-100% (Anteil true-Segmente)

Algorithmus
GPS-Update -> api.php vehicle_position:
  1. Fahrzeug lesen (vorherige Position)
  2. Neue GPS-Position + Track-Punkt speichern (nur wenn collecting=1)
  3. Alle non-completed Routen der Collection laden
  4. Fuer jede Route: GPS auf Route projizieren
     -> Wenn innerhalb Toleranz: Segmente markieren + fill_small_gaps()
     -> Route auf 'active' + assigned_token setzen
     -> Falls alle Segmente true: Route auf 'completed'

Gap-Fill: fill_small_gaps(driven, maxGap=4)
  Luecken von <=4 false-Segmenten zwischen true-Segmenten werden gefuellt.

Toleranzen
- Mit OSRM-Snap: 20m / Ohne Snap: 30m
- Vorherige Position: Toleranz x 2.0
- Max. Segmente auf einmal: 50

---

Regeln fuer Aenderungen

IMMER
- config.php niemals ueberschreiben
- tmp/install.php bei jeder Schema-Aenderung mitpflegen
- Koordinaten immer als [lat, lng]
- Alle geaenderten Dateien als ZIP ausgeben

Netcup Shared Hosting
- Kein JSON_LENGTH(), JSON_EXTRACT() -> PHP-seitig
- Kein Background-PHP, kein Node.js, kein WebSocket
- Browser-Polling alle 2.5s

---

Bekannte Bugs (gefixt, nicht nochmal einbauen)

Segmente rot bleiben         | driven_segments fehlte           | ensure_driven_segments_column()
Kurven nicht erkannt         | Lineare Interpolation            | project_onto_route()
Snap nicht gesendet          | Threshold zu klein               | 0.002 deg Threshold
Falsches Koordinatenformat   | OSRM [lng,lat]                   | Umwandlung in admin.php
Mehrere Fahrzeuge/User       | Token-basierter Join             | vehicle_join by user_id
Luecken auf Geraden          | GPS-Ausreisser                   | fill_small_gaps()
Mobile Sidebar kein Aufklappen | Handle im Scroll-Container    | Handle ausserhalb + Floating-Button
Sprunganimation bei GPS-Sprung | immer animiert                 | distM() Pruefung >100m -> kein animated
Browser blockiert Permission   | noSleep.enable() ohne Geste    | NUR in toggleCollecting() aufrufen

---

Antwort-Format
1. Analyse (kurz)
2. Geaenderte Dateien (vollstaendig, kein Diff)
3. ZIP ohne config.php
4. tmp/install.php wenn Schema geaendert
5. claude.ai.md updaten (txt, keine Formatierung)
