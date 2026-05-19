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

/* Mobile handle – nur auf Mobile sichtbar */
#mob-handle{display:none}

/* Inner wrapper: auf Desktop flex-col, auf Mobile scrollbar */
#sidebar-inner{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}

#hdr{padding:10px 14px;border-bottom:1px solid var(--border);background:#0c0e12;display:flex;align-items:center;gap:8px;flex-shrink:0}
.logo{font-size:15px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);flex:1}
.logo span{color:var(--text)}
.lbl{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);font-family:var(--font-mono)}
#col-box{padding:8px 14px;border-bottom:1px solid var(--border);background:#0d1014;flex-shrink:0}
select{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:7px 10px;border-radius:var(--r);font-family:var(--font-ui);font-size:13px;outline:none}
select:focus{border-color:var(--accent)}
#id-box{padding:8px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
#join-form{display:flex;gap:6px}
#join-form input{flex:1;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:7px 10px;border-radius:var(--r);font-family:var(--font-ui);font-size:13px;outline:none}
#join-form input:focus{border-color:var(--accent)}
#v-info{display:none;align-items:center;gap:8px}
.vn{font-size:14px;font-weight:700;color:var(--accent);flex:1}
.vs{font-size:10px;font-family:var(--font-mono);padding:2px 7px;border-radius:2px;text-transform:uppercase}
.vs.idle{background:#1a2030;color:var(--muted)}.vs.driving{background:#0a2010;color:var(--green)}.vs.paused{background:#2a1a08;color:var(--orange)}
#wake-banner{background:#0a1a10;border-bottom:1px solid var(--green);padding:5px 14px;font-size:11px;font-family:var(--font-mono);color:var(--green);display:none;flex-shrink:0;align-items:center;gap:8px}
#gps-bar{padding:5px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:7px;font-family:var(--font-mono);font-size:10px;background:#0c0e12;flex-shrink:0}
.gdot{width:6px;height:6px;border-radius:50%;background:var(--muted);flex-shrink:0}
.gdot.on{background:var(--green);box-shadow:0 0 4px var(--green);animation:pulse 1.5s infinite}
.gdot.err{background:var(--red)}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* Section headers */
.sec-hdr{display:flex;align-items:center;gap:6px;padding:7px 14px 5px;flex-shrink:0}
.sec-hdr .lbl{flex:1}
.sec-hdr .bulk-btns{display:flex;gap:3px}
.bulk-btn{font-size:10px;font-family:var(--font-mono);padding:2px 7px;border-radius:2px;border:1px solid var(--border2);background:transparent;color:var(--muted);cursor:pointer;transition:all .15s;white-space:nowrap}
.bulk-btn:hover{border-color:var(--accent);color:var(--accent)}
.bulk-btn.active{border-color:var(--accent);color:var(--accent);background:rgba(0,212,255,.08)}

/* Route list */
#routes-wrap{flex:1;overflow-y:auto;min-height:0}
#routes-wrap::-webkit-scrollbar{width:3px}
#routes-wrap::-webkit-scrollbar-thumb{background:var(--border2)}
#route-list{padding:4px 8px 8px}
.rc{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:9px 11px;position:relative;overflow:hidden;margin-bottom:6px;transition:opacity .2s}
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

/* Vehicle list */
#veh-panel{border-top:1px solid var(--border);background:#0c0e12;flex-shrink:0;max-height:185px;overflow-y:auto}
.vi{display:flex;align-items:center;gap:7px;font-size:12px;padding:6px 10px;cursor:pointer;border-bottom:1px solid var(--border);transition:opacity .2s,background .15s}
.vi:last-child{border-bottom:none}
.vi:hover{background:rgba(255,255,255,.03)}
.vi.v-hidden{opacity:.38}
.vd{width:7px;height:7px;border-radius:50%;background:var(--accent);flex-shrink:0}
.vd.paused{background:var(--orange)}.vd.idle{background:var(--muted)}
.vi-eye{font-size:12px;color:var(--muted);flex-shrink:0}
.vi.v-hidden .vi-eye{color:#333}

/* Buttons */
.btn{background:transparent;border:1px solid var(--border2);color:var(--text);padding:6px 11px;border-radius:var(--r);font-family:var(--font-ui);font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;cursor:pointer;transition:all .2s;white-space:nowrap;text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.p{border-color:var(--accent);color:var(--accent)}.btn.p:hover{background:var(--accent);color:var(--bg)}
.btn.g{border-color:var(--green);color:var(--green)}.btn.g:hover{background:var(--green);color:var(--bg)}
.btn.d{border-color:var(--orange);color:var(--orange)}.btn.d:hover{background:var(--orange);color:var(--bg)}
.btn.s{padding:3px 7px;font-size:10px}

/* Map */
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

/* ═══════════════════════════════════════════════════════════
   MOBILE: Sidebar unten, zusammenrollbar, ein einziger Scroll
   ═══════════════════════════════════════════════════════════ */
@media(max-width:700px){
  #app{flex-direction:column}

  /* Karte oben */
  #mc{order:1;flex:1;min-height:0}

  /* Sidebar unten – zusammenrollbar */
  #sidebar{
    order:2;
    width:100%;min-width:0;
    height:54vh;
    flex-shrink:0;
    border-right:none;
    border-top:1px solid var(--border);
    transition:height .25s ease;
    overflow:hidden;
  }
  #sidebar.collapsed{height:36px}

  /* Handle-Leiste – nur auf Mobile */
  #mob-handle{
    display:flex;
    align-items:center;justify-content:center;gap:8px;
    height:36px;min-height:36px;
    background:#0c0e12;
    border-bottom:1px solid var(--border);
    cursor:pointer;
    flex-shrink:0;
    font-size:11px;font-family:var(--font-mono);
    color:var(--muted);
    user-select:none;
    -webkit-user-select:none;
  }
  #mob-handle:hover{color:var(--accent)}
  #mob-arrow{font-size:10px;transition:transform .25s}
  #sidebar.collapsed #mob-arrow{transform:rotate(180deg)}

  /* Inner content: ein einziger Scroll statt mehrerer */
  #sidebar-inner{
    overflow-y:auto;
    overflow-x:hidden;
    -webkit-overflow-scrolling:touch;
    flex:1;
  }
  #sidebar-inner::-webkit-scrollbar{width:3px}
  #sidebar-inner::-webkit-scrollbar-thumb{background:var(--border2)}

  /* Routen-Wrap: kein eigener Scroll auf Mobile */
  #routes-wrap{overflow:visible!important;flex:none!important;min-height:0}
  /* Fahrzeug-Panel: volle Höhe auf Mobile */
  #veh-panel{max-height:none!important;overflow:visible!important}
}
</style>
</head>
<body>
<div id="app">
<aside id="sidebar">

  <!-- Mobile: Anfass-Leiste zum Ein-/Ausklappen -->
  <div id="mob-handle" onclick="toggleSidebar()">
    <span id="mob-arrow">▼</span>
    <span id="mob-label">Menü</span>
  </div>

  <!-- Gesamter Sidebar-Inhalt -->
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

    <div id="id-box">
      <div class="lbl" style="margin-bottom:5px">Fahrzeug / Team</div>
      <div id="join-form">
        <input id="vname-in" type="text" placeholder="Name eingeben..." maxlength="30">
        <button class="btn p" id="join-btn">Los</button>
      </div>
      <div id="v-info">
        <div class="vn" id="disp-vname">—</div>
        <div class="vs idle" id="disp-vs">Inaktiv</div>
      </div>
    </div>

    <div id="wake-banner">📍 GPS aktiv – Bildschirm bleibt an</div>

    <div id="gps-bar">
      <div class="gdot" id="gdot"></div>
      <span id="gps-txt" style="color:var(--muted)">GPS inaktiv</span>
    </div>

    <!-- Routen -->
    <div class="sec-hdr" style="border-top:1px solid var(--border)">
      <span class="lbl">Routen</span>
      <div class="bulk-btns">
        <button class="bulk-btn" onclick="showAllRoutes()">👁 Alle</button>
        <button class="bulk-btn" onclick="hideAllRoutes()">🚫 Keine</button>
      </div>
    </div>
    <div id="routes-wrap">
      <div id="route-list"></div>
    </div>

    <!-- Fahrzeuge -->
    <div class="sec-hdr" style="border-top:1px solid var(--border)">
      <span class="lbl">Fahrzeuge</span>
      <div class="bulk-btns">
        <button class="bulk-btn" id="btn-only-me" onclick="selectOnlyMe()">👤 Ich</button>
        <button class="bulk-btn active" id="btn-all-veh" onclick="selectAllVehicles()">👥 Alle</button>
        <button class="bulk-btn" onclick="deselectAllVehicles()">🚫</button>
      </div>
    </div>
    <div id="veh-panel">
      <div id="veh-list"></div>
    </div>
  </div><!-- /sidebar-inner -->
