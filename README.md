# 🚛 FleetTrack – PHP Version (Shared Hosting)

Läuft auf Netcup Webhosting / jedem PHP+MySQL Shared Hosting.

---

## Setup in 4 Schritten

### 1. Dateien hochladen
Alle Dateien per FTP/SFTP in dein Webhosting-Verzeichnis hochladen:
```
config.php
db.php
api.php
install.php
index.html
```

### 2. Datenbank konfigurieren
`config.php` öffnen und deine Zugangsdaten eintragen:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'dein_datenbankname');
define('DB_USER', 'dein_benutzer');
define('DB_PASS', 'dein_passwort');
```
Die Zugangsdaten findest du in deinem **Netcup Plesk Panel** unter:
Websites & Domains → Datenbanken

### 3. Installation ausführen
Im Browser aufrufen:
```
https://deine-domain.de/install.php
```
→ Erstellt alle Tabellen und lädt 4 Beispielrouten (Zürich)

### 4. install.php löschen!
Nach erfolgreicher Installation `install.php` per FTP löschen.

---

## App starten
```
https://deine-domain.de/
```

---

## Routen anpassen

### Eigene Koordinaten eintragen
`install.php` vor der Installation öffnen und das `$sampleRoutes`-Array anpassen.

Koordinaten bekommst du einfach von:
- https://geojson.io (Linie zeichnen → Koordinaten kopieren)
- Google Maps → Rechtsklick → "Was ist hier?" → Koordinaten kopieren

### Per Browser-URL neue Route hinzufügen
```
POST https://deine-domain.de/api.php?action=route_add
Content-Type: application/json

{
  "name": "Meine neue Route",
  "color": "#ffd700",
  "coordinates": [
    [47.3769, 8.5417],
    [47.3850, 8.5350]
  ]
}
```

---

## Dateien Übersicht

| Datei | Zweck |
|-------|-------|
| `config.php` | Datenbankzugangsdaten |
| `db.php` | Datenbankverbindung + Hilfsfunktionen |
| `api.php` | Alle API-Endpunkte |
| `install.php` | Einmalig ausführen, dann löschen |
| `index.html` | Die Webapp (Frontend) |

---

## Wie es funktioniert (kein Node.js nötig)

Statt WebSockets pollt der Browser alle **2.5 Sekunden** die PHP-API:
```
Browser → GET api.php?action=state → PHP liest MySQL → JSON zurück
```

Aktionen (Route starten, Pause, etc.) werden sofort per POST gesendet.
Alle Fahrzeuge sehen Änderungen beim nächsten Poll-Intervall.

---

## Troubleshooting

**Weisse Seite / PHP-Fehler:**
- PHP-Version prüfen: mindestens PHP 7.4 (Plesk: PHP-Einstellungen)
- Fehlerlog in Plesk prüfen

**"Datenbankfehler":**
- Zugangsdaten in `config.php` nochmal prüfen
- Im Netcup Plesk: Datenbank → Benutzer hat Rechte auf die DB?

**GPS funktioniert nicht:**
- Seite muss über **HTTPS** aufgerufen werden (GPS nur mit SSL)
- Netcup bietet kostenloses Let's Encrypt SSL im Plesk

**Karte lädt nicht:**
- Internetverbindung prüfen (Leaflet wird von CDN geladen)
