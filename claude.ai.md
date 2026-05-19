# Papiersammlung – KI-Kontext für neue Chats

Du hilfst mir eine PHP/MySQL Web-App namens **Papiersammlung** weiterzuentwickeln.
Ich lade jeweils die aktuellen Dateien als ZIP-Anhang hoch.

---

## App-Zweck
Echtzeit-Routenverfolgung für Pfadfinder-Papiersammlungen.
Fahrzeuge fahren vordefinierte Routen ab, GPS trackt den Fortschritt segment-genau.

---

## Tech-Stack (unveränderlich)
- **Hosting:** Netcup Shared Hosting – kein Node.js, kein background-PHP, kein JSON_LENGTH()
- **Backend:** PHP 8+ · MySQL · PDO
- **Frontend:** Leaflet.js (OpenStreetMap) · Vanilla JS · Browser Polling alle 2.5s
- **GPS:** Browser Geolocation API · OSRM für Strassensnapping (router.project-osrm.org)
- **PWA:** Service Worker + manifest.json
- **Design:** Dunkles Theme (`#0a0c0f`, Akzent `#00d4ff`) · Rajdhani + JetBrains Mono

---

## Dateistruktur
```
config.php          ← NIE überschreiben (echte DB-Zugangsdaten)
db.php              ← DB-Helfer, Geo-Algorithmen
auth.php            ← Session/Auth
api.php             ← Alle API-Endpunkte (?action=...)
index.php           ← Karte + Sidebar (Fahreransicht)
admin.php           ← Admin-Panel
login.php / logout.php
sw.js / manifest.json / favicon.svg
tmp/install.php     ← DB-Schema + idempotentes Upgrade (IMMER mitpflegen!)
tmp/migrate3.php    ← Diagnose-Tool
```

---

## Datenbank-Schema (aktuell)
```sql
users              (id, username, password_hash, role[admin/user], created_at)
route_templates    (id, name, color, coordinates[JSON [lat,lng][]],
                   description, created_at)
collections        (id, name, collection_date, status[draft/active/completed],
                   created_at)
collection_routes  (id, collection_id, template_id, name, color,
                   coordinates[JSON [lat,lng][]],
                   status[pending/active/completed/paused],
                   assigned_token, progress[0-100], visible, sort_order,
                   driven_segments[JSON bool[]])   ← pro Segment true/false
vehicles           (token, name, user_id, lat, lng,
                   status[idle/driving/paused/offline],
                   active_collection_id, active_route_id, last_seen)
```

**Koordinaten immer als `[lat, lng]`** (nicht GeoJSON-Reihenfolge).
OSRM-Routen-Endpunkt gibt `[lng, lat]` zurück → wird in admin.php zu `[lat, lng]` umgewandelt.

---

## API-Endpunkte (`api.php?action=...`)
```
state                – Routen + Fahrzeuge für eine Collection
collections_active / collections_all
collection_create / collection_update / collection_delete
col_routes_list / col_route_add / col_route_delete
templates_list / template_detail / template_create / template_update / template_delete
users_list / user_create / user_delete / user_change_password
vehicle_join         – Fahrzeug anmelden, gibt Token zurück
vehicle_position     – GPS-Position senden (?debug=1 für Diagnose-Output)
route_start / route_pause / route_resume / route_complete / route_reset / route_toggle
```

---

## Kern-Algorithmus: Segment-Erkennung (db.php)

### Datenmodell
- Route hat N Koordinaten → N-1 Segmente
- `driven_segments`: bool-Array der Länge N-1
- `progress`: 0-100% (Anteil true-Segmente)

### Algorithmus (Routen-Projektion, seit v3)
```
GPS-Update kommt rein → api.php vehicle_position:
  1. Fahrzeug-Record VOR Update lesen (enthält vorherige GPS-Position)
  2. Neue GPS-Position in DB schreiben
  3. Falls status='driving' UND active_route_id gesetzt:
     a. Aktuellen GPS auf Route projizieren → Segment B
     b. Vorherigen GPS auf Route projizieren → Segment A
     c. Alle Segmente A..B als true markieren
     d. progress neu berechnen, in DB schreiben
     e. Falls alle Segmente true → Route als 'completed' markieren
```

