<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error_response(string $msg, int $status = 400): void {
    json_response(['error' => $msg], $status);
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// Stale Fahrzeuge bereinigen
function cleanup_vehicles(): void {
    db()->prepare("
        UPDATE vehicles SET status = 'offline'
        WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND)
        AND status != 'offline'
    ")->execute([VEHICLE_TIMEOUT]);
}

// Fortschritt berechnen: nächsten Routenpunkt per Euklidischer Distanz
function calculate_progress(float $lat, float $lng, array $coords): int {
    if (empty($coords)) return 0;
    $closest = 0;
    $minDist = PHP_FLOAT_MAX;
    foreach ($coords as $i => $point) {
        $d = hypot($point[0] - $lat, $point[1] - $lng);
        if ($d < $minDist) { $minDist = $d; $closest = $i; }
    }
    return (int) round(($closest / (count($coords) - 1)) * 100);
}
