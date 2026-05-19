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
}

// ── Haversine Distanz in Metern ───────────────────────────────────────────────
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000;
    $a = sin(deg2rad($lat2-$lat1)/2)**2
       + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// ── Senkrechtabstand Punkt → Liniensegment (Meter) ───────────────────────────
// deg2rad() ist ZWINGEND nötig – ohne gibt es Grad-Meter statt echte Meter!
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

// ── Segment-Erkennung mit Pfad-Interpolation ──────────────────────────────────
//
// Problem ohne Interpolation:
//   GPS alle 5s, 30km/h → 40m zwischen Punkten.
//   Routensegmente (z.B. 20m) liegen komplett zwischen zwei GPS-Fixes.
//   → Segment nie erkannt, obwohl tatsächlich abgefahren.
//
// Lösung:
//   Zwischen letzter ($fromLat/$fromLng) und aktueller ($toLat/$toLng)
//   Position werden $steps Zwischenpunkte interpoliert und jeder gegen
//   alle Routensegmente geprüft.
//
// $tolerance: Senkrechtabstand in Metern
//   - 15m mit OSRM-Snap (GPS auf Strasse korrigiert)
//   - 25m ohne Snap (rohes GPS mit grösserem Fehler)
//
function update_driven_segments(
    float $fromLat, float $fromLng,
    float $toLat,   float $toLng,
    array $coords,  array $driven,
    float $tolerance = 20.0,
    int   $steps = 8
): array {
    $n = count($coords);
    if ($n < 2) return $driven;

    for ($step = 0; $step <= $steps; $step++) {
        $t        = $steps > 0 ? $step / $steps : 0;
        $checkLat = $fromLat + $t * ($toLat - $fromLat);
        $checkLng = $fromLng + $t * ($toLng - $fromLng);

        for ($i = 0; $i < $n-1; $i++) {
            if ($driven[$i] ?? false) continue;
            if (distToSegment($checkLat, $checkLng, $coords[$i], $coords[$i+1]) <= $tolerance) {
                $driven[$i] = true;
            }
        }
    }
    return $driven;
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
