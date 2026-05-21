# Server-Anpassungen für Vehicle Track Feature (v5.6)

## 📊 Datenbank

### Status: ✅ KEINE NEUE TABELLE NÖTIG!

Die Tabelle `vehicle_tracks` existiert bereits und wird bereits genutzt:

```sql
CREATE TABLE vehicle_tracks (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    token         VARCHAR(64)  NOT NULL,
    collection_id VARCHAR(32)  NOT NULL,
    lat           DOUBLE       NOT NULL,
    lng           DOUBLE       NOT NULL,
    speed         FLOAT        DEFAULT NULL COMMENT 'm/s',
    recorded_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_track (token, collection_id, recorded_at)
)
```

**Funktionsweise**:
- Speichert automatisch GPS-Punkte beim `vehicle_position` API-Call
- Nur während `collecting=1` (Datenschutz)
- Bis zu 800 neuste Punkte werden zur Android App übertragen

---

## 🔧 API-Anpassung (WICHTIG!)

### Problem gefunden:
Der Endpoint `vehicle_track` antwortet mit:
```json
{
  "track": [[lat, lng], ...],
  "count": 123
}
```

Aber die Android App v5.6 erwartet:
```json
{
  "points": [[lat, lng], ...],
  "count": 123,
  "latest_at": "2024-05-21 12:34:56"
}
```

### Lösung: `api.php` Zeilen 353-364 ersetzen

**Datei**: `api.php`
**Zeilen**: 353-364 (case 'vehicle_track':)
**Aktion**: Siehe `api_patch_vehicle_track.php.txt`

---

## 📝 Änderungen auf dem Server

### Zu ersetzen in `api.php`:

```diff
    case 'vehicle_track':
        $token = $_GET['token'] ?? '';
        $cid   = $_GET['collection_id'] ?? '';
        if (!$token || !$cid) error_response('Parameter fehlen');
+       
+       // Auto-Cleanup: Tracks älter als 7 Tage löschen
+       try {
+           db_run("DELETE FROM vehicle_tracks 
+                   WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
+       } catch (Exception $e) {}
+       
        // Letzte 800 Punkte, älteste zuerst
        $rows = db_rows("SELECT lat,lng,speed,recorded_at FROM vehicle_tracks
                         WHERE token=? AND collection_id=?
                         ORDER BY recorded_at DESC LIMIT 800",
                        [$token,$cid]);
        $rows = array_reverse($rows); // chronologisch
-       $track = array_map(fn($r)=>[(float)$r['lat'],(float)$r['lng']],$rows);
-       json_response(['track'=>$track,'count'=>count($track)]);
+       $points = array_map(fn($r)=>[(float)$r['lat'],(float)$r['lng']],$rows);
+       
+       // Letzte Punkt-Zeit für Info
+       $latestTime = !empty($rows) ? end($rows)['recorded_at'] : null;
+       
+       // Android App erwartet 'points', nicht 'track'
+       json_response([
+           'points'    => $points,
+           'count'     => count($points),
+           'latest_at' => $latestTime
+       ]);
```

---

## 🎯 Zusammenfassung

| Bereich | Status | Aktion |
|---------|--------|--------|
| **Tabelle `vehicle_tracks`** | ✅ Existiert | Keine Änderung |
| **Speichern von GPS-Punkten** | ✅ Funktioniert | Keine Änderung |
| **API-Endpoint Struktur** | ❌ Falsch | **MUSS ANGEPASST WERDEN** |
| **Auto-Cleanup** | ⚠️ Optional | Empfohlen (alte Tracks löschen) |

---

## 🔒 Performance & Cleanup

### Aktuelle Limits:
- **Max. Punkte pro Request**: 800 (gut für Performance)
- **Speichergröße**: Begrenzt durch Index auf `(token, collection_id, recorded_at)`
- **Retention**: Aktuell unbegrenzt (→ **Problem!**)

### Empfehlung:
```sql
-- Alte Tracks nach 7 Tagen löschen (im Patch enthalten)
DELETE FROM vehicle_tracks 
WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

Falls du längere Retention brauchst:
```sql
-- Tracks nach 30 Tagen löschen
DELETE FROM vehicle_tracks 
WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

---

## ✅ Checkliste für die Implementierung

```
☐ api.php Zeilen 353-364 mit Patch ersetzen
☐ Datei speichern & deployen
☐ Android App neu builden (mit MainActivity v5.6)
☐ Test: Track-Button klicken → Fahrspur sollte angezeigt werden
☐ Test: Nach 5 Sekunden sollten neue Punkte hinzukommen
```

---

## 🧪 Test-Query für Datenbank

```sql
-- Anzahl Punkte pro Fahrzeug in Sammlung XYZ
SELECT token, COUNT(*) as points
FROM vehicle_tracks
WHERE collection_id = 'COLLECTION_ID'
GROUP BY token;

-- Älteste und neueste Punkt-Zeit
SELECT token, MIN(recorded_at) as oldest, MAX(recorded_at) as newest
FROM vehicle_tracks
WHERE collection_id = 'COLLECTION_ID'
GROUP BY token;
```

---

**Stand**: v5.6
**Kritikalität**: 🔴 HOCH (API-Mismatch → Feature funktioniert nicht ohne Patch)
**Implementierungszeit**: ~5 Minuten
