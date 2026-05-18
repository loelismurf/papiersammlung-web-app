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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Papiersammlung – Karte</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0c0f;--panel:#0f1216;--border:#1e2530;--border2:#2a3340;
  --text:#c8d4e0;--muted:#4a5a6a;--accent:#00d4ff;--green:#a8ff3e;
  --orange:#ff6b35;--yellow:#ffd700;
  --font-ui:'Rajdhani',sans-serif;--font-mono:'JetBrains Mono',monospace;--r:4px
}
html,body{height:100%;margin:0;overflow:hidden;background:var(--bg);color:var(--text);font-family:var(--font-ui)}
#app{display:flex;width:100vw;height:100vh;overflow:hidden}
#sidebar{width:300px;min-width:300px;flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;z-index:10}
#hdr{padding:12px 16px;border-bottom:1px solid var(--border);background:#0c0e12;display:flex;align-items:center;gap:8px;flex-shrink:0}
.logo{font-size:17px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);flex:1}
.logo span{color:var(--text)}
.lbl{font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;font-family:var(--font-mono)}
#col-box{padding:10px 14px;border-bottom:1px solid var(--border);background:#0d1014;flex-shrink:0}
select{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:8px 10px;border-radius:var(--r);font-family:var(--font-ui);font-size:14px;outline:none}
select:focus{border-color:var(--accent)}
#id-box{padding:10px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
#join-form{display:flex;gap:6px}
#join-form input{flex:1;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:8px 10px;border-radius:var(--r);font-family:var(--font-ui);font-size:14px;outline:none}
#join-form input:focus{border-color:var(--accent)}
#v-info{display:none;align-items:center;gap:8px}
#v-info .vn{font-size:15px;font-weight:700;color:var(--accent);flex:1}
.vs{font-size:10px;font-family:var(--font-mono);padding:2px 7px;border-radius:2px;text-transform:uppercase;letter-spacing:1px}
.vs.idle{background:#1a2030;color:var(--muted)}.vs.driving{background:#0a2010;color:var(--green)}.vs.paused{background:#2a1a08;color:var(--orange)}
#gps-bar{padding:6px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;font-family:var(--font-mono);font-size:11px;background:#0c0e12;flex-shrink:0}
.gdot{width:7px;height:7px;border-radius:50%;background:var(--muted);flex-shrink:0}
.gdot.on{background:var(--green);box-shadow:0 0 5px var(--green);animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
#routes-wrap{flex:1;overflow-y:auto;min-height:0}
#routes-panel{padding:10px}
.rc{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;position:relative;overflow:hidden;margin-bottom:7px}
.rc::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--rc,var(--muted))}
.rc.hidden-r{opacity:.4}
.rt{display:flex;align-items:center;gap:6px;margin-bottom:6px}
.rn{font-size:14px;font-weight:700;flex:1}
.rb{font-size:10px;font-family:var(--font-mono);padding:2px 6px;border-radius:2px;text-transform:uppercase}
.rb.pending{background:#1a2030;color:var(--muted)}.rb.active{background:#0a2010;color:var(--green)}.rb.completed{background:#0a1a2a;color:var(--accent)}.rb.paused{background:#2a1a08;color:var(--orange)}
.pb{height:3px;background:var(--border);border-radius:2px;margin-bottom:8px;overflow:hidden}
.pf{height:100%;border-radius:2px;transition:width .5s}
.rm{font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-bottom:8px}
.ra{display:flex;gap:5px;flex-wrap:wrap}
#veh-panel{border-top:1px solid var(--border);padding:10px 14px;background:#0c0e12;flex-shrink:0;max-height:150px;overflow-y:auto}
.vi{display:flex;align-items:center;gap:7px;font-size:12px;padding:5px 8px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);margin-top:5px}
.vd{width:7px;height:7px;border-radius:50%;background:var(--accent);flex-shrink:0}
.vd.paused{background:var(--orange)}.vd.idle{background:var(--muted)}
.btn{background:transparent;border:1px solid var(--border2);color:var(--text);padding:7px 12px;border-radius:var(--r);font-family:var(--font-ui);font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;cursor:pointer;transition:all .2s;white-space:nowrap;text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.p{border-color:var(--accent);color:var(--accent)}.btn.p:hover{background:var(--accent);color:var(--bg)}
.btn.g{border-color:var(--green);color:var(--green)}.btn.g:hover{background:var(--green);color:var(--bg)}
.btn.d{border-color:var(--orange);color:var(--orange)}.btn.d:hover{background:var(--orange);color:var(--bg)}
.btn.s{padding:4px 8px;font-size:10px}
/* KEY FIX: map fills container correctly */
#mc{flex:1;position:relative;min-width:0}
#map{position:absolute;top:0;left:0;right:0;bottom:0}
#topbar{position:absolute;top:10px;left:10px;right:10px;display:flex;gap:8px;z-index:500;pointer-events:none}
.mb{background:rgba(10,12,15,.88);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:var(--r);padding:7px 12px;font-size:11px;font-family:var(--font-mono);pointer-events:auto}
.mb .ml{color:var(--muted);font-size:9px;letter-spacing:2px;text-transform:uppercase}
.mb .mv{color:var(--text);font-weight:500;margin-top:1px}
#pi{position:absolute;bottom:20px;right:10px;z-index:500;display:flex;align-items:center;gap:7px;background:rgba(10,12,15,.88);backdrop-filter:blur(6px);border:1px solid var(--border2);border-radius:var(--r);padding:7px 12px;font-size:11px;font-family:var(--font-mono)}
.pd{width:7px;height:7px;border-radius:50%;background:var(--muted)}
.pd.on{background:var(--green);box-shadow:0 0 5px var(--green)}
#notifs{position:absolute;top:60px;right:10px;z-index:600;display:flex;flex-direction:column;gap:5px;max-width:270px}
.notif{background:rgba(10,12,15,.94);border:1px solid var(--border2);border-left:3px solid var(--accent);border-radius:var(--r);padding:9px 12px;font-size:12px;animation:si .3s ease}
.notif.g{border-left-color:var(--green)}.notif.w{border-left-color:var(--orange)}
@keyframes si{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:translateX(0)}}
#gps-warn{position:absolute;bottom:60px;left:50%;transform:translateX(-50%);z-index:500;background:rgba(42,26,8,.96);border:1px solid var(--orange);border-radius:var(--r);padding:10px 16px;font-size:12px;font-family:var(--font-mono);color:var(--orange);max-width:340px;text-align:center;display:none}
#no-coll{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;z-index:400;color:var(--muted);display:none}
#no-coll .big{font-size:48px;margin-bottom:12px}
#no-coll p{font-family:var(--font-mono);font-size:13px;line-height:1.8}
@media(max-width:700px){
  #app{flex-direction:column}
  #sidebar{width:100%;min-width:0;height:52vh;flex-shrink:0;border-right:none;border-top:1px solid var(--border);order:2}
  #mc{height:48vh;order:1}
}
</style>
</head>
<body>
<div id="app">
<aside id="sidebar">
  <div id="hdr">
    <div class="logo">♻ Papier<span>sammlung</span></div>
    <?php if($is_admin): ?><a class="btn s p" href="admin.php">Admin</a><?php endif; ?>
    <a class="btn s" href="logout.php" title="Abmelden">🚪 <?=htmlspecialchars($username)?></a>
  </div>
  <div id="col-box">
    <div class="lbl">Sammlung</div>
    <select id="col-select"><option value="">Lade...</option></select>
  </div>
  <div id="id-box">
    <div class="lbl">Dein Fahrzeug / Team</div>
    <div id="join-form">
      <input id="vname-in" type="text" placeholder="Name oder Teamnummer..." maxlength="30">
      <button class="btn p" id="join-btn">Los</button>
    </div>
    <div id="v-info">
      <div class="vn" id="disp-vname">—</div>
      <div class="vs idle" id="disp-vs">Inaktiv</div>
    </div>
  </div>
  <div id="gps-bar">
    <div class="gdot" id="gdot"></div>
    <span id="gps-txt" style="color:var(--muted)">GPS inaktiv</span>
  </div>
  <div id="routes-wrap">
    <div id="routes-panel">
      <div class="lbl" style="padding:2px 4px 8px">Routen</div>
      <div id="route-list"></div>
    </div>
  </div>
  <div id="veh-panel">
    <div class="lbl">Aktive Fahrzeuge</div>
    <div id="veh-list"><span style="color:var(--muted);font-size:12px;font-family:var(--font-mono)">—</span></div>
  </div>
