<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
function db_row(string $sql, array $p=[]): ?array { $s=db()->prepare($sql);$s->execute($p);return $s->fetch()?:null; }
function db_rows(string $sql, array $p=[]): array  { $s=db()->prepare($sql);$s->execute($p);return $s->fetchAll(); }
function db_run(string $sql, array $p=[]): void     { db()->prepare($sql)->execute($p); }
function db_val(string $sql, array $p=[])           { $s=db()->prepare($sql);$s->execute($p);return $s->fetchColumn(); }
function json_response(array $d, int $s=200): void  { http_response_code($s); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; }
function error_response(string $m, int $s=400): void { json_response(['error'=>$m],$s); }
function body(): array { return json_decode(file_get_contents('php://input'),true)??[]; }
function new_id(): string { return bin2hex(random_bytes(8)); }

function cleanup_vehicles(): void {
    db_run("UPDATE vehicles SET status='offline'
            WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND) AND status!='offline'",
           [VEHICLE_TIMEOUT]);
    // Self-Healing: Schema sicherstellen
    ensure_driven_segments_column();
    ensure_collecting_column();
}

// ── Self-Healing: driven_segments Spalte ────────────────────────────────────
function ensure_driven_segments_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $exists = db_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME    = 'collection_routes'
             AND COLUMN_NAME   = 'driven_segments'"
        );
        if (!$exists) {
            db_run("ALTER TABLE collection_routes
                    ADD COLUMN driven_segments LONGTEXT DEFAULT NULL
                    COMMENT 'JSON bool[] pro Segment'");
        }
    } catch (Exception $e) {}
}

// ── Self-Healing: collecting Spalte ─────────────────────────────────────────
// Neue Spalte für Sammelmodus: nur wenn collecting=1 werden Segmente getrackt.
function ensure_collecting_column(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $exists = db_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME    = 'vehicles'
             AND COLUMN_NAME   = 'collecting'"
        );
        if (!$exists) {
            db_run("ALTER TABLE vehicles
                    ADD COLUMN collecting TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1 = Sammelmodus aktiv, Segmente werden getrackt'");
        }
    } catch (Exception $e) {}
}

// ── Haversine Distanz in Metern ──────────────────────────────────────────────
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000;
    $a = sin(deg2rad($lat2-$lat1)/2)**2
       + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// ── Senkrechtabstand Punkt -> Liniensegment (Meter) ─────────────────────────
function distToSegment(float $lat, float $lng, array $a, array $b): float {
    $cosLat = cos(deg2rad(($a[0]+$b[0]+$lat)/3));
    $R = 6371000;
    $ax = deg2rad($a[1])*$cosLat*$R;  $ay = deg2rad($a[0])*$R;
    $bx = deg2rad($b[1])*$cosLat*$R;  $by = deg2rad($b[0])*$R;
    $px = deg2rad($lng) *$cosLat*$R;  $py = deg2rad($lat) *$R;
    $dx = $bx-$ax; $dy = $by-$ay;
    $lenSq = $dx*$dx + $dy*$dy;
    if ($lenSq < 0.001) return hypot($px-$ax, $py-$ay);
    $t = max(0.0, min(1.0, (($px-$ax)*$dx+($py-$ay)*$dy)/$lenSq));
    return hypot($px-$ax-$t*$dx, $py-$ay-$t*$dy);
}

// ── GPS-Punkt auf Route projizieren ─────────────────────────────────────────
//
// Findet das naechstgelegene Segment auf der Route zum gegebenen GPS-Punkt.
// Gibt zurueck: ['seg'=>Index, 't'=>0..1, 'dist'=>Meter] oder null wenn zu weit.
//
function project_onto_route(float $lat, float $lng, array $coords, float $maxDist = 50.0): ?array {
    $n = count($coords);
    $bestDist = PHP_FLOAT_MAX;
    $bestSeg  = null;
    $bestT    = 0.0;

    for ($i = 0; $i < $n - 1; $i++) {
        $cosLat = cos(deg2rad(($coords[$i][0] + $coords[$i+1][0] + $lat) / 3));
        $R = 6371000;
        $ax = deg2rad($coords[$i][1])   * $cosLat * $R;
        $ay = deg2rad($coords[$i][0])   * $R;
        $bx = deg2rad($coords[$i+1][1]) * $cosLat * $R;
        $by = deg2rad($coords[$i+1][0]) * $R;
        $px = deg2rad($lng) * $cosLat * $R;
        $py = deg2rad($lat) * $R;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lenSq = $dx*$dx + $dy*$dy;

        if ($lenSq < 0.01) {
            $t  = 0.0;
            $cx = $ax;
            $cy = $ay;
        } else {
            $t  = max(0.0, min(1.0, (($px-$ax)*$dx + ($py-$ay)*$dy) / $lenSq));
            $cx = $ax + $t * $dx;
            $cy = $ay + $t * $dy;
        }
        $d = hypot($px - $cx, $py - $cy);

        if ($d < $bestDist) {
            $bestDist = $d;
            $bestSeg  = $i;
            $bestT    = $t;
        }
    }

    if ($bestSeg === null || $bestDist > $maxDist) return null;
    return ['seg' => $bestSeg, 't' => $bestT, 'dist' => round($bestDist, 1)];
}

