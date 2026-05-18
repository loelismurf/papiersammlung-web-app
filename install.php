<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Installation – Papiersammlung</title>
<style>
  body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:700px}
  h1{color:#00d4ff;letter-spacing:3px;margin-bottom:24px}
  .ok{color:#a8ff3e}.err{color:#ff6b35}.info{color:#ffd700}
  pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2}
  .btn{display:inline-block;margin-top:20px;background:#00d4ff;color:#0a0c0f;border:none;
       padding:12px 24px;font-family:monospace;font-size:14px;font-weight:bold;
       cursor:pointer;border-radius:4px;text-decoration:none}
  .box{background:#0f1216;border:1px solid #2a3340;border-left:3px solid #ffd700;
       padding:16px;margin-top:20px;border-radius:4px}
</style></head><body>
<h1>⚙ PAPIERSAMMLUNG INSTALLATION</h1>
<?php
require_once __DIR__ . '/db.php';
$steps = []; $ok = true;
try {
    $pdo = db();
    $steps[] = ['ok','Datenbankverbindung erfolgreich'];

    // Users
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50)  UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = ['ok','Tabelle `users` erstellt'];

    // Route templates
    $pdo->exec("CREATE TABLE IF NOT EXISTS route_templates (
        id          VARCHAR(32)  PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        color       VARCHAR(20)  NOT NULL DEFAULT '#00d4ff',
        coordinates LONGTEXT     NOT NULL,
        description TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = ['ok','Tabelle `route_templates` erstellt'];

    // Collections (Papiersammlungen)
    $pdo->exec("CREATE TABLE IF NOT EXISTS collections (
        id               VARCHAR(32)  PRIMARY KEY,
        name             VARCHAR(100) NOT NULL,
        collection_date  DATE         NOT NULL,
        status           ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = ['ok','Tabelle `collections` erstellt'];

    // Collection routes
    $pdo->exec("CREATE TABLE IF NOT EXISTS collection_routes (
        id              VARCHAR(32)  PRIMARY KEY,
        collection_id   VARCHAR(32)  NOT NULL,
        template_id     VARCHAR(32)  DEFAULT NULL,
        name            VARCHAR(100) NOT NULL,
        color           VARCHAR(20)  NOT NULL DEFAULT '#00d4ff',
        coordinates     LONGTEXT     NOT NULL,
        status          ENUM('pending','active','completed','paused') NOT NULL DEFAULT 'pending',
        assigned_token  VARCHAR(64)  DEFAULT NULL,
        progress        TINYINT UNSIGNED NOT NULL DEFAULT 0,
        visible         TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order      INT          NOT NULL DEFAULT 0,
        INDEX idx_collection (collection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = ['ok','Tabelle `collection_routes` erstellt'];

    // Vehicles
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
        token                VARCHAR(64)  PRIMARY KEY,
        name                 VARCHAR(100) NOT NULL,
        user_id              INT          DEFAULT NULL,
        lat                  DOUBLE       DEFAULT NULL,
        lng                  DOUBLE       DEFAULT NULL,
        status               ENUM('idle','driving','paused','offline') NOT NULL DEFAULT 'idle',
        active_collection_id VARCHAR(32)  DEFAULT NULL,
        active_route_id      VARCHAR(32)  DEFAULT NULL,
        last_seen            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $steps[] = ['ok','Tabelle `vehicles` erstellt'];

    // Default admin user
    $exists = db_val("SELECT COUNT(*) FROM users WHERE username='admin'");
    if (!$exists) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        db_run("INSERT INTO users (username,password_hash,role) VALUES ('admin',?,'admin')", [$hash]);
        $steps[] = ['ok','Standard-Admin erstellt: admin / admin123'];
    } else {
        $steps[] = ['info','Admin-User bereits vorhanden'];
    }

} catch (Exception $e) {
    $steps[] = ['err','FEHLER: '.$e->getMessage()]; $ok = false;
}
?>
<pre><?php foreach($steps as [$t,$m]): ?>
<span class="<?=$t?>"><?=$t==='ok'?'✅':($t==='err'?'❌':'ℹ️')?> <?=htmlspecialchars($m)?></span>
<?php endforeach; ?></pre>

<?php if($ok): ?>
<div class="box">
  <strong style="color:#ffd700">⚠️ Zugangsdaten für ersten Login:</strong><br><br>
  Benutzername: <strong style="color:#00d4ff">admin</strong><br>
  Passwort: <strong style="color:#00d4ff">admin123</strong><br><br>
  <span style="color:#ff6b35">Bitte sofort nach dem Login im Admin-Panel ändern!</span>
</div>
<p style="margin-top:16px;color:#a8ff3e">✅ Installation erfolgreich!</p>
<p style="color:#ffd700;margin-top:8px">⚠️ Bitte <strong>install.php</strong> anschliessend löschen.</p>
<a class="btn" href="login.php">→ Zum Login</a>
<?php else: ?>
<p style="color:#ff6b35;margin-top:16px">❌ Fehler – prüfe config.php</p>
<?php endif; ?>
</body></html>