</aside>

<div id="mc">
  <div id="map"></div>
  <div id="topbar">
    <div class="mb"><div class="ml">Fahrzeuge</div><div class="mv" id="sv">0</div></div>
    <div class="mb"><div class="ml">Aktiv</div><div class="mv" id="sa">0</div></div>
    <div class="mb"><div class="ml">Erledigt</div><div class="mv" id="sd">0</div></div>
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
const API      = 'api.php';
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
const POLL_MS  = <?= POLL_INTERVAL ?>;
const OSRM     = 'https://router.project-osrm.org';

let myToken=localStorage.getItem('ps_token')||null;
let myName=localStorage.getItem('ps_name')||null;
let isJoined=false, currentColId=null;
let myLat=null, myLng=null, wakeLock=null;
let routes=[], vehicles=[];
const routeLayers={}, vehicleMarkers={};

// ── Mobile Sidebar Toggle ─────────────────────────────────────────────────────
let sidebarCollapsed = false;
function toggleSidebar(){
  sidebarCollapsed = !sidebarCollapsed;
  document.getElementById('sidebar').classList.toggle('collapsed', sidebarCollapsed);
  document.getElementById('mob-label').textContent = sidebarCollapsed ? 'Menü' : 'Menü ausblenden';
}

// ── Routen-Sichtbarkeit (lokal) ───────────────────────────────────────────────
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
        if(d.code==='Ok'&&d.waypoints?.[0]){ const[lng,lat]=d.waypoints[0].location; vehicleSnap[v.token]={srcLat:v.lat,srcLng:v.lng,lat,lng}; }
        else vehicleSnap[v.token]={srcLat:v.lat,srcLng:v.lng,lat:v.lat,lng:v.lng};
        snapInFlight.delete(v.token); renderVehicleMarkers();
      }).catch(()=>{ vehicleSnap[v.token]={srcLat:v.lat,srcLng:v.lng,lat:v.lat,lng:v.lng}; snapInFlight.delete(v.token); });
  });
}

