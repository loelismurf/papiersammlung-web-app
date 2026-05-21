Papiersammlung - KI-Kontext fuer neue Chats

Du hilfst mir eine PHP/MySQL Web-App + Android-App namens Papiersammlung weiterzuentwickeln.
Ich lade jeweils die aktuellen Dateien als ZIP-Anhang hoch.

---

App-Zweck
Echtzeit-Routenverfolgung fuer Pfadfinder-Papiersammlungen.
Fahrzeuge fahren vordefinierte Routen ab, GPS trackt den Fortschritt segment-genau.

---

Tech-Stack Web (unveraenderlich)
- Hosting: Netcup Shared Hosting - kein Node.js, kein background-PHP, kein JSON_LENGTH()
- Backend: PHP 8+ / MySQL / PDO
- Frontend: Leaflet.js (OpenStreetMap) / Vanilla JS / Browser Polling alle 2.5s
- GPS: Browser Geolocation API (liefert auch speed in m/s) / OSRM fuer Strassensnapping
- PWA: Service Worker (sw.js) + manifest.json
- Design: Dunkles Theme (#0a0c0f, Akzent #00d4ff) / Rajdhani + JetBrains Mono
- NoSleep.js (cdnjs, 0.12.0): verhindert Bildschirm-Dunkel auf iOS

Tech-Stack Android-App (Source/Android/)
- Sprache: Kotlin, minSdk 26, targetSdk 34
- Karte: OSMDroid (OpenStreetMap, kein Google Maps)
- Netzwerk: OkHttp 4.x
- Hintergrund-GPS: ForegroundService (GpsService.kt) + WakeLock
- Offline-Puffer: SQLite via OfflineBuffer.kt (kein Room)
- Auth: Bearer-Token (30 Tage, via mobile_login API-Endpoint)
- Build: Gradle 8.2, Android Studio Hedgehog+

---

Dateistruktur Web
config.php          <- NIE ueberschreiben
db.php              <- DB-Helfer, Geo-Algorithmen, Gap-Fill, ensure_api_tokens_table()
auth.php            <- Session/Auth
api.php             <- Alle API-Endpunkte + CORS + Bearer-Token-Auth
index.php           <- Karte + Sidebar (Fahreransicht)
admin.php           <- Admin-Panel
sw.js               <- Service Worker (Background-GPS)
login.php / logout.php
manifest.json / favicon.svg
push.php            <- Web Push Notifications
tmp/install.php     <- DB-Schema v5.1 (IMMER mitpflegen!)

Dateistruktur Android (Source/Android/)
app/src/main/java/ch/papiersammlung/app/
  PapiersammlungApp.kt   <- Application, Notification-Channels
  MainActivity.kt        <- Karte + Sidebar + GPS-Broadcast-Receiver
  LoginActivity.kt       <- Login-Screen (Server-URL + Credentials)
  SettingsActivity.kt    <- Abmelden, Info
  GpsService.kt          <- ForegroundService: GPS im Hintergrund + WakeLock
  SyncService.kt         <- Offline-Puffer beim Reconnect leeren
  ApiClient.kt           <- OkHttp REST-Client mit Bearer-Auth
  OfflineBuffer.kt       <- SQLite GPS-Puffer fuer Offline-Betrieb
  AppPrefs.kt            <- SharedPreferences: Token, URL, Session

---

Datenbank-Schema (v5.1)

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
api_tokens         (id, token VARCHAR(64) UNIQUE, user_id, expires_at, last_used, created_at)
                   INDEX idx_token, idx_user
                   <- NEU v5.1: Bearer-Token-Auth fuer Mobile-Apps

Koordinaten immer als [lat, lng]. OSRM gibt [lng, lat] -> admin.php wandelt um.

---

Route-Konzept (v5 - vereinfacht)

- Routen haben keinen manuellen Start/Stop mehr
- Neue Routen werden direkt mit status='active' angelegt
- Automatisches Tracking: vehicle_position iteriert ALLE non-completed Routen
- Auto-assign: assigned_token wird auf das zuletzt fahrende Fahrzeug gesetzt
- Auto-complete: wenn alle Segmente true -> status='completed'
- Einzige manuelle Aktion: Route zuruecksetzen (Admin)

---

Fahrzeug-Konzept (v4+)

- 1 Fahrzeug pro User (via user_id)
- Auto-Join beim Seitenstart (vehicle_join findet Fahrzeug via user_id)
- Sammelmodus: collecting=0 status='idle' / collecting=1 status='driving'

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
vehicle_ping            - Nur last_seen aktualisieren (idle Fahrzeuge sichtbar halten) <- NEU v5.1
vehicle_track           - GET: Fahrspur abrufen {token, collection_id}
route_reset             - Admin: Route zuruecksetzen
route_toggle            - Admin: Sichtbarkeit umschalten
mobile_login            - POST {username,password} -> {token,expires_at} Bearer-Token <- NEU v5.1
get_vapid_key / push_subscribe - Web Push

---

API-Authentifizierung (v5.1)

Web-App: Session-Cookie (PHP-Session wie bisher)
Mobile-App: Bearer-Token im Authorization-Header
  POST api.php?action=mobile_login mit {username, password}
  Response: {token, expires_at, username, role}
  Alle weiteren Requests: Header "Authorization: Bearer <token>"
  Alternativ: Header "X-Auth-Token: <token>" oder Body-Feld "auth_token"
  Token-Gueltigkeit: 30 Tage, danach neuer Login noetig
  Tabelle: api_tokens (auto-erstellt via ensure_api_tokens_table())

CORS: Access-Control-Allow-Origin: * fuer alle api.php-Responses (Mobile-kompatibel)

---

Background-GPS Web (sw.js + index.php) - 3-Schichten-Architektur

Schicht 1: fetch(..., keepalive:true)
  Laeuft auch nach Tab-Wechsel. sendGPS() immer mit keepalive:true.
  BUGFIX v5.1: GPS wird auch im Idle gesendet (nicht nur beim Sammeln)

Schicht 2: bgPageTimer (setInterval, alle 6s)
  BUGFIX v5.1: Startet jetzt IMMER wenn App in HG geht (auch idle).
  Prueft ob watchPosition seit >8s keine Daten geliefert hat.
  Sendet sendGPS() als Fallback (auch ohne collecting).

Schicht 3: SW-Notification (BG_START/BG_STOP)
  Nur noch beim Sammeln (collecting=1) ausgeloest.

Vehicle-Ping: pollState() sendet alle 2.5s vehicle_ping -> last_seen aktualisiert
  -> Idle-Fahrzeuge verschwinden nicht nach 60s (BUGFIX v5.1)

---

Background-GPS Android (GpsService.kt)

ForegroundService mit persistenter Notification.
GPS-Interval: 3s beim Sammeln, 30s im Idle.
WakeLock: PARTIAL_WAKE_LOCK (CPU wach, Bildschirm kann aus).
Broadcast: Intent(BROADCAST_LOCATION) an MainActivity fuer Live-Karte.
Offline-Modus: GPS-Punkte in OfflineBuffer (SQLite) speichern.
Sync: alle 15s pruefen ob online, dann Puffer leeren (sequenziell).
START_STICKY: Service wird nach Kill vom System neu gestartet.

---

Fahrspur (vehicle_tracks)

- Jeder GPS-Punkt bei collecting=1 wird in vehicle_tracks gespeichert (inkl. speed m/s)
- API: vehicle_track GET {token, collection_id} -> letzte 800 Punkte als [[lat,lng],...]
- index.php: Fahrspur-Toggle-Button (🛣) pro Fahrzeug in der Liste
- BUGFIX v5.1: Fahrspur wird automatisch angezeigt wenn Sammeln startet (kein manueller Klick noetig)
- Fahrspur = gestrichelte Polyline in Fahrzeugfarbe, alle ~10s aktualisiert
- Tracks werden beim Loeschen einer Collection mitgeloescht

---

Follow-Modus Karte (NEU v5.1)

Web-App:
  followMode = true (Standard: Karte folgt GPS)
  map.on('dragstart'): followMode = false, 🎯-Button erscheint (orange)
  🎯-Button Click: followMode = true, Karte springt zur aktuellen Position
  btn-follow: display:none wenn followMode=true, sichtbar wenn false
  setFollowMode(bool): aktualisiert followMode + Button-Style
  showMapBtns(): zeigt alle 3 Buttons (locate, fit-all, follow) + setzt followMode=true

Android:
  followMode in MainActivity
  map.setOnTouchListener: followMode=false, btn_follow erscheint
  btn_follow Click: followMode=true, animateTo(selfMarker)

---

Eigener GPS-Marker Web (selfGpsMarker) - NEU v5.1

Problem: State-Poll-basierter Marker erscheint erst nach naechstem Poll (2.5s Verzoegerung)
         und verschwindet wenn Fahrzeug kein lat/lng hat (erstes Laden).
Loesung: selfGpsMarker = separater Leaflet-Marker direkt aus watchPosition
  - Gold (#ffd700), 18px, zIndexOffset:2000 (immer vorne)
  - Permanent-Tooltip: Name + Sammelmodus-Status
  - Wird in watchPosition-Handler aktualisiert (jede GPS-Messung)
  - Im vehicles.forEach wird self uebersprungen (selfGpsMarker hat Vorrang)
  - updateSelfMarker(): erstellt oder aktualisiert den Marker
  - removeSelfMarker(): beim Logout/Disconnect entfernen

---

Idle-Fahrzeug-Sichtbarkeit (BUGFIX v5.1)

Vorher: Idle-Fahrzeug-Marker hatte Farbe #4a5a6a (fast schwarz auf dunklem Hintergrund)
Jetzt:  Farbe #7a9ab0 (sichtbares Blaugrau)
Vorher: bgPageTimer und visibilitychange nur bei isCollecting=true aktiv
Jetzt:  bgPageTimer laeuft immer wenn joined (auch idle)
Vorher: vehicle_ping fehlte -> Fahrzeuge gingen nach 60s offline
Jetzt:  pollState() sendet vehicle_ping jede Runde

---

Fahrzeug-Marker-Animation

CSS-Klasse 'animated' auf .leaflet-marker-icon: transition:transform 2200ms linear
Sprung >100m: 'animated' entfernen -> Position setzen -> wieder hinzufuegen
distM(lat1,lng1,lat2,lng2): Haversine in Metern (JS)

---

Geschwindigkeitsanzeige

GPS liefert coords.speed (m/s, kann null sein)
Im GPS-Bar als "X.X km/h" Badge
Im Fahrzeug-Marker: speed > 5 km/h -> kleine Zahl unter dem Kreis

---

Mobile Sidebar

Handle-Leiste (44px) als erstes Kind von #sidebar, AUSSERHALB von #sidebar-inner
Floating-Button #mob-open-btn auf Karte wenn kollabiert

---

Kern-Algorithmus: Segment-Erkennung (db.php)

GPS-Update -> vehicle_position:
  1. Fahrzeug lesen (vorherige Position)
  2. Neue GPS-Position + Track-Punkt speichern (nur wenn collecting=1)
  3. Alle non-completed Routen laden
  4. Fuer jede Route: GPS projizieren, Segmente markieren, fill_small_gaps()
  5. Auto-complete wenn alle Segmente true

Gap-Fill: fill_small_gaps(driven, maxGap=4)
Toleranzen: OSRM 20m / Kein Snap 30m / Vorherige Position x2.0

---

Offline-Puffer Android (OfflineBuffer.kt)

Tabelle gps_buffer: id, token, collection_id, lat, lng, speed, snap_lat, snap_lng, recorded_at
buffer(): GPS-Punkt lokal speichern
getPending(limit): Punkte abrufen (aelteste zuerst)
deleteIds(ids): erfolgreich gesendete loeschen
count(): Anzahl gepufferter Punkte
cleanup(): Punkte >24h loeschen

GpsService: alle 15s periodicSync() -> getPending(50) -> ApiClient.sendPosition() -> deleteIds()
MainActivity: updateBufferCount() zeigt Anzahl gepufferter Punkte an (orange Banner)

---

Regeln fuer Aenderungen

IMMER
- config.php niemals ueberschreiben
- tmp/install.php bei jeder Schema-Aenderung mitpflegen
- Koordinaten immer als [lat, lng]
- Alle geaenderten Dateien als ZIP ausgeben
- Android: Source/Android/ Ordner mitliefern

Netcup Shared Hosting
- Kein JSON_LENGTH(), JSON_EXTRACT() -> PHP-seitig
- Kein Background-PHP, kein Node.js, kein WebSocket
- Browser-Polling alle 2.5s

Android Build
- Gradle 8.2, Android Studio Hedgehog+
- assembleDebug: ./gradlew assembleDebug
- APK: app/build/outputs/apk/debug/app-debug.apk

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
Idle-Position nicht sichtbar   | bgPageTimer nur bei collecting | bgPageTimer immer + vehicle_ping (v5.1)
Idle-Marker unsichtbar         | Farbe #4a5a6a (zu dunkel)      | Farbe #7a9ab0 (v5.1)
GPS-Track nicht automatisch    | Nur manuell via Knopf          | Auto-start beim Sammeln (v5.1)
Karte folgt nicht GPS          | Kein Follow-Modus              | followMode + 🎯-Button (v5.1)

---

Antwort-Format
1. Analyse (kurz)
2. Geaenderte Dateien (vollstaendig, kein Diff)
3. ZIP ohne config.php
4. tmp/install.php wenn Schema geaendert
5. claude.ai.md updaten (txt, keine Formatierung)
