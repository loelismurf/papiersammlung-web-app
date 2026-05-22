package ch.papiersammlung.app

import android.content.Context
import android.content.SharedPreferences

/**
 * Zentrale Einstellungen und Session-Daten der App.
 * Speichert: Server-URL, Bearer-Token, Fahrzeug-Token, Sammelmodus-Status.
 */
object AppPrefs {

    private const val PREFS = "papiersammlung_prefs"

    private fun prefs(): SharedPreferences =
        PapiersammlungApp.appContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    // ── Server-Konfiguration ──────────────────────────────────────────────────
    var serverUrl: String
        get() = prefs().getString("server_url", "https://papiersammlung.pfadiriko.ch") ?: "https://papiersammlung.pfadiriko.ch"
        set(v) = prefs().edit().putString("server_url", v.trimEnd('/')).apply()

    /** Vollständige API-URL, z.B. https://example.com/papiersammlung/api.php */
    val apiUrl: String get() = "${serverUrl}/api.php"

    // ── Auth ──────────────────────────────────────────────────────────────────
    /** Bearer-Token für die REST-API (30 Tage gültig, via mobile_login geholt) */
    var bearerToken: String
        get() = prefs().getString("bearer_token", "") ?: ""
        set(v) = prefs().edit().putString("bearer_token", v).apply()

    var username: String
        get() = prefs().getString("username", "") ?: ""
        set(v) = prefs().edit().putString("username", v).apply()

    var userRole: String
        get() = prefs().getString("user_role", "user") ?: "user"
        set(v) = prefs().edit().putString("user_role", v).apply()

    val isLoggedIn: Boolean get() = bearerToken.isNotEmpty() && serverUrl.isNotEmpty()

    fun logout() {
        prefs().edit()
            .remove("bearer_token")
            .remove("vehicle_token")
            .remove("vehicle_name")
            .remove("active_collection_id")
            .apply()
    }

    // ── Fahrzeug-Session ──────────────────────────────────────────────────────
    var vehicleToken: String
        get() = prefs().getString("vehicle_token", "") ?: ""
        set(v) = prefs().edit().putString("vehicle_token", v).apply()

    var vehicleName: String
        get() = prefs().getString("vehicle_name", username) ?: username
        set(v) = prefs().edit().putString("vehicle_name", v).apply()

    var activeCollectionId: String
        get() = prefs().getString("active_collection_id", "") ?: ""
        set(v) = prefs().edit().putString("active_collection_id", v).apply()

    var isCollecting: Boolean
        get() = prefs().getBoolean("is_collecting", false)
        set(v) = prefs().edit().putBoolean("is_collecting", v).apply()

    // Persistente Geräte-ID für Multi-Device-Management
    val deviceId: String
        get() {
            val existing = prefs().getString("device_id", "") ?: ""
            if (existing.isNotEmpty()) return existing
            val newId = java.util.UUID.randomUUID().toString()
            prefs().edit().putString("device_id", newId).apply()
            return newId
        }

    // View-Only: dieses Gerät ist inaktiv, ein anderes Gerät sendet GPS
    var isViewOnly: Boolean
        get() = prefs().getBoolean("is_view_only", false)
        set(v) = prefs().edit().putBoolean("is_view_only", v).apply()

    // ── Karte ─────────────────────────────────────────────────────────────────
    var lastMapLat: Double
        get() = prefs().getFloat("map_lat", 47.3769f).toDouble()
        set(v) = prefs().edit().putFloat("map_lat", v.toFloat()).apply()

    var lastMapLng: Double
        get() = prefs().getFloat("map_lng", 8.5417f).toDouble()
        set(v) = prefs().edit().putFloat("map_lng", v.toFloat()).apply()

    var lastMapZoom: Double
        get() = prefs().getFloat("map_zoom", 13f).toDouble()
        set(v) = prefs().edit().putFloat("map_zoom", v.toFloat()).apply()
}
