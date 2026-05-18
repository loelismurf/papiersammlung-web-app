<?php
session_start();
require_once __DIR__ . '/db.php';
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $user = db_row("SELECT * FROM users WHERE username = ?", [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            header('Location: index.php'); exit;
        }
    }
    $error = 'Falscher Benutzername oder Passwort.';
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login – Papiersammlung</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0c0f;--panel:#0f1216;--border:#1e2530;--border2:#2a3340;
      --text:#c8d4e0;--muted:#4a5a6a;--accent:#00d4ff;--orange:#ff6b35;
      --font-ui:'Rajdhani',sans-serif;--font-mono:'JetBrains Mono',monospace}
body{min-height:100vh;background:var(--bg);color:var(--text);font-family:var(--font-ui);
     display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:6px;padding:40px;width:100%;max-width:380px}
.icon{text-align:center;font-size:44px;margin-bottom:12px}
.logo{font-size:26px;font-weight:700;letter-spacing:4px;text-transform:uppercase;color:var(--accent);text-align:center}
.logo span{color:var(--text)}
.sub{font-size:11px;color:var(--muted);letter-spacing:2px;text-transform:uppercase;text-align:center;
     font-family:var(--font-mono);margin-bottom:32px;margin-top:4px}
.field{margin-bottom:16px}
label{display:block;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:5px;font-family:var(--font-mono)}
input{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);
      padding:10px 14px;border-radius:4px;font-family:var(--font-ui);font-size:15px;font-weight:500;outline:none;transition:border-color .2s}
input:focus{border-color:var(--accent)}
.btn{width:100%;background:var(--accent);border:none;color:var(--bg);padding:12px;border-radius:4px;
     font-family:var(--font-ui);font-size:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase;
     cursor:pointer;margin-top:8px;transition:opacity .2s}
.btn:hover{opacity:.85}
.err{background:#1a0808;border:1px solid var(--orange);color:var(--orange);padding:10px 14px;
     border-radius:4px;font-size:13px;margin-bottom:16px;font-family:var(--font-mono)}
</style>
</head>
<body>
<div class="card">
  <div class="icon">♻️</div>
  <div class="logo">Papier<span>sammlung</span></div>
  <div class="sub">Pfadi Riko – Routenverfolgung</div>
  <?php if($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <form method="POST">
    <div class="field">
      <label>Benutzername</label>
      <input type="text" name="username" autocomplete="username" autofocus value="<?=htmlspecialchars($_POST['username']??'')?>">
    </div>
    <div class="field">
      <label>Passwort</label>
      <input type="password" name="password" autocomplete="current-password">
    </div>
    <button class="btn" type="submit">Anmelden</button>
  </form>
</div>
</body></html>
