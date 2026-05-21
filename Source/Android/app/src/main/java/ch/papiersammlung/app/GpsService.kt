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
 * Läuft auch wenn App minimiert oder Bildschirm gesperrt ist.
 *
 * BUGFIX v5.5:
 *  - Broadcasts erhalten `setPackage(packageName)`. Damit werden sie auf
 *    Android 14 (targetSdk 34) zuverlässig an `RECEIVER_NOT_EXPORTED`-
 *    Empfänger zugestellt, auch wenn implizite App-interne Broadcasts
 *    durch das System gefiltert werden.
 *  - Bei jedem GPS-Update wird die zuletzt bekannte Position auch dann
 *    gebroadcastet, wenn der User nicht aktiv am Sammeln ist.
 *
 * BUGFIX v5.3:
 *  - startForeground() wird nun IMMER in onStartCommand() aufgerufen
 *    (Android resettet den 5-Sekunden-Timer bei JEDEM startForegroundService()).
 *  - GPS-Intervall wird bei Moduswechsel korrekt aktualisiert.
 *  - runBlocking entfernt; bufferedCount asynchron gecacht.
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
        private const val GPS_INTERVAL_COLLECTING_MS = 3_000L
        private const val GPS_INTERVAL_IDLE_MS       = 30_000L
        private const val GPS_MIN_DISTANCE_M         = 2f
    }

    private lateinit var locationManager: LocationManager
    private lateinit var offlineBuffer: OfflineBuffer
    private var wakeLock: PowerManager.WakeLock? = null
    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    private var lastLat        = 0.0
    private var lastLng        = 0.0
    private var lastSpeed: Double? = null
    private var isRunning      = false

    @Volatile private var bufferedCount = 0L

    // ── LocationListener ──────────────────────────────────────────────────────
    private val locationListener = object : LocationListener {
        override fun onLocationChanged(loc: Location) {
            lastLat   = loc.latitude
            lastLng   = loc.longitude
            lastSpeed = if (loc.hasSpeed()) loc.speed.toDouble() else null
            onGpsUpdate(loc)
        }
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
        // CRITICAL: startForeground() MUSS bei JEDEM onStartCommand() aufgerufen
        // werden, sonst → ForegroundServiceDidNotStartInTimeException.
        startForeground(NOTIF_ID, buildNotification(currentStatusText()))

        when (intent?.action) {
            ACTION_STOP -> stopTracking()
            else        -> startOrUpdate()
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel()
        stopLocationUpdates()
        releaseWakeLock()
    }

    // ── Tracking starten / Modus aktualisieren ────────────────────────────────
    private fun startOrUpdate() {
        if (!isRunning) {
            isRunning = true
            acquireWakeLock()
            scope.launch { periodicSync() }
            // Sofort beim Start: letzte bekannte Position broadcasten,
            // damit MainActivity direkt etwas anzeigen kann.
            broadcastLastKnownIfAny()
        }
        stopLocationUpdates()
        requestLocationUpdates()
    }

    private fun stopTracking() {
        isRunning = false
        stopLocationUpdates()
        releaseWakeLock()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    // ── GPS-Updates registrieren ──────────────────────────────────────────────
    private fun requestLocationUpdates() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) return

        val collecting = AppPrefs.isCollecting
        val intervalMs = if (collecting) GPS_INTERVAL_COLLECTING_MS else GPS_INTERVAL_IDLE_MS

        try {
            locationManager.requestLocationUpdates(
                LocationManager.GPS_PROVIDER, intervalMs, GPS_MIN_DISTANCE_M, locationListener
            )
        } catch (e: Exception) {}

        try {
            locationManager.requestLocationUpdates(
                LocationManager.NETWORK_PROVIDER, intervalMs * 2, GPS_MIN_DISTANCE_M * 5, locationListener
            )
        } catch (e: Exception) {}
    }

    private fun stopLocationUpdates() {
        try { locationManager.removeUpdates(locationListener) } catch (e: Exception) {}
    }

    /** Beim Service-Start letzte bekannte Position broadcasten. */
    private fun broadcastLastKnownIfAny() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) return
        val gps = try {
            locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER)
        } catch (_: Exception) { null }
        val net = try {
            locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)
        } catch (_: Exception) { null }
        val last = listOfNotNull(gps, net).maxByOrNull { it.time } ?: return
        lastLat   = last.latitude
        lastLng   = last.longitude
        lastSpeed = if (last.hasSpeed()) last.speed.toDouble() else null
        sendLocationBroadcast(last.latitude, last.longitude,
            if (last.hasSpeed()) last.speed.toDouble() else null)
    }

    // ── GPS-Punkt verarbeiten ─────────────────────────────────────────────────
    private fun onGpsUpdate(loc: Location) {
        sendLocationBroadcast(loc.latitude, loc.longitude,
            if (loc.hasSpeed()) loc.speed.toDouble() else null)

        val token = AppPrefs.vehicleToken
        val cid   = AppPrefs.activeCollectionId
        if (token.isEmpty() || cid.isEmpty()) { updateNotification(); return }

        scope.launch {
            if (ApiClient.isOnline()) {
                val result = ApiClient.sendPosition(token, loc.latitude, loc.longitude,
                    if (loc.hasSpeed()) loc.speed.toDouble() else null, cid)
                if (result == null && AppPrefs.isCollecting) {
                    offlineBuffer.buffer(token, cid, loc.latitude, loc.longitude,
                        if (loc.hasSpeed()) loc.speed.toDouble() else null)
                }
            } else if (AppPrefs.isCollecting) {
                offlineBuffer.buffer(token, cid, loc.latitude, loc.longitude,
                    if (loc.hasSpeed()) loc.speed.toDouble() else null)
            }
            bufferedCount = offlineBuffer.count()
            updateNotification()
        }
    }

    /**
     * Broadcast mit setPackage() → wird auf Android 14+ zuverlässig an die
     * eigene App zugestellt, auch wenn implizite App-interne Broadcasts
     * gefiltert werden.
     */
    private fun sendLocationBroadcast(lat: Double, lng: Double, speed: Double?) {
        sendBroadcast(Intent(BROADCAST_LOCATION).apply {
            setPackage(packageName)
            putExtra(EXTRA_LAT,   lat)
            putExtra(EXTRA_LNG,   lng)
            putExtra(EXTRA_SPEED, speed ?: -1.0)
        })
    }

    // ── Offline-Puffer sync ───────────────────────────────────────────────────
    private suspend fun periodicSync() {
        while (isRunning) {
            delay(15_000)
            bufferedCount = offlineBuffer.count()
            if (!ApiClient.isOnline()) continue
            val pending = offlineBuffer.getPending(50)
            if (pending.isEmpty()) continue
            val sent = mutableListOf<Long>()
            for (pt in pending) {
                val r = ApiClient.sendPosition(pt.token, pt.lat, pt.lng, pt.speed,
                    pt.collectionId, pt.snapLat, pt.snapLng)
                if (r != null) sent.add(pt.id) else break
            }
            if (sent.isNotEmpty()) {
                offlineBuffer.deleteIds(sent)
                offlineBuffer.cleanup()
                bufferedCount = offlineBuffer.count()
            }
        }
    }

    // ── Notification ──────────────────────────────────────────────────────────
    private fun currentStatusText(): String {
        val speedKmh = lastSpeed?.let { it * 3.6 }
        return when {
            lastLat == 0.0 -> "Warte auf GPS…"
            speedKmh != null && speedKmh > 0.5 ->
                "%.5f, %.5f · %d km/h".format(lastLat, lastLng, speedKmh.toInt())
            else -> "%.5f, %.5f".format(lastLat, lastLng)
        }.let { txt ->
            if (bufferedCount > 0) "$txt  [$bufferedCount gepuffert]" else txt
        }
    }

    private fun buildNotification(text: String): Notification {
        val openApp = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE
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
        getSystemService(NotificationManager::class.java)
            .notify(NOTIF_ID, buildNotification(currentStatusText()))
    }

    // ── WakeLock ──────────────────────────────────────────────────────────────
    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        wakeLock = (getSystemService(PowerManager::class.java))
            .newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "papiersammlung:gps")
            .apply { acquire(6 * 60 * 60 * 1000L) }
    }

    private fun releaseWakeLock() {
        wakeLock?.takeIf { it.isHeld }?.release()
        wakeLock = null
    }
}
