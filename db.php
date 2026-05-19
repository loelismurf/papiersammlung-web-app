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
function json_response(array $data, int $status=200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
function error_response(string $msg, int $status=400): void { json_response(['error'=>$msg],$status); }
function body(): array { return json_decode(file_get_contents('php://input'),true)??[]; }
function new_id(): string { return bin2hex(random_bytes(8)); }

function cleanup_vehicles(): void {
    db_run("UPDATE vehicles SET status='offline'
            WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND) AND status!='offline'",
           [VEHICLE_TIMEOUT]);
}

function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000;
    $a = sin(deg2rad($lat2-$lat1)/2)**2
       + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// ── Senkrechtabstand Punkt P → Segment A→B in Metern ─────────────────────────
// FIX: deg2rad() war vorher vergessen → Distanzen waren in Grad-Metern,
//      nicht in echten Metern → 2m-Vergleich hat nie angeschlagen.
function distToSegment(float $lat, float $lng, array $a, array $b): float {
    $cosLat = cos(deg2rad(($a[0]+$b[0]+$lat) / 3));
    $R = 6371000;
    // Koordinaten korrekt in Meter umrechnen (deg2rad ist entscheidend!)
    $ax = deg2rad($a[1]) * $cosLat * $R;  $ay = deg2rad($a[0]) * $R;
    $bx = deg2rad($b[1]) * $cosLat * $R;  $by = deg2rad($b[0]) * $R;
    $px = deg2rad($lng)  * $cosLat * $R;  $py = deg2rad($lat)  * $R;

    $dx = $bx-$ax;  $dy = $by-$ay;
    $lenSq = $dx*$dx + $dy*$dy;
    if ($lenSq < 0.001) return hypot($px-$ax, $py-$ay);

    // Projektion auf Segment, geklemmt auf [0,1]
    $t = max(0.0, min(1.0, (($px-$ax)*$dx + ($py-$ay)*$dy) / $lenSq));
    return hypot($px - $ax - $t*$dx, $py - $ay - $t*$dy);
}

// ── Segment-Tracking: 2m Toleranz senkrecht zur Route ────────────────────────
function update_driven_segments(float $lat, float $lng, array $coords, array $driven): array {
    $n = count($coords);
    for ($i = 0; $i < $n-1; $i++) {
        if ($driven[$i] ?? false) continue;
        if (distToSegment($lat, $lng, $coords[$i], $coords[$i+1]) <= 2.0) {
            $driven[$i] = true;
        }
    }
    return $driven;
}

function progress_from_segments(array $driven): int {
    if (empty($driven)) return 0;
    return (int)round(count(array_filter($driven,fn($v)=>$v===true)) / count($driven) * 100);
}
function all_segments_driven(array $driven): bool {
    if (empty($driven)) return false;
    return !in_array(false,$driven,true) && !in_array(null,$driven,true);
}
function init_segments(int $numCoords): array {
    return array_fill(0, max(0, $numCoords-1), false);
}
