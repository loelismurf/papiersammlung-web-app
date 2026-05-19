<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Diagnose & Fix – Segment-Tracking</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<style>
body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:720px}
h1{color:#00d4ff;margin-bottom:6px}
.sub{font-size:11px;color:#4a5a6a;letter-spacing:2px;margin-bottom:24px}
.ok{color:#a8ff3e}.err{color:#ff6b35}.info{color:#ffd700}.warn{color:#ff6b35}
pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2;margin-bottom:16px;overflow-x:auto}
a.btn,button.btn{display:inline-block;margin-top:16px;background:#00d4ff;color:#0a0c0f;
      padding:10px 20px;font-weight:bold;border-radius:4px;text-decoration:none;
      border:none;font-family:monospace;font-size:14px;cursor:pointer;margin-right:8px}
a.btn.g,button.btn.g{background:#a8ff3e}
a.btn.d,button.btn.d{background:#ff6b35}
h2{color:#00d4ff;font-size:13px;letter-spacing:2px;text-transform:uppercase;margin:20px 0 8px}
.box{background:#0f1216;border:1px solid #2a3340;border-left:3px solid #ffd700;
     padding:12px 16px;border-radius:4px;margin-bottom:12px;line-height:1.8}
</style>
</head>
<body>
<h1>🔧 Diagnose & Fix – Segment-Tracking</h1>
<div class="sub">PAPIERSAMMLUNG · MIGRATION 3</div>

<?php
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    echo '<pre><span class="err">❌ config.php nicht gefunden. migrate3.php muss im /tmp/ Unterordner liegen.</span></pre>';
    exit;
}
require_once $configPath;

$steps = []; $ok = true; $fixes = [];

function step(bool $s, string $m): void { global $steps, $ok; $steps[] = [$s, $m]; if (!$s) $ok = false; }
function info(string $m): void { global $steps; $steps[] = ['info', $m]; }
function warn(string $m): void { global $steps; $steps[] = ['warn', $m]; }

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    step(true, 'DB-Verbindung: ' . DB_HOST . ' / ' . DB_NAME);

    // ── 1. driven_segments Spalte ─────────────────────────────────────────
    $ex = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME    = 'collection_routes'
        AND COLUMN_NAME   = 'driven_segments'")->fetchColumn();

    if (!$ex) {
        $pdo->exec("ALTER TABLE collection_routes
                    ADD COLUMN driven_segments LONGTEXT DEFAULT NULL
                    COMMENT 'JSON bool[] pro Segment'");
        step(true, '✔ Spalte driven_segments hinzugefügt (war fehlend → HAUPTFEHLER!)');
        $fixes[] = 'driven_segments Spalte erstellt';
    } else {
        step(true, 'Spalte driven_segments bereits vorhanden ✓');
    }

    // ── 2. Aktuelle Routen & Fahrzeuge analysieren ────────────────────────
    $activeRoutes = $pdo->query(
        "SELECT cr.id, cr.name, cr.status, cr.progress,
                cr.driven_segments, cr.assigned_token,
                LENGTH(cr.coordinates) as coord_len
         FROM collection_routes cr
         WHERE cr.status IN ('active','paused')
         ORDER BY cr.status"
    )->fetchAll();

    info('── Aktive/Pausierte Routen: ' . count($activeRoutes));
    foreach ($activeRoutes as $r) {
        $ds = $r['driven_segments'] ? json_decode($r['driven_segments'], true) : null;
        $driven = $ds ? count(array_filter($ds)) : 0;
        $total  = $ds ? count($ds) : '?';
        $seg = $ds ? "$driven/$total Segmente abgefahren" : "driven_segments = NULL";
        info("  Route '{$r['name']}' [{$r['status']}] · {$r['progress']}% · $seg");
    }

    $activeVehicles = $pdo->query(
        "SELECT v.token, v.name, v.status, v.lat, v.lng, v.active_route_id, v.last_seen
         FROM vehicles v
         WHERE v.status != 'offline'
         ORDER BY v.last_seen DESC"
    )->fetchAll();

    info('── Aktive Fahrzeuge: ' . count($activeVehicles));
    foreach ($activeVehicles as $v) {
        $pos = ($v['lat'] && $v['lng']) ? round($v['lat'],5).','.round($v['lng'],5) : 'keine GPS';
        $route = $v['active_route_id'] ? 'Route: '.substr($v['active_route_id'],0,8) : 'keine Route';
        info("  {$v['name']} [{$v['status']}] · $pos · $route · Zuletzt: {$v['last_seen']}");

        if ($v['status'] === 'driving' && !$v['active_route_id']) {
            warn("  ⚠ Fahrzeug '{$v['name']}' status=driving aber active_route_id=NULL!");
        }
    }

    // ── 3. Reset-Option ───────────────────────────────────────────────────
    $doReset = ($_GET['reset'] ?? '') === '1';
    if ($doReset) {
        $n = $pdo->exec("UPDATE collection_routes
                         SET driven_segments = NULL, progress = 0
                         WHERE status IN ('active','paused','pending')");
        step(true, "Segment-Daten zurückgesetzt ($n Routen) → Sauberer Neustart");
        $fixes[] = 'Segment-Daten zurückgesetzt';
    }

    // ── 4. Koordinaten-Format prüfen ──────────────────────────────────────
    $sampleRoute = $pdo->query("SELECT id, name, coordinates FROM collection_routes LIMIT 1")->fetch();
    if ($sampleRoute) {
        $coords = json_decode($sampleRoute['coordinates'], true);
        if ($coords && isset($coords[0])) {
            $first = $coords[0];
            if (is_array($first) && count($first) === 2) {
                $lat = $first[0]; $lng = $first[1];
                // Schweiz: lat ~46-48, lng ~5-11
                if ($lat >= 45 && $lat <= 49 && $lng >= 5 && $lng <= 12) {
                    step(true, "Koordinaten-Format: [lat,lng] korrekt (Probe: $lat,$lng)");
                } elseif ($lng >= 45 && $lng <= 49 && $lat >= 5 && $lat <= 12) {
                    step(false, "Koordinaten-Format: FALSCH! Gespeichert als [lng,lat], muss [lat,lng] sein! Probe: $lat,$lng");
                } else {
                    warn("Koordinaten ausserhalb Schweiz: $lat,$lng – prüfen ob korrekt");
                }
            }
        }
    }

    step(true, '── Diagnose abgeschlossen ──');

} catch (PDOException $e) {
    step(false, 'DB-Fehler: ' . $e->getMessage());
} catch (Exception $e) {
    step(false, 'Fehler: ' . $e->getMessage());
}
?>

<h2>Diagnose-Protokoll</h2>
<pre><?php foreach($steps as [$t, $m]): ?>
<?php if($t==='info'): ?><span style="color:#7a8a9a"><?= htmlspecialchars($m) ?></span>
<?php elseif($t==='warn'): ?><span class="warn">⚠ <?= htmlspecialchars($m) ?></span>
<?php else: ?><span class="<?= $t ? 'ok' : 'err' ?>"><?= $t ? '✅' : '❌' ?> <?= htmlspecialchars($m) ?></span>
<?php endif; endforeach; ?></pre>

<?php if (!empty($fixes)): ?>
<div class="box">
  <strong style="color:#a8ff3e">✅ Folgende Fixes wurden angewendet:</strong><br>
  <?= implode('<br>', array_map('htmlspecialchars', $fixes)) ?>
</div>
<?php endif; ?>

<div class="box">
  <strong style="color:#ffd700">🐛 Debug-Modus testen:</strong><br>
  Öffne die Karte, starte eine Route, dann rufe auf:<br>
  <code style="color:#00d4ff">api.php?action=vehicle_position&debug=1</code><br>
  (POST mit token, lat, lng) um zu sehen was der Server erkennt.
</div>

<?php if ($ok): ?>
<a class="btn g" href="../login.php">→ Zur App</a>
<?php if (!isset($_GET['reset'])): ?>
<a class="btn d" href="?reset=1" onclick="return confirm('Alle Segment-Daten zurücksetzen?')">↺ Segments zurücksetzen</a>
<?php endif; ?>
<?php else: ?>
<p class="err" style="margin-top:16px">❌ Fehler aufgetreten – siehe oben.</p>
<?php endif; ?>

<p class="info" style="margin-top:20px;font-size:11px">
  ⚠️ Diese Datei nach der Verwendung löschen (/tmp/migrate3.php)
</p>
</body>
</html>
