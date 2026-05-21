<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ── CORS für Mobile-Apps (Android / iOS) ──────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// Erlaube alle Origins (Mobile-Apps senden keinen Origin-Header oder eine App-URL)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Max-Age: 86400');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

if ($action === 'ping') { json_response(['ok'=>true]); }

// ── Mobile-Login: gibt Bearer-Token zurück (kein Session-Cookie nötig) ────────
if ($action === 'mobile_login') {
    $data = body();
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    if (!$username || !$password) error_response('Username und Passwort erforderlich', 400);
    $user = db_row("SELECT id, password_hash, role FROM users WHERE username=?", [$username]);
    if (!$user || !password_verify($password, $user['password_hash']))
        error_response('Ungültige Zugangsdaten', 401);
    ensure_api_tokens_table();
    // Alten Token für diesen User löschen, neuen erstellen (30 Tage gültig)
    db_run("DELETE FROM api_tokens WHERE user_id=?", [$user['id']]);
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    db_run("INSERT INTO api_tokens (token, user_id, expires_at) VALUES (?,?,?)",
           [$token, $user['id'], $expires]);
    json_response(['token' => $token, 'expires_at' => $expires,
                   'user_id' => $user['id'], 'username' => $username, 'role' => $user['role']]);
}

// ── Auth: Session ODER Bearer-Token (für Mobile-Apps) ────────────────────────
if (!isset($_SESSION['user_id'])) {
    // Bearer-Token aus Authorization-Header oder X-Auth-Token
    $bearer = '';
    $authHdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHdr, $m)) $bearer = trim($m[1]);
    if (!$bearer) $bearer = trim($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (!$bearer) $bearer = trim($data['auth_token'] ?? $_GET['auth_token'] ?? '');
    if ($bearer) {
        ensure_api_tokens_table();
        $tok = db_row("SELECT user_id FROM api_tokens WHERE token=? AND expires_at > NOW()", [$bearer]);
        if ($tok) {
            $_SESSION['user_id'] = $tok['user_id'];
            db_run("UPDATE api_tokens SET last_used=NOW() WHERE token=?", [$bearer]);
        }
    }
    if (!isset($_SESSION['user_id'])) error_response('Nicht eingeloggt', 401);
}

$admin   = is_admin();
$data    = body();
$user_id = me_id();