// ── Service Worker ────────────────────────────────────────────────────────────
if('serviceWorker' in navigator){
  navigator.serviceWorker.register('sw.js').catch(()=>{});
  navigator.serviceWorker.addEventListener('message',e=>{ if(e.data?.type==='REQUEST_GPS'&&myLat!==null&&isJoined) sendGPS(myLat,myLng); });
}
function sendGPS(lat, lng) {
  const payload = {token:myToken, lat, lng, collection_id:currentColId};
  // OSRM-Snap mitschicken wenn im Cache vorhanden
  // Threshold 0.002° (~170m Lat / ~120m Lng @47°N) – weit genug dass bei 30km/h
  // alle 2s (= ~17m Bewegung) der Snap immer noch mitgeschickt wird.
  // Server nutzt Snap-Position (auf Strasse korrigiert) für genauere Segment-Erkennung.
  const c = vehicleSnap[myToken];
  if (c && Math.abs(c.srcLat-lat)<0.002 && Math.abs(c.srcLng-lng)<0.002) {
    payload.snap_lat = c.lat;
    payload.snap_lng = c.lng;
  }
  api('vehicle_position', payload).then(res => {
    // Fehler loggen (z.B. DB-Problem) – Client ignoriert normalerweise die Antwort
    if (res && res.error) console.warn('[GPS] vehicle_position error:', res.error);
  }).catch(()=>{});
  // Snap im Hintergrund aktualisieren wenn veraltet
  if (!c || Math.abs(c.srcLat-lat)>0.0001 || Math.abs(c.srcLng-lng)>0.0001) {
    fetch(`${OSRM}/nearest/v1/driving/${lng},${lat}?number=1`)
      .then(r=>r.json()).then(d=>{
        if(d.code==='Ok'&&d.waypoints?.[0]){
          const[sl,slt]=d.waypoints[0].location;
          vehicleSnap[myToken]={srcLat:lat,srcLng:lng,lat:slt,lng:sl};
        }
      }).catch(()=>{});
  }
}

