<?php
/**
 * CORRECTED vehicle_track ENDPOINT für api.php
 * 
 * ERSETZE Zeilen 353-364 in deiner api.php MIT DIESEM CODE:
 * 
 * Die wichtigsten Änderungen:
 * 1. Response Key: 'track' → 'points' (Android App v5.6 Kompatibilität)
 * 2. Auto-Cleanup: Tracks älter als 7 Tage automatisch löschen
 * 3. Zusätzlich: 'latest_at' Timestamp für UI-Info
 */

    case 'vehicle_track':
        $token = $_GET['token'] ?? '';
        $cid   = $_GET['collection_id'] ?? '';
        if (!$token || !$cid) error_response('Parameter fehlen');
        
        // 🧹 Auto-Cleanup: Tracks älter als 7 Tage löschen (optional auf 30 Tage setzen)
        try {
            db_run("DELETE FROM vehicle_tracks 
                    WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        } catch (Exception $e) {
            // Cleanup-Fehler sind nicht kritisch
        }
        
        // Letzte 800 Punkte abrufen, älteste zuerst nach Reverse
        $rows = db_rows("SELECT lat,lng,speed,recorded_at FROM vehicle_tracks
                         WHERE token=? AND collection_id=?
                         ORDER BY recorded_at DESC LIMIT 800",
                        [$token,$cid]);
        
        // Chronologische Reihenfolge (älteste zuerst = Anfang der Route)
        $rows = array_reverse($rows);
        
        // Nur Lat/Lng als Array für Karte
        $points = array_map(fn($r) => [(float)$r['lat'], (float)$r['lng']], $rows);
        
        // Letzte Punkt-Zeit für Info (wann war die neuste Position?)
        $latestTime = !empty($rows) ? end($rows)['recorded_at'] : null;
        
        // ✅ WICHTIG: 'points' statt 'track' (Android App v5.6 Kompatibilität!)
        json_response([
            'points'    => $points,           // [[lat,lng], [lat,lng], ...]
            'count'     => count($points),    // Anzahl Punkte
            'latest_at' => $latestTime        // Zeitstempel der neuesten Position
        ]);
        
?>
