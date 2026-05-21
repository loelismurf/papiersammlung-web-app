package ch.papiersammlung.app

import android.app.Service
import android.content.Intent
import android.os.IBinder
import kotlinx.coroutines.*

/**
 * Sync-Service: Sendet gepufferte GPS-Punkte nach wenn Netzwerk verfügbar.
 * Wird von GpsService regelmässig getriggert und beim App-Start.
 */
class SyncService : Service() {

    private val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        scope.launch {
            flushBuffer()
            stopSelf()
        }
        return START_NOT_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        scope.cancel()
    }

    private suspend fun flushBuffer() {
        if (!ApiClient.isOnline()) return
        val buffer = OfflineBuffer(applicationContext)
        val pending = buffer.getPending(200)
        if (pending.isEmpty()) return

        val sent = mutableListOf<Long>()
        for (pt in pending) {
            val r = ApiClient.sendPosition(
                pt.token, pt.lat, pt.lng, pt.speed,
                pt.collectionId, pt.snapLat, pt.snapLng
            )
            if (r != null) sent.add(pt.id) else break
        }
        buffer.deleteIds(sent)
        buffer.cleanup()
    }
}
