<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
$action = $_GET['action'] ?? '';

if ($action === 'ping') { json_response(['ok'=>true]); }
if (!isset($_SESSION['user_id'])) { error_response('Nicht eingeloggt', 401); }

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
            $v['lat'] = $v['lat'] !== null ? (float)$v['lat'] : null;
            $v['lng'] = $v['lng'] !== null ? (float)$v['lng'] : null;
        }
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
        db_run("INSERT INTO collection_routes (id,collection_id,template_id,name,color,coordinates,sort_order) VALUES (?,?,?,?,?,?,?)",
               [$id,$cid,$tid,$name,$color,json_encode($coords),$sort]);
        json_response(['ok'=>true,'id'=>$id]);

    case 'col_route_delete':
        if (!$admin) error_response('Kein Zugriff', 403);
        $id=$data['id']??''; if (!$id) error_response('ID fehlt');
        db_run("UPDATE vehicles SET active_route_id=NULL WHERE active_route_id=?",[$id]);
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
        db_run("DELETE FROM users WHERE id=?",[$id]);
        json_response(['ok'=>true]);

    case 'user_change_password':
        $id=(int)($data['id']??0); $pass=$data['password']??'';
        if (!$admin&&$id!==$user_id) error_response('Kein Zugriff',403);
        if (strlen($pass)<6) error_response('Passwort min. 6 Zeichen');
        db_run("UPDATE users SET password_hash=? WHERE id=?",[password_hash($pass,PASSWORD_DEFAULT),$id]);
        json_response(['ok'=>true]);

    case 'vehicle_join':
        $name=trim($data['name']??''); $token=$data['token']??null; $cid=$data['collection_id']??null;
        if (!$name) error_response('Name fehlt');
        if (!$token||strlen($token)<20) $token=bin2hex(random_bytes(32));
        db_run("INSERT INTO vehicles (token,name,user_id,status,active_collection_id,last_seen)
                VALUES (?,?,?,'idle',?,NOW())
                ON DUPLICATE KEY UPDATE
                  name=VALUES(name),user_id=VALUES(user_id),
                  status='idle',active_collection_id=VALUES(active_collection_id),last_seen=NOW()",
               [$token,$name,$user_id,$cid]);
        json_response(['token'=>$token,'name'=>$name,'status'=>'idle']);

    case 'vehicle_position':
        $token=$data['token']??''; $lat=(float)($data['lat']??0); $lng=(float)($data['lng']??0);
        $cid=$data['collection_id']??null;
        if (!$token) error_response('Token fehlt');

        // Position aktualisieren
        if ($cid) {
            db_run("UPDATE vehicles SET lat=?,lng=?,active_collection_id=?,last_seen=NOW() WHERE token=?",[$lat,$lng,$cid,$token]);
        } else {
            db_run("UPDATE vehicles SET lat=?,lng=?,last_seen=NOW() WHERE token=?",[$lat,$lng,$token]);
        }

        // Segment-Tracking für aktive Route
        $v=db_row("SELECT * FROM vehicles WHERE token=?",[$token]);
        if ($v && $v['active_route_id'] && $v['status']==='driving') {
            $r=db_row("SELECT * FROM collection_routes WHERE id=?",[$v['active_route_id']]);
            if ($r) {
                $coords = json_decode($r['coordinates'], true);
                $numSegs = count($coords) - 1;

                // Gefahrene Segmente laden oder initialisieren
                $driven = $r['driven_segments']
                    ? json_decode($r['driven_segments'], true)
                    : init_segments(count($coords));

                // Segmente aktualisieren (5m Toleranz, jedes Segment einzeln)
                $driven   = update_driven_segments($lat, $lng, $coords, $driven);
                $progress = progress_from_segments($driven);

                if (all_segments_driven($driven)) {
                    // Alle Segmente abgefahren → Route komplett
                    db_run("UPDATE collection_routes SET status='completed', progress=100, driven_segments=? WHERE id=?",
                           [json_encode($driven), $r['id']]);
                    db_run("UPDATE vehicles SET status='idle', active_route_id=NULL WHERE token=?", [$token]);
                } else {
                    db_run("UPDATE collection_routes SET progress=?, driven_segments=? WHERE id=?",
                           [$progress, json_encode($driven), $r['id']]);
                }
            }
        }
        json_response(['ok'=>true]);

    case 'route_start':
        $token=$data['token']??''; $rid=$data['route_id']??'';
        if (!$token||!$rid) error_response('Parameter fehlen');
        $v=db_row("SELECT * FROM vehicles WHERE token=?",[$token]);
        if ($v&&$v['active_route_id'])
            db_run("UPDATE collection_routes SET status='pending',assigned_token=NULL WHERE id=? AND status='active'",[$v['active_route_id']]);
        $route=db_row("SELECT * FROM collection_routes WHERE id=?",[$rid]);
        if (!$route) error_response('Route nicht gefunden');

        // Segmente initialisieren falls noch nicht vorhanden
        $coords=json_decode($route['coordinates'],true);
        if (!$route['driven_segments']) {
            db_run("UPDATE collection_routes SET driven_segments=? WHERE id=?",
                   [json_encode(init_segments(count($coords))), $rid]);
        }

        db_run("UPDATE collection_routes SET status='active',assigned_token=? WHERE id=?",[$token,$rid]);
        db_run("UPDATE vehicles SET status='driving',active_route_id=?,active_collection_id=?,last_seen=NOW() WHERE token=?",
               [$rid,$route['collection_id'],$token]);
        json_response(['ok'=>true]);

    case 'route_pause':
        $token=$data['token']??''; $rid=$data['route_id']??'';
        if (!$rid) error_response('route_id fehlt');
        db_run("UPDATE collection_routes SET status='paused' WHERE id=?",[$rid]);
        if ($token) db_run("UPDATE vehicles SET status='paused',last_seen=NOW() WHERE token=?",[$token]);
        json_response(['ok'=>true]);

    case 'route_resume':
        $token=$data['token']??''; $rid=$data['route_id']??'';
        if (!$rid) error_response('route_id fehlt');
        db_run("UPDATE collection_routes SET status='active' WHERE id=?",[$rid]);
        if ($token) db_run("UPDATE vehicles SET status='driving',active_route_id=?,last_seen=NOW() WHERE token=?",[$rid,$token]);
        json_response(['ok'=>true]);

    case 'route_complete':
        $token=$data['token']??''; $rid=$data['route_id']??'';
        if (!$rid) error_response('route_id fehlt');
        // Manuell als erledigt markieren: alle Segmente auf true setzen
        $route=db_row("SELECT coordinates FROM collection_routes WHERE id=?",[$rid]);
        if ($route) {
            $coords=json_decode($route['coordinates'],true);
            $allDriven=array_fill(0,max(0,count($coords)-1),true);
            db_run("UPDATE collection_routes SET status='completed',progress=100,driven_segments=? WHERE id=?",
                   [json_encode($allDriven),$rid]);
        } else {
            db_run("UPDATE collection_routes SET status='completed',progress=100 WHERE id=?",[$rid]);
        }
        if ($token) db_run("UPDATE vehicles SET status='idle',active_route_id=NULL,last_seen=NOW() WHERE token=?",[$token]);
        json_response(['ok'=>true]);

    case 'route_reset':
        if (!$admin) error_response('Kein Zugriff', 403);
        $rid=$data['route_id']??''; if (!$rid) error_response('route_id fehlt');
        db_run("UPDATE vehicles SET status='idle',active_route_id=NULL WHERE active_route_id=?",[$rid]);
        // Alle Segmente zurücksetzen
        db_run("UPDATE collection_routes SET status='pending',progress=0,assigned_token=NULL,driven_segments=NULL WHERE id=?",[$rid]);
        json_response(['ok'=>true]);

    case 'route_toggle':
        if (!$admin) error_response('Kein Zugriff', 403);
        $rid=$data['route_id']??''; if (!$rid) error_response('route_id fehlt');
        db_run("UPDATE collection_routes SET visible=NOT visible WHERE id=?",[$rid]);
        json_response(['ok'=>true]);

    default:
        error_response('Unbekannte Aktion: '.htmlspecialchars($action),404);
    }
} catch (PDOException $e) {
    error_response('DB-Fehler: '.$e->getMessage(),500);
} catch (Exception $e) {
    error_response('Fehler: '.$e->getMessage(),500);
}
