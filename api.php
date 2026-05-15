<?php
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

try {
    cleanup_vehicles();

    switch ($action) {

        // ── GET state: alle Routen + aktive Fahrzeuge ─────────────────────
        case 'state':
            $routes   = db()->query("SELECT * FROM routes ORDER BY created_at ASC")->fetchAll();
            $vehicles = db()->query("
                SELECT * FROM vehicles
                WHERE status != 'offline'
                AND last_seen >= DATE_SUB(NOW(), INTERVAL " . VEHICLE_TIMEOUT . " SECOND)
            ")->fetchAll();

            foreach ($routes as &$r) {
                $r['coordinates'] = json_decode($r['coordinates'], true);
                $r['visible']     = (bool) $r['visible'];
                $r['progress']    = (int)  $r['progress'];
            }
            foreach ($vehicles as &$v) {
                $v['lat'] = $v['lat'] !== null ? (float) $v['lat'] : null;
                $v['lng'] = $v['lng'] !== null ? (float) $v['lng'] : null;
            }

            json_response(['routes' => $routes, 'vehicles' => $vehicles]);
            break;

        // ── POST vehicle_join ─────────────────────────────────────────────
        case 'vehicle_join':
            $data  = body();
            $name  = trim($data['name'] ?? '');
            $token = $data['token'] ?? null; // Wiederverbindung

            if (!$name) error_response('Name fehlt');

            // Neues Token generieren oder bestehendes wiederverwenden
            if (!$token || strlen($token) < 20) {
                $token = bin2hex(random_bytes(32));
            }

            $stmt = db()->prepare("
                INSERT INTO vehicles (token, name, status, last_seen)
                VALUES (:token, :name, 'idle', NOW())
                ON DUPLICATE KEY UPDATE name = :name, status = 'idle', last_seen = NOW()
            ");
            $stmt->execute([':token' => $token, ':name' => $name]);

            $vehicle = db()->prepare("SELECT * FROM vehicles WHERE token = ?")->execute([$token]);
            json_response(['token' => $token, 'name' => $name, 'status' => 'idle']);
            break;

        // ── POST vehicle_position ─────────────────────────────────────────
        case 'vehicle_position':
            $data  = body();
            $token = $data['token'] ?? '';
            $lat   = (float) ($data['lat'] ?? 0);
            $lng   = (float) ($data['lng'] ?? 0);

            if (!$token) error_response('Token fehlt');

            // Fahrzeug-Position updaten
            $stmt = db()->prepare("
                UPDATE vehicles SET lat = :lat, lng = :lng, last_seen = NOW()
                WHERE token = :token
            ");
            $stmt->execute([':lat' => $lat, ':lng' => $lng, ':token' => $token]);

            // Fortschritt berechnen falls aktive Route
            $v = db()->prepare("SELECT * FROM vehicles WHERE token = ?")->execute([$token]);
            $vehicle = db()->query("SELECT * FROM vehicles WHERE token = " . db()->quote($token))->fetch();

            if ($vehicle && $vehicle['active_route'] && $vehicle['status'] === 'driving') {
                $route = db()->query("SELECT * FROM routes WHERE id = " . db()->quote($vehicle['active_route']))->fetch();
                if ($route) {
                    $coords   = json_decode($route['coordinates'], true);
                    $progress = calculate_progress($lat, $lng, $coords);

                    if ($progress >= 98) {
                        db()->prepare("UPDATE routes SET status = 'completed', progress = 100 WHERE id = ?")
                            ->execute([$route['id']]);
                        db()->prepare("UPDATE vehicles SET status = 'idle', active_route = NULL WHERE token = ?")
                            ->execute([$token]);
                    } else {
                        db()->prepare("UPDATE routes SET progress = ? WHERE id = ?")
                            ->execute([$progress, $route['id']]);
                    }
                }
            }

            json_response(['ok' => true]);
            break;

        // ── POST route_start ──────────────────────────────────────────────
        case 'route_start':
            $data    = body();
            $token   = $data['token'] ?? '';
            $routeId = $data['route_id'] ?? '';
            if (!$token || !$routeId) error_response('Parameter fehlen');

            // Vorherige Route dieses Fahrzeugs freigeben
            $old = db()->query("SELECT active_route FROM vehicles WHERE token = " . db()->quote($token))->fetch();
            if ($old && $old['active_route']) {
                db()->prepare("UPDATE routes SET status = 'pending', assigned_token = NULL WHERE id = ? AND status = 'active'")
                    ->execute([$old['active_route']]);
            }

            db()->prepare("UPDATE routes SET status = 'active', assigned_token = ? WHERE id = ?")
                ->execute([$token, $routeId]);
            db()->prepare("UPDATE vehicles SET status = 'driving', active_route = ?, last_seen = NOW() WHERE token = ?")
                ->execute([$routeId, $token]);

            json_response(['ok' => true]);
            break;

        // ── POST route_pause ──────────────────────────────────────────────
        case 'route_pause':
            $data    = body();
            $token   = $data['token'] ?? '';
            $routeId = $data['route_id'] ?? '';
            if (!$routeId) error_response('route_id fehlt');

            db()->prepare("UPDATE routes SET status = 'paused' WHERE id = ?")
                ->execute([$routeId]);
            if ($token) {
                db()->prepare("UPDATE vehicles SET status = 'paused', last_seen = NOW() WHERE token = ?")
                    ->execute([$token]);
            }
            json_response(['ok' => true]);
            break;

        // ── POST route_resume ─────────────────────────────────────────────
        case 'route_resume':
            $data    = body();
            $token   = $data['token'] ?? '';
            $routeId = $data['route_id'] ?? '';
            if (!$routeId) error_response('route_id fehlt');

            db()->prepare("UPDATE routes SET status = 'active' WHERE id = ?")
                ->execute([$routeId]);
            if ($token) {
                db()->prepare("UPDATE vehicles SET status = 'driving', active_route = ?, last_seen = NOW() WHERE token = ?")
                    ->execute([$routeId, $token]);
            }
            json_response(['ok' => true]);
            break;

        // ── POST route_complete ───────────────────────────────────────────
        case 'route_complete':
            $data    = body();
            $token   = $data['token'] ?? '';
            $routeId = $data['route_id'] ?? '';
            if (!$routeId) error_response('route_id fehlt');

            db()->prepare("UPDATE routes SET status = 'completed', progress = 100 WHERE id = ?")
                ->execute([$routeId]);
            if ($token) {
                db()->prepare("UPDATE vehicles SET status = 'idle', active_route = NULL, last_seen = NOW() WHERE token = ?")
                    ->execute([$token]);
            }
            json_response(['ok' => true]);
            break;

        // ── POST route_reset ──────────────────────────────────────────────
        case 'route_reset':
            $data    = body();
            $routeId = $data['route_id'] ?? '';
            if (!$routeId) error_response('route_id fehlt');

            // Fahrzeuge von dieser Route lösen
            db()->prepare("UPDATE vehicles SET status = 'idle', active_route = NULL WHERE active_route = ?")
                ->execute([$routeId]);
            db()->prepare("UPDATE routes SET status = 'pending', progress = 0, assigned_token = NULL WHERE id = ?")
                ->execute([$routeId]);
            json_response(['ok' => true]);
            break;

        // ── POST route_toggle ─────────────────────────────────────────────
        case 'route_toggle':
            $data    = body();
            $routeId = $data['route_id'] ?? '';
            if (!$routeId) error_response('route_id fehlt');

            db()->prepare("UPDATE routes SET visible = NOT visible WHERE id = ?")
                ->execute([$routeId]);
            json_response(['ok' => true]);
            break;

        // ── POST route_add ────────────────────────────────────────────────
        case 'route_add':
            $data  = body();
            $name  = trim($data['name'] ?? '');
            $color = $data['color'] ?? '#ffffff';
            $coords = $data['coordinates'] ?? [];

            if (!$name || empty($coords)) error_response('name und coordinates erforderlich');

            $id = 'route-' . bin2hex(random_bytes(6));
            db()->prepare("
                INSERT INTO routes (id, name, color, coordinates)
                VALUES (:id, :name, :color, :coords)
            ")->execute([
                ':id'     => $id,
                ':name'   => $name,
                ':color'  => $color,
                ':coords' => json_encode($coords),
            ]);
            json_response(['ok' => true, 'id' => $id]);
            break;

        // ── POST/DELETE route_delete ──────────────────────────────────────
        case 'route_delete':
            $data    = body();
            $routeId = $data['route_id'] ?? $_GET['route_id'] ?? '';
            if (!$routeId) error_response('route_id fehlt');

            db()->prepare("UPDATE vehicles SET active_route = NULL WHERE active_route = ?")
                ->execute([$routeId]);
            db()->prepare("DELETE FROM routes WHERE id = ?")
                ->execute([$routeId]);
            json_response(['ok' => true]);
            break;

        default:
            error_response('Unbekannte Aktion: ' . htmlspecialchars($action), 404);
    }

} catch (PDOException $e) {
    error_response('Datenbankfehler: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_response('Fehler: ' . $e->getMessage(), 500);
}
