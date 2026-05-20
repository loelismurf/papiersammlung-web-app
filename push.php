<?php
// push.php – Web Push via VAPID (pure PHP, kein Composer nötig)
// Unterstützt: Chrome, Samsung Internet, Ecosia, Edge, Firefox, Safari 16.4+
//
// Ablauf:
//   1. tmp/generate_vapid.php einmal ausführen → speichert Keys in DB
//   2. Client abonniert Push (subscribeToPush in index.php)
//   3. Subscription wird per api.php?action=push_subscribe gespeichert
//   4. Wenn Fahrzeug offline geht, sendet state-Polling eine Push-Notification
//      → SW zeigt Notification → User öffnet App → GPS läuft weiter

require_once __DIR__ . '/db.php';

// ── VAPID Keys aus DB laden ───────────────────────────────────────────────────
function get_vapid_keys(): ?array {
    try {
        $pub  = db_val("SELECT value FROM settings WHERE key_name='vapid_public_key'");
        $pem  = db_val("SELECT value FROM settings WHERE key_name='vapid_private_key_pem'");
        $subj = db_val("SELECT value FROM settings WHERE key_name='vapid_subject'");
        if (!$pub || !$pem) return null;
        return ['public' => $pub, 'private_pem' => $pem, 'subject' => $subj ?: 'mailto:admin@localhost'];
    } catch (Exception $e) { return null; }
}

// ── DER-Signatur → IEEE P1363 (raw r||s, je 32 Byte) ─────────────────────────
function der_to_p1363(string $der): string {
    $pos = 2; // SEQUENCE header überspringen
    $pos++; $rLen = ord($der[$pos++]); // INTEGER r
    $r = substr($der, $pos, $rLen); $pos += $rLen;
    $pos++; $sLen = ord($der[$pos++]); // INTEGER s
    $s = substr($der, $pos, $sLen);
    // Leading-Null-Bytes entfernen, auf 32 Byte auffüllen
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return substr($r, -32) . substr($s, -32);
}

// ── VAPID JWT bauen ───────────────────────────────────────────────────────────
function build_vapid_jwt(string $audience, string $subject, string $privateKeyPem): string {
    $b64u = fn(string $d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $header  = $b64u('{"typ":"JWT","alg":"ES256"}');
    $payload = $b64u(json_encode(['aud'=>$audience,'exp'=>time()+3600,'sub'=>$subject]));
    $signing = "$header.$payload";
    $privKey = openssl_pkey_get_private($privateKeyPem);
    openssl_sign($signing, $derSig, $privKey, 'SHA256');
    return "$signing." . $b64u(der_to_p1363($derSig));
}

// ── Push-Notification senden (leere Payload = kein Content-Encryption nötig) ──
function send_push(string $endpoint, string $vapidPublic, string $vapidPem, string $vapidSubject): bool {
    if (!function_exists('curl_init') || !function_exists('openssl_sign')) return false;
    try {
        $parts    = parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'];
        $jwt      = build_vapid_jwt($audience, $vapidSubject, $vapidPem);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => '',
            CURLOPT_HTTPHEADER    => [
                "Authorization: vapid t={$jwt},k={$vapidPublic}",
                "TTL: 86400",
                "Urgency: normal",
                "Content-Length: 0",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => 6,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    } catch (Exception $e) { return false; }
}

// ── Sammel-Fahrzeuge die offline gegangen sind benachrichtigen ────────────────
// Wird aus api.php?action=state aufgerufen (piggyback auf Polling).
// Rate-Limit via DB: max. 1× pro 30s (verhindert Spam ohne Cron-Job).
function notify_offline_collectors(string $collection_id): void {
    if (!function_exists('curl_init')) return;
    try {
        $lastRun = (int)db_val("SELECT value FROM settings WHERE key_name='last_push_run'");
        $now = time();
        if ($now - $lastRun < 30) return;
        db_run("INSERT INTO settings (key_name,value) VALUES ('last_push_run',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$now]);

        $vapid = get_vapid_keys();
        if (!$vapid) return;

        // Fahrzeuge: collecting=1, seit 45-600s nicht gesehen
        $offline = db_rows(
            "SELECT v.user_id FROM vehicles v
             WHERE v.collecting=1 AND v.active_collection_id=?
             AND v.last_seen < DATE_SUB(NOW(), INTERVAL 45 SECOND)
             AND v.last_seen > DATE_SUB(NOW(), INTERVAL 600 SECOND)",
            [$collection_id]
        );
        foreach ($offline as $v) {
            $subs = db_rows(
                "SELECT endpoint FROM push_subscriptions WHERE user_id=?",
                [$v['user_id']]
            );
            foreach ($subs as $sub) {
                send_push($sub['endpoint'], $vapid['public'], $vapid['private_pem'], $vapid['subject']);
            }
        }
    } catch (Exception $e) {}
}