// ── Map ───────────────────────────────────────────────────────────────────────
const map=L.map('map',{center:[47.3769,8.5417],zoom:13,zoomControl:false});
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(map);
L.control.zoom({position:'bottomleft'}).addTo(map);
document.getElementById('btn-locate').addEventListener('click',()=>{ if(myLat!==null) map.setView([myLat,myLng],17); else notify('Noch keine GPS-Position','w'); });
document.getElementById('btn-fit-all').addEventListener('click',()=>{
  const all=routes.filter(r=>isRouteVisible(r)&&r.coordinates.length).flatMap(r=>r.coordinates);
  if(all.length) map.fitBounds(L.latLngBounds(all),{padding:[30,30]}); else notify('Keine sichtbaren Routen','w');
});

// ── Wake Lock ─────────────────────────────────────────────────────────────────
async function requestWakeLock(){
  if(!('wakeLock' in navigator)) return;
  try{ wakeLock=await navigator.wakeLock.request('screen'); document.getElementById('wake-banner').style.display='flex'; wakeLock.addEventListener('release',()=>{document.getElementById('wake-banner').style.display='none';wakeLock=null;}); }catch(e){}
}
async function releaseWakeLock(){ if(wakeLock){await wakeLock.release();wakeLock=null;} document.getElementById('wake-banner').style.display='none'; }
document.addEventListener('visibilitychange',async()=>{ if(document.visibilityState==='visible'&&isJoined&&!wakeLock) await requestWakeLock(); });

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
    document.getElementById('pt').textContent='Keine Sammlung'; return;
  }
  sel.innerHTML=cols.map(c=>{
    const d=new Date(c.collection_date+'T00:00:00').toLocaleDateString('de-CH');
    const st={draft:' [Entwurf]',completed:' [Abgeschlossen]'}[c.status]||'';
    return `<option value="${c.id}">${c.name} – ${d}${st}</option>`;
  }).join('');
  const saved=localStorage.getItem('ps_col');
  if(saved&&cols.find(c=>c.id===saved)) sel.value=saved;
  currentColId=sel.value||cols[0].id; sel.value=currentColId;
  startPolling();
  if(myToken&&myName){
    const r=await api('vehicle_join',{name:myName,token:myToken,collection_id:currentColId});
    if(!r.error){ isJoined=true; document.getElementById('join-form').style.display='none'; document.getElementById('v-info').style.display='flex'; document.getElementById('disp-vname').textContent=myName; showMapBtns(); setMyStatus('idle'); startGPS(); }
  }
}
document.getElementById('col-select').addEventListener('change',function(){
  currentColId=this.value; localStorage.setItem('ps_col',currentColId);
  Object.values(routeLayers).flat().forEach(l=>map.removeLayer(l)); Object.values(vehicleMarkers).forEach(m=>map.removeLayer(m));
  Object.keys(routeLayers).forEach(k=>delete routeLayers[k]); Object.keys(vehicleMarkers).forEach(k=>delete vehicleMarkers[k]);
  routes=[]; vehicles=[]; renderRouteList(); renderVehicleList(); updateStats();
  if(currentColId) pollState();
});

// ── Polling ───────────────────────────────────────────────────────────────────
let pollTimer=null;
function startPolling(){if(pollTimer)clearInterval(pollTimer);pollState();pollTimer=setInterval(pollState,POLL_MS);}
async function pollState(){
  if(!currentColId) return;
  const pd=document.getElementById('pd'); pd.classList.add('on');
  try{
    const data=await apiGet('state',{collection_id:currentColId});
    if(data&&!data.error){ routes=data.routes||[]; vehicles=data.vehicles||[]; renderAll(); document.getElementById('pt').textContent='Live'; document.getElementById('no-coll').style.display=routes.length?'none':'block'; }
  }catch(e){document.getElementById('pt').textContent='Fehler';}
  setTimeout(()=>pd.classList.remove('on'),400);
}

