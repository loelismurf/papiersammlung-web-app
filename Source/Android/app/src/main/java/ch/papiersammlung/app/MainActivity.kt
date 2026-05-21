package ch.papiersammlung.app

import android.Manifest
import android.content.*
import android.content.pm.PackageManager
import android.graphics.Color
import android.os.Bundle
import android.os.IBinder
import android.view.View
import android.widget.*
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.*
import org.json.JSONArray
import org.json.JSONObject
import org.osmdroid.config.Configuration
import org.osmdroid.tileprovider.tilesource.TileSourceFactory
import org.osmdroid.util.GeoPoint
import org.osmdroid.views.MapView
import org.osmdroid.views.overlay.Marker
import org.osmdroid.views.overlay.Polyline

/**
 * Hauptaktivität: OSMDroid-Karte + Sidebar mit Routen/Fahrzeugen.
 * GPS-Tracking läuft via GpsService (Foreground-Service, überlebt App-Minimierung).
 */
class MainActivity : AppCompatActivity() {

    // ── Views ─────────────────────────────────────────────────────────────────
    private lateinit var map: MapView
    private lateinit var btnCollect: Button
    private lateinit var tvStatus: TextView
    private lateinit var tvSpeed: TextView
    private lateinit var tvVehicleName: TextView
    private lateinit var spinnerCollection: Spinner
    private lateinit var routeList: LinearLayout
    private lateinit var vehicleList: LinearLayout
    private lateinit var tvBuffered: TextView
    private lateinit var btnFollow: ImageButton

    // ── Map Overlays ──────────────────────────────────────────────────────────
    private var selfMarker: Marker? = null
    private val vehicleMarkers = mutableMapOf<String, Marker>()
    private val routePolylines = mutableMapOf<String, Polyline>()
    private val trackPolylines = mutableMapOf<String, Polyline>()
    private var followMode = true

    // ── State ─────────────────────────────────────────────────────────────────
    private var collections = listOf<JSONObject>()
    private var routes     = listOf<JSONObject>()
    private var vehicles   = listOf<JSONObject>()
    private var pollJob: Job? = null
    private var offlineBuffer: OfflineBuffer? = null

