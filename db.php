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

// ── Haversine Distanz in Metern ───────────────────────────────────────────────
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R=$R=6371000;
    $a=sin(deg2rad($lat2-$lat1)/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lng2-$lng1)/2)**2;
    return $R*2*atan2(sqrt($a),sqrt(1-$a));
}

// ── Senkrechtabstand Punkt → Liniensegment (Meter) ───────────────────────────
// Berechnet den minimalen Abstand von GPS-Punkt P zum Segment A→B.
// Viel genauer als nur Endpunkte prüfen: erfasst auch seitliches Abfahren.
function distToSegment(float $lat, float $lng, array $a, array $b): float {
    // Lokale Flachprojektion (ausreichend für kurze Segmente < 1 km)
    $cosLat = cos(deg2rad(($a[0]+$b[0]+$lat)/3));
    $R = 6371000;
    $ax=$a[1]*$cosLat*$R; $ay=$a[0]*$R;
    $bx=$b[1]*$cosLat*$R; $by=$b[0]*$R;
    $px=$lng *$cosLat*$R; $py=$lat *$R;
    // Projektion des Punktes auf das Segment
    $dx=$bx-$ax; $dy=$by-$ay;
    $lenSq=$dx*$dx+$dy*$dy;
    if ($lenSq<0.001) return hypot($px-$ax,$py-$ay); // Segment=Punkt
    $t=max(0.0, min(1.0, (($px-$ax)*$dx+($py-$ay)*$dy)/$lenSq));
    return hypot($px-$ax-$t*$dx, $py-$ay-$t*$dy);
}

// ── Segment-basiertes Tracking ────────────────────────────────────────────────
// Toleranz 2m: ±2m seitlich von der Route gilt als abgefahren.
function update_driven_segments(float $lat, float $lng, array $coords, array $driven): array {
    $n=count($coords);
    for ($i=0; $i<$n-1; $i++) {
        if ($driven[$i]??false) continue;
        // Senkrechtabstand zum Segment ≤ 2m → abgefahren
        if (distToSegment($lat,$lng,$coords[$i],$coords[$i+1])<=2.0) {
            $driven[$i]=true;
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
    return !in_array(false,$driven,true)&&!in_array(null,$driven,true);
}
function init_segments(int $numCoords): array {
    return array_fill(0,max(0,$numCoords-1),false);
}
