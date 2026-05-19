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
function db_row(string $sql, array $p = []): ?array {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetch() ?: null;
}
function db_rows(string $sql, array $p = []): array {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function db_run(string $sql, array $p = []): void {
    db()->prepare($sql)->execute($p);
}
function db_val(string $sql, array $p = []) {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn();
}
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
function error_response(string $msg, int $status = 400): void {
    json_response(['error' => $msg], $status);
}
function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}
function new_id(): string {
    return bin2hex(random_bytes(8));
}
function cleanup_vehicles(): void {
    db_run("UPDATE vehicles SET status='offline'
            WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND) AND status != 'offline'",
           [VEHICLE_TIMEOUT]);
}

// Haversine-Distanz in Metern
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R    = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Fortschritt: 5m Toleranz, geht nur vorwärts
function calculate_progress(float $lat, float $lng, array $coords, int $currentProgress = 0): int {
    $n = count($coords);
    if ($n < 2) return 0;
    $maxIndex = (int) round($currentProgress / 100 * ($n - 1));
    foreach ($coords as $i => $p) {
        if (haversine($lat, $lng, $p[0], $p[1]) <= 5.0) {
            $maxIndex = max($maxIndex, $i);
        }
    }
    return (int) round($maxIndex / ($n - 1) * 100);
}
