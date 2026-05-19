<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Migration 2</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<style>
body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:620px}
h1{color:#00d4ff;margin-bottom:20px}
.ok{color:#a8ff3e}.err{color:#ff6b35}.info{color:#ffd700}
pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2}
a.btn{display:inline-block;margin-top:16px;background:#00d4ff;color:#0a0c0f;
      padding:10px 20px;font-weight:bold;border-radius:4px;text-decoration:none}
</style>
</head>
<body>
<h1>🔧 Migration 2 – Segment-Tracking</h1>
<?php
// config.php liegt eine Ebene über /tmp/ im Root-Verzeichnis
$configPath = __DIR__ . '/../config.php';

if (!file_exists($configPath)) {
    echo '<pre><span class="err">❌ config.php nicht gefunden unter: ' . htmlspecialchars($configPath) . '</span></pre>';
    echo '<p class="err">Bitte sicherstellen dass migrate2.php im /tmp/ Unterordner liegt.</p>';
    exit;
}

require_once $configPath;

$steps = []; $ok = true;

function step(bool $s, string $m): void {
    global $steps, $ok;
    $steps[] = [$s, $m];
    if (!$s) $ok = false;
}

step(true, 'config.php geladen (' . DB_HOST . ' / ' . DB_NAME . ')');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    step(true, 'DB-Verbindung OK');

    // driven_segments Spalte
    $ex = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME    = 'collection_routes'
        AND COLUMN_NAME   = 'driven_segments'")->fetchColumn();

    if (!$ex) {
        $pdo->exec("ALTER TABLE collection_routes
                    ADD COLUMN driven_segments LONGTEXT DEFAULT NULL
                    COMMENT 'JSON bool[] pro Segment'");
        step(true, 'Spalte driven_segments hinzugefügt');
    } else {
        step(true, 'Spalte driven_segments bereits vorhanden');
    }

    // Segment-Daten zurücksetzen für sauberen Neustart
    $n = $pdo->exec("UPDATE collection_routes
                     SET driven_segments = NULL, progress = 0
                     WHERE status IN ('active','paused','pending')");
    step(true, "Segment-Daten zurückgesetzt ($n Routen)");

    step(true, '── Migration erfolgreich abgeschlossen ──');

} catch (PDOException $e) {
    step(false, 'DB-Fehler: ' . $e->getMessage());
} catch (Exception $e) {
    step(false, 'Fehler: ' . $e->getMessage());
}
?>

<pre><?php foreach($steps as [$t, $m]): ?>
<span class="<?= $t ? 'ok' : 'err' ?>"><?= $t ? '✅' : '❌' ?> <?= htmlspecialchars($m) ?></span>
<?php endforeach; ?></pre>

<?php if ($ok): ?>
  <p class="ok" style="margin-top:16px">✅ Migration erfolgreich!</p>
  <p class="info" style="margin-top:8px">⚠️ Bitte den ganzen /tmp/ Ordner danach löschen.</p>
  <a class="btn" href="../login.php">→ Zur App</a>
<?php else: ?>
  <p class="err" style="margin-top:16px">❌ Fehler aufgetreten – siehe oben.</p>
<?php endif; ?>
</body>
</html>
