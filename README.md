# ♻️ Papiersammlung – Pfadi Riko

Echtzeit-Routenverfolgung für Papiersammlungen. Läuft auf PHP + MySQL (Netcup Webhosting).

---

## Dateien

| Datei | Beschreibung |
|-------|-------------|
| `config.php` | **← DB-Zugangsdaten eintragen** |
| `db.php` | Datenbankverbindung (nicht ändern) |
| `auth.php` | Session-Hilffunktionen (nicht ändern) |
| `install.php` | Einmalig ausführen, dann löschen |
| `login.php` | Login-Seite |
| `logout.php` | Abmelden |
| `index.php` | Haupt-App mit Karte |
| `admin.php` | Admin-Panel |
| `api.php` | API (alle Endpunkte) |
| `.htaccess` | Weiterleitungen + Sicherheit |

---

## Setup

### 1. config.php anpassen
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'dein_datenbankname');
define('DB_USER', 'dein_benutzer');
define('DB_PASS', 'dein_passwort');
```

### 2. Alle Dateien per FTP hochladen
Ziel: `httpdocs/` (oder Unterordner)

### 3. Installation ausführen
```
https://deine-domain.ch/install.php
```
→ Erstellt alle Tabellen + Standard-Admin-Account

### 4. install.php löschen!

### 5. Login
```
https://deine-domain.ch/
```
Standard: **admin / admin123** → sofort ändern!

---

## Workflow

### Admin
1. Login → Admin-Panel
2. **Routen-Vorlagen** erstellen (Karte anklicken)
3. **Neue Sammlung** erstellen (Name + Datum)
4. Routen aus Vorlagen zur Sammlung hinzufügen
5. Sammlung **Aktivieren** → User sehen sie
6. Benutzer für Fahrer anlegen

### User / Fahrer
1. Login
2. Sammlung auswählen (falls mehrere aktiv)
3. Fahrzeugname eingeben → Verbinden
4. Route wählen → **▶ Start**
5. GPS trackt automatisch den Fortschritt
6. **⏸ Pause** bei Unterbruch
7. **✓ Erledigt** wenn Route abgeschlossen

---

## Features

- 🗺 **OpenStreetMap** – keine API-Keys nötig
- 📍 **Live GPS** – Browser sendet Position automatisch
- 🚛 **Alle Fahrzeuge** – sieht man in Echtzeit auf der Karte
- 📦 **Mehrere Sammlungen** – pro Datum, z.B. Morgen/Nachmittag
- 🗺 **Routen-Vorlagen** – einmal erstellen, mehrfach verwenden
- 👥 **Admin/User** – getrennte Rollen
- ⏸ **Pause** – Route als unterbrochen markieren
- 📊 **Fortschritt** – automatisch per GPS oder manuell

---

## Rollen

| Rolle | Kann |
|-------|------|
| **Admin** | Sammlungen erstellen/verwalten, Routen erstellen, Benutzer verwalten, alles was User kann |
| **User** | Karte sehen, Fahrzeug verbinden, Route starten/pausieren/abschliessen |

---

## Tipps

**GPS funktioniert nicht?**
→ Seite muss über HTTPS aufgerufen werden
→ Let's Encrypt SSL in Plesk aktivieren (kostenlos)

**Karte lädt nicht?**
→ Internetverbindung prüfen (Leaflet kommt vom CDN)

**Koordinaten für Routen**
→ https://geojson.io – Route zeichnen, dann in Admin übertragen
→ Oder direkt im Admin-Panel auf die Karte klicken

**Routen importieren**
→ Im Admin: Vorlage erstellen → In Sammlung: Vorlage aus Dropdown wählen → automatisch übernommen
