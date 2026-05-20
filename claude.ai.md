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
- GPS: Browser Geolocation API / OSRM fuer Strassensnapping (router.project-osrm.org)
- PWA: Service Worker + manifest.json
- Design: Dunkles Theme (#0a0c0f, Akzent #00d4ff) / Rajdhani + JetBrains Mono

---

Dateistruktur
config.php          <- NIE ueberschreiben (echte DB-Zugangsdaten)
db.php              <- DB-Helfer, Geo-Algorithmen, Gap-Fill
auth.php            <- Session/Auth
api.php             <- Alle API-Endpunkte (?action=...)
index.php           <- Karte + Sidebar (Fahreransicht)
admin.php           <- Admin-Panel
login.php / logout.php
sw.js / manifest.json / favicon.svg
tmp/install.php     <- DB-Schema + idempotentes Upgrade (IMMER mitpflegen!)
tmp/migrate3.php    <- Diagnose-Tool

---

Datenbank-Schema (aktuell, v4)

users              (id, username, password_hash, role[admin/user], created_at)
route_templates    (id, name, color, coordinates[JSON [lat,lng][]], description, created_at)
collections        (id, name, collection_date, status[draft/active/completed], created_at)
collection_routes  (id, collection_id, template_id, name, color,
                   coordinates[JSON [lat,lng][]],
                   status[pending/active/completed/paused],
                   assigned_token, progress[0-100], visible, sort_order,
                   driven_segments[JSON bool[]])
vehicles           (token, name, user_id, lat, lng,
                   status[idle/driving/paused/offline],
                   active_collection_id, active_route_id,
                   collecting[0/1],    <- NEU v4: Sammelmodus-Flag
                   last_seen)
                   INDEX idx_user (user_id)

Koordinaten immer als [lat, lng] (nicht GeoJSON-Reihenfolge).
OSRM-Routen-Endpunkt gibt [lng, lat] zurueck -> wird in admin.php zu [lat, lng] umgewandelt.

---

Fahrzeug-Konzept (v4)

- 1 Fahrzeug pro User: vehicle_join findet Fahrzeug via user_id (kein manuelles Token mehr).
- Auto-Join: index.php ruft vehicle_join automatisch beim Laden auf, kein Formular.
- Fahrzeug-Name: Default = Benutzername, kann umbenannt werden (vehicle_rename API).
- Sammelmodus (collecting):
    collecting=0 -> Fahrzeug sichtbar auf Karte, aber KEINE Segment-Aufzeichnung
    collecting=1 -> GPS wird aufgezeichnet, Segmente werden als abgefahren markiert
    Umschaltbar per "Am Sammeln / Nicht am Sammeln" Button in index.php

---

API-Endpunkte (api.php?action=...)

state                - Routen + Fahrzeuge fuer eine Collection
collections_active / collections_all
collection_create / collection_update / collection_delete
col_routes_list / col_route_add / col_route_delete
templates_list / template_detail / template_create / template_update / template_delete
users_list / user_create / user_delete / user_change_password
vehicle_join         - Fahrzeug anmelden/finden (1 pro User, via user_id)
vehicle_rename       - Fahrzeug umbenennen (nur eigenes, via token + user_id)
vehicle_set_collecting - Sammelmodus ein/aus {token, collecting:bool}
vehicle_position     - GPS-Position senden (?debug=1 fuer Diagnose-Output)
                       Segment-Tracking nur wenn collecting=1 UND status=driving
route_start / route_pause / route_resume / route_complete / route_reset / route_toggle

---

Kern-Algorithmus: Segment-Erkennung (db.php)

Datenmodell
- Route hat N Koordinaten -> N-1 Segmente
- driven_segments: bool-Array der Laenge N-1
- progress: 0-100% (Anteil true-Segmente)

Algorithmus (Routen-Projektion, v3)
GPS-Update kommt rein -> api.php vehicle_position:
  1. Fahrzeug-Record VOR Update lesen (enthaelt vorherige GPS-Position)
  2. Neue GPS-Position in DB schreiben
  3. Falls status=driving UND active_route_id UND collecting=1:
     a. Aktuellen GPS auf Route projizieren -> Segment B
     b. Vorherigen GPS auf Route projizieren -> Segment A
     c. Alle Segmente A..B als true markieren
     d. fill_small_gaps() anwenden (kleine Luecken auf geraden schliessen)
     e. progress neu berechnen, in DB schreiben
     f. Falls alle Segmente true -> Route als completed markieren

Gap-Fill-Optimierung (neu v4)
Funktion: fill_small_gaps(array $driven, int $maxGap = 4): array
  Sucht Laeufe von false-Segmenten die von true-Segmenten eingeschlossen sind.
  Wenn Laenge <= maxGap (Standard: 4 Segmente = ca. 80m bei 20m Segmenten):
    -> Luecke wird als abgefahren markiert.
  Begruendung: Wenn vor und nach einer kurzen Luecke gefahren wurde, ist das
  Fahrzeug garantiert durchgefahren (GPS-Ausreisser oder kurzer Empfangsverlust).
  fill_small_gaps() wird am Ende von update_driven_segments() aufgerufen.

Wichtige Funktionen in db.php
project_onto_route($lat, $lng, $coords, $maxDist)
  -> [seg=>int, t=>0..1, dist=>float] | null
update_driven_segments($fromLat, $fromLng, $toLat, $toLng, $coords, $driven, $tolerance)
  -> bool[] (inkl. fill_small_gaps am Ende)
fill_small_gaps(array $driven, int $maxGap = 4): array
ensure_driven_segments_column() / ensure_collecting_column()
  -> Self-Healing, wird in cleanup_vehicles() aufgerufen

Toleranzen
- Mit OSRM-Snap: 20m (GPS auf Strasse korrigiert)
- Ohne Snap: 30m (rohes GPS +-15m + Puffer)
- Vorherige Position: Toleranz x 2.0 (kann weiter weg sein)
- Max. Segmente auf einmal: 50 (Schutz vor GPS-Spruengen)

---

Snap-Mechanismus (index.php)
// Client sendet snap_lat/snap_lng wenn OSRM-Snap im Cache:
vehicleSnap[token] = {srcLat, srcLng, lat, lng}
// Threshold: 0.002 deg (~170m) - Snap wird bei normaler Fahrt immer mitgeschickt
// Server nutzt Snap fuer genauere Segment-Erkennung (kleinere Toleranz)

---

Debug-Modus
POST api.php?action=vehicle_position&debug=1
Body: {token, lat, lng, collection_id, snap_lat?, snap_lng?}
Response enthaelt debug-Objekt mit:
  vehicle_status, active_route, has_snap, collecting,
  check_pos, prev_pos, tolerance_m, route_coords, route_segments,
  cur_proj, prev_proj, newly_driven, driven_total, progress,
  driven_map[0..19], tracking_skipped (falls collecting=0)

---

Admin-Panel (admin.php)
- Sammlungen: erstellen, Status aendern, loeschen, Routen verwalten
- Routen-Vorlagen: erstellen mit Leaflet-Karte + OSRM-Snap/Routing
- Benutzer:
    Neuen Benutzer anlegen (Benutzername, Passwort, Rolle admin/user)
    Passwort aendern (fuer beliebigen User als Admin, oder eigenes als User)
    User loeschen (loescht auch verknuepftes Fahrzeug)

---

Regeln fuer Aenderungen

IMMER
- config.php niemals ueberschreiben
- tmp/install.php bei jeder Schema-Aenderung mitpflegen:
    Neue Spalten in $requiredCols Array eintragen
    Neue Tabellen als CREATE TABLE IF NOT EXISTS ergaenzen
- Alle geaenderten Dateien als ZIP ausgeben
- Koordinaten immer als [lat, lng] (niemals [lng, lat])

DB-Schema erweitern (Checkliste)
1. CREATE TABLE IF NOT EXISTS in install.php ergaenzen
2. Spalte in $requiredCols von install.php eintragen
3. Spalte in db.php ensure_*_column() oder direkt pruefen
4. API-Endpunkt anpassen
5. Frontend anpassen

Netcup Shared Hosting - Einschraenkungen
- Kein JSON_LENGTH(), kein JSON_EXTRACT() in MySQL-Queries -> PHP-seitig loesen
- Kein Background-PHP (kein Cron-Polling aus PHP heraus)
- Kein Node.js, kein WebSocket -> Browser-Polling alle 2.5s
- PHP exec() / shell_exec() wahrscheinlich gesperrt

---

Bekannte frueherer Bugs (nicht nochmal einbauen)

Bug                          | Ursache                          | Fix
Segmente bleiben immer rot   | driven_segments Spalte fehlte    | ensure_driven_segments_column() + install.php
Kurven nie erkannt           | Lineare Interpolation statt Proj | project_onto_route() Algorithmus
Snap wird nicht gesendet     | Threshold 0.0003 deg zu klein    | Threshold auf 0.002 deg erhoeht
Falsches Koordinaten-Format  | OSRM gibt [lng,lat] zurueck      | Umwandlung in admin.php zu [lat,lng]
Mehrere Fahrzeuge pro User   | Token-basierter Join ohne User   | vehicle_join sucht via user_id (v4)
Luecken auf Geraden (GPS)    | Einzelne GPS-Ausreisser          | fill_small_gaps() in update_driven_segments (v4)

---

Antwort-Format (bitte einhalten)
1. Analyse - Was ist das Problem / was wird geaendert (kurz)
2. Geaenderte Dateien - Vollstaendige Dateiinhalte, nie nur Diffs
3. ZIP - Alle geaenderten Dateien als Download, ohne config.php
4. tmp/install.php immer mitliefern wenn Schema geaendert wurde
5. claude.ai.md immer updaten damit wir mit dir in einem neuen Prompt arbeiten koennen format txt ohne formatierung