// ── Join ──────────────────────────────────────────────────────────────────────
if(myName) document.getElementById('vname-in').value=myName;
document.getElementById('join-btn').addEventListener('click',joinVehicle);
document.getElementById('vname-in').addEventListener('keydown',e=>{if(e.key==='Enter')joinVehicle();});
async function joinVehicle(){
  const name=document.getElementById('vname-in').value.trim();
  if(!name){notify('Bitte Name eingeben');return;}
  if(!currentColId){notify('Bitte zuerst eine Sammlung wählen','w');return;}
  const res=await api('vehicle_join',{name,token:myToken,collection_id:currentColId});
  if(res.error){notify(res.error,'w');return;}
  myToken=res.token; myName=res.name;
  localStorage.setItem('ps_token',myToken); localStorage.setItem('ps_name',myName);
  isJoined=true;
  document.getElementById('join-form').style.display='none'; document.getElementById('v-info').style.display='flex';
  document.getElementById('disp-vname').textContent=myName;
  showMapBtns(); setMyStatus('idle'); notify(`${myName} verbunden`,'g');
  startGPS(); await requestWakeLock();
}
function showMapBtns(){ document.getElementById('btn-locate').style.display='block'; document.getElementById('btn-fit-all').style.display='block'; }
function setMyStatus(s){ const el=document.getElementById('disp-vs'); el.className=`vs ${s}`; el.textContent={idle:'Inaktiv',driving:'Fährt',paused:'Pausiert'}[s]||s; }

// ── GPS ───────────────────────────────────────────────────────────────────────
let gpsInit=false;
function startGPS(){
  if(!navigator.geolocation){document.getElementById('gps-txt').textContent='GPS n/a';return;}
  if(location.protocol!=='https:'){document.getElementById('gdot').className='gdot err';document.getElementById('gps-txt').textContent='⚠ Kein HTTPS';return;}
  navigator.geolocation.watchPosition(
    pos=>{
      myLat=pos.coords.latitude; myLng=pos.coords.longitude;
      document.getElementById('gdot').className='gdot on';
      document.getElementById('gps-txt').textContent=`${myLat.toFixed(5)}, ${myLng.toFixed(5)} ±${Math.round(pos.coords.accuracy)}m`;
      if(isJoined) sendGPS(myLat,myLng);
      if(!gpsInit){map.setView([myLat,myLng],15);gpsInit=true;}
    },
    err=>{ document.getElementById('gdot').className='gdot err'; document.getElementById('gps-txt').textContent={1:'GPS verweigert',2:'Position n/a',3:'GPS Timeout'}[err.code]||err.message; },
    {enableHighAccuracy:true,maximumAge:3000,timeout:15000}
  );
}

// ── Render ────────────────────────────────────────────────────────────────────
function renderAll(){ renderRoutes(); renderRouteList(); updateVehicleSnaps(); renderVehicleMarkers(); renderVehicleList(); updateStats(); if(isJoined){const me=vehicles.find(v=>v.token===myToken);if(me)setMyStatus(me.status);} }

function renderRoutes(){
  routes.forEach(route=>{
    if(routeLayers[route.id]) routeLayers[route.id].forEach(l=>map.removeLayer(l));
    if(!isRouteVisible(route)){routeLayers[route.id]=[];return;}
    const coords=route.coordinates, layers=[], driven=route.driven_segments||[];
    if(route.status==='pending'){
      layers.push(L.polyline(coords,{color:route.color,weight:3,opacity:0.5,dashArray:'6,5',lineCap:'round'}));
    } else if(route.status==='completed'){
      layers.push(L.polyline(coords,{color:'#a8ff3e',weight:4,opacity:0.9,lineCap:'round'}));
    } else if(route.status==='active'||route.status==='paused'){
      const paused=route.status==='paused'; let i=0;
      while(i<coords.length-1){
        const isDriven=driven[i]===true; const seg=[coords[i]]; let j=i;
        while(j<coords.length-1&&(driven[j]===true)===isDriven){seg.push(coords[j+1]);j++;}
        layers.push(L.polyline(seg,{color:isDriven?'#a8ff3e':'#ff4444',weight:isDriven?5:4,opacity:isDriven?1:0.9,dashArray:(!isDriven&&paused)?'8,5':null,lineCap:'round'}));
        i=j;
      }
    }
    const f=coords[0],la=coords[coords.length-1];
    layers.push(L.circleMarker(f,{radius:6,fillColor:route.color,color:'#fff',weight:2,fillOpacity:1,opacity:1}).bindTooltip('Start: '+route.name,{className:'rtt',direction:'right'}));
    layers.push(L.circleMarker(la,{radius:6,fillColor:route.status==='completed'?'#a8ff3e':route.color,color:'#fff',weight:2,fillOpacity:1,opacity:1}).bindTooltip('Ziel: '+route.name,{className:'rtt',direction:'right'}));
    layers.forEach(l=>l.addTo(map));
    if(layers[0]) layers[0].bindTooltip(`<b>${route.name}</b><br>${slabel(route.status)} – ${route.progress}%`,{permanent:false,direction:'top',className:'rtt'});
    routeLayers[route.id]=layers;
  });
}