### Wichtige Funktionen in db.php
```php
project_onto_route($lat, $lng, $coords, $maxDist)
  → ['seg'=>int, 't'=>0..1, 'dist'=>float] | null
  // Findet nächstes Segment auf Route, null wenn > maxDist Meter entfernt

update_driven_segments($fromLat, $fromLng, $toLat, $toLng, $coords, $driven, $tolerance)
  → bool[]
  // Projiziert beide GPS-Punkte, markiert Segmente dazwischen

ensure_driven_segments_column()
  // Self-Healing: erstellt driven_segments Spalte falls fehlend (einmal pro Request)
```

### Toleranzen
- Mit OSRM-Snap: **20m** (GPS auf Strasse korrigiert)
- Ohne Snap: **30m** (rohes GPS ±15m + Puffer)
- Vorherige Position: Toleranz × 2.0 (kann weiter weg sein)
- Max. Segmente auf einmal: 50 (Schutz vor GPS-Sprüngen)

---

## Snap-Mechanismus (index.php)
```js
// Client sendet snap_lat/snap_lng wenn OSRM-Snap im Cache:
vehicleSnap[token] = {srcLat, srcLng, lat, lng}  // srcLat/Lng = GPS-Roh, lat/lng = Snap

// Threshold: 0.002° (~170m) – Snap wird bei normaler Fahrt immer mitgeschickt
// Server nutzt Snap für genauere Segment-Erkennung (kleinere Toleranz)
```

---

## Debug-Modus
```
POST api.php?action=vehicle_position&debug=1
Body: {token, lat, lng, collection_id, snap_lat?, snap_lng?}

Response enthält debug-Objekt mit:
  vehicle_status, active_route, has_snap, check_pos, prev_pos,
  tolerance_m, route_coords, route_segments,
  cur_proj, prev_proj, newly_driven, driven_total, progress, driven_map[0..19]
```

---

## Regeln für Änderungen

### IMMER
- `config.php` **niemals überschreiben**
- `tmp/install.php` **bei jeder Schema-Änderung mitpflegen**:
  - Neue Spalten in `$requiredCols` Array eintragen
  - Neue Tabellen als `CREATE TABLE IF NOT EXISTS` ergänzen
- Alle geänderten Dateien als ZIP ausgeben
- Koordinaten immer als `[lat, lng]` (niemals `[lng, lat]`)

### DB-Schema erweitern (Checkliste)
```
1. CREATE TABLE IF NOT EXISTS in install.php ergänzen
2. Spalte in $requiredCols von install.php eintragen
3. Spalte in db.php ensure_*_column() oder direkt prüfen
4. API-Endpunkt anpassen
5. Frontend anpassen
```

### Netcup Shared Hosting – Einschränkungen
- Kein `JSON_LENGTH()`, kein `JSON_EXTRACT()` in MySQL-Queries → PHP-seitig lösen
- Kein Background-PHP (kein Cron-Polling aus PHP heraus)
- Kein Node.js, kein WebSocket → Browser-Polling alle 2.5s
- PHP `exec()` / `shell_exec()` wahrscheinlich gesperrt

---

## Bekannte frühere Bugs (nicht nochmal einbauen)
| Bug | Ursache | Fix |
|-----|---------|-----|
| Segmente bleiben immer rot | `driven_segments` Spalte fehlte → silent DB error | `ensure_driven_segments_column()` + install.php |
| Kurven nie erkannt | Lineare Interpolation statt Routen-Projektion | `project_onto_route()` Algorithmus |
| Snap wird nicht gesendet | Threshold 0.0003° zu klein | Threshold auf 0.002° erhöht |
| Falsches Koordinaten-Format | OSRM gibt [lng,lat] zurück | Umwandlung in admin.php zu [lat,lng] |

---

## Antwort-Format (bitte einhalten)
1. **Analyse** – Was ist das Problem / was wird geändert (kurz)
2. **Geänderte Dateien** – Vollständige Dateiinhalte, nie nur Diffs
3. **ZIP** – Alle geänderten Dateien als Download, **ohne config.php**
4. `tmp/install.php` **immer mitliefern** wenn Schema geändert wurde