    // ── GPS Broadcast Receiver ─────────────────────────────────────────────────
    private val locationReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context, intent: Intent) {
            val lat   = intent.getDoubleExtra(GpsService.EXTRA_LAT, 0.0)
            val lng   = intent.getDoubleExtra(GpsService.EXTRA_LNG, 0.0)
            val speed = intent.getDoubleExtra(GpsService.EXTRA_SPEED, -1.0).let {
                if (it < 0) null else it
            }
            onGpsUpdate(lat, lng, speed)
        }
    }

    // ── Permissions ───────────────────────────────────────────────────────────
    companion object {
        private const val REQ_LOCATION = 100
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Nicht eingeloggt → Login-Screen
        if (!AppPrefs.isLoggedIn) {
            startActivity(Intent(this, LoginActivity::class.java))
            finish(); return
        }

        // OSMDroid konfigurieren
        Configuration.getInstance().apply {
            load(applicationContext, getSharedPreferences("osmdroid", MODE_PRIVATE))
            userAgentValue = packageName
        }

        setContentView(R.layout.activity_main)
        bindViews()
        setupMap()
        setupSidebar()

        offlineBuffer = OfflineBuffer(applicationContext)
        requestLocationPermission()
    }

    override fun onResume() {
        super.onResume()
        map.onResume()
        // GPS-Broadcast empfangen
        ContextCompat.registerReceiver(this, locationReceiver,
            IntentFilter(GpsService.BROADCAST_LOCATION), ContextCompat.RECEIVER_NOT_EXPORTED)
        // Polling starten
        startPolling()
        // Offline-Puffer anzeigen
        updateBufferCount()
    }

    override fun onPause() {
        super.onPause()
        map.onPause()
        unregisterReceiver(locationReceiver)
        pollJob?.cancel()
        // Karten-Position speichern
        AppPrefs.lastMapLat  = map.mapCenter.latitude
        AppPrefs.lastMapLng  = map.mapCenter.longitude
        AppPrefs.lastMapZoom = map.zoomLevelDouble
    }

    // ── Views binden ──────────────────────────────────────────────────────────
    private fun bindViews() {
        map               = findViewById(R.id.map)
        btnCollect        = findViewById(R.id.btn_collect)
        tvStatus          = findViewById(R.id.tv_status)
        tvSpeed           = findViewById(R.id.tv_speed)
        tvVehicleName     = findViewById(R.id.tv_vehicle_name)
        spinnerCollection = findViewById(R.id.spinner_collection)
        routeList         = findViewById(R.id.route_list)
        vehicleList       = findViewById(R.id.vehicle_list)
        tvBuffered        = findViewById(R.id.tv_buffered)
        btnFollow         = findViewById(R.id.btn_follow)

        tvVehicleName.text = AppPrefs.vehicleName

        btnCollect.setOnClickListener { toggleCollecting() }
        btnFollow.setOnClickListener  {
            followMode = true
            selfMarker?.position?.let { map.controller.animateTo(it) }
            btnFollow.visibility = View.GONE
        }
        map.setOnTouchListener { _, _ ->
            if (followMode) {
                followMode = false
                btnFollow.visibility = View.VISIBLE
            }
            false
        }

        // Einstellungen-Button
        findViewById<ImageButton>(R.id.btn_settings).setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
    }

    // ── Karte einrichten ──────────────────────────────────────────────────────
    private fun setupMap() {
        map.setTileSource(TileSourceFactory.MAPNIK)
        map.setMultiTouchControls(true)
        map.controller.setZoom(AppPrefs.lastMapZoom)
        map.controller.setCenter(GeoPoint(AppPrefs.lastMapLat, AppPrefs.lastMapLng))

        // Dunkles Filter (annähernd das Web-Theme)
        map.overlayManager.tilesOverlay.setColorFilter(
            android.graphics.ColorMatrixColorFilter(
                floatArrayOf(
                    0.72f, 0f, 0f, 0f, 0f,
                    0f, 0.72f, 0f, 0f, 0f,
                    0f, 0f, 0.72f, 0f, 0f,
                    0f, 0f, 0f, 1f, 0f
                )
            )
        )
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────
    private fun setupSidebar() {
        lifecycleScope.launch { loadCollections() }
    }

    private suspend fun loadCollections() {
        val resp = ApiClient.getCollections() ?: return
        val arr = resp.optJSONArray("") ?: resp.optJSONArray("collections") ?: run {
            // API gibt direkt Array zurück
            val direct = runCatching { JSONArray(resp.toString()) }.getOrNull()
            direct
        } ?: return

        collections = (0 until arr.length()).map { arr.getJSONObject(it) }
        val names = collections.map { it.optString("name", "—") }
        runOnUiThread {
            spinnerCollection.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_item, names)
                .apply { setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item) }
            spinnerCollection.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
                override fun onItemSelected(p: AdapterView<*>, v: View?, pos: Int, id: Long) {
                    val cid = collections[pos].optString("id")
                    AppPrefs.activeCollectionId = cid
                    lifecycleScope.launch { joinVehicle(cid) }
                }
                override fun onNothingSelected(p: AdapterView<*>) {}
            }
            // Gespeicherte Collection vorauswählen
            val savedIdx = collections.indexOfFirst { it.optString("id") == AppPrefs.activeCollectionId }
            if (savedIdx >= 0) spinnerCollection.setSelection(savedIdx)
        }
    }

    private suspend fun joinVehicle(collectionId: String) {
        val resp = ApiClient.vehicleJoin(AppPrefs.vehicleName, collectionId) ?: return
        val token = resp.optString("token")
        if (token.isNotEmpty()) {
            AppPrefs.vehicleToken = token
            AppPrefs.isCollecting = resp.optBoolean("collecting", false)
            runOnUiThread { updateCollectingUI() }
        }
    }

    // ── Polling ───────────────────────────────────────────────────────────────
    private fun startPolling() {
        pollJob?.cancel()
        pollJob = lifecycleScope.launch {
            val cid = AppPrefs.activeCollectionId
            if (cid.isEmpty()) return@launch
            while (isActive) {
                pollState(cid)
                delay(2500)
            }
        }
    }

    private suspend fun pollState(cid: String) {
        val resp = ApiClient.getState(cid) ?: return
        // Ping um last_seen zu aktualisieren (auch idle Fahrzeuge sichtbar halten)
        val tok = AppPrefs.vehicleToken
        if (tok.isNotEmpty()) lifecycleScope.launch { ApiClient.ping(tok) }

        val routesArr   = resp.optJSONArray("routes")   ?: return
        val vehiclesArr = resp.optJSONArray("vehicles")  ?: JSONArray()

        routes   = (0 until routesArr.length()).map  { routesArr.getJSONObject(it)  }
        vehicles = (0 until vehiclesArr.length()).map { vehiclesArr.getJSONObject(it) }

        runOnUiThread {
            renderRoutes()
            renderVehicles()
            renderRouteSidebar()
            renderVehicleSidebar()
        }
    }

    // ── GPS-Update (vom GpsService-Broadcast) ─────────────────────────────────
    private fun onGpsUpdate(lat: Double, lng: Double, speed: Double?) {
        val pt = GeoPoint(lat, lng)

        // Eigener Marker aktualisieren
        if (selfMarker == null) {
            selfMarker = Marker(map).apply {
                title   = AppPrefs.vehicleName
                snippet = if (AppPrefs.isCollecting) "🟢 Sammeln" else "○ Bereit"
                setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_CENTER)
                position = pt
                map.overlays.add(this)
            }
        } else {
            selfMarker!!.position = pt
            selfMarker!!.snippet  = if (AppPrefs.isCollecting) "🟢 Sammeln" else "○ Bereit"
        }

        // Follow-Modus
        if (followMode) map.controller.animateTo(pt)

        // Geschwindigkeit anzeigen
        val kmh = speed?.let { it * 3.6 }
        tvSpeed.text  = if (kmh != null && kmh > 0.5) "%.1f km/h".format(kmh) else ""
        tvSpeed.visibility = if (tvSpeed.text.isEmpty()) View.GONE else View.VISIBLE

        map.invalidate()
        updateBufferCount()
    }

    // ── Render Karte ──────────────────────────────────────────────────────────
    private fun renderRoutes() {
        // Alte Polylines entfernen
        routePolylines.values.forEach { map.overlays.remove(it) }
        routePolylines.clear()

        routes.filter { it.optBoolean("visible", true) }.forEach { route ->
            val coords = route.optJSONArray("coordinates") ?: return@forEach
            val driven = route.optJSONArray("driven_segments")
            val color  = runCatching { Color.parseColor(route.optString("color", "#00d4ff")) }
                .getOrDefault(Color.CYAN)
            val n = coords.length()
            if (n < 2) return@forEach

            for (i in 0 until n - 1) {
                val c1 = coords.getJSONArray(i)
                val c2 = coords.getJSONArray(i + 1)
                val seg = driven?.optBoolean(i, false) ?: false
                val polyline = Polyline(map).apply {
                    addPoint(GeoPoint(c1.getDouble(0), c1.getDouble(1)))
                    addPoint(GeoPoint(c2.getDouble(0), c2.getDouble(1)))
                    outlinePaint.color = if (seg) Color.parseColor("#a8ff3e") else Color.parseColor("#ff4444")
                    outlinePaint.strokeWidth = 5f
                }
                map.overlays.add(polyline)
                routePolylines["${route.optString("id")}_$i"] = polyline
            }
        }
    }

    private fun renderVehicles() {
        val activeTokens = vehicles.map { it.optString("token") }.toSet()
        // Alte Marker entfernen
        vehicleMarkers.keys.filter { it !in activeTokens }.forEach {
            map.overlays.remove(vehicleMarkers[it])
            vehicleMarkers.remove(it)
        }

        val myToken = AppPrefs.vehicleToken
        vehicles.forEach { v ->
            val token = v.optString("token")
            if (token == myToken) return@forEach // eigener Marker via selfMarker
            val lat = if (v.isNull("lat")) return@forEach else v.optDouble("lat")
            val lng = if (v.isNull("lng")) return@forEach else v.optDouble("lng")
            val collecting = v.optBoolean("collecting", false)
            val pt = GeoPoint(lat, lng)
            val marker = vehicleMarkers.getOrPut(token) {
                Marker(map).also { map.overlays.add(it) }
            }
            marker.position = pt
            marker.title    = v.optString("name", token)
            marker.snippet  = if (collecting) "🟢 Sammeln" else "○ Idle"
        }
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────
    private fun renderRouteSidebar() {
        routeList.removeAllViews()
        routes.forEach { r ->
            val tv = TextView(this).apply {
                text = "%s — %d%%".format(r.optString("name"), r.optInt("progress"))
                setTextColor(Color.parseColor(r.optString("color","#00d4ff")))
                textSize = 12f
                setPadding(8, 4, 8, 4)
            }
            routeList.addView(tv)
        }
    }

    private fun renderVehicleSidebar() {
        vehicleList.removeAllViews()
        vehicles.forEach { v ->
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(0,4,0,4) }
            val dot = View(this).apply {
                val col = if (v.optBoolean("collecting")) "#00d4ff" else "#7a9ab0"
                setBackgroundColor(Color.parseColor(col))
                layoutParams = LinearLayout.LayoutParams(8, 8).apply { setMargins(0,6,8,0) }
            }
            val name = TextView(this).apply {
                text = v.optString("name")
                setTextColor(Color.parseColor("#c8d4e0"))
                textSize = 12f
            }
            row.addView(dot); row.addView(name)
            vehicleList.addView(row)
        }
    }

    // ── Sammelmodus ───────────────────────────────────────────────────────────
    private fun toggleCollecting() {
        val tok = AppPrefs.vehicleToken
        if (tok.isEmpty()) { Toast.makeText(this, "Kein Fahrzeug-Token", Toast.LENGTH_SHORT).show(); return }
        lifecycleScope.launch {
            val r = ApiClient.setCollecting(tok, !AppPrefs.isCollecting) ?: return@launch
            AppPrefs.isCollecting = r.optBoolean("collecting", AppPrefs.isCollecting)
            runOnUiThread {
                updateCollectingUI()
                // GPS-Service Interval anpassen
                restartGpsService()
            }
        }
    }

    private fun updateCollectingUI() {
        val on = AppPrefs.isCollecting
        btnCollect.text = if (on) "🟢 Am Sammeln" else "🔴 Nicht am Sammeln"
        btnCollect.setBackgroundColor(Color.parseColor(if (on) "#0a2010" else "#1a1010"))
        tvStatus.text = if (on) "Sammeln aktiv" else "Bereit"
    }

    // ── GPS-Service ───────────────────────────────────────────────────────────
    private fun restartGpsService() {
        val svc = Intent(this, GpsService::class.java).apply { action = GpsService.ACTION_START }
        startForegroundService(svc)
    }

    private fun stopGpsService() {
        startService(Intent(this, GpsService::class.java).apply { action = GpsService.ACTION_STOP })
    }

    // ── Offline-Buffer-Anzeige ────────────────────────────────────────────────
    private fun updateBufferCount() {
        lifecycleScope.launch {
            val n = offlineBuffer?.count() ?: 0
            runOnUiThread {
                tvBuffered.text = if (n > 0) "⚠ $n Punkte offline gepuffert" else ""
                tvBuffered.visibility = if (n > 0) View.VISIBLE else View.GONE
            }
        }
    }

    // ── Permissions ───────────────────────────────────────────────────────────
    private fun requestLocationPermission() {
        val perms = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        )
        if (android.os.Build.VERSION.SDK_INT >= 29) {
            perms.add(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
        }
        val missing = perms.filter { ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED }
        if (missing.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, missing.toTypedArray(), REQ_LOCATION)
        } else {
            restartGpsService()
        }
    }

    override fun onRequestPermissionsResult(code: Int, perms: Array<String>, results: IntArray) {
        super.onRequestPermissionsResult(code, perms, results)
        if (code == REQ_LOCATION && results.any { it == PackageManager.PERMISSION_GRANTED }) {
            restartGpsService()
        }
    }
}
