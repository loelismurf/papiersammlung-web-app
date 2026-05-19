<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Migration 2</title>
<style>body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:600px}
h1{color:#00d4ff;margin-bottom:20px}.ok{color:#a8ff3e}.err{color:#ff6b35}
pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2}
a.btn{display:inline-block;margin-top:16px;background:#00d4ff;color:#0a0c0f;padding:10px 20px;font-weight:bold;border-radius:4px;text-decoration:none}
</style></head><body>
<h1>🔧 Migration 2 – Segment-Tracking</h1>
<?php
define('DB_HOST', '10.35.47.103:3306');
define('DB_NAME', 'k82299_papiersammlung');
define('DB_USER', 'k82299_papiersammlung');
define('DB_PASS', '7NpFrRhycO2cb7K3Cfkb');
$steps=[]; $ok=true;
try {
    $pdo=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $steps[]=['ok','Datenbankverbindung OK'];

    // Spalte driven_segments hinzufügen
    $exists=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collection_routes' AND COLUMN_NAME='driven_segments'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE collection_routes ADD COLUMN driven_segments LONGTEXT DEFAULT NULL COMMENT 'JSON boolean array per segment'");
        $steps[]=['ok','Spalte driven_segments hinzugefügt'];
    } else {
        $steps[]=['ok','Spalte driven_segments bereits vorhanden'];
    }

    // Bestehende aktive Routen: driven_segments aus progress ableiten
    $routes=$pdo->query("SELECT id,coordinates,progress FROM collection_routes WHERE status IN ('active','paused') AND driven_segments IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($routes as $r) {
        $coords=json_decode($r['coordinates'],true);
        $n=count($coords)-1;
        if ($n<=0) continue;
        $done=(int)round($r['progress']/100*$n);
        $driven=array_merge(array_fill(0,$done,true),array_fill($done,$n-$done,false));
        $pdo->prepare("UPDATE collection_routes SET driven_segments=? WHERE id=?")->execute([json_encode($driven),$r['id']]);
    }
    if ($routes) $steps[]=['ok',count($routes).' aktive Routen migriert'];

    $steps[]=['ok','─── Migration abgeschlossen ───'];
} catch(Exception $e){ $steps[]=['err',$e->getMessage()]; $ok=false; }
?>
<pre><?php foreach($steps as [$t,$m]):?><span class="<?=$t?>"><?=$t==='ok'?'✅':'❌'?> <?=htmlspecialchars($m)?></span>
<?php endforeach;?></pre>
<?php if($ok):?>
<p class="ok">✅ Fertig! Diese Datei danach löschen.</p>
<a class="btn" href="login.php">→ Zur App</a>
<?php else:?><p class="err">❌ Fehler aufgetreten</p><?php endif;?>
</body></html>
