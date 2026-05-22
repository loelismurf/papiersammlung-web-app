package ch.papiersammlung.app

import android.Manifest
import android.content.*
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.MotionEvent
import android.view.View
import android.widget.*
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.content.res.AppCompatResources
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
 * Hauptaktivität v5.6 — mit Custom Markers und Vehicle Track History
 *
 * Neu in v5.6:
 *  - Eigener Marker: blauer Punkt mit Pfeil (Google Maps Style) statt grüner Bubble
 *  - Fahrzeugrouten werden auf dem Server gespeichert und können auf der Karte
 *    angezeigt werden (optional via Toggle)
 *  - Fahrspur wird alle 5 Sekunden aktualisiert
 */
class MainActivity : AppCompatActivity() {

    // ── Views ─────────────────────────────────────────────────────────────────
    private lateinit var map: MapView
    private lateinit var sidebar: ScrollView
    private lateinit var btnToggleSidebar: ImageButton
    private lateinit var btnCollect: Button
    private lateinit var btnExit: ImageButton
    private lateinit var tvStatus: TextView
    private lateinit var tvSpeed: TextView
    private lateinit var tvVehicleName: TextView
    private lateinit var spinnerCollection: Spinner
    private lateinit var routeList: LinearLayout
    private lateinit var vehicleList: LinearLayout
    private lateinit var tvBuffered: TextView
    private lateinit var btnFollow: ImageButton
    private lateinit var tvCollectionStatus: TextView
    private lateinit var btnToggleTrack: ImageButton
    private lateinit var tvViewOnly: TextView

    // ── System Services ───────────────────────────────────────────────────────
    private lateinit var locationManager: LocationManager

    // ── Map Overlays ──────────────────────────────────────────────────────────
    private var selfMarker: Marker? = null
    private val vehicleMarkers = mutableMapOf<String, Marker>()
    private val routePolylines = mutableMapOf<String, Polyline>()
    private val trackPolylines = mutableMapOf<String, Polyline>()
    private var followMode    = true
    private var firstGpsFix   = true
    private var sidebarVisible = true
    private var isViewOnly    = false
    // Per-vehicle track visibility
    private val vehicleTrackVisible = mutableMapOf<String, Boolean>()

    // ── State ─────────────────────────────────────────────────────────────────
    private var collections = listOf<JSONObject>()
    private var routes      = listOf<JSONObject>()
    private var vehicles    = listOf<JSONObject>()
    private var pollJob: Job? = null
    private var trackJob: Job? = null
    private var offlineBuffer: OfflineBuffer? = null
    private var isJoining = false

    // Aktuelle eigene GPS-Position
    private var myLat: Double = 0.0
    private var myLng: Double = 0.0
    private var lastUpdateMs: Long = 0L

    // ── Direkter LocationListener ──────────────────────────────────────────────
    private val directLocationListener = object : LocationListener {
        override fun onLocationChanged(loc: Location) {
            onGpsUpdate(
                loc.latitude, loc.longitude,
                if (loc.hasSpeed()) loc.speed.toDouble() else null
            )
        }
        @Suppress("OVERRIDE_DEPRECATION")
        override fun onStatusChanged(p: String?, s: Int, e: Bundle?) {}
        override fun onProviderEnabled(p: String) {}
        override fun onProviderDisabled(p: String) {}
    }