// ── Kleine Lücken auf geraden Abschnitten schliessen ─────────────────────────
//
// Optimierung: Wenn ≤ $maxGap aufeinanderfolgende Segmente NICHT als abgefahren
// markiert sind, aber auf BEIDEN Seiten abgefahrene Segmente angrenzen,
// werden sie als abgefahren gerechnet.
//
// Begründung: Auf geraden Strecken kann GPS kurz verloren gehen oder leicht
// neben der Route liegen. Das Fahrzeug ist aber eindeutig durchgefahren,
// wenn es Segmente vor UND nach dem fehlenden Block markiert hat.
//
// $maxGap: Maximale Anzahl fehlender Segmente die gefüllt werden (Standard: 4)
//          Bei 20m Segmentlänge = max. 80m Lücke → praxistauglich.
//
function fill_small_gaps(array $driven, int $maxGap = 4): array {
    $n = count($driven);
    if ($n < 3) return $driven;

    $i = 0;
    while ($i < $n) {
        // Überspringe bereits abgefahrene Segmente
        if ($driven[$i] === true) { $i++; continue; }

        // Beginn einer nicht-abgefahrenen Strecke
        $runStart = $i;
        while ($i < $n && $driven[$i] !== true) $i++;
        $runLen = $i - $runStart;

        // Prüfe ob Lücke von true-Segmenten eingeschlossen ist
        $leftTrue  = ($runStart > 0 && $driven[$runStart - 1] === true);
        $rightTrue = ($i < $n   && $driven[$i]             === true);

        if ($leftTrue && $rightTrue && $runLen <= $maxGap) {
            for ($k = $runStart; $k < $i; $k++) {
                $driven[$k] = true;
            }
        }
    }
    return $driven;
}

// ── Segment-Erkennung via Routen-Projektion ──────────────────────────────────
//
// Projiziert Vorgänger- und aktuellen GPS-Punkt auf die Route,
// markiert alle Segmente dazwischen als abgefahren.
// Danach: kleine Lücken auf geraden Abschnitten schliessen (fill_small_gaps).
//
function update_driven_segments(
    float $fromLat, float $fromLng,
    float $toLat,   float $toLng,
    array $coords,  array $driven,
    float $tolerance = 30.0,
    int   $steps = 8  // Kompatibilitaetsparameter
): array {
    $n = count($coords);
    if ($n < 2) return $driven;

    // Aktuellen GPS-Punkt auf Route projizieren
    $curProj = project_onto_route($toLat, $toLng, $coords, $tolerance);
    if ($curProj === null) {
        // Fahrzeug nicht auf Route -> Lücken trotzdem schliessen
        return fill_small_gaps($driven);
    }

    $segTo = $curProj['seg'];

    // Vorherigen GPS-Punkt auf Route projizieren (groessere Toleranz)
    $prevProj = project_onto_route($fromLat, $fromLng, $coords, $tolerance * 2.0);

    if ($prevProj !== null) {
        $segFrom = $prevProj['seg'];
        $lo = min($segFrom, $segTo);
        $hi = max($segFrom, $segTo);

        // Plausibilitaets-Check: max. 50 Segmente auf einmal
        if ($hi - $lo <= 50) {
            for ($i = $lo; $i <= $hi; $i++) {
                $driven[$i] = true;
            }
        } else {
            // GPS-Sprung: nur nahe Segmente beim aktuellen Punkt markieren
            $window = 3;
            for ($i = max(0, $segTo - $window); $i <= min($n-2, $segTo + $window); $i++) {
                $driven[$i] = true;
            }
        }
    } else {
        // Vorherige Position nicht auf Route -> nur aktuelles Segment
        $driven[$segTo] = true;
    }

    // Kleine Lücken auf geraden Abschnitten füllen
    return fill_small_gaps($driven);
}

function progress_from_segments(array $driven): int {
    if (empty($driven)) return 0;
    return (int)round(count(array_filter($driven,fn($v)=>$v===true))/count($driven)*100);
}
function all_segments_driven(array $driven): bool {
    if (empty($driven)) return false;
    return !in_array(false,$driven,true) && !in_array(null,$driven,true);
}
function init_segments(int $n): array {
    return array_fill(0, max(0,$n-1), false);
}
