# Server-Anpassungen für v5.6

## ⚠️ WICHTIG: API-Patch erforderlich!

Die Android App v5.6 benötigt eine kleine Anpassung am `vehicle_track` Endpoint.

### Problem
Der Server antwortet mit:
```json
{ "track": [[lat, lng], ...] }
```

Die App erwartet aber:
```json
{ "points": [[lat, lng], ...] }
```

### Lösung (5 Minuten)

1. **api.php öffnen**
2. **Zeilen 353-364 suchen** (case 'vehicle_track':)
3. **Durch den Code in `docs/api_vehicle_track_corrected.php` ersetzen**
4. **Datei speichern & deployen**

Das war's! ✅

### Details
Siehe `docs/SERVER_PATCH_REQUIREMENTS.md` für:
- Detaillierte Änderungen
- Auto-Cleanup Erklärung
- SQL Test-Queries
- Datenbank-Status

---

## Datenbank-Status ✅

**Keine Änderungen nötig!** Die Tabelle `vehicle_tracks` existiert bereits:
- Speichert GPS-Punkte während des Sammelns
- Nutzt nur 7-Tage Retention (mit Patch)
- Indexed für Performance
- Max 800 Punkte pro Track

---

## Checkliste

```
☐ api.php Zeilen 353-364 ersetzen
☐ Datei deployen
☐ Android App rebuilden
☐ Testen: Track-Button sollte Fahrspur zeigen
```
