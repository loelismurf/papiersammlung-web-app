<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>FleetTrack – Installation</title>
<style>
  body { font-family: monospace; background: #0a0c0f; color: #c8d4e0; padding: 40px; max-width: 700px; }
  h1 { color: #00d4ff; letter-spacing: 3px; }
  .ok  { color: #a8ff3e; } .err { color: #ff6b35; } .info { color: #ffd700; }
  pre  { background: #0f1216; border: 1px solid #1e2530; padding: 16px; border-radius: 4px; }
  .btn { background: #00d4ff; color: #0a0c0f; border: none; padding: 12px 24px;
         font-family: monospace; font-size: 14px; cursor: pointer; border-radius: 4px;
         text-decoration: none; display: inline-block; margin-top: 20px; font-weight: bold; }
</style>
</head>
<body>
<h1>⚙ FLEETTRACK INSTALLATION</h1>
<?php
require_once __DIR__ . '/db.php';

$steps = [];
$ok = true;

try {
    $pdo = db();
    $steps[] = ['ok', 'Datenbankverbindung erfolgreich'];

    // ── Tabellen erstellen ────────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS routes (
            id           VARCHAR(36)  PRIMARY KEY,
            name         VARCHAR(100) NOT NULL,
            color        VARCHAR(20)  NOT NULL DEFAULT '#00d4ff',
            coordinates  LONGTEXT     NOT NULL COMMENT 'JSON [[lat,lng],...]',
            status       ENUM('pending','active','completed','paused') NOT NULL DEFAULT 'pending',
            assigned_token VARCHAR(64) DEFAULT NULL,
            progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            visible      TINYINT(1)   NOT NULL DEFAULT 1,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $steps[] = ['ok', 'Tabelle `routes` erstellt'];

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicles (
            token        VARCHAR(64)  PRIMARY KEY,
            name         VARCHAR(100) NOT NULL,
            lat          DOUBLE       DEFAULT NULL,
            lng          DOUBLE       DEFAULT NULL,
            status       ENUM('idle','driving','paused','offline') NOT NULL DEFAULT 'idle',
            active_route VARCHAR(36)  DEFAULT NULL,
            last_seen    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $steps[] = ['ok', 'Tabelle `vehicles` erstellt'];

    // ── Beispiel-Routen (Zürich) ──────────────────────────────────────────
    $existing = $pdo->query("SELECT COUNT(*) FROM routes")->fetchColumn();
    if ($existing == 0) {
        $sampleRoutes = [
            [
                'id'    => 'route-1',
                'name'  => 'Route Nord',
                'color' => '#00d4ff',
                'coords'=> [[47.3769,8.5417],[47.3850,8.5350],[47.3920,8.5280],[47.3980,8.5220],[47.4050,8.5180],[47.4100,8.5120]],
            ],
            [
                'id'    => 'route-2',
                'name'  => 'Route Ost',
                'color' => '#ff6b35',
                'coords'=> [[47.3769,8.5417],[47.3720,8.5500],[47.3680,8.5600],[47.3640,8.5700],[47.3600,8.5800],[47.3570,8.5900]],
            ],
            [
                'id'    => 'route-3',
                'name'  => 'Route Süd',
                'color' => '#a8ff3e',
                'coords'=> [[47.3769,8.5417],[47.3700,8.5400],[47.3640,8.5380],[47.3580,8.5350],[47.3520,8.5320],[47.3460,8.5280]],
            ],
            [
                'id'    => 'route-4',
                'name'  => 'Route West',
                'color' => '#ff3e9d',
                'coords'=> [[47.3769,8.5417],[47.3780,8.5300],[47.3790,8.5180],[47.3800,8.5060],[47.3810,8.4950],[47.3820,8.4840]],
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO routes (id, name, color, coordinates)
            VALUES (:id, :name, :color, :coords)
        ");
        foreach ($sampleRoutes as $r) {
            $stmt->execute([
                ':id'     => $r['id'],
                ':name'   => $r['name'],
                ':color'  => $r['color'],
                ':coords' => json_encode($r['coords']),
            ]);
        }
        $steps[] = ['ok', count($sampleRoutes) . ' Beispiel-Routen (Zürich) eingefügt'];
    } else {
        $steps[] = ['info', 'Routen bereits vorhanden – übersprungen'];
    }

} catch (Exception $e) {
    $steps[] = ['err', 'FEHLER: ' . $e->getMessage()];
    $ok = false;
}
?>

<pre><?php foreach ($steps as [$type, $msg]): ?>
<span class="<?= $type ?>"><?= $type === 'ok' ? '✅' : ($type === 'err' ? '❌' : 'ℹ️ ') ?> <?= htmlspecialchars($msg) ?></span>
<?php endforeach; ?></pre>

<?php if ($ok): ?>
<p class="ok">✅ Installation erfolgreich abgeschlossen!</p>
<p class="info">⚠️ Bitte lösche oder schütze diese Datei nach der Installation:<br>
<code>rm install.php</code> oder per FTP löschen</p>
<a class="btn" href="index.html">→ App öffnen</a>
<?php else: ?>
<p class="err">❌ Installation fehlgeschlagen. Prüfe die Zugangsdaten in config.php</p>
<?php endif; ?>

</body>
</html>
