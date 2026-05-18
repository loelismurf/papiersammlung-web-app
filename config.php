<?php
// ─── Datenbankzugangsdaten ─────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'dein_dbname');
define('DB_USER', 'dein_dbuser');
define('DB_PASS', 'dein_passwort');
define('DB_CHARSET', 'utf8mb4');

// ─── App-Einstellungen ─────────────────────────────────────────────────────
define('APP_NAME',       'Papiersammlung');
define('VEHICLE_TIMEOUT', 30);    // Sekunden bis Fahrzeug als offline gilt
define('POLL_INTERVAL',  2500);   // Millisekunden zwischen Browser-Polls
