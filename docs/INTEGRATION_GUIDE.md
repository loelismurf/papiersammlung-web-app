# Android App v5.6 — Custom Markers & Vehicle Track History

## 📋 Zusammenfassung der Änderungen

### Feature 1: Custom Marker (Google Maps Style)
Statt der Standard-Hand im grünen Bubble zeigen jetzt:
- **Eigenes Fahrzeug**: Blauer Punkt mit Nordpfeil (wie Google Maps)
- **Fremde Fahrzeuge**: Grüne Kreise

Visuell klarer, professioneller, am User gewöhnt.

### Feature 2: Vehicle Track History
Neue Funktion: Die Fahrspur (Breadcrumb-Trail) wird auf dem Server gespeichert und kann optional auf der Karte angezeigt werden.

- **Aktivierung**: Button in der unteren rechten Ecke (Kartenicon, gelb wenn aktiv)
- **Updatefrequenz**: Alle 5 Sekunden
- **Design**: Hellblau (#00d4ff) Polyline mit Alpha 180

## 🔧 Integrations-Anleitung

### Dateien zum Ersetzen/Hinzufügen:

#### 1. **SVG Drawable für Selbst-Marker** (NEU)
```
Source/Android/app/src/main/res/drawable/ic_self_marker.xml
```
- Blauer Kreis mit weißem Nordpfeil
- 48x48 dp, transparent Hintergrund
- Wird automatisch rotiert um aktuelle Fahrtrichtung (optional)

#### 2. **SVG Drawable für Fahrzeug-Marker** (NEU)
```
Source/Android/app/src/main/res/drawable/ic_vehicle_marker.xml
```
- Grüner Kreis
- 48x48 dp, für fremde Fahrzeuge

#### 3. **MainActivity.kt** (ERSETZT)
**WICHTIG**: `ic_self_marker.xml` und `ic_vehicle_marker.xml` müssen im `res/drawable/` Ordner vorhanden sein!

Neue/geänderte Methoden:
```kotlin
// Marker-Icons setzen (Zeile ~530 & ~550)
setIcon(AppCompatResources.getDrawable(this, R.drawable.ic_self_marker))
setIcon(AppCompatResources.getDrawable(this, R.drawable.ic_vehicle_marker))

// Fahrspur-Toggle
toggleVehicleTrack()                // Zeigt/verbirgt Fahrspur
startTrackPolling()                 // Aktualisiert Track alle 5s
loadVehicleTracks()                 // Lädt Track vom Server
renderVehicleTrack(trackResp)       // Rendert Track-Polyline

// Track-Button Style
updateTrackButton()                 // Färbt Button gelb wenn aktiv
```

#### 4. **activity_main.xml** (ERSETZT)
Zwei neue Button-Positionen:
```xml
<!-- Follow-Button: jetzt bei 90dp vom unten (statt 24dp) -->
android:layout_marginBottom="90dp"

<!-- Track-Toggle Button: bei 24dp vom unten -->
android:id="@+id/btn_toggle_track"
android:src="@android:drawable/ic_menu_mapmode"
android:layout_marginBottom="24dp"
```

## 📱 UI-Änderungen

### Buttons in der Ecke:
```
┌─────────────────────────┐
│    KARTE               │
│                        │
│                   [🧭]  ← Follow (Position zentrieren)
│                   
│                   [🗺️]   ← Track (Fahrspur anzeigen)
└─────────────────────────┘
```

- **Follow-Button** (oben): Cyan wenn aktiv, Grau wenn inaktiv
- **Track-Button** (unten): Gold/Gelb wenn aktiv, Grau wenn inaktiv

### Marker auf Karte:
- **Blauer Punkt mit Pfeil**: Du (eigenes Fahrzeug)
- **Grüne Kreise**: Andere Fahrzeuge mit Koordinaten im Tooltip

### Fahrspur (Track):
- Hellblaue Polyline (#00d4ff)
- Wird NICHT automatisch angezeigt (Datenschutz/Performance)
- Toggle-Button schaltet zwischen An/Aus um
- Aktualisiert sich live während Tracking

## 🔌 Backend-Voraussetzungen

Die Android App nutzt die bestehende API-Endpoint:
```
GET /api.php?action=vehicle_track&token=...&collection_id=...&auth_token=...
```

**Erwartet vom Server**:
```json
{
  "points": [
    [47.3769, 8.5417],
    [47.3770, 8.5418],
    ...
  ]
}
```

oder:
```json
{
  "data": [[lat, lng], ...],
  "latest_at": "2024-05-21 12:34:56"
}
```

Die ApiClient.getVehicleTrack() Methode versucht mehrere JSON-Pfade zu parsen.

## 📦 Datei-Checklist zum Kopieren

```
✓ ic_self_marker.xml          (NEU → res/drawable/)
✓ ic_vehicle_marker.xml       (NEU → res/drawable/)
✓ MainActivity.kt             (ERSETZT v5.5)
✓ activity_main.xml           (ERSETZT v5.5)
```

Alle anderen Dateien bleiben unverändert:
- `GpsService.kt` ← NICHT ändern (v5.5 ist OK)
- `ApiClient.kt` ← NICHT ändern (getVehicleTrack existiert bereits)
- `AppPrefs.kt` ← NICHT ändern
- Database/PHP ← Keine Änderung nötig

## 🎨 Custom Marker anpassen

Falls du die Farben ändern möchtest:

**ic_self_marker.xml**:
- `<circle android:fillColor="#00d4ff" ...>` ← Ändere zu deiner Farbe
- `<path android:fillColor="#ffffff" ...>` ← Pfeil-Farbe

**ic_vehicle_marker.xml**:
- `<circle android:fillColor="#a8ff3e" ...>` ← Grün
- `<circle android:fillColor="#c0ff5e" ...>` ← Hell-Grün innen

## 🐛 Debugging

Falls die Fahrspur nicht angezeigt wird:
1. Prüfe, dass der Server `vehicle_track` Endpoint existiert
2. Schau in Logcat nach "vehicle_track" Fehlern
3. Prüfe, dass Track-Button geklickt wurde (sollte gelb werden)
4. Warte 5 Sekunden (Update-Interval)

Falls die Marker nicht angezeigt werden:
1. Prüfe, dass `ic_self_marker.xml` und `ic_vehicle_marker.xml` im `res/drawable/` sind
2. Rebuild & Clean durchführen
3. Schau nach Fehler "drawable not found" in Logcat

## 🚀 Performance-Hinweise

- Track mit 800 Punkten ist kein Problem (ein Array mit Koordinaten)
- Die Polyline wird mit `outlinePaint.alpha = 180` semi-transparent gerendert
- Updates alle 5 Sekunden sind okay (GPS-Daten sind nicht real-time)

## 📝 Notizen

- Die Fahrspur ist **optional** (Toggle-Button) → Datenschutz
- Sie wird auf dem **Server** gespeichert (best practice)
- Beim **Logout** wird die Fahrspur nicht gelöscht (nur für UI-Session)
- **Multi-Fahrzeug-Support**: Jedes Fahrzeug hat seine eigene Track
- Die eigenen Track-Punkte werden aktualisiert alle 5s, fremde Fahrzeuge nur beim Polling (2.5s)

---

**Version**: 5.6
**Kompatibilität**: Android API 26+, targetSdk 34
**Abhängigkeiten**: OSMDroid, OkHttp, Kotlin Coroutines (alle schon vorhanden)
