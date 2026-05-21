package ch.papiersammlung.app

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context

class PapiersammlungApp : Application() {

    companion object {
        const val CHANNEL_GPS     = "gps_tracking"
        const val CHANNEL_GENERAL = "general"

        lateinit var instance: PapiersammlungApp
            private set

        fun appContext(): Context = instance.applicationContext
    }

    override fun onCreate() {
        super.onCreate()
        instance = this
        createNotificationChannels()
    }

    private fun createNotificationChannels() {
        val nm = getSystemService(NotificationManager::class.java)

        // GPS-Tracking Channel (Foreground Service Notification)
        nm.createNotificationChannel(
            NotificationChannel(
                CHANNEL_GPS,
                "GPS-Tracking",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Aktiv während der Papiersammlung"
                setShowBadge(false)
            }
        )

        // Allgemeine Benachrichtigungen
        nm.createNotificationChannel(
            NotificationChannel(
                CHANNEL_GENERAL,
                "Allgemein",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply {
                description = "Status und Meldungen"
            }
        )
    }
}
