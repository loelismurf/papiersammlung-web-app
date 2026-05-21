<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Installation – Papiersammlung</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<style>
  body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:740px}
  h1{color:#00d4ff;letter-spacing:3px;margin-bottom:6px}
  .sub{font-size:11px;color:#4a5a6a;letter-spacing:2px;margin-bottom:28px}
  .ok{color:#a8ff3e}.err{color:#ff6b35}.sec{color:#00d4ff}
  pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.1;margin-bottom:16px}
  .box{background:#0f1216;border:1px solid #2a3340;border-left:3px solid #ffd700;padding:16px;margin-top:20px;border-radius:4px;line-height:1.9}
  a.btn{display:inline-block;margin-top:20px;background:#00d4ff;color:#0a0c0f;border:none;padding:12px 28px;font-family:monospace;font-size:14px;font-weight:bold;cursor:pointer;border-radius:4px;text-decoration:none}
  h2{font-size:13px;color:#4a5a6a;letter-spacing:2px;margin:20px 0 4px;text-transform:uppercase}
</style>
</head>
<body>
<h1>⚙ PAPIERSAMMLUNG</h1>
<div class="sub">INSTALLATION / AKTUALISIERUNG · v5</div>
<?php
// ── Zugangsdaten aus config.php lesen ────────────────────────────────────────
require_once __DIR__ . '/../config.php';

$steps = []; $hasErr = false; $newAdmin = false;

function step(bool $ok, string $msg): void {
    global $steps, $hasErr;
    $steps[] = [$ok, $msg];
    if (!$ok) $hasErr = true;
}
function section(string $title): void { global $steps; $steps[] = ['sec', $title]; }

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    step(true, 'Datenbankverbindung erfolgreich');

    section('Tabellen erstellen / prüfen');

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL, role ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `users`');

    $pdo->exec("CREATE TABLE IF NOT EXISTS route_templates (
        id VARCHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL,
        color VARCHAR(20) NOT NULL DEFAULT '#00d4ff', coordinates LONGTEXT NOT NULL,
        description TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `route_templates`');

    $pdo->exec("CREATE TABLE IF NOT EXISTS collections (
        id VARCHAR(32) PRIMARY KEY, name VARCHAR(100) NOT NULL,
        collection_date DATE NOT NULL, status ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `collections`');

    $pdo->exec("CREATE TABLE IF NOT EXISTS collection_routes (
        id VARCHAR(32) PRIMARY KEY, collection_id VARCHAR(32) NOT NULL,
        template_id VARCHAR(32) DEFAULT NULL, name VARCHAR(100) NOT NULL,
        color VARCHAR(20) NOT NULL DEFAULT '#00d4ff', coordinates LONGTEXT NOT NULL,
        status ENUM('pending','active','completed','paused') NOT NULL DEFAULT 'active',
        assigned_token VARCHAR(64) DEFAULT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
        visible TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
        driven_segments LONGTEXT DEFAULT NULL COMMENT 'JSON bool[] pro Segment',
        INDEX idx_collection (collection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `collection_routes` (Default status=active)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
        token VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL,
        user_id INT DEFAULT NULL, lat DOUBLE DEFAULT NULL, lng DOUBLE DEFAULT NULL,
        status ENUM('idle','driving','paused','offline') NOT NULL DEFAULT 'idle',
        active_collection_id VARCHAR(32) DEFAULT NULL, active_route_id VARCHAR(32) DEFAULT NULL,
        collecting TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Sammelmodus aktiv',
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `vehicles` (inkl. collecting)');

    // v5: Fahrspur-Tabelle
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_tracks (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL, collection_id VARCHAR(32) NOT NULL,
        lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
        speed FLOAT DEFAULT NULL COMMENT 'm/s',
        recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_track (token, collection_id, recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `vehicle_tracks` (Fahrspur-Aufzeichnung) ← NEU v5');

    section('Spalten-Upgrade (bestehende DB)');

    $requiredCols = [
        'collection_routes' => [
            'driven_segments' => "LONGTEXT DEFAULT NULL COMMENT 'JSON bool[] pro Segment'",
        ],
        'vehicles' => [
            'user_id'              => 'INT DEFAULT NULL',
            'active_collection_id' => 'VARCHAR(32) DEFAULT NULL',
            'active_route_id'      => 'VARCHAR(32) DEFAULT NULL',
            'collecting'           => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ];

    foreach ($requiredCols as $table => $columns) {
        foreach ($columns as $col => $def) {
            $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->fetchColumn();
            if (!$exists) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def"); step(true,"Spalte `$table.$col` hinzugefügt ← NEU"); }
            else step(true,"Spalte `$table.$col` ✓");
        }
    }

    // Route-Status Default auf 'active' setzen (v5: kein manueller Start mehr)
    try {
        $pdo->exec("ALTER TABLE collection_routes MODIFY COLUMN status
                    ENUM('pending','active','completed','paused') NOT NULL DEFAULT 'active'");
        step(true, 'collection_routes.status Default=active ✓');
    } catch(Exception $e) { step(true, 'collection_routes.status – bereits korrekt'); }

    // Bestehende 'pending' Routen auf 'active' setzen
    $pendingCount = $pdo->query("SELECT COUNT(*) FROM collection_routes WHERE status='pending'")->fetchColumn();
    if ($pendingCount > 0) {
        $pdo->exec("UPDATE collection_routes SET status='active' WHERE status='pending'");
        step(true, "Bestehende 'pending' Routen ($pendingCount) auf 'active' gesetzt ← Migration v5");
    } else {
        step(true, "Keine pending Routen – Migration nicht nötig");
    }

    // vehicles.status Enum
    try {
        $pdo->exec("ALTER TABLE vehicles MODIFY COLUMN status ENUM('idle','driving','paused','offline') NOT NULL DEFAULT 'idle'");
        step(true, 'vehicles.status Enum ✓');
    } catch(Exception $e) { step(true, 'vehicles.status – bereits korrekt'); }

    // Index vehicles.user_id
    try {
        $idxExists = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='vehicles' AND INDEX_NAME='idx_user'")->fetchColumn();
        if (!$idxExists) { $pdo->exec("ALTER TABLE vehicles ADD INDEX idx_user (user_id)"); step(true,'Index vehicles.idx_user ← NEU'); }
        else step(true, 'Index vehicles.idx_user ✓');
    } catch(Exception $e) { step(true, 'Index vehicles.idx_user – übersprungen'); }

    section('Benutzer');
    $adminExists = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if (!$adminExists) {
        $pdo->prepare("INSERT IGNORE INTO users (username,password_hash,role) VALUES ('admin',?,'admin')")->execute([password_hash('admin123',PASSWORD_DEFAULT)]);
        step(true,'Standard-Admin erstellt: admin / admin123'); $newAdmin=true;
    } else { step(true,"Admin-Benutzer vorhanden ($adminExists)"); }

    section('Beispieldaten');
    $tplCount = $pdo->query("SELECT COUNT(*) FROM route_templates")->fetchColumn();
    if (!$tplCount) {
        $templates=[
            ['name'=>'Route Nord','color'=>'#00d4ff','coords'=>[[47.3769,8.5417],[47.3850,8.5350],[47.3920,8.5280],[47.3980,8.5220],[47.4050,8.5180]]],
            ['name'=>'Route Ost', 'color'=>'#ff6b35','coords'=>[[47.3769,8.5417],[47.3720,8.5500],[47.3680,8.5600],[47.3640,8.5700],[47.3600,8.5800]]],
            ['name'=>'Route Süd', 'color'=>'#a8ff3e','coords'=>[[47.3769,8.5417],[47.3700,8.5400],[47.3640,8.5380],[47.3580,8.5350],[47.3460,8.5280]]],
            ['name'=>'Route West','color'=>'#ff3e9d','coords'=>[[47.3769,8.5417],[47.3780,8.5300],[47.3790,8.5180],[47.3810,8.4950],[47.3820,8.4840]]],
        ];
        $stmt=$pdo->prepare("INSERT INTO route_templates (id,name,color,coordinates) VALUES (?,?,?,?)");
        foreach($templates as $t) $stmt->execute([bin2hex(random_bytes(8)),$t['name'],$t['color'],json_encode($t['coords'])]);
        step(true,count($templates).' Beispiel-Vorlagen erstellt (Zürich)');
    } else step(true,"Vorlagen vorhanden ($tplCount) – übersprungen");

    // v5: Settings-Tabelle (VAPID-Keys etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key_name VARCHAR(100) PRIMARY KEY,
        value    TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `settings` (VAPID-Schlüssel, App-Konfiguration)');

    // v5: Push-Subscriptions
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        endpoint   TEXT NOT NULL,
        p256dh     TEXT NOT NULL,
        auth_key   VARCHAR(128) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_auth (user_id, auth_key(64)),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `push_subscriptions` (Web Push) ← NEU v5');

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        token      VARCHAR(64)  NOT NULL UNIQUE,
        user_id    INT          NOT NULL,
        expires_at DATETIME     NOT NULL,
        last_used  DATETIME     DEFAULT NULL,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user  (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `api_tokens` (Mobile-App Bearer-Auth) ← NEU v5.1');

    step(true,'─── Installation v5 abgeschlossen ───');
} catch (Exception $e) { step(false,'Fehler: '.$e->getMessage()); }
?>
<h2>Protokoll</h2>
<pre><?php foreach($steps as [$t,$m]):
    if($t==='sec'): ?><span class="sec">── <?=htmlspecialchars($m)?> ──</span>
<?php else: ?><span class="<?=$t?'ok':'err'?>"><?=$t?'✅':'❌'?> <?=htmlspecialchars($m)?></span>
<?php endif; endforeach; ?></pre>
<?php if(!$hasErr): ?>
<?php if($newAdmin): ?>
<div class="box"><strong style="color:#ffd700">⚠️ Standard-Zugangsdaten:</strong><br><br>
  Benutzername: <strong style="color:#00d4ff">admin</strong><br>
  Passwort: <strong style="color:#00d4ff">admin123</strong><br><br>
  <span style="color:#ff6b35">Bitte sofort nach dem Login das Passwort ändern!</span></div>
<?php endif; ?>
<p style="color:#a8ff3e;margin-top:16px">✅ Erfolgreich!</p>
<p style="color:#ffd700;margin-top:8px">⚠️ <strong>install.php nach der Installation löschen!</strong></p>
<a class="btn" href="../login.php">→ Zum Login</a>
<?php else: ?>
<p style="color:#ff6b35;margin-top:16px">❌ Fehler aufgetreten.</p>
<?php endif; ?>
</body></html>