try {
    cleanup_vehicles();
    switch ($action) {

    case 'state':
        $cid = $_GET['collection_id'] ?? ($data['collection_id'] ?? '');
        if (!$cid) { json_response(['routes'=>[],'vehicles'=>[]]); }
        $routes = db_rows("SELECT * FROM collection_routes WHERE collection_id=? ORDER BY sort_order,name", [$cid]);
        foreach ($routes as &$r) {
            $r['coordinates']     = json_decode($r['coordinates'], true);
            $r['driven_segments'] = $r['driven_segments'] ? json_decode($r['driven_segments'], true) : null;
            $r['visible']         = (bool)$r['visible'];
            $r['progress']        = (int)$r['progress'];
        }
        $vehicles = db_rows("SELECT * FROM vehicles
            WHERE status!='offline' AND active_collection_id=?
            AND last_seen >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$cid, VEHICLE_TIMEOUT]);
        foreach ($vehicles as &$v) {
            $v['lat']        = $v['lat'] !== null ? (float)$v['lat'] : null;
            $v['lng']        = $v['lng'] !== null ? (float)$v['lng'] : null;
            $v['collecting'] = (bool)($v['collecting'] ?? false);
        }
        // Web Push: offline gegangene Sammel-Fahrzeuge benachrichtigen
        // (Piggyback auf Polling – kein Cron-Job nötig)
        try {
            require_once __DIR__ . '/push.php';
            notify_offline_collectors($cid);
        } catch (Exception $e) {}
        json_response(['routes'=>$routes,'vehicles'=>$vehicles]);

    case 'collections_active':
        json_response(db_rows("SELECT id,name,collection_date,status FROM collections WHERE status='active' ORDER BY collection_date DESC"));

    case 'collections_all':
        if (!$admin) error_response('Kein Zugriff', 403);
        json_response(db_rows("SELECT c.*, COUNT(cr.id) as route_count
            FROM collections c LEFT JOIN collection_routes cr ON cr.collection_id=c.id
            GROUP BY c.id ORDER BY c.collection_date DESC"));

    case 'collection_create':
        if (!$admin) error_response('Kein Zugriff', 403);
        $name=trim($data['name']??''); $date=$data['date']??'';
        if (!$name||!$date) error_response('Name und Datum erforderlich');
        $id=new_id();
        db_run("INSERT INTO collections (id,name,collection_date) VALUES (?,?,?)",[$id,$name,$date]);
        json_response(['ok'=>true,'id'=>$id]);

    case 'collection_update':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=$data['id']??''; $name=trim($data['name']??''); $date=$data['date']??''; $status=$data['status']??'';
        if (!$id) error_response('ID fehlt');
        if ($name)   db_run("UPDATE collections SET name=? WHERE id=?",[$name,$id]);
        if ($date)   db_run("UPDATE collections SET collection_date=? WHERE id=?",[$date,$id]);
        if ($status && in_array($status,['draft','active','completed']))
            db_run("UPDATE collections SET status=? WHERE id=?",[$status,$id]);
        json_response(['ok'=>true]);

    case 'collection_delete':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=$data['id']??''; if (!$id) error_response('ID fehlt');
        db_run("DELETE FROM collection_routes WHERE collection_id=?",[$id]);
        db_run("DELETE FROM vehicle_tracks WHERE collection_id=?",[$id]);
        db_run("DELETE FROM collections WHERE id=?",[$id]);
        json_response(['ok'=>true]);

    case 'col_routes_list':
        if (!$admin) error_response('Kein Zugriff', 403);
        $cid=$_GET['collection_id']??'';
        $rows=db_rows("SELECT cr.*,rt.name as template_name FROM collection_routes cr
                       LEFT JOIN route_templates rt ON rt.id=cr.template_id
                       WHERE cr.collection_id=? ORDER BY cr.sort_order,cr.name",[$cid]);
        foreach ($rows as &$r) {
            $r['coordinates']=json_decode($r['coordinates'],true);
            $r['visible']=(bool)$r['visible'];
        }
        json_response($rows);

    case 'col_route_add':
        if (!$admin) error_response('Kein Zugriff', 403);
        $cid=$data['collection_id']??''; $name=trim($data['name']??'');
        $color=$data['color']??'#00d4ff'; $coords=$data['coordinates']??[]; $tid=$data['template_id']?:null;
        if (!$cid||!$name||empty($coords)) error_response('Pflichtfelder fehlen');
        $id=new_id(); $sort=(int)db_val("SELECT COUNT(*) FROM collection_routes WHERE collection_id=?",[$cid]);
        // Neue Routen direkt als 'active' anlegen – kein manueller Start nötig
        db_run("INSERT INTO collection_routes (id,collection_id,template_id,name,color,coordinates,status,sort_order,driven_segments) VALUES (?,?,?,?,?,?,?,?,?)",
               [$id,$cid,$tid,$name,$color,json_encode($coords),'active',$sort,json_encode(init_segments(count($coords)))]);
        json_response(['ok'=>true,'id'=>$id]);

    case 'col_route_delete':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=$data['id']??''; if (!$id) error_response('ID fehlt');
        db_run("DELETE FROM collection_routes WHERE id=?",[$id]);
        json_response(['ok'=>true]);

    case 'templates_list':
        $rows=db_rows("SELECT id,name,color,description,created_at FROM route_templates ORDER BY name");
        foreach ($rows as &$r) {
            $raw=db_val("SELECT coordinates FROM route_templates WHERE id=?",[$r['id']]);
            $r['point_count']=$raw ? count(json_decode($raw,true)) : 0;
        }
        json_response($rows);

    case 'template_detail':
        $id=$_GET['id']??'';
        $r=db_row("SELECT * FROM route_templates WHERE id=?",[$id]);
        if (!$r) error_response('Nicht gefunden',404);
        $r['coordinates']=json_decode($r['coordinates'],true);
        json_response($r);

    case 'template_create':
        if (!$admin) error_response('Kein Zugriff', 403);
        $name=trim($data['name']??''); $color=$data['color']??'#00d4ff';
        $coords=$data['coordinates']??[]; $desc=$data['description']??'';
        if (!$name||empty($coords)) error_response('Name und Koordinaten erforderlich');
        $id=new_id();
        db_run("INSERT INTO route_templates (id,name,color,coordinates,description) VALUES (?,?,?,?,?)",
               [$id,$name,$color,json_encode($coords),$desc]);
        json_response(['ok'=>true,'id'=>$id]);

    case 'template_update':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=$data['id']??''; if (!$id) error_response('ID fehlt');
        if ($data['name']??'')     db_run("UPDATE route_templates SET name=? WHERE id=?",[trim($data['name']),$id]);
        if ($data['color']??'')    db_run("UPDATE route_templates SET color=? WHERE id=?",[$data['color'],$id]);
        if ($data['coordinates']??null) db_run("UPDATE route_templates SET coordinates=? WHERE id=?",[json_encode($data['coordinates']),$id]);
        if (isset($data['description'])) db_run("UPDATE route_templates SET description=? WHERE id=?",[$data['description'],$id]);
        json_response(['ok'=>true]);

    case 'template_delete':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=$data['id']??''; if (!$id) error_response('ID fehlt');
        db_run("DELETE FROM route_templates WHERE id=?",[$id]);
        json_response(['ok'=>true]);

    case 'users_list':
        if (!$admin) error_response('Kein Zugriff', 403);
        json_response(db_rows("SELECT id,username,role,created_at FROM users ORDER BY role DESC,username"));

    case 'user_create':
        if (!$admin) error_response('Kein Zugriff', 403);
        $uname=trim($data['username']??''); $pass=$data['password']??'';
        $role=in_array($data['role']??'',['admin','user'])?$data['role']:'user';
        if (!$uname||!$pass) error_response('Felder erforderlich');
        if (strlen($pass)<6) error_response('Passwort min. 6 Zeichen');
        if (db_val("SELECT COUNT(*) FROM users WHERE username=?",[$uname])) error_response('Benutzername vergeben');
        db_run("INSERT INTO users (username,password_hash,role) VALUES (?,?,?)",
               [$uname,password_hash($pass,PASSWORD_DEFAULT),$role]);
        json_response(['ok'=>true]);

    case 'user_delete':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=(int)($data['id']??0);
        if ($id===$user_id) error_response('Eigenen Account nicht löschbar');
        db_run("DELETE FROM vehicles WHERE user_id=?",[$id]);
        db_run("DELETE FROM users WHERE id=?",[$id]);
        json_response(['ok'=>true]);

    case 'user_change_password':
        $id=(int)($data['id']??0); $pass=$data['password']??'';
        if (!$admin&&$id!==$user_id) error_response('Kein Zugriff',403);
        if (strlen($pass)<6) error_response('Passwort min. 6 Zeichen');
        db_run("UPDATE users SET password_hash=? WHERE id=?",[password_hash($pass,PASSWORD_DEFAULT),$id]);
        json_response(['ok'=>true]);

    // ── FAHRZEUG ─────────────────────────────────────────────────────────────

    case 'vehicle_join':
        $name = trim($data['name'] ?? '');
        $cid  = $data['collection_id'] ?? null;
        $existing = db_row("SELECT * FROM vehicles WHERE user_id=?", [$user_id]);
        if ($existing) {
            $token = $existing['token'];
            if ($name && $name !== $existing['name']) {
                db_run("UPDATE vehicles SET name=?, active_collection_id=?, last_seen=NOW() WHERE token=?",[$name,$cid,$token]);
                $existing['name'] = $name;
            } else {
                db_run("UPDATE vehicles SET active_collection_id=?, last_seen=NOW() WHERE token=?",[$cid,$token]);
            }
            json_response(['token'=>$token,'name'=>$existing['name'],'status'=>$existing['status'],'collecting'=>(bool)($existing['collecting']??false)]);
        } else {
            if (!$name) { $u=db_row("SELECT username FROM users WHERE id=?",[$user_id]); $name=$u['username']??'Fahrzeug'; }
            $token = bin2hex(random_bytes(32));
            db_run("INSERT INTO vehicles (token,name,user_id,status,active_collection_id,last_seen) VALUES (?,?,?,'idle',?,NOW())",
                   [$token,$name,$user_id,$cid]);
            json_response(['token'=>$token,'name'=>$name,'status'=>'idle','collecting'=>false]);
        }

    case 'vehicle_rename':
        $token=trim($data['token']??''); $name=trim($data['name']??'');
        if (!$token||!$name) error_response('Token oder Name fehlt');
        $v=db_row("SELECT user_id FROM vehicles WHERE token=?",[$token]);
        if (!$v||(int)$v['user_id']!==$user_id) error_response('Kein Zugriff',403);
        db_run("UPDATE vehicles SET name=? WHERE token=?",[$name,$token]);
        json_response(['ok'=>true,'name'=>$name]);

    case 'vehicle_set_collecting':
        // Sammelmodus: steuert ob GPS-Tracking aktiv ist.
        // Ein → vehicle status='driving', Aus → status='idle'
        $token      = $data['token'] ?? '';
        $collecting = !empty($data['collecting']) ? 1 : 0;
        if (!$token) error_response('Token fehlt');
        $v=db_row("SELECT user_id FROM vehicles WHERE token=?",[$token]);
        if (!$v||(int)$v['user_id']!==$user_id) error_response('Kein Zugriff',403);
        $status = $collecting ? 'driving' : 'idle';
        db_run("UPDATE vehicles SET collecting=?, status=? WHERE token=?",[$collecting,$status,$token]);
        json_response(['ok'=>true,'collecting'=>(bool)$collecting,'status'=>$status]);

    case 'vehicle_position':
        $token     = $data['token']??'';
        $lat       = (float)($data['lat']??0);
        $lng       = (float)($data['lng']??0);
        $speed_ms  = isset($data['speed']) ? (float)$data['speed'] : null; // m/s vom Client
        $cid       = $data['collection_id']??null;
        $debugMode = isset($_GET['debug']);
        if (!$token) error_response('Token fehlt');

        $v = db_row("SELECT * FROM vehicles WHERE token=?", [$token]);

        if ($cid) db_run("UPDATE vehicles SET lat=?,lng=?,active_collection_id=?,last_seen=NOW() WHERE token=?",[$lat,$lng,$cid,$token]);
        else      db_run("UPDATE vehicles SET lat=?,lng=?,last_seen=NOW() WHERE token=?",[$lat,$lng,$token]);

        $debugInfo = ['collecting'=>(bool)($v['collecting']??false),'tracking'=>false];

        // Fahrspur + Segment-Tracking nur im Sammelmodus
        if ($v && !empty($v['collecting']) && $cid) {
            // GPS-Track-Punkt speichern
            save_vehicle_track($token, $cid, $lat, $lng, $speed_ms);

            // Prüfposition: Client-Snap (OSRM) wenn vorhanden
            $hasSnap  = isset($data['snap_lat'],$data['snap_lng'])
                        && abs((float)$data['snap_lat'])>0.001 && abs((float)$data['snap_lng'])>0.001;
            $checkLat = $hasSnap ? (float)$data['snap_lat'] : $lat;
            $checkLng = $hasSnap ? (float)$data['snap_lng'] : $lng;
            $tolerance = $hasSnap ? 20.0 : 30.0;
            $prevLat  = ($v['lat']!==null) ? (float)$v['lat'] : $checkLat;
            $prevLng  = ($v['lng']!==null) ? (float)$v['lng'] : $checkLng;

            // Alle nicht-abgeschlossenen Routen dieser Sammlung prüfen
            // (kein manueller Start nötig – automatisches Tracking auf allen Routen)
            $allRoutes = db_rows("SELECT * FROM collection_routes WHERE collection_id=? AND status!='completed'",[$cid]);
            $updatedRouteId = null;

            foreach ($allRoutes as $r) {
                $coords = json_decode($r['coordinates'], true);
                $nCoords = count($coords);

                // Prüfen ob Fahrzeug überhaupt auf dieser Route ist
                $curProj = project_onto_route($checkLat, $checkLng, $coords, $tolerance);
                if ($curProj === null) continue; // nicht auf dieser Route

                $driven = ($r['driven_segments']!==null)
                    ? json_decode($r['driven_segments'],true)
                    : init_segments($nCoords);
                $expectedLen = max(0,$nCoords-1);
                if (count($driven)!==$expectedLen)
                    $driven=array_pad(array_slice($driven,0,$expectedLen),$expectedLen,false);

                $driven = update_driven_segments($prevLat,$prevLng,$checkLat,$checkLng,$coords,$driven,$tolerance);
                $progress = progress_from_segments($driven);

                if (all_segments_driven($driven)) {
                    db_run("UPDATE collection_routes SET status='completed',progress=100,driven_segments=?,assigned_token=? WHERE id=?",
                           [json_encode(array_values($driven)),$token,$r['id']]);
                } else {
                    // Auto-aktivieren wenn Fahrzeug auf pendender Route fährt
                    db_run("UPDATE collection_routes SET status='active',progress=?,driven_segments=?,assigned_token=? WHERE id=?",
                           [$progress,json_encode(array_values($driven)),$token,$r['id']]);
                }
                $updatedRouteId = $r['id'];
            }

            // active_route_id auf zuletzt befahrene Route setzen
            if ($updatedRouteId) {
                db_run("UPDATE vehicles SET active_route_id=? WHERE token=?",[$updatedRouteId,$token]);
            }

            if ($debugMode) {
                $debugInfo['tracking']  = true;
                $debugInfo['has_snap']  = $hasSnap;
                $debugInfo['routes_checked'] = count($allRoutes);
            }
        } elseif ($debugMode) {
            $debugInfo['tracking_skipped'] = $cid ? 'Sammelmodus inaktiv' : 'Keine Collection-ID';
        }

        if ($debugMode) json_response(['ok'=>true,'debug'=>$debugInfo]);
        json_response(['ok'=>true]);

    // ── Fahrspur abrufen ──────────────────────────────────────────────────────
    case 'vehicle_track':
        $token = $_GET['token'] ?? '';
        $cid   = $_GET['collection_id'] ?? '';
        if (!$token || !$cid) error_response('Parameter fehlen');

        // Auto-Cleanup: Tracks älter als 7 Tage löschen
        try { db_run("DELETE FROM vehicle_tracks WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"); } catch (Exception $e) {}

        // Letzte 800 Punkte, älteste zuerst
        $rows = db_rows("SELECT lat,lng,speed,recorded_at FROM vehicle_tracks
                         WHERE token=? AND collection_id=?
                         ORDER BY recorded_at DESC LIMIT 800",
                        [$token,$cid]);
        $rows = array_reverse($rows); // chronologisch
        $points = array_map(fn($r)=>[(float)$r['lat'],(float)$r['lng']],$rows);
        $latestTime = !empty($rows) ? end($rows)['recorded_at'] : null;
        json_response(['points'=>$points,'count'=>count($points),'latest_at'=>$latestTime]);

    // ── Route-Reset (einzige verbleibende Route-Aktion für User) ─────────────
    case 'route_reset':
        if (!$admin) error_response('Kein Zugriff', 403);
        $rid=$data['route_id']??''; if (!$rid) error_response('route_id fehlt');
        // Segmente zurücksetzen, Route wieder auf 'active' (nicht pending – braucht keinen manuellen Start)
        $route=db_row("SELECT coordinates FROM collection_routes WHERE id=?",[$rid]);
        $segs = $route ? json_encode(init_segments(count(json_decode($route['coordinates'],true)))) : 'null';
        db_run("UPDATE collection_routes SET status='active',progress=0,assigned_token=NULL,driven_segments=? WHERE id=?",[$segs,$rid]);
        json_response(['ok'=>true]);

    // ── Vehicle Ping: last_seen aktualisieren (idle Fahrzeuge bleiben sichtbar) ─
    case 'vehicle_ping':
        $token = $data['token'] ?? '';
        if (!$token) error_response('Token fehlt');
        db_run("UPDATE vehicles SET last_seen=NOW() WHERE token=?", [$token]);
        json_response(['ok'=>true,'ts'=>date('c')]);

    case 'route_toggle':
        if (!$admin) error_response('Kein Zugriff', 403);
        $rid=$data['route_id']??''; if (!$rid) error_response('route_id fehlt');
        db_run("UPDATE collection_routes SET visible=NOT visible WHERE id=?",[$rid]);
        json_response(['ok'=>true]);

    // ── Web Push Subscription ──────────────────────────────────────────────────
    case 'get_vapid_key':
        // Liefert den öffentlichen VAPID-Schlüssel für die Push-Subscription im Client
        try {
            require_once __DIR__ . '/push.php';
            $vapid = get_vapid_keys();
            if (!$vapid) json_response(['key'=>null,'available'=>false]);
            json_response(['key'=>$vapid['public'],'available'=>true]);
        } catch (Exception $e) { json_response(['key'=>null,'available'=>false]); }

    case 'push_subscribe':
        // Push-Subscription (endpoint + keys) des Clients speichern
        $endpoint = trim($data['endpoint'] ?? '');
        $p256dh   = trim($data['p256dh']   ?? '');
        $auth     = trim($data['auth']      ?? '');
        if (!$endpoint || !$p256dh || !$auth) error_response('Felder fehlen');
        try {
            db_run("INSERT INTO push_subscriptions (user_id,endpoint,p256dh,auth_key)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE endpoint=VALUES(endpoint),p256dh=VALUES(p256dh)",
                   [$user_id, $endpoint, $p256dh, $auth]);
        } catch (Exception $e) {
            // Tabelle existiert noch nicht → ignorieren
        }
        json_response(['ok'=>true]);

    case 'push_unsubscribe':
        db_run("DELETE FROM push_subscriptions WHERE user_id=?", [$user_id]);
        json_response(['ok'=>true]);

    default:
        error_response('Unbekannte Aktion: '.htmlspecialchars($action),404);
    }
} catch (PDOException $e) {
    error_response('DB-Fehler: '.$e->getMessage(),500);
} catch (Exception $e) {
    error_response('Fehler: '.$e->getMessage(),500);
}
