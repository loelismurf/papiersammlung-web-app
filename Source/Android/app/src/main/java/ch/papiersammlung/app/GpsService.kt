package ch.papiersammlung.app

import android.Manifest
import android.app.Notification
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Bundle
import android.os.IBinder
import android.os.PowerManager
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.*

/**
 * Foreground-Service für GPS-Tracking im Hintergrund.
 * Verwendet Android's eingebauten LocationManager (KEIN Google Play Services nötig).
 *
 * Läuft auch wenn App minimiert oder Bildschirm gesperrt ist.
 * Offline-Puffer: GPS-Punkte werden bei fehlendem Netz lokal gespeichert
 * und automatisch nachgesendet sobald die Verbindung wiederhergestellt ist.
 */
class GpsService : Service() {

    companion object {
        const val ACTION_START           = "ch.papiersammlung.GPS_START"
        const val ACTION_STOP            = "ch.papiersammlung.GPS_STOP"
        const val BROADCAST_LOCATION     = "ch.papiersammlung.LOCATION_UPDATE"
        const val EXTRA_LAT              = "lat"
        const val EXTRA_LNG              = "lng"
        const val EXTRA_SPEED            = "speed"

        private const val NOTIF_ID                   = 1001
        private const val GPS_INTERVAL_COLLECTING_MS = 3_000L   // 3s beim Sammeln
        private const val GPS_INTERVAL_IDLE_MS       = 30_000L  // 30s im Idle (Ping)
        private const val GPS_MIN_DISTANCE_M         = 2f        // mind. 2m Bewegung
    }

    private lateinit var locationManager: LocationManager
    private lateinit var offlineBuffer: OfflineBuffer
    private var wakeLock: PowerManager.WakeLock? = null
    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    private var lastLat   = 0.0
    private var lastLng   = 0.0
    private var lastSpeed: Double? = null
    private var isRunning = false

    // ── LocationListener (eingebaute Android-API, kein Play Services) ─────────
    private val locationListener = object : LocationListener {
        override fun onLocationChanged(loc: Location) {
            lastLat   = loc.latitude
            lastLng   = loc.longitude
            lastSpeed = if (loc.hasSpeed()) loc.speed.toDouble() else null
            onGpsUpdate(loc)
        }

        // Deprecated in API 29 aber nötig für API 26 Kompatibilität
        @Suppress("OVERRIDE_DEPRECATION")
        override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
        override fun onProviderEnabled(provider: String)  {}
        override fun onProviderDisabled(provider: String) {}
    }

