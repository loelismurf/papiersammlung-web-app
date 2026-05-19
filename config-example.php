<?php
// ─── Datenbankzugangsdaten ─────────────────────────────────────────────────
define('DB_HOST', 'IP:PORT');
define('DB_NAME', 'DB_NAME');
define('DB_USER', 'DB_USER');
define('DB_PASS', 'DB_PASS');
define('DB_CHARSET', 'utf8mb4');

// ─── App-Einstellungen ─────────────────────────────────────────────────────
define('APP_NAME',        'Papiersammlung');
define('VEHICLE_TIMEOUT', 60);    // Sekunden bis Fahrzeug als offline gilt
define('POLL_INTERVAL',   2000);  // Millisekunden zwischen Browser-Polls
