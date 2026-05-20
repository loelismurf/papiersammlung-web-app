<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
auth_check();
$is_admin = is_admin();
$username = me_name();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Papiersammlung">
<meta name="theme-color" content="#00d4ff">
<link rel="manifest" href="manifest.json">
<title>Papiersammlung</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0c0f;--panel:#0f1216;--border:#1e2530;--border2:#2a3340;
  --text:#c8d4e0;--muted:#4a5a6a;--accent:#00d4ff;--green:#a8ff3e;
  --orange:#ff6b35;--red:#ff4444;--yellow:#ffd700;
  --font-ui:'Rajdhani',sans-serif;--font-mono:'JetBrains Mono',monospace;--r:4px
}
html,body{height:100%;margin:0;overflow:hidden;background:var(--bg);color:var(--text);font-family:var(--font-ui)}
#app{display:flex;width:100vw;height:100vh;overflow:hidden}

/* ── Sidebar ── */
#sidebar{width:300px;min-width:300px;flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;z-index:10}
#mob-handle{display:none}
#sidebar-inner{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}
#hdr{padding:10px 14px;border-bottom:1px solid var(--border);background:#0c0e12;display:flex;align-items:center;gap:8px;flex-shrink:0}
.logo{font-size:15px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);flex:1}
.logo span{color:var(--text)}
.lbl{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);font-family:var(--font-mono)}
#col-box{padding:8px 14px;border-bottom:1px solid var(--border);background:#0d1014;flex-shrink:0}
select{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:7px 10px;border-radius:var(--r);font-family:var(--font-ui);font-size:13px;outline:none}
select:focus{border-color:var(--accent)}

