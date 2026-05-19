<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Migration 2</title>
<style>body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:600px}
h1{color:#00d4ff;margin-bottom:20px}.ok{color:#a8ff3e}.err{color:#ff6b35}
pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2.2}
a.btn{display:inline-block;margin-top:16px;background:#00d4ff;color:#0a0c0f;padding:10px 20px;font-weight:bold;border-radius:4px;text-decoration:none}
</style></head><body><h1>🔧 Migration 2 – Segment-Tracking</h1>
<?php
define('DB_HOST', '10.35.47.103:3306');
define('DB_NAME', 'k82299_papiersammlung');
define('DB_USER', 'k82299_papiersammlung');
define('DB_PASS', '7NpFrRhycO2cb7K3Cfkb');
$steps=[];$ok=true;
try{
  $pdo=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $steps[]=['ok','DB-Verbindung OK'];
  // driven_segments Spalte
  $ex=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collection_routes' AND COLUMN_NAME='driven_segments'")->fetchColumn();
  if(!$ex){$pdo->exec("ALTER TABLE collection_routes ADD COLUMN driven_segments LONGTEXT DEFAULT NULL");$steps[]=['ok','Spalte driven_segments hinzugefügt'];}
  else{$steps[]=['ok','driven_segments bereits vorhanden'];}
  // Alle driven_segments zurücksetzen (sauberer Neustart)
  $pdo->exec("UPDATE collection_routes SET driven_segments=NULL, progress=0 WHERE status IN ('active','paused','pending')");
  $steps[]=['ok','Segment-Daten zurückgesetzt (sauberer Neustart)'];
  $steps[]=['ok','── Fertig ──'];
}catch(Exception $e){$steps[]=['err',$e->getMessage()];$ok=false;}
?><pre><?php foreach($steps as[$t,$m]):?><span class="<?=$t?>"><?=$t==='ok'?'✅':'❌'?> <?=htmlspecialchars($m)?></span>
<?php endforeach;?></pre>
<?php if($ok):?><p class="ok">✅ Fertig! Datei danach löschen.</p><a class="btn" href="login.php">→ App</a><?php else:?><p class="err">❌ Fehler</p><?php endif;?>
</body></html>
