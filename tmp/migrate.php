<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Migration – Papiersammlung</title>
<style>
  body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:700px}
  h1{color:#00d4ff;letter-spacing:3px;margin-bottom:24px}
  .ok{color:#a8ff3e}.err{color:#ff6b35}.info{color:#ffd700}
  pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2}
  .btn{display:inline-block;margin-top:20px;background:#00d4ff;color:#0a0c0f;border:none;
       padding:12px 24px;font-family:monospace;font-size:14px;font-weight:bold;
       cursor:pointer;border-radius:4px;text-decoration:none}
</style></head><body>
<h1>🔧 DATENBANK MIGRATION</h1>
<?php
require_once __DIR__ . '/db.php';

$steps = [];

// Helper: fügt Spalte hinzu wenn sie noch nicht existiert
function addColumnIfMissing(string $table, string $column, string $definition, array &$steps): void {
    try {
        $exists = db_val(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if (!$exists) {
            db()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            $steps[] = ['ok', "Spalte `{$table}.{$column}` hinzugefügt"];
        } else {
            $steps[] = ['info', "Spalte `{$table}.{$column}` bereits vorhanden"];
        }
    } catch (Exception $e) {
        $steps[] = ['err', "Fehler bei `{$table}.{$column}`: " . $e->getMessage()];
    }
}

// Helper: erstellt Tabelle wenn sie nicht existiert
function createTableIfMissing(string $table, string $sql, array &$steps): void {
    try {
        db()->exec($sql);
        $steps[] = ['ok', "Tabelle `{$table}` geprüft / erstellt"];
    } catch (Exception $e) {
        $steps[] = ['err', "Fehler bei Tabelle `{$table}`: " . $e->getMessage()];
    }
}

try {
    db(); // Verbindungstest
    $steps[] = ['ok', 'Datenbankverbindung OK'];

    // ── vehicles: fehlende Spalten hinzufügen ──────────────────────────────
    addColumnIfMissing('vehicles', 'user_id',              'INT DEFAULT NULL',          $steps);
    addColumnIfMissing('vehicles', 'active_collection_id', 'VARCHAR(32) DEFAULT NULL',  $steps);
    addColumnIfMissing('vehicles', 'active_route_id',      'VARCHAR(32) DEFAULT NULL',  $steps);

    // Status-Spalte: Enum erweitern (falls alte Version nur idle/driving/paused hatte)
    try {
        db()->exec("ALTER TABLE vehicles MODIFY COLUMN status ENUM('idle','driving','paused','offline') NOT NULL DEFAULT 'idle'");
        $steps[] = ['ok', 'vehicles.status Enum aktualisiert'];
    } catch (Exception $e) {
        $steps[] = ['info', 'vehicles.status – keine Änderung nötig'];
    }

    // ── Neue Tabellen erstellen falls nicht vorhanden ───────────────────────
    createTableIfMissing('users', "CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50)  UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $steps);

    createTableIfMissing('route_templates', "CREATE TABLE IF NOT EXISTS route_templates (
        id          VARCHAR(32)  PRIMARY KEY,
        name        VARCHAR(100) NOT NULL,
        color       VARCHAR(20)  NOT NULL DEFAULT '#00d4ff',
        coordinates LONGTEXT     NOT NULL,
        description TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $steps);

    createTableIfMissing('collections', "CREATE TABLE IF NOT EXISTS collections (
        id               VARCHAR(32)  PRIMARY KEY,
        name             VARCHAR(100) NOT NULL,
        collection_date  DATE         NOT NULL,
        status           ENUM('draft','active','completed') NOT NULL DEFAULT 'draft',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $steps);

    createTableIfMissing('collection_routes', "CREATE TABLE IF NOT EXISTS collection_routes (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $steps);

    // ── Admin-User erstellen falls noch keiner vorhanden ───────────────────
    $hasAdmin = db_val("SELECT COUNT(*) FROM users WHERE role='admin'");
    if (!$hasAdmin) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        db_run("INSERT IGNORE INTO users (username,password_hash,role) VALUES ('admin',?,'admin')", [$hash]);
        $steps[] = ['ok', 'Standard-Admin erstellt: admin / admin123'];
    } else {
        $steps[] = ['info', 'Admin-User bereits vorhanden'];
    }

    $steps[] = ['ok', '── Migration abgeschlossen ──'];

} catch (Exception $e) {
    $steps[] = ['err', 'Verbindungsfehler: ' . $e->getMessage()];
}
?>
<pre><?php foreach($steps as [$t,$m]): ?>
<span class="<?=$t?>"><?=$t==='ok'?'✅':($t==='err'?'❌':'ℹ️')?> <?=htmlspecialchars($m)?></span>
<?php endforeach; ?></pre>

<?php
$hasError = !empty(array_filter($steps, fn($s) => $s[0] === 'err'));
if (!$hasError): ?>
<p style="color:#a8ff3e;margin-top:16px">✅ Migration erfolgreich!</p>
<p style="color:#ffd700;margin-top:8px">⚠️ Bitte <strong>migrate.php danach löschen</strong>.</p>
<a class="btn" href="login.php">→ Zum Login</a>
<?php else: ?>
<p style="color:#ff6b35;margin-top:16px">❌ Fehler aufgetreten – prüfe config.php</p>
<?php endif; ?>
</body></html>
