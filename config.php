<?php
// ─── Datenbankzugangsdaten ─────────────────────────────────────────────────
// Diese Werte aus dem Netcup Kundencenter / Plesk eintragen

define('DB_HOST', '10.35.47.103:3306');       // meistens localhost
define('DB_NAME', 'k82299_papiersammlung');     // Datenbankname
define('DB_USER', 'k82299_papiersammlung');     // Datenbankbenutzer
define('DB_PASS', '7NpFrRhycO2cb7K3Cfkb');   // Datenbankpasswort
define('DB_CHARSET', 'utf8mb4');

// ─── Einstellungen ─────────────────────────────────────────────────────────
define('VEHICLE_TIMEOUT', 30);        // Sekunden bis Fahrzeug als offline gilt
define('POLL_INTERVAL', 1000);        // Millisekunden zwischen Browser-Polls
