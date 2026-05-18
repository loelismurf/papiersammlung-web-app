<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Migration</title>
<style>
body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:700px}
h1{color:#00d4ff;letter-spacing:3px;margin-bottom:24px}
.ok{color:#a8ff3e}.err{color:#ff6b35}.info{color:#ffd700}
pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2}
a.btn{display:inline-block;margin-top:20px;background:#00d4ff;color:#0a0c0f;
      padding:12px 24px;font-weight:bold;border-radius:4px;text-decoration:none}
</style></head><body>
<h1>🔧 DATENBANK MIGRATION</h1>
<?php
// Direkte DB-Verbindung ohne require (falls config.php Probleme macht)
define('DB_HOST', '10.35.47.103:3306');
define('DB_NAME', 'k82299_papiersammlung');
define('DB_USER', 'k82299_papiersammlung');
define('DB_PASS', '7NpFrRhycO2cb7K3Cfkb');

$steps = [];
$hasErr = false;

function step($ok, $msg) {
    global $steps, $hasErr;
    $steps[] = [$ok, $msg];
    if (!$ok) $hasErr = true;
}

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    step(true, 'Datenbankverbindung OK');
} catch (Exception $e) {
    step(false, 'DB-Verbindung fehlgeschlagen: '.$e->getMessage());
    // Sofort ausgeben und beenden
    echo '<pre>';
    foreach ($steps as [$ok,$m]) echo '<span class="'.($ok?'ok':'err').'">'.($ok?'✅':'❌').' '.htmlspecialchars($m)."</span>\n";
    echo '</pre><p class="err">Bitte config.php prüfen.</p></body></html>';
    exit;
}

// Hilfsfunktionen
function colExists($pdo, $table, $col) {
    $r = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $r->execute([$table, $col]);
    return (bool)$r->fetchColumn();
}
function tableExists($pdo, $table) {
    $r = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $r->execute([$table]);
    return (bool)$r->fetchColumn();
}

// ── Tabelle users ──────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50)  UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `users` OK');
} catch(Exception $e) { step(false, '`users`: '.$e->getMessage()); }

// ── Tabelle route_templates ───────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS route_templates (
        id          VARCHAR(32) PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        color       VARCHAR(20) NOT NULL DEFAULT '#00d4ff',
        coordinates LONGTEXT NOT NULL,
        description TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `route_templates` OK');
} catch(Exception $e) { step(false, '`route_templates`: '.$e->getMessage()); }

// ── Tabelle collections ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS collections (
        id               VARCHAR(32) PRIMARY KEY,
        name             VARCHAR(100) NOT NULL,
        collection_date  DATE NOT NULL,
        status           ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `collections` OK');
} catch(Exception $e) { step(false, '`collections`: '.$e->getMessage()); }

// ── Tabelle collection_routes ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS collection_routes (
        id             VARCHAR(32) PRIMARY KEY,
        collection_id  VARCHAR(32) NOT NULL,
        template_id    VARCHAR(32) DEFAULT NULL,
        name           VARCHAR(100) NOT NULL,
        color          VARCHAR(20) NOT NULL DEFAULT '#00d4ff',
        coordinates    LONGTEXT NOT NULL,
        status         ENUM('pending','active','completed','paused') NOT NULL DEFAULT 'pending',
        assigned_token VARCHAR(64) DEFAULT NULL,
        progress       TINYINT UNSIGNED NOT NULL DEFAULT 0,
        visible        TINYINT(1) NOT NULL DEFAULT 1,
        sort_order     INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    step(true, 'Tabelle `collection_routes` OK');
} catch(Exception $e) { step(false, '`collection_routes`: '.$e->getMessage()); }

// ── Tabelle vehicles: fehlende Spalten ───────────────────────────────────
$vCols = [
    'user_id'              => 'INT DEFAULT NULL',
    'active_collection_id' => 'VARCHAR(32) DEFAULT NULL',
    'active_route_id'      => 'VARCHAR(32) DEFAULT NULL',
];
foreach ($vCols as $col => $def) {
    try {
        if (!colExists($pdo, 'vehicles', $col)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN `$col` $def");
            step(true, "Spalte vehicles.`$col` hinzugefügt");
        } else {
            step(true, "Spalte vehicles.`$col` bereits vorhanden");
        }
    } catch(Exception $e) { step(false, "vehicles.`$col`: ".$e->getMessage()); }
}

// vehicles.status Enum erweitern
try {
    $pdo->exec("ALTER TABLE vehicles MODIFY COLUMN status ENUM('idle','driving','paused','offline') NOT NULL DEFAULT 'idle'");
    step(true, 'vehicles.status Enum aktualisiert');
} catch(Exception $e) {
    step(true, 'vehicles.status – bereits korrekt');
}

// ── Standard-Admin ────────────────────────────────────────────────────────
try {
    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if (!$count) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT IGNORE INTO users (username,password_hash,role) VALUES ('admin',?,'admin')")->execute([$hash]);
        step(true, 'Standard-Admin erstellt: admin / admin123');
    } else {
        step(true, 'Admin-Benutzer bereits vorhanden');
    }
} catch(Exception $e) { step(false, 'Admin: '.$e->getMessage()); }

step(true, '── Migration abgeschlossen ──');
?>
<pre><?php foreach($steps as [$ok,$m]): ?>
<span class="<?=$ok?'ok':'err'?>"><?=$ok?'✅':'❌'?> <?=htmlspecialchars($m)?></span>
<?php endforeach; ?></pre>

<?php if(!$hasErr): ?>
<p class="ok" style="margin-top:16px">✅ Migration erfolgreich!</p>
<p class="info" style="margin-top:8px">⚠️ Bitte diese Datei (migrate.php) jetzt löschen.</p>
<a class="btn" href="../login.php">→ Zum Login</a>
<?php else: ?>
<p class="err" style="margin-top:16px">❌ Fehler – siehe oben</p>
<?php endif; ?>
</body></html>