function renderVehicleMarkers(){
  const ids=new Set(vehicles.map(v=>v.token));
  Object.keys(vehicleMarkers).forEach(id=>{ if(!ids.has(id)){map.removeLayer(vehicleMarkers[id]);delete vehicleMarkers[id];} });
  vehicles.forEach(v=>{
    if(v.lat===null||v.lng===null) return;
    const self=v.token===myToken;
    if(!isVehicleVisible(v)){ if(vehicleMarkers[v.token]){map.removeLayer(vehicleMarkers[v.token]);delete vehicleMarkers[v.token];}return; }
    const snap=vehicleSnap[v.token];
    const snapFresh=!self&&snap&&Math.abs(snap.srcLat-v.lat)<SNAP_THRESHOLD&&Math.abs(snap.srcLng-v.lng)<SNAP_THRESHOLD;
    const dLat=snapFresh?snap.lat:v.lat, dLng=snapFresh?snap.lng:v.lng;
    const col=self?'#ffd700':v.status==='paused'?'#ff6b35':'#00d4ff', sz=self?18:12;
    const icon=L.divIcon({className:'',html:`<div style="width:${sz}px;height:${sz}px;background:${col};border:2px solid ${self?'#fff':'rgba(255,255,255,.7)'};border-radius:50%;box-shadow:0 0 ${self?12:6}px ${col}"></div>`,iconSize:[sz,sz],iconAnchor:[sz/2,sz/2]});
    if(vehicleMarkers[v.token]){
      const el=vehicleMarkers[v.token].getElement();
      if(el) el.classList.add('animated');
      vehicleMarkers[v.token].setLatLng([dLat,dLng]); vehicleMarkers[v.token].setIcon(icon);
      vehicleMarkers[v.token].getTooltip()?.setContent(`<b>${v.name}</b>${self?' (Ich)':''}<br>${slabel(v.status)}`);
    } else {
      const marker=L.marker([dLat,dLng],{icon,zIndexOffset:self?1000:0}).addTo(map)
        .bindTooltip(`<b>${v.name}</b>${self?' (Ich)':''}<br>${slabel(v.status)}`,{permanent:self,direction:'top',offset:[0,-(sz/2+4)],className:'rtt'});
      vehicleMarkers[v.token]=marker;
      setTimeout(()=>{const el=marker.getElement();if(el)el.classList.add('animated');},200);
    }
  });
}