</aside>
<div id="mc">
  <div id="map"></div>
  <div id="topbar">
    <div class="mb"><div class="ml">Fahrzeuge</div><div class="mv" id="sv">0</div></div>
    <div class="mb"><div class="ml">Aktiv</div><div class="mv" id="sa">0</div></div>
    <div class="mb"><div class="ml">Erledigt</div><div class="mv" id="sd">0</div></div>
  </div>
  <div id="notifs"></div>
  <div id="gps-warn">⚠️ GPS benötigt ein gültiges SSL-Zertifikat.<br>Bitte <b>Let's Encrypt</b> in Plesk aktivieren.</div>
  <div id="no-coll">
    <div class="big">📦</div>
    <p>Keine aktive Sammlung vorhanden.<br>
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

let myToken = localStorage.getItem('ps_token') || null;
let myName  = localStorage.getItem('ps_name')  || null;
let isJoined = false, currentColId = null, myActiveRouteId = null;
let routes = [], vehicles = [];
const routeLayers = {}, vehicleMarkers = {};

// ── Map ───────────────────────────────────────────────────────────────────────
const map = L.map('map', { center:[47.3769,8.5417], zoom:13, zoomControl:false });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution:'© OpenStreetMap contributors', maxZoom:19
}).addTo(map);
L.control.zoom({ position:'bottomleft' }).addTo(map);

