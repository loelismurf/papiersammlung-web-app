<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>VAPID Keys – Papiersammlung</title>
<style>
  body{font-family:monospace;background:#0a0c0f;color:#c8d4e0;padding:40px;max-width:740px}
  h1{color:#00d4ff;letter-spacing:3px;margin-bottom:6px}
  .sub{font-size:11px;color:#4a5a6a;letter-spacing:2px;margin-bottom:28px}
  pre{background:#0f1216;border:1px solid #1e2530;padding:16px;border-radius:4px;line-height:2;word-break:break-all}
  .ok{color:#a8ff3e}.err{color:#ff6b35}.warn{color:#ffd700}
  a.btn{display:inline-block;margin-top:20px;background:#00d4ff;color:#0a0c0f;padding:12px 28px;font-family:monospace;font-size:14px;font-weight:bold;cursor:pointer;border-radius:4px;text-decoration:none}
</style>
</head>
<body>
<h1>🔑 VAPID KEY GENERATOR</h1>
<div class="sub">Einmalig ausführen – generiert Web-Push-Schlüssel und speichert in DB</div>
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$error = null;
$result = null;

// Nur wenn "generate=1" GET-Parameter gesetzt
if (isset($_GET['generate'])) {
    try {
        // Prüfen ob bereits Schlüssel vorhanden
        $existing = db_val("SELECT value FROM settings WHERE key_name='vapid_public_key'");
        if ($existing && !isset($_GET['force'])) {
            $result = ['status' => 'exists', 'public' => $existing];
        } else {
            // EC-Schlüsselpaar generieren (prime256v1 = P-256)
            $keyResource = openssl_pkey_new([
                'curve_name'       => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ]);
            if (!$keyResource) throw new Exception('openssl_pkey_new fehlgeschlagen: ' . openssl_error_string());

            openssl_pkey_export($keyResource, $privateKeyPem);
            $details = openssl_pkey_get_details($keyResource);

            if (!isset($details['ec']['x'], $details['ec']['y'])) {
                throw new Exception('EC-Koordinaten nicht verfügbar');
            }

            // Uncompressed point: 0x04 || x (32B) || y (32B)
            $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
            $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
            $publicKeyRaw = "\x04" . $x . $y;
            $publicKeyB64 = rtrim(strtr(base64_encode($publicKeyRaw), '+/', '-_'), '=');

            // In DB speichern
            db_run("CREATE TABLE IF NOT EXISTS settings (
                key_name VARCHAR(100) PRIMARY KEY,
                value    TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $subject = 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            db_run("INSERT INTO settings (key_name,value) VALUES ('vapid_public_key',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$publicKeyB64]);
            db_run("INSERT INTO settings (key_name,value) VALUES ('vapid_private_key_pem',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$privateKeyPem]);
            db_run("INSERT INTO settings (key_name,value) VALUES ('vapid_subject',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$subject]);

            $result = ['status' => 'generated', 'public' => $publicKeyB64, 'subject' => $subject];
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<?php if ($error): ?>
  <p class="err">❌ Fehler: <?=htmlspecialchars($error)?></p>
<?php elseif ($result && $result['status'] === 'exists'): ?>
  <p class="warn">⚠️ VAPID-Schlüssel bereits vorhanden – kein Überschreiben.</p>
  <pre>Public Key: <span class="ok"><?=htmlspecialchars($result['public'])?></span></pre>
  <p><a class="btn" style="background:var(--orange,#ff6b35)" href="?generate=1&force=1">🔄 Neu generieren (überschreibt alten Key!)</a></p>
<?php elseif ($result && $result['status'] === 'generated'): ?>
  <p class="ok">✅ VAPID-Schlüssel erfolgreich generiert und in DB gespeichert!</p>
  <pre>Public Key:  <span class="ok"><?=htmlspecialchars($result['public'])?></span>
Subject:     <?=htmlspecialchars($result['subject'])?></pre>
  <p class="warn">⚠️ Diese Datei nach Ausführung löschen!</p>
  <a class="btn" href="../index.php">→ Zur App</a>
<?php else: ?>
  <p>Drücke den Button um VAPID-Schlüssel für Web Push zu generieren.</p>
  <p style="color:#4a5a6a;font-size:11px;margin-top:8px">Voraussetzung: install.php muss vorher ausgeführt worden sein.</p>
  <a class="btn" href="?generate=1">🔑 VAPID Keys generieren</a>
<?php endif; ?>
</body>
</html>
