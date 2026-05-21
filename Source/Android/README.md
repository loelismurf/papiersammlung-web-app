# Papiersammlung Android App

Native Android-App für die Papiersammlung GPS-Tracking-Plattform.

## Funktionen (zusätzlich zur Web-App)

| Feature | Web-App | Android-App |
|---|---|---|
| GPS-Tracking | ✅ | ✅ |
| Hintergrund-GPS | ⚠ (SW+NoSleep) | ✅ (ForegroundService) |
| Offline-Puffer | ❌ | ✅ (SQLite, automatisches Nachladen) |
| Karte | ✅ Leaflet/OSM | ✅ OSMDroid/OSM |
| Follow-Modus | ✅ | ✅ |
| Push-Notifications | ✅ (Web Push) | ✅ (Native) |
| WakeLock | ✅ (API) | ✅ (PowerManager) |
| Läuft ohne Browser | ❌ | ✅ |

## Architektur

```
MainActivity       – Karte (OSMDroid) + Sidebar UI
GpsService         – ForegroundService: GPS im Hintergrund, WakeLock
OfflineBuffer      – SQLite: GPS-Punkte puffern wenn offline
SyncService        – Gepufferte Punkte beim Reconnect nachsenden
ApiClient          – HTTP via OkHttp, Bearer-Token-Auth
AppPrefs           – SharedPreferences: Token, Server-URL, Session
PapiersammlungApp  – Application: Notification-Channels
LoginActivity      – Login mit Server-URL + Zugangsdaten
SettingsActivity   – Abmelden, Server-Info
```

## API-Authentifizierung

Die App verwendet Bearer-Token-Auth (kein Session-Cookie):

1. `POST api.php?action=mobile_login` mit `{username, password}`
2. Response: `{token, expires_at}` → 30 Tage gültig
3. Alle weiteren Requests: `Authorization: Bearer <token>`

Der Token wird in `SharedPreferences` gespeichert.

## Hintergrund-GPS

- Android-ForegroundService mit persistenter Notification
- GPS-Interval: **3 Sekunden** beim Sammeln, **30 Sekunden** im Idle
- WakeLock verhindert Deep-Sleep
- Beim Sammeln: WakeLock PARTIAL (CPU wach, Bildschirm kann aus)

## Offline-Puffer

Wenn kein Netzwerk verfügbar:
1. GPS-Punkt → `gps_buffer.db` (SQLite, lokal)
2. Alle 15 Sekunden prüft `GpsService` ob Netzwerk verfügbar
3. Bei Reconnect: Puffer sequenziell an Server senden
4. Erfolgreich gesendete Punkte werden gelöscht
5. Punkte > 24h werden automatisch aufgeräumt

## Build-Voraussetzungen

- Android Studio Hedgehog (2023.1.1) oder neuer
- JDK 17
- Android SDK 34
- Gradle 8.2

## Build-Anleitung

### Android Studio (empfohlen)
1. `File → Open` → `Source/Android/` Ordner öffnen
2. Gradle-Sync abwarten
3. `Run → Run 'app'` oder `Build → Build Bundle(s) / APK(s)`

### Kommandozeile
```bash
cd Source/Android

# Debug-APK
./gradlew assembleDebug

# Release-APK (braucht Signing-Config in build.gradle)
./gradlew assembleRelease

# APK-Pfad
# app/build/outputs/apk/debug/app-debug.apk
```

## Erste Inbetriebnahme

1. App installieren
2. Server-URL eingeben: `https://deine-domain.ch/papiersammlung`
3. Mit Benutzername + Passwort anmelden
4. Sammlung aus Dropdown wählen
5. „Am Sammeln" aktivieren → GPS startet im Hintergrund

## Permissions

| Permission | Warum |
|---|---|
| ACCESS_FINE_LOCATION | GPS-Position |
| ACCESS_BACKGROUND_LOCATION | GPS wenn App im Hintergrund |
| FOREGROUND_SERVICE | ForegroundService für GPS |
| INTERNET | API-Kommunikation |
| WAKE_LOCK | CPU wach beim Sammeln |

## Server-Anforderungen

Der Papiersammlung-Server muss mindestens **v5.1** installiert sein:
- `api_tokens`-Tabelle (via `tmp/install.php` erstellt)
- `mobile_login`-Endpoint
- CORS-Headers (`Access-Control-Allow-Origin: *`)

## Hinweise

- Hintergrund-GPS erfordert Android 9+ und `ACCESS_BACKGROUND_LOCATION`
- Android 13+: Notification-Permission wird beim ersten Start angefragt
- Akkuoptimierung für die App deaktivieren empfohlen (Einstellungen → Apps → Akku)
