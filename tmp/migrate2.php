<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Migration 2</title>
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<style>
body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:600px}
h1{color:#00d4ff;margin-bottom:20px}
.ok{color:#a8ff3e}.err{color:#ff6b35}
pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2}
a.btn{display:inline-block;margin-top:16px;background:#00d4ff;color:#0a0c0f;
      padding:10px 20px;font-weight:bold;border-radius:4px;text-decoration:none}
</style>
</head>
<body>
<h1>🔧 Migration 2 – Segment-Tracking</h1>
<?php
require_once __DIR__ . '/config.php';

$steps = []; $ok = true;

function step(bool $s, string $m): void {
    global $steps, $ok;
    $steps[] = [$s, $m];
    if (!$s) $ok = false;
}

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    step(true, 'DB-Verbindung OK (' . DB_NAME . ')');

    // driven_segments Spalte hinzufügen falls fehlend
    $ex = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='collection_routes'
        AND COLUMN_NAME='driven_segments'")->fetchColumn();
    if (!$ex) {
        $pdo->exec("ALTER TABLE collection_routes
                    ADD COLUMN driven_segments LONGTEXT DEFAULT NULL
                    COMMENT 'JSON bool[] pro Segment'");
        step(true, 'Spalte driven_segments hinzugefügt');
    } else {
        step(true, 'driven_segments bereits vorhanden');
    }

    // Segment-Daten zurücksetzen für sauberen Neustart
    $n = $pdo->exec("UPDATE collection_routes
                     SET driven_segments=NULL, progress=0
                     WHERE status IN ('active','paused','pending')");
    step(true, "Segment-Daten zurückgesetzt ($n Routen)");

    step(true, '── Migration abgeschlossen ──');

} catch (Exception $e) {
    step(false, 'Fehler: ' . $e->getMessage());
}
?>
<pre><?php foreach($steps as [$t,$m]): ?>
<span class="<?= $t?'ok':'err' ?>"><?= $t?'✅':'❌' ?> <?= htmlspecialchars($m) ?></span>
<?php endforeach; ?></pre>

<?php if($ok): ?>
<p class="ok" style="margin-top:16px">✅ Fertig!</p>
<p style="color:#ffd700;margin-top:8px">⚠️ Diese Datei (migrate2.php) danach löschen.</p>
<a class="btn" href="login.php">→ Zur App</a>
<?php else: ?>
<p class="err" style="margin-top:16px">❌ Fehler – config.php prüfen.</p>
<?php endif; ?>
</body>
</html>
