# Papiersammlung Android App v5.6

## Features in dieser Version

### 1. Custom Markers (Google Maps Style)
- Eigenes Fahrzeug: Blauer Punkt mit Nordpfeil
- Fremde Fahrzeuge: Grüne Kreise
- Professionellere Darstellung statt Standard-Pin

### 2. Vehicle Track History
- Fahrspur wird auf dem Server gespeichert
- Optional auf der Karte anzeigen (Toggle-Button)
- Hellblau Polyline, aktualisiert alle 5 Sekunden

## Installation

1. Die Dateien in die entsprechenden Verzeichnisse kopieren
2. Android Studio: Build → Clean Build
3. Die App neu starten

## Dateien zum Ersetzen

```
✓ Source/Android/app/src/main/res/drawable/ic_self_marker.xml (NEU)
✓ Source/Android/app/src/main/res/drawable/ic_vehicle_marker.xml (NEU)
✓ Source/Android/app/src/main/java/ch/papiersammlung/app/MainActivity.kt (ERSETZT)
✓ Source/Android/app/src/main/res/layout/activity_main.xml (ERSETZT)
```

## Backend-Anforderung

Der Server muss den `vehicle_track` Endpoint bereitstellen:
```
GET /api.php?action=vehicle_track&token=...&collection_id=...
```

Erwartet JSON mit Punkten-Array:
```json
{
  "points": [[lat, lng], ...]
}
```

Siehe `docs/INTEGRATION_GUIDE.md` für Details.
