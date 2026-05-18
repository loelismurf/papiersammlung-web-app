<?php
// ─── Datenbankzugangsdaten ─────────────────────────────────────────────────
define('DB_HOST', '10.35.47.103:3306');       // meistens localhost
define('DB_NAME', 'k82299_papiersammlung');     // Datenbankname
define('DB_USER', 'k82299_papiersammlung');     // Datenbankbenutzer
define('DB_PASS', '7NpFrRhycO2cb7K3Cfkb');   // Datenbankpasswort
define('DB_CHARSET', 'utf8mb4');

// ─── App-Einstellungen ─────────────────────────────────────────────────────
define('APP_NAME',       'Papiersammlung');
define('VEHICLE_TIMEOUT', 30);    // Sekunden bis Fahrzeug als offline gilt
define('POLL_INTERVAL',  2500);   // Millisekunden zwischen Browser-Polls