    private val locationReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context, intent: Intent) {
            val lat   = intent.getDoubleExtra(GpsService.EXTRA_LAT, 0.0)
            val lng   = intent.getDoubleExtra(GpsService.EXTRA_LNG, 0.0)
            val speed = intent.getDoubleExtra(GpsService.EXTRA_SPEED, -1.0)
                .let { if (it < 0) null else it }
            onGpsUpdate(lat, lng, speed)
        }
    }

    companion object {
        private const val REQ_LOCATION            = 100
        private const val REQ_BACKGROUND_LOCATION = 101
        private const val MIN_UPDATE_INTERVAL_MS  = 800L
        private const val LAST_KNOWN_MAX_AGE_MS   = 5 * 60 * 1000L
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        if (!AppPrefs.isLoggedIn) {
            startActivity(Intent(this, LoginActivity::class.java))
            finish(); return
        }

        Configuration.getInstance().apply {
            load(applicationContext, getSharedPreferences("osmdroid", MODE_PRIVATE))
            userAgentValue = packageName
        }

        setContentView(R.layout.activity_main)
        locationManager = getSystemService(Context.LOCATION_SERVICE) as LocationManager
        bindViews()
        setupMap()

        offlineBuffer = OfflineBuffer(applicationContext)

        requestLocationPermission()
        lifecycleScope.launch { loadCollections() }
    }

    override fun onResume() {
        super.onResume()
        map.onResume()

        ContextCompat.registerReceiver(
            this, locationReceiver,
            IntentFilter(GpsService.BROADCAST_LOCATION),
            ContextCompat.RECEIVER_NOT_EXPORTED
        )

        startDirectLocationUpdates()

        if (AppPrefs.vehicleToken.isNotEmpty() && AppPrefs.activeCollectionId.isNotEmpty()) {
            startPolling()
            startTrackPolling()
        }
        updateBufferCount()
        updateCollectingUI()
        isViewOnly = AppPrefs.isViewOnly
        updateViewOnlyBanner()
    }

    override fun onPause() {
        super.onPause()
        map.onPause()
        try { unregisterReceiver(locationReceiver) } catch (_: Exception) {}
        try { locationManager.removeUpdates(directLocationListener) } catch (_: Exception) {}
        pollJob?.cancel()
        trackJob?.cancel()
        AppPrefs.lastMapLat  = map.mapCenter.latitude
        AppPrefs.lastMapLng  = map.mapCenter.longitude
        AppPrefs.lastMapZoom = map.zoomLevelDouble
    }

    // ── Direkte GPS-Updates ────────────────────────────────────────────────────
    private fun startDirectLocationUpdates() {
        if (ContextCompat.checkSelfPermission(
                this, Manifest.permission.ACCESS_FINE_LOCATION
            ) != PackageManager.PERMISSION_GRANTED) return

        val lastGps = try {
            locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER)
        } catch (_: Exception) { null }
        val lastNet = try {
            locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)
        } catch (_: Exception) { null }

        val lastKnown = listOfNotNull(lastGps, lastNet)
            .filter { System.currentTimeMillis() - it.time < LAST_KNOWN_MAX_AGE_MS }
            .maxByOrNull { it.time }

        lastKnown?.let {
            onGpsUpdate(
                it.latitude, it.longitude,
                if (it.hasSpeed()) it.speed.toDouble() else null
            )
        }

        try {
            locationManager.requestLocationUpdates(
                LocationManager.GPS_PROVIDER, 1000L, 1f, directLocationListener
            )
        } catch (_: Exception) {}
        try {
            locationManager.requestLocationUpdates(
                LocationManager.NETWORK_PROVIDER, 5000L, 5f, directLocationListener
            )
        } catch (_: Exception) {}
    }

    // ── Views binden ──────────────────────────────────────────────────────────
    private fun bindViews() {
        map                = findViewById(R.id.map)
        sidebar            = findViewById(R.id.sidebar)
        btnToggleSidebar   = findViewById(R.id.btn_toggle_sidebar)
        btnCollect         = findViewById(R.id.btn_collect)
        btnExit            = findViewById(R.id.btn_exit)
        tvStatus           = findViewById(R.id.tv_status)
        tvSpeed            = findViewById(R.id.tv_speed)
        tvVehicleName      = findViewById(R.id.tv_vehicle_name)
        spinnerCollection  = findViewById(R.id.spinner_collection)
        routeList          = findViewById(R.id.route_list)
        vehicleList        = findViewById(R.id.vehicle_list)
        tvBuffered         = findViewById(R.id.tv_buffered)
        btnFollow          = findViewById(R.id.btn_follow)
        tvCollectionStatus = findViewById(R.id.tv_collection_status)
        btnToggleTrack     = findViewById(R.id.btn_toggle_track)
        tvViewOnly         = findViewById(R.id.tv_view_only)

        tvVehicleName.text = AppPrefs.vehicleName

        btnCollect.isEnabled = false
        btnCollect.setOnClickListener { toggleCollecting() }

        btnToggleSidebar.setOnClickListener { toggleSidebar() }
        btnExit.setOnClickListener { confirmExit() }
        btnFollow.setOnClickListener { centerOnCurrentPosition() }
        updateFollowButton()

        // Fahrspur-Toggle (alle Fahrzeuge)
        btnToggleTrack.setOnClickListener { toggleAllTracks() }
        updateTrackButton()

        // View-Only-Banner: Antippen zum Übernehmen
        tvViewOnly.setOnClickListener {
            if (isViewOnly) lifecycleScope.launch { performTakeover() }
        }
        isViewOnly = AppPrefs.isViewOnly
        updateViewOnlyBanner()

        map.setOnTouchListener { _, ev ->
            if (followMode && ev.action == MotionEvent.ACTION_MOVE) {
                followMode = false
                updateFollowButton()
            }
            false
        }

        findViewById<ImageButton>(R.id.btn_settings).setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
    }

    private fun updateFollowButton() {
        btnFollow.setColorFilter(
            Color.parseColor(if (followMode) "#00d4ff" else "#7a9ab0")
        )
    }

    private fun toggleVehicleTrack(token: String) {
        val current = vehicleTrackVisible[token] ?: false
        vehicleTrackVisible[token] = !current
        if (vehicleTrackVisible[token] == true) {
            lifecycleScope.launch { loadVehicleTrack(token) }
        } else {
            val poly = trackPolylines.remove(token)
            poly?.let { map.overlays.remove(it) }
            map.invalidate()
        }
        renderVehicleSidebar()
        updateTrackButton()
    }

    private fun toggleAllTracks() {
        val anyVisible = vehicleTrackVisible.values.any { it }
        if (anyVisible) {
            vehicleTrackVisible.clear()
            trackPolylines.values.forEach { map.overlays.remove(it) }
            trackPolylines.clear()
            map.invalidate()
        } else {
            vehicles.forEach { v ->
                val token = v.optString("token")
                if (token.isNotEmpty()) {
                    vehicleTrackVisible[token] = true
                    lifecycleScope.launch { loadVehicleTrack(token) }
                }
            }
        }
        renderVehicleSidebar()
        updateTrackButton()
    }

    private fun updateTrackButton() {
        val anyVisible = vehicleTrackVisible.values.any { it }
        btnToggleTrack.setColorFilter(
            Color.parseColor(if (anyVisible) "#ffd700" else "#7a9ab0")
        )
    }

    private fun updateViewOnlyBanner() {
        tvViewOnly.visibility = if (isViewOnly) View.VISIBLE else View.GONE
    }

    private suspend fun performTakeover() {
        val token = AppPrefs.vehicleToken
        if (token.isEmpty()) return
        val resp = ApiClient.takeoverDevice(token, AppPrefs.deviceId)
        if (resp?.optBoolean("ok") == true) {
            isViewOnly = false
            AppPrefs.isViewOnly = false
            runOnUiThread {
                updateViewOnlyBanner()
                Toast.makeText(this, "✅ Dieses Gerät ist jetzt aktiv", Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun centerOnCurrentPosition() {
        val pos = selfMarker?.position
        if (pos != null) {
            map.controller.animateTo(pos)
            map.controller.setZoom(18.0)
            followMode = true
            updateFollowButton()
            return
        }

        if (ContextCompat.checkSelfPermission(
                this, Manifest.permission.ACCESS_FINE_LOCATION
            ) == PackageManager.PERMISSION_GRANTED) {
            val lastGps = try {
                locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER)
            } catch (_: Exception) { null }
            val lastNet = try {
                locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER)
            } catch (_: Exception) { null }
            val last = listOfNotNull(lastGps, lastNet).maxByOrNull { it.time }
            if (last != null) {
                onGpsUpdate(
                    last.latitude, last.longitude,
                    if (last.hasSpeed()) last.speed.toDouble() else null
                )
                followMode = true
                updateFollowButton()
                return
            }
        }

        Toast.makeText(this, "⌛ Warte auf GPS-Fix…", Toast.LENGTH_SHORT).show()
    }

    // ── Sidebar Toggle ────────────────────────────────────────────────────────
    private fun toggleSidebar() {
        sidebarVisible = !sidebarVisible
        sidebar.visibility = if (sidebarVisible) View.VISIBLE else View.GONE
        btnToggleSidebar.setImageResource(
            if (sidebarVisible) R.drawable.ic_chevron_left
            else                R.drawable.ic_chevron_right
        )
    }

    // ── Exit ──────────────────────────────────────────────────────────────────
    private fun confirmExit() {
        AlertDialog.Builder(this)
            .setTitle("App beenden")
            .setMessage("GPS-Tracking stoppen und App schließen?")
            .setPositiveButton("Beenden") { _, _ -> exitApp() }
            .setNegativeButton("Abbrechen", null)
            .show()
    }

    private fun exitApp() {
        startService(Intent(this, GpsService::class.java).apply {
            action = GpsService.ACTION_STOP
        })
        finishAffinity()
    }

    // ── Karte ─────────────────────────────────────────────────────────────────
    private fun setupMap() {
        map.setTileSource(TileSourceFactory.MAPNIK)
        map.setMultiTouchControls(true)
        map.controller.setZoom(AppPrefs.lastMapZoom)
        map.controller.setCenter(GeoPoint(AppPrefs.lastMapLat, AppPrefs.lastMapLng))

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

    // ── Sammlungen laden ──────────────────────────────────────────────────────
    private suspend fun loadCollections() {
        runOnUiThread {
            tvCollectionStatus.text = "Lade Sammlungen…"
            tvCollectionStatus.visibility = View.VISIBLE
        }

        val arr: JSONArray? = ApiClient.getCollections()

        if (arr == null) {
            runOnUiThread {
                tvCollectionStatus.text = "⚠ Keine Antwort vom Server\n(Netzwerk prüfen)"
                tvCollectionStatus.setTextColor(Color.parseColor("#ff6b35"))
                tvCollectionStatus.visibility = View.VISIBLE
            }
            return
        }

        val firstItem = runCatching { arr.getJSONObject(0) }.getOrNull()
        if (firstItem?.has("_error") == true) {
            val msg = firstItem.optString("_error", "Unbekannter Fehler")
            runOnUiThread {
                tvCollectionStatus.text = "⚠ Server: $msg\n(Bitte neu einloggen)"
                tvCollectionStatus.setTextColor(Color.parseColor("#ff6b35"))
                tvCollectionStatus.visibility = View.VISIBLE
            }
            return
        }

        collections = (0 until arr.length()).mapNotNull {
            runCatching { arr.getJSONObject(it) }.getOrNull()
        }

        if (collections.isEmpty()) {
            runOnUiThread {
                tvCollectionStatus.text = "Keine aktiven Sammlungen"
                tvCollectionStatus.setTextColor(Color.parseColor("#4a5a6a"))
                spinnerCollection.adapter = ArrayAdapter(
                    this, android.R.layout.simple_spinner_item,
                    listOf("— Keine aktiven Sammlungen —")
                )
            }
            return
        }

        val names = collections.map { it.optString("name", "—") }

        runOnUiThread {
            tvCollectionStatus.visibility = View.GONE

            val adapter = ArrayAdapter(this, android.R.layout.simple_spinner_item, names)
                .apply { setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item) }
            spinnerCollection.adapter = adapter

            val savedIdx = collections.indexOfFirst {
                it.optString("id") == AppPrefs.activeCollectionId
            }.coerceAtLeast(0)

            spinnerCollection.onItemSelectedListener =
                object : AdapterView.OnItemSelectedListener {
                    override fun onItemSelected(p: AdapterView<*>, v: View?, pos: Int, id: Long) {
                        val cid = collections[pos].optString("id")
                        if (cid == AppPrefs.activeCollectionId &&
                            AppPrefs.vehicleToken.isNotEmpty()) return
                        AppPrefs.activeCollectionId = cid
                        lifecycleScope.launch { joinVehicle(cid) }
                    }
                    override fun onNothingSelected(p: AdapterView<*>) {}
                }

            if (savedIdx == spinnerCollection.selectedItemPosition) {
                val cid = collections[savedIdx].optString("id")
                AppPrefs.activeCollectionId = cid
                lifecycleScope.launch { joinVehicle(cid) }
            } else {
                spinnerCollection.setSelection(savedIdx)
            }
        }
    }

    // ── Fahrzeug-Join ─────────────────────────────────────────────────────────
    private suspend fun joinVehicle(collectionId: String) {
        if (isJoining) return
        isJoining = true

        runOnUiThread {
            tvStatus.text = "Verbinde…"
            btnCollect.isEnabled = false
        }

        val resp = ApiClient.vehicleJoin(AppPrefs.vehicleName, collectionId, AppPrefs.deviceId)
        isJoining = false

        if (resp == null) {
            runOnUiThread {
                tvStatus.text = "⚠ Verbindung fehlgeschlagen"
                tvCollectionStatus.text = "⚠ Server nicht erreichbar"
                tvCollectionStatus.setTextColor(Color.parseColor("#ff6b35"))
                tvCollectionStatus.visibility = View.VISIBLE
            }
            return
        }

        val error = resp.optString("error", "")
        if (error.isNotEmpty()) {
            runOnUiThread { tvStatus.text = "⚠ $error" }
            return
        }

        val token = resp.optString("token", "")
        if (token.isEmpty()) {
            runOnUiThread { tvStatus.text = "⚠ Kein Token erhalten" }
            return
        }

        AppPrefs.vehicleToken = token
        AppPrefs.isCollecting = resp.optBoolean("collecting", false)
        val serverName = resp.optString("name", "")
        if (serverName.isNotEmpty()) AppPrefs.vehicleName = serverName

        val conflict  = resp.optBoolean("conflict", false)
        val viewOnly  = resp.optBoolean("view_only", false)

        if (conflict) {
            runOnUiThread {
                AlertDialog.Builder(this@MainActivity)
                    .setTitle("⚠ Anderes Gerät aktiv")
                    .setMessage(
                        "Ein anderes Gerät sendet bereits GPS für dieses Fahrzeug.\n\n" +
                        "Dieses Gerät übernehmen? Das andere Gerät wird auf \"Nur Anzeigen\" gesetzt."
                    )
                    .setPositiveButton("Übernehmen") { _, _ ->
                        lifecycleScope.launch { performTakeover() }
                    }
                    .setNegativeButton("Nur Anzeigen") { _, _ ->
                        isViewOnly = true
                        AppPrefs.isViewOnly = true
                        updateViewOnlyBanner()
                    }
                    .setCancelable(false)
                    .show()
            }
        } else {
            isViewOnly = viewOnly
            AppPrefs.isViewOnly = viewOnly
        }

        runOnUiThread {
            tvVehicleName.text = AppPrefs.vehicleName
            btnCollect.isEnabled = !isViewOnly
            updateCollectingUI()
            updateViewOnlyBanner()
        }

        startPolling()
        startTrackPolling()
    }

    // ── Polling ───────────────────────────────────────────────────────────────
    private fun startPolling() {
        pollJob?.cancel()
        val cid = AppPrefs.activeCollectionId
        if (cid.isEmpty()) return

        pollJob = lifecycleScope.launch {
            while (isActive) {
                pollState(cid)
                delay(2500)
            }
        }
    }

    private suspend fun pollState(cid: String) {
        val resp = ApiClient.getState(cid) ?: return

        val tok = AppPrefs.vehicleToken
        if (tok.isNotEmpty()) lifecycleScope.launch {
            val pingResp = ApiClient.ping(tok, AppPrefs.deviceId)
            if (pingResp != null && pingResp.has("view_only")) {
                val newViewOnly = pingResp.optBoolean("view_only", false)
                val wasViewOnly = isViewOnly
                if (newViewOnly != wasViewOnly) {
                    isViewOnly = newViewOnly
                    AppPrefs.isViewOnly = newViewOnly
                    runOnUiThread {
                        updateViewOnlyBanner()
                        btnCollect.isEnabled = !isViewOnly
                        if (!wasViewOnly && isViewOnly)
                            Toast.makeText(this@MainActivity, "⚠ Dieses Gerät ist jetzt inaktiv", Toast.LENGTH_SHORT).show()
                    }
                    // Auto-Übernahme wenn altes Gerät offline ging
                    if (wasViewOnly && !newViewOnly) performTakeover()
                }
            }
        }

        val routesArr   = resp.optJSONArray("routes")  ?: return
        val vehiclesArr = resp.optJSONArray("vehicles") ?: JSONArray()

        routes   = (0 until routesArr.length()).map  { routesArr.getJSONObject(it)  }
        vehicles = (0 until vehiclesArr.length()).map { vehiclesArr.getJSONObject(it) }

        runOnUiThread {
            renderRoutes()
            renderVehicles()
            renderRouteSidebar()
            renderVehicleSidebar()
            keepMarkersOnTop()
            map.invalidate()
        }
    }

    // ── Vehicle Track Polling (pro Fahrzeug) ───────────────────────────────────
    private fun startTrackPolling() {
        trackJob?.cancel()
        val cid = AppPrefs.activeCollectionId
        if (cid.isEmpty()) return

        trackJob = lifecycleScope.launch {
            while (isActive) {
                vehicleTrackVisible.filter { it.value }.keys.toList().forEach { token ->
                    launch { loadVehicleTrack(token) }
                }
                delay(5000)
            }
        }
    }

    private suspend fun loadVehicleTrack(token: String) {
        val cid = AppPrefs.activeCollectionId
        if (token.isEmpty() || cid.isEmpty()) return
        val trackResp = ApiClient.getTrack(token, cid) ?: return

        val pointsArr = trackResp.optJSONArray("points")
            ?: trackResp.optJSONArray("track")
            ?: return
        if (pointsArr.length() < 2) return

        val myToken = AppPrefs.vehicleToken
        val col = if (token == myToken) "#ffd700" else "#00d4ff"

        runOnUiThread {
            val existing = trackPolylines.remove(token)
            existing?.let { map.overlays.remove(it) }

            val poly = Polyline(map).apply {
                outlinePaint.color = Color.parseColor(col)
                outlinePaint.strokeWidth = 3f
                outlinePaint.alpha = 180
            }
            for (i in 0 until pointsArr.length()) {
                val pt = pointsArr.getJSONArray(i)
                if (pt.length() >= 2) poly.addPoint(GeoPoint(pt.getDouble(0), pt.getDouble(1)))
            }
            map.overlays.add(poly)
            trackPolylines[token] = poly
            map.invalidate()
        }
    }

    // ── GPS-Update ────────────────────────────────────────────────────────────
    private fun onGpsUpdate(lat: Double, lng: Double, speed: Double?) {
        if (lat == 0.0 && lng == 0.0) return

        val now = System.currentTimeMillis()
        if (now - lastUpdateMs < MIN_UPDATE_INTERVAL_MS &&
            kotlin.math.abs(lat - myLat) < 0.0000005 &&
            kotlin.math.abs(lng - myLng) < 0.0000005) {
            return
        }
        lastUpdateMs = now

        myLat = lat
        myLng = lng

        val pt = GeoPoint(lat, lng)

        if (firstGpsFix) {
            firstGpsFix = false
            map.controller.setZoom(18.0)
            map.controller.setCenter(pt)
            AppPrefs.lastMapZoom = 18.0
            AppPrefs.lastMapLat  = lat
            AppPrefs.lastMapLng  = lng
        }

        if (selfMarker == null) {
            selfMarker = Marker(map).apply {
                title    = AppPrefs.vehicleName
                snippet  = selfSnippet()
                setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_CENTER)
                position = pt
                // Custom Marker: blauer Punkt mit Pfeil (Google Maps Style)
                setIcon(AppCompatResources.getDrawable(this@MainActivity, R.drawable.ic_self_marker))
                map.overlays.add(this)
            }
        } else {
            selfMarker!!.position = pt
            selfMarker!!.snippet  = selfSnippet()
        }

        if (followMode) map.controller.animateTo(pt)

        val kmh = speed?.let { it * 3.6 }
        if (kmh != null && kmh > 0.5) {
            tvSpeed.text       = "%.1f km/h".format(kmh)
            tvSpeed.visibility = View.VISIBLE
        } else {
            tvSpeed.text       = ""
            tvSpeed.visibility = View.GONE
        }

        tvStatus.text = "%.5f, %.5f".format(lat, lng)

        renderVehicleSidebar()

        keepMarkersOnTop()
        map.invalidate()
        updateBufferCount()
    }

    private fun selfSnippet(): String {
        val state = if (AppPrefs.isCollecting) "🟢 Sammeln" else "○ Bereit"
        return if (myLat != 0.0 || myLng != 0.0) {
            "$state\n%.5f, %.5f".format(myLat, myLng)
        } else state
    }

    private fun collectingSnippet() =
        if (AppPrefs.isCollecting) "🟢 Sammeln" else "○ Bereit"

    // ── Karte rendern ─────────────────────────────────────────────────────────
    private fun renderRoutes() {
        routePolylines.values.forEach { map.overlays.remove(it) }
        routePolylines.clear()

        routes.filter { it.optBoolean("visible", true) }.forEach { route ->
            val coords = route.optJSONArray("coordinates") ?: return@forEach
            val driven = route.optJSONArray("driven_segments")
            val n = coords.length()
            if (n < 2) return@forEach

            for (i in 0 until n - 1) {
                val c1  = coords.getJSONArray(i)
                val c2  = coords.getJSONArray(i + 1)
                val seg = driven?.optBoolean(i, false) ?: false
                val poly = Polyline(map).apply {
                    addPoint(GeoPoint(c1.getDouble(0), c1.getDouble(1)))
                    addPoint(GeoPoint(c2.getDouble(0), c2.getDouble(1)))
                    outlinePaint.color       = if (seg) Color.parseColor("#a8ff3e")
                                               else Color.parseColor("#ff4444")
                    outlinePaint.strokeWidth = 5f
                }
                map.overlays.add(poly)
                routePolylines["${route.optString("id")}_$i"] = poly
            }
        }
    }

    private fun renderVehicles() {
        val myToken      = AppPrefs.vehicleToken
        val activeTokens = vehicles.map { it.optString("token") }.toSet()

        vehicleMarkers.keys.toList().filter { it !in activeTokens }.forEach {
            map.overlays.remove(vehicleMarkers[it])
            vehicleMarkers.remove(it)
        }

        vehicles.forEach { v ->
            val token = v.optString("token")
            if (token == myToken) return@forEach
            if (v.isNull("lat") || v.isNull("lng")) {
                vehicleMarkers[token]?.let { map.overlays.remove(it) }
                vehicleMarkers.remove(token)
                return@forEach
            }
            val vLat = v.optDouble("lat")
            val vLng = v.optDouble("lng")
            val marker = vehicleMarkers.getOrPut(token) {
                Marker(map).also { map.overlays.add(it) }
            }
            marker.position = GeoPoint(vLat, vLng)
            marker.title    = v.optString("name", token)
            val state = if (v.optBoolean("collecting", false)) "🟢 Sammeln" else "○ Idle"
            marker.snippet  = "$state\n%.5f, %.5f".format(vLat, vLng)
            // Custom Marker für fremde Fahrzeuge: grüner Punkt
            marker.setIcon(AppCompatResources.getDrawable(this, R.drawable.ic_vehicle_marker))
        }
    }

    private fun keepMarkersOnTop() {
        vehicleMarkers.values.forEach {
            map.overlays.remove(it)
            map.overlays.add(it)
        }
        selfMarker?.let {
            map.overlays.remove(it)
            map.overlays.add(it)
        }
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────
    private fun renderRouteSidebar() {
        routeList.removeAllViews()
        routes.forEach { r ->
            val progress = r.optInt("progress", 0)
            val color    = runCatching {
                Color.parseColor(r.optString("color", "#00d4ff"))
            }.getOrDefault(Color.CYAN)

            val row = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                setPadding(0, 4, 0, 4)
                gravity = android.view.Gravity.CENTER_VERTICAL
            }
            val bar = View(this).apply {
                setBackgroundColor(color)
                layoutParams = LinearLayout.LayoutParams(4, 40)
                    .apply { setMargins(0, 2, 8, 2) }
            }
            val tv = TextView(this).apply {
                text     = "${r.optString("name")} — $progress%"
                setTextColor(Color.parseColor("#c8d4e0"))
                textSize = 12f
            }
            row.addView(bar); row.addView(tv)
            routeList.addView(row)
        }
    }

    private fun renderVehicleSidebar() {
        vehicleList.removeAllViews()
        val myToken = AppPrefs.vehicleToken

        vehicles.forEach { v ->
            val token = v.optString("token")
            val isMe  = token == myToken
            val trackOn = vehicleTrackVisible[token] ?: false

            val container = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(0, 4, 0, 4)
            }

            val header = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = android.view.Gravity.CENTER_VERTICAL
            }
            val dot = View(this).apply {
                val col = if (v.optBoolean("collecting")) "#00d4ff" else "#7a9ab0"
                setBackgroundColor(Color.parseColor(col))
                layoutParams = LinearLayout.LayoutParams(8, 8).apply { setMargins(0, 0, 8, 0) }
            }
            val name = TextView(this).apply {
                text = if (isMe) "▶ ${v.optString("name")} (Du)" else v.optString("name")
                setTextColor(Color.parseColor(if (isMe) "#ffd700" else "#c8d4e0"))
                textSize = 12f
                layoutParams = LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f)
            }
            val trackBtn = TextView(this).apply {
                text = "🛣"
                setTextColor(Color.parseColor(if (trackOn) "#ffd700" else "#4a5a6a"))
                textSize = 14f
                setPadding(8, 0, 0, 0)
                setOnClickListener { toggleVehicleTrack(token) }
            }
            header.addView(dot); header.addView(name); header.addView(trackBtn)
            container.addView(header)

            val (lat, lng) = when {
                isMe && (myLat != 0.0 || myLng != 0.0) -> myLat to myLng
                !v.isNull("lat") && !v.isNull("lng")   -> v.optDouble("lat") to v.optDouble("lng")
                else                                   -> 0.0 to 0.0
            }
            if (lat != 0.0 || lng != 0.0) {
                val coords = TextView(this).apply {
                    text = "    %.5f, %.5f".format(lat, lng)
                    setTextColor(Color.parseColor("#4a5a6a"))
                    textSize = 10f
                    typeface = Typeface.MONOSPACE
                }
                container.addView(coords)
            } else if (isMe) {
                val waiting = TextView(this).apply {
                    text = "    ⌛ kein GPS-Fix"
                    setTextColor(Color.parseColor("#ff6b35"))
                    textSize = 10f
                    typeface = Typeface.MONOSPACE
                }
                container.addView(waiting)
            }

            vehicleList.addView(container)
        }

        if (myToken.isNotEmpty() && vehicles.none { it.optString("token") == myToken }) {
            val container = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(0, 4, 0, 4)
            }
            val header = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = android.view.Gravity.CENTER_VERTICAL
            }
            val dot = View(this).apply {
                val col = if (AppPrefs.isCollecting) "#00d4ff" else "#7a9ab0"
                setBackgroundColor(Color.parseColor(col))
                layoutParams = LinearLayout.LayoutParams(8, 8).apply { setMargins(0, 0, 8, 0) }
            }
            val name = TextView(this).apply {
                text = "▶ ${AppPrefs.vehicleName} (Du)"
                setTextColor(Color.parseColor("#ffd700"))
                textSize = 12f
            }
            header.addView(dot); header.addView(name)
            container.addView(header)

            val coordsLine = TextView(this).apply {
                text = if (myLat != 0.0 || myLng != 0.0)
                    "    %.5f, %.5f".format(myLat, myLng)
                else "    ⌛ kein GPS-Fix"
                setTextColor(Color.parseColor(if (myLat != 0.0 || myLng != 0.0) "#4a5a6a" else "#ff6b35"))
                textSize = 10f
                typeface = Typeface.MONOSPACE
            }
            container.addView(coordsLine)
            vehicleList.addView(container)
        }
    }

    // ── Sammelmodus ───────────────────────────────────────────────────────────
    private fun toggleCollecting() {
        val tok = AppPrefs.vehicleToken
        if (tok.isEmpty()) {
            Toast.makeText(this, "Verbindung wird hergestellt…", Toast.LENGTH_SHORT).show()
            return
        }
        lifecycleScope.launch {
            val newState = !AppPrefs.isCollecting
            val r = ApiClient.setCollecting(tok, newState)
            if (r == null) {
                runOnUiThread {
                    Toast.makeText(this@MainActivity, "⚠ Keine Verbindung", Toast.LENGTH_SHORT).show()
                }
                return@launch
            }
            AppPrefs.isCollecting = r.optBoolean("collecting", newState)
            runOnUiThread {
                updateCollectingUI()
                selfMarker?.snippet = selfSnippet()
                restartGpsService()
            }
        }
    }

    private fun updateCollectingUI() {
        val on = AppPrefs.isCollecting
        btnCollect.text = if (on) "🟢 Am Sammeln" else "🔴 Nicht am Sammeln"
        btnCollect.setBackgroundColor(
            Color.parseColor(if (on) "#0a2010" else "#1a1010")
        )
    }

    // ── GPS-Service ───────────────────────────────────────────────────────────
    private fun restartGpsService() {
        startForegroundService(
            Intent(this, GpsService::class.java).apply { action = GpsService.ACTION_START }
        )
    }

    // ── Offline-Buffer ────────────────────────────────────────────────────────
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
        val fineGranted   = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        val coarseGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_COARSE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED

        if (!fineGranted || !coarseGranted) {
            ActivityCompat.requestPermissions(
                this,
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION
                ),
                REQ_LOCATION
            )
        } else {
            restartGpsService()
            startDirectLocationUpdates()
            requestBackgroundLocationIfNeeded()
            requestBatteryOptimizationExemption()
        }
    }

    private fun requestBackgroundLocationIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) return
        val bgGranted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.ACCESS_BACKGROUND_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        if (bgGranted) return

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            AlertDialog.Builder(this)
                .setTitle("Hintergrund-GPS erlauben")
                .setMessage(
                    "Damit GPS auch bei minimierter App funktioniert:\n\n" +
                    "Einstellungen → Berechtigungen → Standort → „Immer zulassen"
                )
                .setPositiveButton("Einstellungen öffnen") { _, _ ->
                    startActivity(
                        Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                            data = Uri.fromParts("package", packageName, null)
                        }
                    )
                }
                .setNegativeButton("Später", null)
                .show()
        } else {
            ActivityCompat.requestPermissions(
                this,
                arrayOf(Manifest.permission.ACCESS_BACKGROUND_LOCATION),
                REQ_BACKGROUND_LOCATION
            )
        }
    }

    private fun requestBatteryOptimizationExemption() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return
        val pm = getSystemService(PowerManager::class.java)
        if (pm.isIgnoringBatteryOptimizations(packageName)) return
        AlertDialog.Builder(this)
            .setTitle("Akkuoptimierung deaktivieren")
            .setMessage(
                "Für zuverlässiges GPS-Tracking im Hintergrund:\n" +
                "Akkuoptimierung für diese App deaktivieren?"
            )
            .setPositiveButton("Ja") { _, _ ->
                try {
                    startActivity(
                        Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                            data = Uri.parse("package:$packageName")
                        }
                    )
                } catch (_: Exception) {}
            }
            .setNegativeButton("Nein", null)
            .show()
    }

    override fun onRequestPermissionsResult(code: Int, perms: Array<String>, results: IntArray) {
        super.onRequestPermissionsResult(code, perms, results)
        when (code) {
            REQ_LOCATION -> {
                if (results.any { it == PackageManager.PERMISSION_GRANTED }) {
                    restartGpsService()
                    startDirectLocationUpdates()
                    requestBackgroundLocationIfNeeded()
                    requestBatteryOptimizationExemption()
                } else {
                    Toast.makeText(
                        this,
                        "GPS-Berechtigung verweigert – Tracking nicht möglich",
                        Toast.LENGTH_LONG
                    ).show()
                }
            }
            REQ_BACKGROUND_LOCATION -> {}
        }
    }
}
