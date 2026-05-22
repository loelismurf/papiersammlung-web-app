package ch.papiersammlung.app

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * REST-API-Client für die Papiersammlung-PHP-API.
 *
 * BUGFIX v5.2a – Apache/Netcup Authorization-Header-Problem:
 *   Auf Shared Hosting (Apache+CGI) wird der HTTP Authorization-Header oft
 *   von Apache abgestripped bevor er PHP erreicht. Deshalb wird das Bearer-Token
 *   zusätzlich als GET-Parameter ?auth_token=... übertragen (vom Server unterstützt).
 *   Außerdem X-Auth-Token Header als zweiter Fallback.
 *
 * BUGFIX v5.2 – JSON-Array-Parsing:
 *   getRaw() + getArray() für Endpunkte die direkt [...] zurückgeben.
 */
object ApiClient {

    private const val TAG = "ApiClient"

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
    /**
     * Baut Request mit dreifacher Auth-Absicherung:
     * 1. Authorization: Bearer <token>  (Standard, kann von Apache gestripped werden)
     * 2. X-Auth-Token: <token>          (Custom-Header, überlebt Apache meist)
     * 3. ?auth_token=<token> in der URL (GET-Param, 100% zuverlässig auf jedem Hoster)
     */
    private fun buildRequest(url: String, body: JSONObject? = null): Request {
        val token = AppPrefs.bearerToken
        val builder = Request.Builder().url(url)
        if (token.isNotEmpty()) {
            builder.header("Authorization", "Bearer $token")
            builder.header("X-Auth-Token", token)
        }
        return if (body != null) {
            builder.post(body.toString().toRequestBody(JSON_MEDIA)).build()
        } else {
            builder.get().build()
        }
    }

    /**
     * Baut GET-URL mit auth_token als Parameter.
     * Der Server prüft: Authorization-Header → X-Auth-Token → GET auth_token
     */
    private fun buildGetUrl(action: String, params: Map<String, String>): String {
        val sb = StringBuilder("${AppPrefs.apiUrl}?action=$action")
        val token = AppPrefs.bearerToken
        if (token.isNotEmpty()) sb.append("&auth_token=$token")
        params.forEach { (k, v) -> sb.append("&$k=${android.net.Uri.encode(v)}") }
        return sb.toString()
    }

    /** Führt GET-Request aus, gibt rohen Response-String zurück */
    suspend fun getRaw(
        action: String,
        params: Map<String, String> = emptyMap()
    ): String? = withContext(Dispatchers.IO) {
        try {
            val url = buildGetUrl(action, params)
            Log.d(TAG, "GET $url")
            val resp = client.newCall(buildRequest(url)).execute()
            val text = resp.body?.string()
            Log.d(TAG, "Response ($action): ${text?.take(200)}")
            text
        } catch (e: Exception) {
            Log.e(TAG, "GET $action failed: ${e.message}")
            null
        }
    }

    /** GET-Request → JSONObject */
    suspend fun get(action: String, params: Map<String, String> = emptyMap()): JSONObject? {
        val text = getRaw(action, params) ?: return null
        return try { JSONObject(text) } catch (e: Exception) {
            Log.e(TAG, "JSON parse error for $action: ${text.take(100)}")
            null
        }
    }

    /**
     * GET-Request → JSONArray.
     * Gibt bei Auth-Fehler ein JSONArray mit einem Error-Objekt zurück damit
     * MainActivity eine sinnvolle Fehlermeldung zeigen kann.
     */
    suspend fun getArray(action: String, params: Map<String, String> = emptyMap()): JSONArray? {
        val text = getRaw(action, params) ?: return null
        return try {
            val trimmed = text.trim()
            when {
                trimmed.startsWith('[') -> JSONArray(trimmed)
                trimmed.startsWith('{') -> {
                    val obj = JSONObject(trimmed)
                    // Auth-Fehler oder Server-Fehler → als Fehler-Array zurückgeben
                    if (obj.has("error")) {
                        Log.w(TAG, "Server error for $action: ${obj.optString("error")}")
                        return JSONArray().put(JSONObject().put("_error", obj.optString("error")))
                    }
                    obj.optJSONArray("data")
                        ?: obj.optJSONArray("collections")
                        ?: obj.optJSONArray("routes")
                        ?: obj.optJSONArray("result")
                }
                else -> {
                    Log.e(TAG, "Unexpected response for $action: ${trimmed.take(100)}")
                    null
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Parse error for $action: ${e.message}")
            null
        }
    }

    /** POST-Request mit auth_token im Body als zusätzlicher Fallback */
    suspend fun post(action: String, body: JSONObject): JSONObject? =
        withContext(Dispatchers.IO) {
            try {
                // auth_token auch im Body mitschicken (POST-Fallback)
                val token = AppPrefs.bearerToken
                if (token.isNotEmpty() && !body.has("auth_token")) {
                    body.put("auth_token", token)
                }
                val url = "${AppPrefs.apiUrl}?action=$action"
                Log.d(TAG, "POST $url body=${body.toString().take(100)}")
                val resp = client.newCall(buildRequest(url, body)).execute()
                val text = resp.body?.string() ?: return@withContext null
                Log.d(TAG, "Response ($action): ${text.take(200)}")
                JSONObject(text)
            } catch (e: Exception) {
                Log.e(TAG, "POST $action failed: ${e.message}")
                null
            }
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
                val request = Request.Builder().url(url)
                    .post(body.toString().toRequestBody(JSON_MEDIA)).build()
                val resp = client.newCall(request).execute()
                val text = resp.body?.string() ?: return@withContext null
                JSONObject(text)
            } catch (e: Exception) { null }
        }

    /** Fahrzeug-Join */
    suspend fun vehicleJoin(name: String, collectionId: String = "", deviceId: String = ""): JSONObject? =
        post("vehicle_join", JSONObject().apply {
            put("name", name)
            if (collectionId.isNotEmpty()) put("collection_id", collectionId)
            if (deviceId.isNotEmpty()) put("device_id", deviceId)
        })

    /** GPS-Position senden */
    suspend fun sendPosition(
        token: String, lat: Double, lng: Double,
        speed: Double?, collectionId: String,
        snapLat: Double? = null, snapLng: Double? = null,
        deviceId: String = ""
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
            if (deviceId.isNotEmpty()) put("device_id", deviceId)
        }
        return post("vehicle_position", body)
    }

    /** Sammelmodus setzen */
    suspend fun setCollecting(token: String, collecting: Boolean): JSONObject? =
        post("vehicle_set_collecting", JSONObject().apply {
            put("token", token)
            put("collecting", collecting)
        })

    /** Ping */
    suspend fun ping(token: String, deviceId: String = ""): JSONObject? =
        post("vehicle_ping", JSONObject().apply {
            put("token", token)
            if (deviceId.isNotEmpty()) put("device_id", deviceId)
        })

    /** Aktives Gerät übernehmen */
    suspend fun takeoverDevice(token: String, deviceId: String): JSONObject? =
        post("vehicle_takeover", JSONObject().apply {
            put("token", token)
            put("device_id", deviceId)
        })

    /** Aktive Sammlungen laden → JSONArray */
    suspend fun getCollections(): JSONArray? = getArray("collections_active")

    /** State (Routen + Fahrzeuge) */
    suspend fun getState(collectionId: String): JSONObject? =
        get("state", mapOf("collection_id" to collectionId))

    /** Fahrspur */
    suspend fun getTrack(token: String, collectionId: String): JSONObject? =
        get("vehicle_track", mapOf("token" to token, "collection_id" to collectionId))
}