function renderRouteList(){
  const c=document.getElementById('route-list'); c.innerHTML='';
  if(!routes.length){c.innerHTML='<p style="color:var(--muted);font-size:11px;font-family:var(--font-mono);padding:4px 6px">Keine Routen</p>';return;}
  routes.forEach(route=>{
    const isMe=route.assigned_token===myToken, av=vehicles.find(v=>v.token===route.assigned_token);
    const visible=isRouteVisible(route);
    const card=document.createElement('div');
    card.className='rc'+(visible?'':' r-hidden');
    card.style.setProperty('--rc',route.status==='completed'?'#a8ff3e':route.status==='active'||route.status==='paused'?'#ff4444':route.color);
    let acts=`<button class="btn s btn-foc">🔍</button><button class="btn s btn-vis">${visible?'👁':'🚫'}</button>`;
    if(isJoined&&route.status==='pending') acts+=`<button class="btn s g btn-start">▶ Start</button>`;
    if(isJoined&&isMe&&route.status==='active') acts+=`<button class="btn s d btn-pause">⏸</button><button class="btn s g btn-done">✓</button>`;
    if(isJoined&&isMe&&route.status==='paused') acts+=`<button class="btn s g btn-resume">▶</button><button class="btn s g btn-done">✓</button>`;
    if(IS_ADMIN&&(route.status==='completed'||route.status==='paused')) acts+=`<button class="btn s btn-reset">↺</button>`;
    card.innerHTML=`
      <div class="rt"><span class="rn" style="color:${route.color}" title="${route.name}">${route.name}</span><span class="rb ${route.status}">${slabel(route.status)}</span></div>
      <div class="pb"><div class="pf" style="width:${route.progress}%;background:#a8ff3e"></div></div>
      <div class="rm">${route.coordinates.length} Pkt · ${av?'🚛 '+av.name:'—'} · ${route.progress}%</div>
      <div class="ra">${acts}</div>`;
    c.appendChild(card);
    card.querySelector('.btn-foc')?.addEventListener('click',()=>{ if(route.coordinates.length) map.fitBounds(L.latLngBounds(route.coordinates),{padding:[40,40]}); });
    card.querySelector('.btn-vis')?.addEventListener('click',()=>toggleRouteLocal(route.id));
    card.querySelector('.btn-start')?.addEventListener('click',async()=>{
      if(!isJoined){notify('Bitte zuerst verbinden','w');return;}
      const r=await api('route_start',{token:myToken,route_id:route.id});
      if(r.error){notify(r.error,'w');return;}
      setMyStatus('driving'); pollState(); await requestWakeLock();
    });
    card.querySelector('.btn-pause')?.addEventListener('click',async()=>{ await api('route_pause',{token:myToken,route_id:route.id}); setMyStatus('paused'); pollState(); });
    card.querySelector('.btn-resume')?.addEventListener('click',async()=>{ await api('route_resume',{token:myToken,route_id:route.id}); setMyStatus('driving'); pollState(); await requestWakeLock(); });
    card.querySelector('.btn-done')?.addEventListener('click',async()=>{ await api('route_complete',{token:myToken,route_id:route.id}); setMyStatus('idle'); notify(`${route.name} erledigt ✓`,'g'); pollState(); await releaseWakeLock(); });
    card.querySelector('.btn-reset')?.addEventListener('click',async()=>{ if(confirm(`${route.name} zurücksetzen?`)){await api('route_reset',{route_id:route.id});pollState();} });
  });
}

function renderVehicleList(){
  const c=document.getElementById('veh-list');
  const rm=Object.fromEntries(routes.map(r=>[r.id,r.name]));
  if(!vehicles.length){c.innerHTML='<div style="padding:8px 14px;color:var(--muted);font-size:11px;font-family:var(--font-mono)">Keine Fahrzeuge</div>';return;}
  c.innerHTML=vehicles.map(v=>{
    const self=v.token===myToken, vis=isVehicleVisible(v);
    const col=self?'#ffd700':v.status==='paused'?'#ff6b35':v.status==='idle'?'var(--muted)':'#00d4ff';
    return `<div class="vi${vis?'':' v-hidden'}" onclick="toggleVehicle('${v.token}')">
      <div class="vd ${v.status}" style="background:${col}"></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${v.name}${self?' <small style="color:var(--accent)">(Ich)</small>':''}</div>
        <div style="font-size:10px;color:var(--muted);font-family:var(--font-mono)">${v.active_route_id?(rm[v.active_route_id]||'—'):'—'}</div>
      </div>
      <span class="vi-eye">${vis?'👁':'○'}</span>
    </div>`;
  }).join('');
  updateVehBulkBtns();
}

function updateStats(){ document.getElementById('sv').textContent=vehicles.length; document.getElementById('sa').textContent=routes.filter(r=>r.status==='active').length; document.getElementById('sd').textContent=routes.filter(r=>r.status==='completed').length; }
function slabel(s){return{pending:'Ausstehend',active:'Aktiv',completed:'Erledigt',paused:'Pausiert',idle:'Inaktiv',driving:'Fährt',offline:'Offline',draft:'Entwurf'}[s]||s;}
function notify(msg,type=''){const el=document.createElement('div');el.className='notif '+(type||'');el.textContent=msg;document.getElementById('notifs').appendChild(el);setTimeout(()=>el.remove(),3500);}

loadCollections();
</script>
<style>
.rtt{background:rgba(10,12,15,.93)!important;border:1px solid #2a3340!important;color:#c8d4e0!important;font-family:'JetBrains Mono',monospace!important;font-size:11px!important;padding:4px 8px!important;border-radius:4px!important;box-shadow:none!important}
.rtt::before{display:none!important}
.leaflet-container{background:#0a0c0f!important}
.leaflet-tile{filter:brightness(.72) saturate(.6) contrast(1.1)}
</style>
</body></html>