/* ── Fahrzeug-Box ── */
#id-box{padding:8px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
#v-connecting{color:var(--muted);font-size:11px;font-family:var(--font-mono);padding:4px 0}
#v-info{display:none}
.v-name-row{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.vn{font-size:14px;font-weight:700;color:var(--accent);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vs{font-size:10px;font-family:var(--font-mono);padding:2px 7px;border-radius:2px;text-transform:uppercase;flex-shrink:0}
.vs.idle{background:#1a2030;color:var(--muted)}.vs.driving{background:#0a2010;color:var(--green)}.vs.paused{background:#2a1a08;color:var(--orange)}
#rename-row{display:none;gap:4px;margin-bottom:5px;align-items:center}
#rename-in{flex:1;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:5px 8px;border-radius:var(--r);font-family:var(--font-ui);font-size:13px;outline:none}
#rename-in:focus{border-color:var(--accent)}
#btn-collect{width:100%;margin-top:4px;font-size:12px;padding:7px 10px;border-radius:var(--r);border:1px solid;background:transparent;cursor:pointer;font-family:var(--font-ui);font-weight:600;letter-spacing:1px;text-transform:uppercase;transition:all .2s}
#btn-collect.off{border-color:var(--muted);color:var(--muted)}
#btn-collect.off:hover{border-color:var(--orange);color:var(--orange)}
#btn-collect.on{border-color:var(--green);color:var(--green);background:rgba(168,255,62,.07)}
#btn-collect.on:hover{background:rgba(168,255,62,.15)}

#wake-banner{background:#0a1a10;border-bottom:1px solid var(--green);padding:5px 14px;font-size:11px;font-family:var(--font-mono);color:var(--green);display:none;flex-shrink:0;align-items:center;gap:8px}
#gps-bar{padding:5px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:7px;font-family:var(--font-mono);font-size:10px;background:#0c0e12;flex-shrink:0}
.gdot{width:6px;height:6px;border-radius:50%;background:var(--muted);flex-shrink:0}
.gdot.on{background:var(--green);box-shadow:0 0 4px var(--green);animation:pulse 1.5s infinite}
.gdot.err{background:var(--red)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

.sec-hdr{display:flex;align-items:center;gap:6px;padding:7px 14px 5px;flex-shrink:0}
.sec-hdr .lbl{flex:1}
.sec-hdr .bulk-btns{display:flex;gap:3px}
.bulk-btn{font-size:10px;font-family:var(--font-mono);padding:2px 7px;border-radius:2px;border:1px solid var(--border2);background:transparent;color:var(--muted);cursor:pointer;transition:all .15s;white-space:nowrap}
.bulk-btn:hover{border-color:var(--accent);color:var(--accent)}
.bulk-btn.active{border-color:var(--accent);color:var(--accent);background:rgba(0,212,255,.08)}

#routes-wrap{flex:1;overflow-y:auto;min-height:0}
#routes-wrap::-webkit-scrollbar{width:3px}
#routes-wrap::-webkit-scrollbar-thumb{background:var(--border2)}
#route-list{padding:4px 8px 8px}
.rc{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:9px 11px;position:relative;overflow:hidden;margin-bottom:6px}
.rc::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--rc,var(--muted))}
.rc.r-hidden{opacity:.35}
.rt{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.rn{font-size:13px;font-weight:700;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rb{font-size:9px;font-family:var(--font-mono);padding:2px 5px;border-radius:2px;text-transform:uppercase;flex-shrink:0}
.rb.pending{background:#1a2030;color:var(--muted)}.rb.active{background:#0a2010;color:var(--green)}.rb.completed{background:#0a1a2a;color:var(--accent)}.rb.paused{background:#2a1a08;color:var(--orange)}
.pb{height:2px;background:var(--border);border-radius:2px;margin-bottom:7px;overflow:hidden}
.pf{height:100%;border-radius:2px;transition:width .6s}
.rm{font-size:10px;color:var(--muted);font-family:var(--font-mono);margin-bottom:7px}
.ra{display:flex;gap:4px;flex-wrap:wrap}

#veh-panel{border-top:1px solid var(--border);background:#0c0e12;flex-shrink:0;max-height:200px;overflow-y:auto}
.vi{display:flex;align-items:center;gap:7px;font-size:12px;padding:6px 10px;border-bottom:1px solid var(--border)}
.vi:last-child{border-bottom:none}
.vi.v-hidden{opacity:.38}
.vd{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.vi-eye{font-size:12px;color:var(--muted);flex-shrink:0;cursor:pointer}
.vi-eye:hover{color:var(--accent)}
.vi-track{font-size:12px;flex-shrink:0;cursor:pointer;color:var(--muted);transition:color .15s}
.vi-track:hover{color:var(--accent)}
.vi-track.on{color:var(--accent)}

.btn{background:transparent;border:1px solid var(--border2);color:var(--text);padding:6px 11px;border-radius:var(--r);font-family:var(--font-ui);font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;cursor:pointer;transition:all .2s;white-space:nowrap;text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.p{border-color:var(--accent);color:var(--accent)}.btn.p:hover{background:var(--accent);color:var(--bg)}
.btn.g{border-color:var(--green);color:var(--green)}.btn.g:hover{background:var(--green);color:var(--bg)}
.btn.d{border-color:var(--orange);color:var(--orange)}.btn.d:hover{background:var(--orange);color:var(--bg)}
.btn.s{padding:3px 7px;font-size:10px}

/* ── Karte ── */
#mc{flex:1;position:relative;min-width:0}
#map{position:absolute;top:0;left:0;right:0;bottom:0}
.leaflet-marker-icon{transition:transform 0ms linear}
.leaflet-marker-icon.animated{transition:transform 2200ms linear}
#topbar{position:absolute;top:10px;left:10px;right:10px;display:flex;gap:8px;z-index:500;pointer-events:none}
.mb{background:rgba(10,12,15,.88);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:var(--r);padding:6px 11px;font-size:11px;font-family:var(--font-mono);pointer-events:auto}
.mb .ml{color:var(--muted);font-size:9px;letter-spacing:2px;text-transform:uppercase}
.mb .mv{color:var(--text);font-weight:500;margin-top:1px}
#map-actions{position:absolute;bottom:58px;right:10px;z-index:500;display:flex;flex-direction:column;gap:5px}
.map-btn{background:rgba(10,12,15,.92);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:var(--r);padding:8px 10px;font-size:15px;cursor:pointer;line-height:1;transition:border-color .2s;display:none}
.map-btn:hover{border-color:var(--accent)}

/* ── Nicht-Sammeln-Warnung auf Karte ── */
#no-collect-warn{
  position:absolute;top:58px;left:50%;transform:translateX(-50%);
  z-index:600;display:none;
  background:rgba(255,107,53,.92);backdrop-filter:blur(6px);
  border:1px solid var(--orange);border-radius:var(--r);
  padding:8px 18px;font-size:12px;font-family:var(--font-mono);
  color:#fff;cursor:pointer;white-space:nowrap;
  animation:warnpulse 2s infinite;
  text-align:center;
}
@keyframes warnpulse{0%,100%{box-shadow:0 0 0 0 rgba(255,107,53,.4)}50%{box-shadow:0 0 0 8px rgba(255,107,53,0)}}

#pi{position:absolute;bottom:18px;right:10px;z-index:500;display:flex;align-items:center;gap:6px;background:rgba(10,12,15,.88);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:var(--r);padding:6px 11px;font-size:10px;font-family:var(--font-mono)}
.pd{width:6px;height:6px;border-radius:50%;background:var(--muted)}
.pd.on{background:var(--green);box-shadow:0 0 4px var(--green)}
#notifs{position:absolute;top:58px;right:10px;z-index:600;display:flex;flex-direction:column;gap:5px;max-width:260px}
.notif{background:rgba(10,12,15,.94);border:1px solid var(--border2);border-left:3px solid var(--accent);border-radius:var(--r);padding:8px 11px;font-size:12px;animation:si .3s ease}
.notif.g{border-left-color:var(--green)}.notif.w{border-left-color:var(--orange)}
@keyframes si{from{opacity:0;transform:translateX(12px)}to{opacity:1;transform:translateX(0)}}
#legend{position:absolute;bottom:18px;left:10px;z-index:500;background:rgba(10,12,15,.88);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:var(--r);padding:7px 11px;font-size:10px;font-family:var(--font-mono);color:var(--muted);line-height:1.9}
.leg-row{display:flex;align-items:center;gap:5px}
.leg-line{height:3px;width:18px;border-radius:2px}
#no-coll{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;z-index:400;color:var(--muted);display:none}
#no-coll .big{font-size:44px;margin-bottom:10px}
#no-coll p{font-family:var(--font-mono);font-size:12px;line-height:1.8}

/* ── Mobile: Sidebar unten ── */
@media(max-width:700px){
  #app{flex-direction:column}
  #mc{order:1;flex:1;min-height:0;position:relative}
  #sidebar{order:2;width:100%;min-width:0;height:54vh;flex-shrink:0;border-right:none;border-top:1px solid var(--border);transition:height .25s ease;overflow:hidden}
  #sidebar.collapsed{height:44px}

  /* Handle-Leiste am OBEREN Rand der Sidebar (= sichtbare Unterseite des Screens) */
  #mob-handle{
    display:flex;align-items:center;justify-content:space-between;
    height:44px;min-height:44px;
    background:#0c0e12;
    border-bottom:1px solid var(--border);
    cursor:pointer;flex-shrink:0;
    padding:0 16px;
    user-select:none;-webkit-user-select:none;
    position:sticky;top:0;z-index:1;
  }
  .mob-handle-left{display:flex;align-items:center;gap:8px;font-size:12px;font-family:var(--font-mono);color:var(--muted)}
  .mob-handle-right{font-size:18px;color:var(--muted);transition:transform .25s}
  #sidebar.collapsed .mob-handle-right{transform:rotate(180deg)}
  #sidebar.collapsed .mob-handle-left{color:var(--accent)}
  #mob-handle:hover .mob-handle-left{color:var(--accent)}
  #mob-handle:hover .mob-handle-right{color:var(--accent)}

  /* Floating-Button auf Karte wenn Sidebar kollabiert – nie verpassbar */
  #mob-open-btn{
    display:none;position:absolute;bottom:60px;right:10px;z-index:550;
    background:rgba(10,12,15,.92);backdrop-filter:blur(6px);
    border:1px solid var(--accent);border-radius:var(--r);
    padding:10px 14px;font-size:20px;cursor:pointer;
    box-shadow:0 0 12px rgba(0,212,255,.3);
  }

  #sidebar-inner{overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;flex:1}
  #sidebar-inner::-webkit-scrollbar{width:3px}
  #sidebar-inner::-webkit-scrollbar-thumb{background:var(--border2)}
  #routes-wrap{overflow:visible!important;flex:none!important}
  #veh-panel{max-height:none!important;overflow:visible!important}
  /* Warnung: nicht unter dem Handle verstecken */
  #no-collect-warn{top:10px;}
}
@media(max-width:700px) and (display-mode:standalone){
  /* PWA: safe-area Puffer für Home-Indicator */
  #mob-handle{padding-bottom:env(safe-area-inset-bottom,0)}
}
</style>
</head>
<body>
<div id="app">
<aside id="sidebar">

  <!-- Mobile: Anfass-Leiste (immer sichtbar, auch kollabiert) -->
  <div id="mob-handle" onclick="toggleSidebar()">
    <div class="mob-handle-left">
      <span id="mob-status-icon">☰</span>
      <span id="mob-label">Papiersammlung</span>
    </div>
    <div class="mob-handle-right">▲</div>
  </div>

  <div id="sidebar-inner">
    <div id="hdr">
      <div class="logo">♻ Papier<span>sammlung</span></div>
      <?php if($is_admin): ?><a class="btn s p" href="admin.php">Admin</a><?php endif; ?>
      <a class="btn s" href="logout.php" title="<?=htmlspecialchars($username)?> abmelden">🚪</a>
    </div>

    <div id="col-box">
      <div class="lbl" style="margin-bottom:5px">Sammlung</div>
      <select id="col-select"><option value="">Lade...</option></select>
    </div>

    <!-- Fahrzeug (auto-verbunden, 1 pro User) -->
    <div id="id-box">
      <div class="lbl" style="margin-bottom:5px">Mein Fahrzeug</div>
      <div id="v-connecting">Verbinde...</div>
      <div id="v-info">
        <div class="v-name-row">
          <div class="vn" id="disp-vname">—</div>
          <button class="btn s" id="btn-rename-tog" title="Umbenennen" onclick="toggleRename()">✏</button>
          <div class="vs idle" id="disp-vs">Inaktiv</div>
        </div>
        <div id="rename-row">
          <input id="rename-in" type="text" maxlength="50" placeholder="Neuer Name...">
          <button class="btn s p" onclick="confirmRename()">✓</button>
          <button class="btn s" onclick="cancelRename()">✕</button>
        </div>
        <button id="btn-collect" class="off" onclick="toggleCollecting()">🔴 Nicht am Sammeln</button>
        <!-- Push-Notification Status (nur sichtbar wenn relevant) -->
        <div id="push-row" style="display:none;margin-top:5px;font-size:10px;font-family:var(--font-mono);color:var(--muted);align-items:center;gap:6px">
          <span id="push-status-icon">🔔</span>
          <span id="push-status-text">Hintergrund-Push inaktiv</span>
          <button id="push-enable-btn" class="btn s" style="display:none;margin-left:auto" onclick="requestPushPermission()">Erlauben</button>
        </div>
      </div>
    </div>

    <div id="wake-banner">📍 GPS aktiv – Bildschirm bleibt an</div>

    <div id="gps-bar">
      <div class="gdot" id="gdot"></div>
      <span id="gps-txt" style="color:var(--muted)">GPS inaktiv</span>
      <span id="speed-badge" style="display:none;margin-left:auto;background:#1a2030;color:var(--accent);font-size:10px;font-family:var(--font-mono);padding:1px 6px;border-radius:2px;white-space:nowrap"></span>
    </div>

    <!-- Routen -->
    <div class="sec-hdr" style="border-top:1px solid var(--border)">
      <span class="lbl">Routen</span>
      <div class="bulk-btns">
        <button class="bulk-btn" onclick="showAllRoutes()">👁 Alle</button>
        <button class="bulk-btn" onclick="hideAllRoutes()">🚫 Keine</button>
      </div>
    </div>
    <div id="routes-wrap"><div id="route-list"></div></div>

    <!-- Fahrzeuge -->
    <div class="sec-hdr" style="border-top:1px solid var(--border)">
      <span class="lbl">Fahrzeuge</span>
      <div class="bulk-btns">
        <button class="bulk-btn" id="btn-only-me" onclick="selectOnlyMe()">👤 Ich</button>
        <button class="bulk-btn active" id="btn-all-veh" onclick="selectAllVehicles()">👥 Alle</button>
        <button class="bulk-btn" onclick="deselectAllVehicles()">🚫</button>
      </div>
    </div>
    <div id="veh-panel"><div id="veh-list"></div></div>
  </div><!-- /sidebar-inner -->
</aside>

<!-- Floating-Button zum Aufklappen (nur Mobile, nur wenn kollabiert) -->
<div id="mob-open-btn" onclick="toggleSidebar()" title="Menü öffnen">☰</div>

<div id="mc">
  <div id="map"></div>
  <div id="topbar">
    <div class="mb"><div class="ml">Fahrzeuge</div><div class="mv" id="sv">0</div></div>
    <div class="mb"><div class="ml">Aktiv</div><div class="mv" id="sa">0</div></div>
    <div class="mb"><div class="ml">Erledigt</div><div class="mv" id="sd">0</div></div>
  </div>
  <!-- Warnung: Fahrzeug bewegt sich, aber Sammelmodus ist aus -->
  <div id="no-collect-warn" onclick="handleWarnClick()">
    ⚠ Nicht am Sammeln! Antippen zum Aktivieren.
  </div>
  <div id="notifs"></div>
  <div id="map-actions">
    <button class="map-btn" id="btn-locate" title="Zu meiner Position">📍</button>
    <button class="map-btn" id="btn-fit-all" title="Alle Routen">🗺</button>
  </div>
  <div id="legend">
    <div class="leg-row"><div class="leg-line" style="background:var(--green)"></div>Abgefahren</div>
    <div class="leg-row"><div class="leg-line" style="background:var(--red)"></div>Noch offen</div>
    <div class="leg-row"><div class="leg-line" style="background:var(--muted);opacity:.5"></div>Ausstehend</div>
  </div>
  <div id="no-coll">
    <div class="big">📦</div>
    <p>Keine aktive Sammlung.<br>
    <?php if($is_admin): ?><a href="admin.php" style="color:var(--accent)">→ Im Admin-Panel erstellen</a><?php else: ?>Bitte Admin kontaktieren.<?php endif; ?></p>
  </div>
  <div id="pi"><div class="pd" id="pd"></div><span id="pt">Verbinde...</span></div>
</div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const API        = 'api.php';
const IS_ADMIN   = <?= $is_admin ? 'true' : 'false' ?>;
const POLL_MS    = <?= POLL_INTERVAL ?>;
const OSRM       = 'https://router.project-osrm.org';
const MY_USERNAME = <?= json_encode($username) ?>;

// ── State ─────────────────────────────────────────────────────────────────────
let myToken      = localStorage.getItem('ps_token') || null;
let myName       = localStorage.getItem('ps_name')  || null;
let isJoined     = false;
let isCollecting = false;
let currentColId = null;
let myLat = null, myLng = null, mySpeed = null, wakeLock = null;

// iOS-Erkennung: alle Browser auf iOS nutzen WebKit → kein Hintergrund-GPS möglich.
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
           || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
let routes = [], vehicles = [];
const routeLayers = {}, vehicleMarkers = {}, vehicleMarkerProps = {};

// ── Fahrspur pro Fahrzeug ─────────────────────────────────────────────────────
const trackLayers   = {};  // token → Leaflet polyline
const trackVisible  = {};  // token → bool
const trackLoading  = new Set();

async function loadTrack(token) {
  if (!currentColId || trackLoading.has(token)) return;
  trackLoading.add(token);
  try {
    const qs = new URLSearchParams({action:'vehicle_track', token, collection_id:currentColId});
    const data = await (await fetch(`${API}?${qs}`)).json();
    if (data.track && data.track.length > 1) {
      if (trackLayers[token]) map.removeLayer(trackLayers[token]);
      const veh = vehicles.find(v=>v.token===token);
      const col = veh?.token===myToken ? '#ffd700' : (veh?.collecting ? '#00d4ff' : '#4a5a6a');
      trackLayers[token] = L.polyline(data.track, {
        color: col, weight:3, opacity:0.65, dashArray:'6,4', lineCap:'round'
      }).addTo(map);
    }
  } catch(e){}
  trackLoading.delete(token);
}
function toggleTrack(token) {
  trackVisible[token] = !trackVisible[token];
  if (trackVisible[token]) {
    loadTrack(token);
  } else {
    if (trackLayers[token]) { map.removeLayer(trackLayers[token]); delete trackLayers[token]; }
  }
  renderVehicleList();
}
// Sichtbare Tracks alle 10s neu laden (neue Punkte)
let trackRefreshCounter = 0;
function maybeRefreshTracks() {
  trackRefreshCounter++;
  if (trackRefreshCounter % 4 !== 0) return; // alle ~10s
  Object.keys(trackVisible).forEach(t => { if (trackVisible[t]) loadTrack(t); });
}

// ── Mobile Sidebar ─────────────────────────────────────────────────────────────
let sidebarCollapsed = false;
function toggleSidebar(){
  sidebarCollapsed = !sidebarCollapsed;
  document.getElementById('sidebar').classList.toggle('collapsed', sidebarCollapsed);
  // Floating-Button anzeigen wenn kollabiert (nur auf Mobile via CSS-Klasse)
  const openBtn = document.getElementById('mob-open-btn');
  if (openBtn) openBtn.style.display = sidebarCollapsed ? 'block' : 'none';
  // Label aktualisieren
  document.getElementById('mob-label').textContent = sidebarCollapsed ? 'Menü öffnen' : 'Papiersammlung';
}
// Floating-Button immer korrekt initialisieren
window.addEventListener('load', () => {
  const openBtn = document.getElementById('mob-open-btn');
  if (openBtn) openBtn.style.display = 'none'; // initial nicht kollabiert
});

// ── Nicht-Sammeln-Warnung ─────────────────────────────────────────────────────
const WARN_SPEED_KMH = 2.0; // km/h Schwelle für Warnung
let warnVisible = false;
function updateCollectingWarn(speedKmh) {
  const warn = document.getElementById('no-collect-warn');
  const shouldWarn = isJoined && !isCollecting && speedKmh >= WARN_SPEED_KMH;
  if (shouldWarn !== warnVisible) {
    warnVisible = shouldWarn;
    warn.style.display = shouldWarn ? 'block' : 'none';
  }
}
function handleWarnClick() {
  // Sidebar öffnen (falls mobil-kollabiert) und direkt Sammelmodus aktivieren
  if (sidebarCollapsed) toggleSidebar();
  toggleCollecting();
}

// ── Routen-Sichtbarkeit ────────────────────────────────────────────────────────
const localHiddenRoutes = new Set();
function isRouteVisible(r){ return r.visible && !localHiddenRoutes.has(r.id); }
function toggleRouteLocal(id){ localHiddenRoutes.has(id)?localHiddenRoutes.delete(id):localHiddenRoutes.add(id); renderRoutes();renderRouteList(); }
function showAllRoutes(){ localHiddenRoutes.clear(); renderRoutes();renderRouteList(); }
function hideAllRoutes(){ routes.forEach(r=>localHiddenRoutes.add(r.id)); renderRoutes();renderRouteList(); }

// ── Fahrzeug-Selektion ────────────────────────────────────────────────────────
const deselectedVehicles = new Set();
function isVehicleVisible(v){ return !deselectedVehicles.has(v.token); }
function toggleVehicle(token){ deselectedVehicles.has(token)?deselectedVehicles.delete(token):deselectedVehicles.add(token); updateVehBulkBtns();renderVehicleMarkers();renderVehicleList(); }
function selectOnlyMe(){ deselectedVehicles.clear(); vehicles.forEach(v=>{if(v.token!==myToken)deselectedVehicles.add(v.token);}); updateVehBulkBtns();renderVehicleMarkers();renderVehicleList(); }
function selectAllVehicles(){ deselectedVehicles.clear(); updateVehBulkBtns();renderVehicleMarkers();renderVehicleList(); }
function deselectAllVehicles(){ vehicles.forEach(v=>deselectedVehicles.add(v.token)); updateVehBulkBtns();renderVehicleMarkers();renderVehicleList(); }
function updateVehBulkBtns(){
  const allSel=deselectedVehicles.size===0;
  const onlyMe=myToken&&!deselectedVehicles.has(myToken)&&vehicles.filter(v=>v.token!==myToken).every(v=>deselectedVehicles.has(v.token));
  document.getElementById('btn-all-veh').classList.toggle('active',allSel);
  document.getElementById('btn-only-me').classList.toggle('active',onlyMe&&!allSel);
}

// ── Fahrzeug-Snap ─────────────────────────────────────────────────────────────
const vehicleSnap={}, snapInFlight=new Set();
const SNAP_THRESHOLD=0.00005;
function updateVehicleSnaps(){
  vehicles.forEach(v=>{
    if(v.lat===null||v.lng===null||snapInFlight.has(v.token)) return;
    const c=vehicleSnap[v.token];
    if(c&&Math.abs(c.srcLat-v.lat)<SNAP_THRESHOLD&&Math.abs(c.srcLng-v.lng)<SNAP_THRESHOLD) return;
    snapInFlight.add(v.token);
    fetch(`${OSRM}/nearest/v1/driving/${v.lng},${v.lat}?number=1`)
      .then(r=>r.json()).then(d=>{
        if(d.code==='Ok'&&d.waypoints?.[0]){const[lng,lat]=d.waypoints[0].location;vehicleSnap[v.token]={srcLat:v.lat,srcLng:v.lng,lat,lng};}
        else vehicleSnap[v.token]={srcLat:v.lat,srcLng:v.lng,lat:v.lat,lng:v.lng};
        snapInFlight.delete(v.token);renderVehicleMarkers();
      }).catch(()=>{vehicleSnap[v.token]={srcLat:v.lat,srcLng:v.lng,lat:v.lat,lng:v.lng};snapInFlight.delete(v.token);});
  });
}

// ── Service Worker + Background GPS ──────────────────────────────────────────
// ── Service Worker + Background-GPS ──────────────────────────────────────────
// 3 Schichten: keepalive-fetch + Seiten-Timer + SW-Notification (wenn erlaubt)
// Web Push (auch bei geschlossenem Browser): via VAPID, Trigger über state-Polling

let swRegistration = null;
let lastGpsSentAt  = 0;
let bgPageTimer    = null;

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js')
    .then(reg => {
      swRegistration = reg;
      // Push-Status anzeigen sobald SW bereit
      updatePushStatus();
    })
    .catch(() => {});
  navigator.serviceWorker.addEventListener('message', e => {
    if (e.data?.type === 'REQUEST_GPS' && myLat !== null && isJoined) sendGPS(myLat, myLng);
  });
}

function getSwActive() { return swRegistration?.active ?? null; }

// Seiten-Timer: Fallback wenn watchPosition im HG aufhört zu feuern
function startBgPageTimer() {
  if (bgPageTimer) return;
  bgPageTimer = setInterval(() => {
    if (myLat !== null && isJoined && isCollecting && Date.now() - lastGpsSentAt > 8000) {
      sendGPS(myLat, myLng);
    }
  }, 6000);
}
function stopBgPageTimer() {
  if (bgPageTimer) { clearInterval(bgPageTimer); bgPageTimer = null; }
}

document.addEventListener('visibilitychange', async () => {
  if (document.visibilityState === 'hidden' && isJoined && isCollecting) {
    startBgPageTimer();
    // SW-Notification nur wenn Berechtigung bereits vorhanden (nie selbst anfragen!)
    if (Notification?.permission === 'granted') {
      getSwActive()?.postMessage({ type: 'BG_START' });
    }
  } else if (document.visibilityState === 'visible') {
    stopBgPageTimer();
    getSwActive()?.postMessage({ type: 'BG_STOP' });
    if (isJoined && !wakeLock) await requestWakeLock();
  }
});

// ── Push-Notification Berechtigung & Subscription ────────────────────────────
// WICHTIG: Notification.requestPermission() NUR nach explizitem User-Tap aufrufen!
// Automatischer Aufruf → Browser-Fehlerdialog (besonders auf Samsung Internet).

function updatePushStatus() {
  const row  = document.getElementById('push-row');
  const icon = document.getElementById('push-status-icon');
  const text = document.getElementById('push-status-text');
  const btn  = document.getElementById('push-enable-btn');
  if (!('Notification' in window) || !('PushManager' in window)) return;
  row.style.display = 'flex';
  const perm = Notification.permission;
  if (perm === 'granted') {
    icon.textContent = '🔔'; text.textContent = 'Push: Aktiv'; text.style.color = 'var(--green)';
    btn.style.display = 'none';
    subscribeToPush();
  } else if (perm === 'denied') {
    icon.textContent = '🔕'; text.textContent = 'Push gesperrt (Browser-Einstellungen)'; text.style.color = 'var(--muted)';
    btn.style.display = 'none';
  } else {
    icon.textContent = '🔔'; text.textContent = 'Push inaktiv –'; text.style.color = 'var(--muted)';
    btn.style.display = 'inline-block';
  }
}

async function requestPushPermission() {
  // Einzige Stelle wo requestPermission() aufgerufen wird – direkt vom Button-Tap
  if (!('Notification' in window)) return;
  try {
    const perm = await Notification.requestPermission();
    updatePushStatus();
    if (perm === 'granted') {
      notify('🔔 Push-Benachrichtigungen aktiviert – Hintergrund-GPS aktiv','g');
      subscribeToPush();
    } else if (perm === 'denied') {
      notify('Push-Benachrichtigungen gesperrt. Bitte in Browser-Einstellungen freigeben.','w');
    }
  } catch(e) {}
}

async function subscribeToPush() {
  if (!swRegistration || Notification?.permission !== 'granted') return;
  try {
    const vapidRes = await apiGet('get_vapid_key');
    if (!vapidRes?.key) return; // VAPID-Keys noch nicht generiert
    const applicationServerKey = urlB64ToUint8Array(vapidRes.key);
    let sub = await swRegistration.pushManager.getSubscription();
    if (!sub) {
      sub = await swRegistration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey });
    }
    const j = sub.toJSON();
    await api('push_subscribe', { endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth });
  } catch(e) {}
}

function urlB64ToUint8Array(b64) {
  const pad = '='.repeat((4 - b64.length % 4) % 4);
  const raw = atob((b64 + pad).replace(/-/g,'+').replace(/_/g,'/'));
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

function sendGPS(lat, lng) {
  lastGpsSentAt = Date.now();
  const payload = { token:myToken, lat, lng, collection_id:currentColId, speed:mySpeed };
  const c = vehicleSnap[myToken];
  if (c && Math.abs(c.srcLat-lat)<0.002 && Math.abs(c.srcLng-lng)<0.002) {
    payload.snap_lat = c.lat; payload.snap_lng = c.lng;
  }

  // Schicht 1: keepalive:true – funktioniert auch im Hintergrund in ALLEN modernen
  // Chromium-Browsern (Chrome, Samsung Internet, Ecosia, Edge, Brave, Opera)
  // und Firefox. Der Request wird auch nach Tab-Wechsel noch abgeschlossen.
  fetch(`${API}?action=vehicle_position`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    keepalive: true
  }).catch(() => {});

  // Schicht 3: SW über letzten GPS-Stand informieren (Fallback wenn oben fehlschlägt)
  const sw = getSwActive();
  if (sw) sw.postMessage({ type: 'GPS_UPDATE', ...payload });

  // OSRM-Snap im Hintergrund aktualisieren
  if (!c || Math.abs(c.srcLat-lat)>0.0001 || Math.abs(c.srcLng-lng)>0.0001) {
    fetch(`${OSRM}/nearest/v1/driving/${lng},${lat}?number=1`)
      .then(r=>r.json()).then(d=>{
        if(d.code==='Ok'&&d.waypoints?.[0]){const[sl,slt]=d.waypoints[0].location;vehicleSnap[myToken]={srcLat:lat,srcLng:lng,lat:slt,lng:sl};}
      }).catch(()=>{});
  }
}

// ── Map ───────────────────────────────────────────────────────────────────────
const map=L.map('map',{center:[47.3769,8.5417],zoom:13,zoomControl:false});
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(map);
L.control.zoom({position:'bottomleft'}).addTo(map);
document.getElementById('btn-locate').addEventListener('click',()=>{if(myLat!==null)map.setView([myLat,myLng],17);else notify('Noch keine GPS-Position','w');});
document.getElementById('btn-fit-all').addEventListener('click',()=>{
  const all=routes.filter(r=>isRouteVisible(r)&&r.coordinates.length).flatMap(r=>r.coordinates);
  if(all.length)map.fitBounds(L.latLngBounds(all),{padding:[30,30]});else notify('Keine sichtbaren Routen','w');
});

// ── Wake Lock ─────────────────────────────────────────────────────────────────
async function requestWakeLock(){
  // WakeLock API: braucht keine User-Geste (nur sichtbares Dokument)
  if('wakeLock' in navigator){
    try{
      wakeLock = await navigator.wakeLock.request('screen');
      wakeLock.addEventListener('release',()=>{ wakeLock=null; _updateBanner(); });
    }catch(e){}
  }
  _updateBanner();
}
function _updateBanner(){
  if(!wakeLock){ _hideBanner(); return; }
  document.getElementById('wake-banner').textContent = '📍 GPS aktiv – Bildschirm bleibt an';
  document.getElementById('wake-banner').style.display='flex';
}
function _hideBanner(){ document.getElementById('wake-banner').style.display='none'; }
async function releaseWakeLock(){
  if(wakeLock){ try{ await wakeLock.release(); }catch(e){} wakeLock=null; }
  _hideBanner();
}

// ── API ───────────────────────────────────────────────────────────────────────
async function api(action,body={}){
  try{const r=await fetch(`${API}?action=${action}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return await r.json();}
  catch(e){return{error:e.message};}
}
async function apiGet(action,params={}){
  try{const qs=new URLSearchParams({action,...params}).toString();return await(await fetch(`${API}?${qs}`)).json();}
  catch(e){return[];}
}

// ── Collections ───────────────────────────────────────────────────────────────
async function loadCollections(){
  const cols=await apiGet(IS_ADMIN?'collections_all':'collections_active');
  const sel=document.getElementById('col-select');
  if(!Array.isArray(cols)||!cols.length){
    sel.innerHTML='<option value="">Keine aktive Sammlung</option>';
    document.getElementById('no-coll').style.display='block';
    document.getElementById('pt').textContent='Keine Sammlung';return;
  }
  sel.innerHTML=cols.map(c=>{
    const d=new Date(c.collection_date+'T00:00:00').toLocaleDateString('de-CH');
    const st={draft:' [Entwurf]',completed:' [Abgeschlossen]'}[c.status]||'';
    return `<option value="${c.id}">${c.name} – ${d}${st}</option>`;
  }).join('');
  const saved=localStorage.getItem('ps_col');
  if(saved&&cols.find(c=>c.id===saved))sel.value=saved;
  currentColId=sel.value||cols[0].id;sel.value=currentColId;
  startPolling();
  await autoJoin(currentColId);
}

document.getElementById('col-select').addEventListener('change',async function(){
  currentColId=this.value;localStorage.setItem('ps_col',currentColId);
  Object.values(routeLayers).flat().forEach(l=>map.removeLayer(l));
  Object.values(vehicleMarkers).forEach(m=>map.removeLayer(m));
  Object.values(trackLayers).forEach(l=>map.removeLayer(l));
  Object.keys(routeLayers).forEach(k=>delete routeLayers[k]);
  Object.keys(vehicleMarkers).forEach(k=>delete vehicleMarkers[k]);
  Object.keys(trackLayers).forEach(k=>delete trackLayers[k]);
  routes=[];vehicles=[];renderRouteList();renderVehicleList();updateStats();
  if(currentColId){pollState();if(isJoined&&myToken)await api('vehicle_join',{name:myName,collection_id:currentColId});}
});

// ── Auto-Join ─────────────────────────────────────────────────────────────────
async function autoJoin(colId){
  document.getElementById('v-connecting').style.display='block';
  document.getElementById('v-info').style.display='none';
  const r=await api('vehicle_join',{name:myName||MY_USERNAME,collection_id:colId});
  if(r.error){notify('Verbindungsfehler: '+r.error,'w');return;}
  myToken=r.token; myName=r.name; isCollecting=r.collecting||false;
  localStorage.setItem('ps_token',myToken); localStorage.setItem('ps_name',myName);
  isJoined=true;
  document.getElementById('v-connecting').style.display='none';
  document.getElementById('v-info').style.display='block';
  document.getElementById('disp-vname').textContent=myName;
  setMyStatus(r.status||'idle'); updateCollectingUI(); showMapBtns(); startGPS();
  await requestWakeLock();
}

// ── Sammelmodus ───────────────────────────────────────────────────────────────
function updateCollectingUI(){
  const btn=document.getElementById('btn-collect');
  if(isCollecting){btn.textContent='🟢 Am Sammeln';btn.className='on';}
  else{btn.textContent='🔴 Nicht am Sammeln';btn.className='off';}
  // Mobile Handle: Sammelmodus-Indikator
  document.getElementById('mob-status-icon').textContent = isCollecting ? '🟢' : '☰';
}

async function toggleCollecting(){
  if(!myToken)return;
  const r=await api('vehicle_set_collecting',{token:myToken,collecting:!isCollecting});
  if(r.error){notify(r.error,'w');return;}
  isCollecting=r.collecting; setMyStatus(r.status||'idle'); updateCollectingUI();
  if(isCollecting){
    notify('🟢 Sammelmodus aktiviert – GPS wird aufgezeichnet','g');
    if(isIOS) notify('📱 iOS: App offen lassen – Hintergrund-GPS nicht möglich.','w');
    await requestWakeLock();
    updatePushStatus(); // Push-Status aktualisieren
  } else {
    notify('🔴 Sammelmodus deaktiviert','w');
    stopBgPageTimer();
    getSwActive()?.postMessage({type:'BG_STOP'});
    await releaseWakeLock();
  }
}

// ── Umbenennen ────────────────────────────────────────────────────────────────
function toggleRename(){
  const row=document.getElementById('rename-row');
  if(row.style.display!=='flex'){document.getElementById('rename-in').value=myName;row.style.display='flex';document.getElementById('rename-in').focus();}
  else row.style.display='none';
}
async function confirmRename(){
  const name=document.getElementById('rename-in').value.trim();
  if(!name||!myToken)return;
  const r=await api('vehicle_rename',{token:myToken,name});
  if(r.error){notify(r.error,'w');return;}
  myName=r.name;localStorage.setItem('ps_name',myName);
  document.getElementById('disp-vname').textContent=myName;
  document.getElementById('rename-row').style.display='none';
  notify(`✓ Umbenannt in "${myName}"','g`);
}
function cancelRename(){document.getElementById('rename-row').style.display='none';}
document.getElementById('rename-in').addEventListener('keydown',e=>{if(e.key==='Enter')confirmRename();if(e.key==='Escape')cancelRename();});

// ── Polling ───────────────────────────────────────────────────────────────────
let pollTimer=null;
function startPolling(){if(pollTimer)clearInterval(pollTimer);pollState();pollTimer=setInterval(pollState,POLL_MS);}
async function pollState(){
  if(!currentColId)return;
  const pd=document.getElementById('pd');pd.classList.add('on');
  try{
    const data=await apiGet('state',{collection_id:currentColId});
    if(data&&!data.error){routes=data.routes||[];vehicles=data.vehicles||[];renderAll();document.getElementById('pt').textContent='Live';document.getElementById('no-coll').style.display=routes.length?'none':'block';}
  }catch(e){document.getElementById('pt').textContent='Fehler';}
  maybeRefreshTracks();
  setTimeout(()=>pd.classList.remove('on'),400);
}

function showMapBtns(){document.getElementById('btn-locate').style.display='block';document.getElementById('btn-fit-all').style.display='block';}
function setMyStatus(s){const el=document.getElementById('disp-vs');el.className=`vs ${s}`;el.textContent={idle:'Inaktiv',driving:'Fährt',paused:'Pausiert'}[s]||s;}

// ── GPS ───────────────────────────────────────────────────────────────────────
let gpsInit=false;
function startGPS(){
  if(!navigator.geolocation){document.getElementById('gps-txt').textContent='GPS n/a';return;}
  if(location.protocol!=='https:'){document.getElementById('gdot').className='gdot err';document.getElementById('gps-txt').textContent='⚠ Kein HTTPS';return;}
  navigator.geolocation.watchPosition(
    pos=>{
      myLat=pos.coords.latitude; myLng=pos.coords.longitude;
      // Geschwindigkeit: Browser liefert m/s (oder null)
      const speedMs = pos.coords.speed; // kann null sein
      mySpeed = speedMs;
      const speedKmh = (speedMs != null && speedMs >= 0) ? speedMs * 3.6 : null;

      document.getElementById('gdot').className='gdot on';
      let gpsText = `${myLat.toFixed(5)}, ${myLng.toFixed(5)} ±${Math.round(pos.coords.accuracy)}m`;
      document.getElementById('gps-txt').textContent = gpsText;

      // Geschwindigkeits-Badge
      const badge = document.getElementById('speed-badge');
      if (speedKmh !== null) {
        badge.textContent = speedKmh.toFixed(1) + ' km/h';
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }

      // Warnung wenn nicht am Sammeln aber in Bewegung
      updateCollectingWarn(speedKmh ?? 0);

      if(isJoined) sendGPS(myLat, myLng);
      if(!gpsInit){map.setView([myLat,myLng],15);gpsInit=true;}
    },
    err=>{document.getElementById('gdot').className='gdot err';document.getElementById('gps-txt').textContent={1:'GPS verweigert',2:'Position n/a',3:'GPS Timeout'}[err.code]||err.message;},
    {enableHighAccuracy:true,maximumAge:3000,timeout:15000}
  );
}

// ── Render ────────────────────────────────────────────────────────────────────
function renderAll(){
  renderRoutes();renderRouteList();
  updateVehicleSnaps();renderVehicleMarkers();renderVehicleList();
  updateStats();
  if(isJoined){
    const me=vehicles.find(v=>v.token===myToken);
    if(me){
      setMyStatus(me.status);
      const sc=!!(me.collecting);
      if(isCollecting!==sc){isCollecting=sc;updateCollectingUI();}
    }
  }
}

function renderRoutes(){
  routes.forEach(route=>{
    if(routeLayers[route.id])routeLayers[route.id].forEach(l=>map.removeLayer(l));
    if(!isRouteVisible(route)){routeLayers[route.id]=[];return;}
    const coords=route.coordinates,layers=[],driven=route.driven_segments||[];
    if(route.status==='completed'){
      layers.push(L.polyline(coords,{color:'#a8ff3e',weight:4,opacity:0.9,lineCap:'round'}));
    } else {
      // Aktiv oder ausstehend – immer in Fahrmodus-Darstellung (rot/grün)
      let i=0;
      while(i<coords.length-1){
        const isDriven=driven[i]===true;const seg=[coords[i]];let j=i;
        while(j<coords.length-1&&(driven[j]===true)===isDriven){seg.push(coords[j+1]);j++;}
        layers.push(L.polyline(seg,{color:isDriven?'#a8ff3e':'#ff4444',weight:isDriven?5:4,opacity:isDriven?1:0.9,lineCap:'round'}));
        i=j;
      }
    }
    const f=coords[0],la=coords[coords.length-1];
    layers.push(L.circleMarker(f,{radius:6,fillColor:route.color,color:'#fff',weight:2,fillOpacity:1}).bindTooltip('Start: '+route.name,{className:'rtt',direction:'right'}));
    layers.push(L.circleMarker(la,{radius:6,fillColor:route.status==='completed'?'#a8ff3e':route.color,color:'#fff',weight:2,fillOpacity:1}).bindTooltip('Ziel: '+route.name,{className:'rtt',direction:'right'}));
    layers.forEach(l=>l.addTo(map));
    if(layers[0])layers[0].bindTooltip(`<b>${route.name}</b><br>${slabel(route.status)} – ${route.progress}%`,{permanent:false,direction:'top',className:'rtt'});
    routeLayers[route.id]=layers;
  });
}

function makeVehicleIcon(col,sz,self,speedKmh){
  // Geschwindigkeit als Zahl im Marker wenn > 5 km/h
  const speedTxt = (speedKmh != null && speedKmh > 5) ? `<div style="position:absolute;top:${sz}px;left:50%;transform:translateX(-50%);white-space:nowrap;background:rgba(10,12,15,.85);color:${col};font-size:9px;font-family:'JetBrains Mono',monospace;padding:1px 4px;border-radius:2px;margin-top:2px">${speedKmh.toFixed(0)}km/h</div>` : '';
  return L.divIcon({
    className:'',
    html:`<div style="position:relative"><div style="width:${sz}px;height:${sz}px;background:${col};border:2px solid ${self?'#fff':'rgba(255,255,255,.7)'};border-radius:50%;box-shadow:0 0 ${self?12:6}px ${col}"></div>${speedTxt}</div>`,
    iconSize:[sz,sz+16],iconAnchor:[sz/2,sz/2]
  });
}

function renderVehicleMarkers(){
  const ids=new Set(vehicles.map(v=>v.token));
  Object.keys(vehicleMarkers).forEach(id=>{if(!ids.has(id)){map.removeLayer(vehicleMarkers[id]);delete vehicleMarkers[id];delete vehicleMarkerProps[id];}});
  vehicles.forEach(v=>{
    if(v.lat===null||v.lng===null)return;
    const self=v.token===myToken;
    if(!isVehicleVisible(v)){if(vehicleMarkers[v.token]){map.removeLayer(vehicleMarkers[v.token]);delete vehicleMarkers[v.token];delete vehicleMarkerProps[v.token];}return;}
    const snap=vehicleSnap[v.token];
    const snapFresh=!self&&snap&&Math.abs(snap.srcLat-v.lat)<SNAP_THRESHOLD&&Math.abs(snap.srcLng-v.lng)<SNAP_THRESHOLD;
    const dLat=snapFresh?snap.lat:v.lat,dLng=snapFresh?snap.lng:v.lng;
    const collecting=!!(v.collecting);
    const col=self?'#ffd700':v.status==='paused'?'#ff6b35':collecting?'#00d4ff':'#4a5a6a';
    const sz=self?18:12;
    // Geschwindigkeit für eigenes Fahrzeug anzeigen
    const spd = self && mySpeed != null ? mySpeed * 3.6 : null;
    const propKey = col+'|'+sz+'|'+(spd!=null?Math.round(spd/5):0);

    if(vehicleMarkers[v.token]){
      const prev=vehicleMarkerProps[v.token];
      if(!prev||prev.key!==propKey){vehicleMarkers[v.token].setIcon(makeVehicleIcon(col,sz,self,spd));vehicleMarkerProps[v.token]={key:propKey};requestAnimationFrame(()=>{const el=vehicleMarkers[v.token]?.getElement();if(el)el.classList.add('animated');});}
      // Bei Positionssprung >100m: keine Animation (teleportieren statt gleiten)
      const oldPos=vehicleMarkers[v.token].getLatLng();
      const el=vehicleMarkers[v.token].getElement();
      if(distM(oldPos.lat,oldPos.lng,dLat,dLng)>100){
        if(el)el.classList.remove('animated');
        vehicleMarkers[v.token].setLatLng([dLat,dLng]);
        requestAnimationFrame(()=>{const e2=vehicleMarkers[v.token]?.getElement();if(e2)e2.classList.add('animated');});
      } else {
        if(el&&!el.classList.contains('animated'))el.classList.add('animated');
        vehicleMarkers[v.token].setLatLng([dLat,dLng]);
      }
      vehicleMarkers[v.token].getTooltip()?.setContent(`<b>${v.name}</b>${self?' (Ich)':''}<br>${slabel(v.status)}${collecting?' 🟢':' ○'}${spd!=null?' · '+spd.toFixed(0)+'km/h':''}`);
    } else {
      const marker=L.marker([dLat,dLng],{icon:makeVehicleIcon(col,sz,self,spd),zIndexOffset:self?1000:0}).addTo(map)
        .bindTooltip(`<b>${v.name}</b>${self?' (Ich)':''}<br>${slabel(v.status)}${collecting?' 🟢':' ○'}`,{permanent:self,direction:'top',offset:[0,-(sz/2+4)],className:'rtt'});
      vehicleMarkers[v.token]=marker;vehicleMarkerProps[v.token]={key:propKey};
      setTimeout(()=>{const el=marker.getElement();if(el)el.classList.add('animated');},200);
    }
  });
}

function renderRouteList(){
  const c=document.getElementById('route-list');c.innerHTML='';
  if(!routes.length){c.innerHTML='<p style="color:var(--muted);font-size:11px;font-family:var(--font-mono);padding:4px 6px">Keine Routen</p>';return;}
  routes.forEach(route=>{
    const av=vehicles.find(v=>v.token===route.assigned_token);
    const visible=isRouteVisible(route);
    const card=document.createElement('div');
    card.className='rc'+(visible?'':' r-hidden');
    card.style.setProperty('--rc',route.status==='completed'?'#a8ff3e':'#ff4444');
    // Nur Reset-Button (Admin) und Sichtbarkeit/Fokus
    let acts=`<button class="btn s btn-foc">🔍</button><button class="btn s btn-vis">${visible?'👁':'🚫'}</button>`;
    if(IS_ADMIN&&route.status!=='active') acts+=`<button class="btn s btn-reset">↺ Reset</button>`;
    if(IS_ADMIN&&route.status==='active') acts+=`<button class="btn s btn-reset">↺</button>`;
    card.innerHTML=`
      <div class="rt"><span class="rn" style="color:${route.color}" title="${route.name}">${route.name}</span><span class="rb ${route.status}">${slabel(route.status)}</span></div>
      <div class="pb"><div class="pf" style="width:${route.progress}%;background:#a8ff3e"></div></div>
      <div class="rm">${route.coordinates.length} Pkt · ${av?'🚛 '+av.name:'—'} · ${route.progress}%</div>
      <div class="ra">${acts}</div>`;
    c.appendChild(card);
    card.querySelector('.btn-foc')?.addEventListener('click',()=>{if(route.coordinates.length)map.fitBounds(L.latLngBounds(route.coordinates),{padding:[40,40]});});
    card.querySelector('.btn-vis')?.addEventListener('click',()=>toggleRouteLocal(route.id));
    card.querySelector('.btn-reset')?.addEventListener('click',async()=>{if(confirm(`${route.name} zurücksetzen?`)){await api('route_reset',{route_id:route.id});pollState();}});
  });
}

function renderVehicleList(){
  const c=document.getElementById('veh-list');
  const rm=Object.fromEntries(routes.map(r=>[r.id,r.name]));
  if(!vehicles.length){c.innerHTML='<div style="padding:8px 14px;color:var(--muted);font-size:11px;font-family:var(--font-mono)">Keine Fahrzeuge</div>';return;}
  c.innerHTML=vehicles.map(v=>{
    const self=v.token===myToken,vis=isVehicleVisible(v);
    const collecting=!!(v.collecting);
    const col=self?'#ffd700':v.status==='paused'?'#ff6b35':v.status==='idle'?'var(--muted)':collecting?'#00d4ff':'#4a5a6a';
    const trackOn=trackVisible[v.token]||false;
    return `<div class="vi${vis?'':' v-hidden'}">
      <div class="vd" style="background:${col}"></div>
      <div style="flex:1;min-width:0;cursor:pointer" onclick="toggleVehicle('${v.token}')">
        <div style="font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${v.name}${self?' <small style="color:var(--accent)">(Ich)</small>':''}${collecting?' <span style="color:var(--green);font-size:9px">●SAM</span>':''}</div>
        <div style="font-size:10px;color:var(--muted);font-family:var(--font-mono)">${v.active_route_id?(rm[v.active_route_id]||'—'):'—'}</div>
      </div>
      <span class="vi-track${trackOn?' on':''}" onclick="toggleTrack('${v.token}')" title="Fahrspur ein/aus">🛣</span>
      <span class="vi-eye" onclick="toggleVehicle('${v.token}')">${vis?'👁':'○'}</span>
    </div>`;
  }).join('');
  updateVehBulkBtns();
}

function updateStats(){document.getElementById('sv').textContent=vehicles.length;document.getElementById('sa').textContent=routes.filter(r=>r.status==='active').length;document.getElementById('sd').textContent=routes.filter(r=>r.status==='completed').length;}
function slabel(s){return{pending:'Ausstehend',active:'Aktiv',completed:'Erledigt',paused:'Pausiert',idle:'Inaktiv',driving:'Fährt',offline:'Offline',draft:'Entwurf'}[s]||s;}
function distM(lat1,lng1,lat2,lng2){const R=6371000,dL=(lat2-lat1)*Math.PI/180,dl=(lng2-lng1)*Math.PI/180,a=Math.sin(dL/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dl/2)**2;return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));}
function notify(msg,type=''){const el=document.createElement('div');el.className='notif '+(type||'');el.textContent=msg;document.getElementById('notifs').appendChild(el);setTimeout(()=>el.remove(),7000);}

loadCollections();
</script>
<style>
.rtt{background:rgba(10,12,15,.93)!important;border:1px solid #2a3340!important;color:#c8d4e0!important;font-family:'JetBrains Mono',monospace!important;font-size:11px!important;padding:4px 8px!important;border-radius:4px!important;box-shadow:none!important}
.rtt::before{display:none!important}
.leaflet-container{background:#0a0c0f!important}
.leaflet-tile{filter:brightness(.72) saturate(.6) contrast(1.1)}
</style>
</body></html>