// ── API ───────────────────────────────────────────────────────────────────────
async function api(action, body={}) {
  try {
    const r = await fetch(`${API}?action=${action}`, {
      method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    return await r.json();
  } catch(e) { return {error:e.message}; }
}
async function apiGet(action, params={}) {
  try {
    const qs = new URLSearchParams({action,...params}).toString();
    return await (await fetch(`${API}?${qs}`)).json();
  } catch(e) { return []; }
}

// ── Collections ───────────────────────────────────────────────────────────────
async function loadCollections() {
  const cols = await apiGet(IS_ADMIN ? 'collections_all' : 'collections_active');
  const sel  = document.getElementById('col-select');
  if (!Array.isArray(cols) || !cols.length) {
    sel.innerHTML = '<option value="">Keine aktive Sammlung</option>';
    document.getElementById('no-coll').style.display = 'block';
    document.getElementById('pt').textContent = 'Keine Sammlung';
    return;
  }
  sel.innerHTML = cols.map(c => {
    const d  = new Date(c.collection_date+'T00:00:00').toLocaleDateString('de-CH');
    const st = {draft:' [Entwurf]', completed:' [Abgeschlossen]'}[c.status] || '';
    return `<option value="${c.id}">${c.name} – ${d}${st}</option>`;
  }).join('');
  const saved = localStorage.getItem('ps_col');
  if (saved && cols.find(c=>c.id===saved)) sel.value = saved;
  currentColId = sel.value || cols[0].id;
  sel.value = currentColId;
  startPolling();
  // Auto-reconnect NACH dem currentColId gesetzt ist
  if (myToken && myName) {
    const r = await api('vehicle_join', {name:myName, token:myToken, collection_id:currentColId});
    if (!r.error) {
      isJoined = true;
      document.getElementById('join-form').style.display='none';
      document.getElementById('v-info').style.display='flex';
      document.getElementById('disp-vname').textContent = myName;
      setMyStatus('idle'); startGPS();
    }
  }
}

document.getElementById('col-select').addEventListener('change', function() {
  currentColId = this.value;
  localStorage.setItem('ps_col', currentColId);
  Object.values(routeLayers).flat().forEach(l=>map.removeLayer(l));
  Object.values(vehicleMarkers).forEach(m=>map.removeLayer(m));
  Object.keys(routeLayers).forEach(k=>delete routeLayers[k]);
  Object.keys(vehicleMarkers).forEach(k=>delete vehicleMarkers[k]);
  routes=[]; vehicles=[];
  renderRouteList(); renderVehicleList(); updateStats();
  if (currentColId) pollState();
});

// ── Polling ───────────────────────────────────────────────────────────────────
let pollTimer = null;
function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollState();
  pollTimer = setInterval(pollState, POLL_MS);
}
async function pollState() {
  if (!currentColId) return;
  const pd = document.getElementById('pd');
  pd.classList.add('on');
  try {
    const data = await apiGet('state', {collection_id:currentColId});
    if (data && !data.error) {
      routes   = data.routes   || [];
      vehicles = data.vehicles || [];
      renderAll();
      document.getElementById('pt').textContent = 'Live';
      document.getElementById('no-coll').style.display = routes.length ? 'none' : 'block';
    } else {
      document.getElementById('pt').textContent = 'API Fehler';
    }
  } catch(e) { document.getElementById('pt').textContent = 'Fehler'; }
  setTimeout(()=>pd.classList.remove('on'), 400);
}

// ── Join ──────────────────────────────────────────────────────────────────────
if (myName) document.getElementById('vname-in').value = myName;
document.getElementById('join-btn').addEventListener('click', joinVehicle);
document.getElementById('vname-in').addEventListener('keydown', e=>{ if(e.key==='Enter') joinVehicle(); });

async function joinVehicle() {
  const name = document.getElementById('vname-in').value.trim();
  if (!name)         { notify('Bitte Name eingeben'); return; }
  if (!currentColId) { notify('Bitte zuerst eine Sammlung wählen','w'); return; }
  const res = await api('vehicle_join', {name, token:myToken, collection_id:currentColId});
  if (res.error) { notify(res.error,'w'); return; }
  myToken=res.token; myName=res.name;
  localStorage.setItem('ps_token',myToken); localStorage.setItem('ps_name',myName);
  isJoined=true;
  document.getElementById('join-form').style.display='none';
  document.getElementById('v-info').style.display='flex';
  document.getElementById('disp-vname').textContent=myName;
  setMyStatus('idle'); notify(`${myName} verbunden`,'g'); startGPS();
}

function setMyStatus(s) {
  const el=document.getElementById('disp-vs');
  el.className=`vs ${s}`;
  el.textContent={idle:'Inaktiv',driving:'Fährt',paused:'Pausiert'}[s]||s;
}

// ── GPS ───────────────────────────────────────────────────────────────────────
let gpsInit=false;
function startGPS() {
  if (!navigator.geolocation) {
    document.getElementById('gps-txt').textContent='GPS nicht verfügbar'; return;
  }
  if (location.protocol !== 'https:') {
    document.getElementById('gps-warn').style.display='block';
    document.getElementById('gps-txt').textContent='⚠ Kein HTTPS'; return;
  }
  navigator.geolocation.watchPosition(
    pos => {
      const {latitude:lat, longitude:lng, accuracy} = pos.coords;
      document.getElementById('gdot').classList.add('on');
      document.getElementById('gps-txt').textContent=`${lat.toFixed(5)}, ${lng.toFixed(5)} (±${Math.round(accuracy)}m)`;
      if (isJoined) api('vehicle_position',{token:myToken,lat,lng});
      if (!gpsInit) { map.setView([lat,lng],15); gpsInit=true; }
    },
    err => {
      const msgs={1:'GPS verweigert – Berechtigung erlauben',2:'Position nicht verfügbar',3:'GPS Timeout'};
      document.getElementById('gps-txt').textContent=msgs[err.code]||err.message;
      if (err.code===1) document.getElementById('gps-warn').style.display='block';
    },
    {enableHighAccuracy:true, maximumAge:3000, timeout:15000}
  );
}

// ── Render ────────────────────────────────────────────────────────────────────
function renderAll() {
  renderRoutes(); renderRouteList(); renderVehicleMarkers(); renderVehicleList(); updateStats();
  if (isJoined) { const me=vehicles.find(v=>v.token===myToken); if(me) setMyStatus(me.status); }
}

function renderRoutes() {
  routes.forEach(route=>{
    if (routeLayers[route.id]) routeLayers[route.id].forEach(l=>map.removeLayer(l));
    if (!route.visible) { routeLayers[route.id]=[]; return; }
    const c=route.color, w=route.status==='active'?5:3, op=route.status==='completed'?0.4:0.9;
    const dash=route.status==='paused'?'8,6':route.status==='pending'?'4,4':null;
    const line=L.polyline(route.coordinates,{color:c,weight:w,opacity:op,dashArray:dash,lineCap:'round'});
    const layers=[line];
    if ((route.status==='active'||route.status==='paused')&&route.progress>0) {
      const ei=Math.floor((route.coordinates.length-1)*route.progress/100);
      layers.push(L.polyline(route.coordinates.slice(0,ei+1),{color:c,weight:w+2,opacity:1,lineCap:'round'}));
    }
    const f=route.coordinates[0], la=route.coordinates[route.coordinates.length-1];
    layers.push(L.circleMarker(f,{radius:6,fillColor:c,color:'#fff',weight:2,fillOpacity:1,opacity:1}));
    layers.push(L.circleMarker(la,{radius:6,fillColor:route.status==='completed'?'#888':c,color:'#fff',weight:2,fillOpacity:1,opacity:1}));
    layers.forEach(l=>l.addTo(map));
    line.bindTooltip(`<b>${route.name}</b><br>${slabel(route.status)} – ${route.progress}%`,
      {permanent:false,direction:'top',className:'rtt'});
    routeLayers[route.id]=layers;
  });
}

function renderVehicleMarkers() {
  const ids=new Set(vehicles.map(v=>v.token));
  Object.keys(vehicleMarkers).forEach(id=>{if(!ids.has(id)){map.removeLayer(vehicleMarkers[id]);delete vehicleMarkers[id];}});
  vehicles.forEach(v=>{
    if (v.lat===null||v.lng===null) return;
    const self=v.token===myToken, col=self?'#ffd700':v.status==='paused'?'#ff6b35':'#00d4ff', sz=self?16:12;
    const icon=L.divIcon({className:'',
      html:`<div style="width:${sz}px;height:${sz}px;background:${col};border:2px solid ${self?'#fff':'rgba(255,255,255,.6)'};border-radius:50%;box-shadow:0 0 ${self?10:6}px ${col}"></div>`,
      iconSize:[sz,sz],iconAnchor:[sz/2,sz/2]});
    if (vehicleMarkers[v.token]) {
      vehicleMarkers[v.token].setLatLng([v.lat,v.lng]); vehicleMarkers[v.token].setIcon(icon);
    } else {
      vehicleMarkers[v.token]=L.marker([v.lat,v.lng],{icon}).addTo(map)
        .bindTooltip(`<b>${v.name}</b>${self?' (Ich)':''}<br>${slabel(v.status)}`,
          {permanent:self,direction:'top',offset:[0,-10],className:'rtt'});
    }
  });
}

function renderRouteList() {
  const c=document.getElementById('route-list'); c.innerHTML='';
  if (!routes.length) {
    c.innerHTML='<p style="color:var(--muted);font-size:12px;font-family:var(--font-mono);padding:4px">Keine Routen</p>'; return;
  }
  routes.forEach(route=>{
    const isMe=route.assigned_token===myToken;
    const av=vehicles.find(v=>v.token===route.assigned_token);
    const card=document.createElement('div');
    card.className='rc'+(route.visible?'':' hidden-r');  // FIXED
    card.style.setProperty('--rc',route.color);
    let acts='';
    acts+=`<button class="btn s btn-foc">🔍 Fokus</button>`;
    acts+=`<button class="btn s btn-tog">${route.visible?'Ausblenden':'Einblenden'}</button>`;
    if (isJoined&&route.status==='pending')
      acts+=`<button class="btn s g btn-start">▶ Start</button>`;
    if (isJoined&&isMe&&route.status==='active')
      acts+=`<button class="btn s d btn-pause">⏸ Pause</button><button class="btn s g btn-done">✓ Erledigt</button>`;
    if (isJoined&&isMe&&route.status==='paused')
      acts+=`<button class="btn s g btn-resume">▶ Weiter</button><button class="btn s g btn-done">✓ Erledigt</button>`;
    if (IS_ADMIN&&(route.status==='completed'||route.status==='paused'))
      acts+=`<button class="btn s btn-reset">↺ Reset</button>`;
    card.innerHTML=`
      <div class="rt"><span class="rn" style="color:${route.color}">${route.name}</span><span class="rb ${route.status}">${slabel(route.status)}</span></div>
      <div class="pb"><div class="pf" style="width:${route.progress}%;background:${route.color}"></div></div>
      <div class="rm">${route.coordinates.length} Pkt · ${av?'🚛 '+av.name:'—'} · ${route.progress}%</div>
      <div class="ra">${acts}</div>`;
    c.appendChild(card);
    card.querySelector('.btn-foc')?.addEventListener('click',()=>{
      if(route.coordinates.length) map.fitBounds(L.latLngBounds(route.coordinates),{padding:[40,40]});
    });
    card.querySelector('.btn-tog')?.addEventListener('click',async()=>{ await api('route_toggle',{route_id:route.id}); pollState(); });
    card.querySelector('.btn-start')?.addEventListener('click',async()=>{
      if(!isJoined){notify('Bitte zuerst verbinden','w');return;}
      const r=await api('route_start',{token:myToken,route_id:route.id});
      if(r.error){notify(r.error,'w');return;}
      myActiveRouteId=route.id; setMyStatus('driving'); pollState();
    });
    card.querySelector('.btn-pause')?.addEventListener('click',async()=>{
      await api('route_pause',{token:myToken,route_id:route.id}); setMyStatus('paused'); pollState();
    });
    card.querySelector('.btn-resume')?.addEventListener('click',async()=>{
      await api('route_resume',{token:myToken,route_id:route.id}); myActiveRouteId=route.id; setMyStatus('driving'); pollState();
    });
    card.querySelector('.btn-done')?.addEventListener('click',async()=>{
      await api('route_complete',{token:myToken,route_id:route.id});
      myActiveRouteId=null; setMyStatus('idle'); notify(`${route.name} erledigt ✓`,'g'); pollState();
    });
    card.querySelector('.btn-reset')?.addEventListener('click',async()=>{
      if(confirm(`${route.name} zurücksetzen?`)){await api('route_reset',{route_id:route.id}); pollState();}
    });
  });
}

function renderVehicleList() {
  const c=document.getElementById('veh-list');
  const rm=Object.fromEntries(routes.map(r=>[r.id,r.name]));
  if(!vehicles.length){c.innerHTML='<span style="color:var(--muted);font-size:12px;font-family:var(--font-mono)">Keine Fahrzeuge</span>';return;}
  c.innerHTML=vehicles.map(v=>`<div class="vi"><div class="vd ${v.status}"></div>
    <span style="font-weight:600;flex:1">${v.name}${v.token===myToken?' <small style="color:var(--accent)">(Ich)</small>':''}</span>
    <span style="font-size:11px;color:var(--muted);font-family:var(--font-mono)">${v.active_route_id?(rm[v.active_route_id]||'—'):'—'}</span></div>`).join('');
}
function updateStats(){
  document.getElementById('sv').textContent=vehicles.length;
  document.getElementById('sa').textContent=routes.filter(r=>r.status==='active').length;
  document.getElementById('sd').textContent=routes.filter(r=>r.status==='completed').length;
}
function slabel(s){return{pending:'Ausstehend',active:'Aktiv',completed:'Erledigt',paused:'Pausiert',idle:'Inaktiv',driving:'Fährt',offline:'Offline',draft:'Entwurf'}[s]||s;}
function notify(msg,type=''){
  const el=document.createElement('div'); el.className='notif '+(type||'');
  el.textContent=msg; document.getElementById('notifs').appendChild(el);
  setTimeout(()=>el.remove(),3500);
}
loadCollections();
</script>
<style>
.rtt{background:rgba(10,12,15,.93)!important;border:1px solid #2a3340!important;color:#c8d4e0!important;font-family:'JetBrains Mono',monospace!important;font-size:11px!important;padding:5px 9px!important;border-radius:4px!important;box-shadow:none!important}
.rtt::before{display:none!important}
.leaflet-container{background:#0a0c0f!important}
.leaflet-tile{filter:brightness(.72) saturate(.6) contrast(1.1)}
</style>
</body></html>
