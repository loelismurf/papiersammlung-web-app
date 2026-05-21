package ch.papiersammlung.app

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * REST-API-Client für die Papiersammlung-PHP-API.
 * Unterstützt Bearer-Token-Auth (kein Session-Cookie nötig).
 * Gibt null zurück wenn offline → SyncService puffert.
 */
object ApiClient {

    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(10, TimeUnit.SECONDS)
        .build()

    private val JSON_MEDIA = "application/json; charset=utf-8".toMediaType()

    // ── Netzwerk-Status ───────────────────────────────────────────────────────
    fun isOnline(): Boolean {
        val cm = PapiersammlungApp.appContext()
            .getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val net = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(net) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    // ── Basisanfrage ──────────────────────────────────────────────────────────
    private fun buildRequest(url: String, body: JSONObject? = null): Request {
        val token = AppPrefs.bearerToken
        val builder = Request.Builder().url(url)
        if (token.isNotEmpty()) builder.header("Authorization", "Bearer $token")
        return if (body != null) {
            builder.post(body.toString().toRequestBody(JSON_MEDIA)).build()
        } else {
            builder.get().build()
        }
    }

    /** Führt GET-Request aus, gibt JSONObject oder null zurück */
    suspend fun get(action: String, params: Map<String, String> = emptyMap()): JSONObject? =
        withContext(Dispatchers.IO) {
            try {
                val sb = StringBuilder("${AppPrefs.apiUrl}?action=$action")
                params.forEach { (k, v) -> sb.append("&$k=$v") }
                val resp = client.newCall(buildRequest(sb.toString())).execute()
                val text = resp.body?.string() ?: return@withContext null
                JSONObject(text)
            } catch (e: Exception) { null }
        }

    /** Führt POST-Request aus, gibt JSONObject oder null zurück */
    suspend fun post(action: String, body: JSONObject): JSONObject? =
        withContext(Dispatchers.IO) {
            try {
                val url = "${AppPrefs.apiUrl}?action=$action"
                val resp = client.newCall(buildRequest(url, body)).execute()
                val text = resp.body?.string() ?: return@withContext null
                JSONObject(text)
            } catch (e: Exception) { null }
        }

    // ── Spezifische API-Aufrufe ───────────────────────────────────────────────

    /** Login: gibt Bearer-Token zurück oder null bei Fehler */
    suspend fun login(username: String, password: String): JSONObject? =
        withContext(Dispatchers.IO) {
            try {
                val url = "${AppPrefs.apiUrl}?action=mobile_login"
                val body = JSONObject().apply {
                    put("username", username)
                    put("password", password)
                }
                // Login braucht noch keinen Auth-Header
                val request = Request.Builder().url(url)
                    .post(body.toString().toRequestBody(JSON_MEDIA)).build()
                val resp = client.newCall(request).execute()
                val text = resp.body?.string() ?: return@withContext null
                JSONObject(text)
            } catch (e: Exception) { null }
        }

    /** Fahrzeug-Join: erstellt oder findet Fahrzeug für aktuellen User */
    suspend fun vehicleJoin(name: String, collectionId: String): JSONObject? =
        post("vehicle_join", JSONObject().apply {
            put("name", name)
            put("collection_id", collectionId)
        })

    /** GPS-Position senden (mit OSRM-Snap falls vorhanden) */
    suspend fun sendPosition(
        token: String, lat: Double, lng: Double,
        speed: Double?, collectionId: String,
        snapLat: Double? = null, snapLng: Double? = null
    ): JSONObject? {
        val body = JSONObject().apply {
            put("token", token)
            put("lat", lat)
            put("lng", lng)
            put("collection_id", collectionId)
            if (speed != null) put("speed", speed)
            if (snapLat != null && snapLng != null) {
                put("snap_lat", snapLat)
                put("snap_lng", snapLng)
            }
        }
        return post("vehicle_position", body)
    }

    /** Sammelmodus setzen */
    suspend fun setCollecting(token: String, collecting: Boolean): JSONObject? =
        post("vehicle_set_collecting", JSONObject().apply {
            put("token", token)
            put("collecting", collecting)
        })

    /** Ping: last_seen aktualisieren (idle Fahrzeug bleibt sichtbar) */
    suspend fun ping(token: String): JSONObject? =
        post("vehicle_ping", JSONObject().apply { put("token", token) })

    /** Sammlungen laden */
    suspend fun getCollections(): JSONObject? = get("collections_active")

    /** State (Routen + Fahrzeuge) für eine Collection */
    suspend fun getState(collectionId: String): JSONObject? =
        get("state", mapOf("collection_id" to collectionId))

    /** Fahrspur für ein Fahrzeug */
    suspend fun getTrack(token: String, collectionId: String): JSONObject? =
        get("vehicle_track", mapOf("token" to token, "collection_id" to collectionId))
}