    // ── Service Lifecycle ─────────────────────────────────────────────────────
    override fun onCreate() {
        super.onCreate()
        locationManager = getSystemService(Context.LOCATION_SERVICE) as LocationManager
        offlineBuffer   = OfflineBuffer(applicationContext)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> startTracking()
            ACTION_STOP  -> stopTracking()
        }
        return START_STICKY // System startet Service nach Kill neu
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel()
        stopLocationUpdates()
        releaseWakeLock()
    }

    // ── Tracking starten/stoppen ──────────────────────────────────────────────
    private fun startTracking() {
        if (isRunning) { updateNotification(); return }
        isRunning = true

        startForeground(NOTIF_ID, buildNotification("Warte auf GPS…"))
        acquireWakeLock()
        requestLocationUpdates()

        // Hintergrund-Sync: Offline-Puffer leeren wenn wieder online
        scope.launch { periodicSync() }
    }

    private fun stopTracking() {
        isRunning = false
        stopLocationUpdates()
        releaseWakeLock()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    // ── GPS-Updates anfordern ─────────────────────────────────────────────────
    private fun requestLocationUpdates() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) return

        val collecting = AppPrefs.isCollecting
        val intervalMs = if (collecting) GPS_INTERVAL_COLLECTING_MS else GPS_INTERVAL_IDLE_MS

        // GPS-Provider (genaueste Quelle)
        try {
            locationManager.requestLocationUpdates(
                LocationManager.GPS_PROVIDER,
                intervalMs,
                GPS_MIN_DISTANCE_M,
                locationListener
            )
        } catch (e: Exception) { /* GPS nicht verfügbar */ }

        // Netzwerk-Provider als Fallback (schneller erster Fix)
        try {
            locationManager.requestLocationUpdates(
                LocationManager.NETWORK_PROVIDER,
                intervalMs * 2,
                GPS_MIN_DISTANCE_M * 5,
                locationListener
            )
        } catch (e: Exception) { /* Netzwerk-Location nicht verfügbar */ }
    }

    private fun stopLocationUpdates() {
        try { locationManager.removeUpdates(locationListener) } catch (e: Exception) {}
    }

    // ── GPS-Punkt verarbeiten ─────────────────────────────────────────────────
    private fun onGpsUpdate(loc: Location) {
        // Broadcast an MainActivity für Live-Karte
        sendBroadcast(Intent(BROADCAST_LOCATION).apply {
            putExtra(EXTRA_LAT,   loc.latitude)
            putExtra(EXTRA_LNG,   loc.longitude)
            putExtra(EXTRA_SPEED, if (loc.hasSpeed()) loc.speed.toDouble() else -1.0)
        })

        val token = AppPrefs.vehicleToken
        val cid   = AppPrefs.activeCollectionId
        if (token.isEmpty() || cid.isEmpty()) { updateNotification(); return }

        scope.launch {
            if (ApiClient.isOnline()) {
                val result = ApiClient.sendPosition(token, loc.latitude, loc.longitude,
                    if (loc.hasSpeed()) loc.speed.toDouble() else null, cid)
                if (result == null && AppPrefs.isCollecting) {
                    // Online aber Request fehlgeschlagen → puffern
                    offlineBuffer.buffer(token, cid, loc.latitude, loc.longitude,
                        if (loc.hasSpeed()) loc.speed.toDouble() else null)
                }
            } else if (AppPrefs.isCollecting) {
                // Offline → lokal puffern
                offlineBuffer.buffer(token, cid, loc.latitude, loc.longitude,
                    if (loc.hasSpeed()) loc.speed.toDouble() else null)
            }
            updateNotification()
        }
    }

    // ── Offline-Puffer leeren ─────────────────────────────────────────────────
    private suspend fun periodicSync() {
        while (isRunning) {
            delay(15_000)
            if (!ApiClient.isOnline()) continue
            val pending = offlineBuffer.getPending(50)
            if (pending.isEmpty()) continue
            val sent = mutableListOf<Long>()
            for (pt in pending) {
                val r = ApiClient.sendPosition(pt.token, pt.lat, pt.lng, pt.speed,
                    pt.collectionId, pt.snapLat, pt.snapLng)
                if (r != null) sent.add(pt.id) else break
            }
            if (sent.isNotEmpty()) { offlineBuffer.deleteIds(sent); offlineBuffer.cleanup() }
        }
    }

    // ── Notification ──────────────────────────────────────────────────────────
    private fun buildNotification(text: String): Notification {
        val openApp = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(this, PapiersammlungApp.CHANNEL_GPS)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentTitle(if (AppPrefs.isCollecting) "🟢 Am Sammeln" else "○ GPS bereit")
            .setContentText(text)
            .setContentIntent(openApp)
            .setOngoing(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()
    }

    private fun updateNotification() {
        val speedKmh = lastSpeed?.let { it * 3.6 }
        val txt = when {
            lastLat == 0.0 -> "Warte auf GPS…"
            speedKmh != null && speedKmh > 0.5 ->
                "%.5f, %.5f · %d km/h".format(lastLat, lastLng, speedKmh.toInt())
            else ->
                "%.5f, %.5f".format(lastLat, lastLng)
        }
        val buffered = runBlocking { offlineBuffer.count() }
        val finalTxt = if (buffered > 0) "$txt  [$buffered gepuffert]" else txt
        getSystemService(NotificationManager::class.java).notify(NOTIF_ID, buildNotification(finalTxt))
    }

    // ── WakeLock ──────────────────────────────────────────────────────────────
    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        wakeLock = (getSystemService(PowerManager::class.java))
            .newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "papiersammlung:gps")
            .apply { acquire(6 * 60 * 60 * 1000L) } // max 6h
    }

    private fun releaseWakeLock() {
        wakeLock?.takeIf { it.isHeld }?.release()
        wakeLock = null
    }
}
