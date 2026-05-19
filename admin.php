<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
auth_check(); auth_admin();
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin – Papiersammlung</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0c0f;--panel:#0f1216;--panel2:#12151a;--border:#1e2530;--border2:#2a3340;
      --text:#c8d4e0;--muted:#4a5a6a;--accent:#00d4ff;--green:#a8ff3e;
      --orange:#ff6b35;--pink:#ff3e9d;--yellow:#ffd700;
      --font-ui:'Rajdhani',sans-serif;--font-mono:'JetBrains Mono',monospace;--r:4px}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:var(--font-ui)}
#shell{display:flex;height:100vh;overflow:hidden}
#nav{width:220px;min-width:220px;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column}
#nav-top{padding:16px;border-bottom:1px solid var(--border);background:#0c0e12}
.logo{font-size:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent)}
.logo span{color:var(--text)}
.logo-sub{font-size:10px;color:var(--muted);letter-spacing:2px;font-family:var(--font-mono);margin-top:2px}
#nav-links{flex:1;padding:8px 0}
.nl{display:block;padding:10px 16px;font-size:14px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--muted);cursor:pointer;transition:all .2s;border-left:3px solid transparent;text-decoration:none}
.nl:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nl.active{color:var(--accent);border-left-color:var(--accent);background:rgba(0,212,255,.05)}
.nl .ico{margin-right:8px}
#nav-bottom{padding:12px 16px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:6px}
#main{flex:1;overflow-y:auto;padding:24px}
#main::-webkit-scrollbar{width:4px}
#main::-webkit-scrollbar-thumb{background:var(--border2)}
.page{display:none}.page.active{display:block}
h2{font-size:22px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);margin-bottom:20px;padding-bottom:10px;border-bottom:1px solid var(--border)}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:16px}
.card h3{font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--text);margin-bottom:14px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:8px 12px;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);font-family:var(--font-mono);font-weight:400}
td{padding:9px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.02)}
.field{margin-bottom:12px}
label{display:block;font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:5px;font-family:var(--font-mono)}
input[type=text],input[type=date],input[type=password],select,textarea{width:100%;background:var(--bg);border:1px solid var(--border2);color:var(--text);padding:8px 12px;border-radius:var(--r);font-family:var(--font-ui);font-size:14px;outline:none;transition:border-color .2s}
input:focus,select:focus,textarea:focus{border-color:var(--accent)}
textarea{resize:vertical;min-height:60px;font-family:var(--font-mono);font-size:12px}
.color-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.color-sw{width:28px;height:28px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:border-color .15s;flex-shrink:0}
.color-sw.sel{border-color:#fff}
input[type=color]{width:36px;height:28px;padding:2px;border-radius:var(--r);background:var(--bg);border:1px solid var(--border2);cursor:pointer}
.btn{background:transparent;border:1px solid var(--border2);color:var(--text);padding:8px 14px;border-radius:var(--r);font-family:var(--font-ui);font-size:13px;font-weight:600;letter-spacing:1px;text-transform:uppercase;cursor:pointer;transition:all .2s;white-space:nowrap;text-decoration:none;display:inline-block}
.btn:hover{border-color:var(--accent);color:var(--accent)}
.btn.p{border-color:var(--accent);color:var(--accent)}.btn.p:hover{background:var(--accent);color:var(--bg)}
.btn.g{border-color:var(--green);color:var(--green)}.btn.g:hover{background:var(--green);color:var(--bg)}
.btn.d{border-color:var(--orange);color:var(--orange)}.btn.d:hover{background:var(--orange);color:var(--bg)}
.btn.s{padding:5px 10px;font-size:11px}
.badge{font-size:10px;font-family:var(--font-mono);padding:2px 7px;border-radius:2px;text-transform:uppercase}
.badge.draft{background:#1a2030;color:var(--muted)}.badge.active{background:#0a2010;color:var(--green)}
.badge.completed{background:#0a1a2a;color:var(--accent)}.badge.admin{background:#1a0a2a;color:var(--pink)}
.badge.user{background:#1a2030;color:var(--muted)}.badge.pending{background:#1a2030;color:var(--muted)}
.badge.paused{background:#2a1a08;color:var(--orange)}
/* Snap-Toolbar */
.snap-bar{display:flex;gap:6px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.snap-bar .btn.s.active-snap{border-color:var(--accent);color:var(--accent);background:rgba(0,212,255,.1)}
.snap-loading{font-size:12px;font-family:var(--font-mono);color:var(--yellow);display:none;animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.map-ed{height:360px;border-radius:var(--r);border:1px solid var(--border2);overflow:hidden;margin-bottom:8px}
.map-hint{font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-bottom:6px}
#col-detail{background:var(--panel2);border:1px solid var(--border);border-radius:var(--r);padding:16px;margin-top:16px}
#toast{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:6px;max-width:300px}
.tmsg{background:var(--panel);border:1px solid var(--border2);border-left:3px solid var(--accent);padding:10px 14px;border-radius:var(--r);font-size:13px;animation:ti .3s ease}
.tmsg.g{border-left-color:var(--green)}.tmsg.e{border-left-color:var(--orange)}
@keyframes ti{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.flex-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.ml-auto{margin-left:auto}
.text-muted{color:var(--muted);font-size:12px;font-family:var(--font-mono)}
.dot-c{display:inline-block;width:10px;height:10px;border-radius:50%;flex-shrink:0;vertical-align:middle}
</style>
</head>
<body>
<div id="shell">
<nav id="nav">
  <div id="nav-top">
    <div class="logo">♻ Papier<span>sammlung</span></div>
    <div class="logo-sub">Admin-Panel</div>
  </div>
  <div id="nav-links">
    <a class="nl active" data-page="sammlungen"><span class="ico">📦</span>Sammlungen</a>
    <a class="nl" data-page="vorlagen"><span class="ico">🗺</span>Routen-Vorlagen</a>
    <a class="nl" data-page="benutzer"><span class="ico">👥</span>Benutzer</a>
  </div>
  <div id="nav-bottom">
    <a class="btn s p" href="index.php" style="text-align:center">← Zur Karte</a>
    <a class="btn s" href="logout.php" style="text-align:center">Abmelden</a>
  </div>
</nav>
<main id="main">

  <!-- SAMMLUNGEN -->
  <div class="page active" id="page-sammlungen">
    <h2>📦 Papiersammlungen</h2>
    <div class="grid2">
      <div class="card">
        <h3>Neue Sammlung erstellen</h3>
        <div class="field"><label>Name</label><input type="text" id="new-col-name" placeholder="z.B. Frühjahrsammlung 2026"></div>
        <div class="field"><label>Datum</label><input type="date" id="new-col-date"></div>
        <button class="btn p" onclick="createCollection()">+ Sammlung erstellen</button>
      </div>
      <div class="card">
        <h3>Hinweis</h3>
        <p class="text-muted" style="line-height:1.9">
          Mehrere Sammlungen pro Datum sind möglich.<br>
          <b style="color:var(--muted)">Entwurf</b> → nur Admins sehen sie.<br>
          <b style="color:var(--green)">Aktiv</b> → alle Benutzer sehen sie.
        </p>
      </div>
    </div>
    <div class="card">
      <h3>Alle Sammlungen</h3>
      <table><thead><tr><th>Name</th><th>Datum</th><th>Status</th><th>Routen</th><th>Aktionen</th></tr></thead>
      <tbody id="col-tbody"></tbody></table>
    </div>
    <div id="col-detail" style="display:none">
      <div class="flex-row" style="margin-bottom:14px">
        <h3 id="col-detail-title" style="font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase">Routen</h3>
        <button class="btn s ml-auto" onclick="hideColDetail()">✕ Schliessen</button>
      </div>
      <div class="card" style="margin-bottom:12px">
        <h3>Route hinzufügen</h3>
        <div class="grid2">
          <div>
            <div class="field"><label>Aus Vorlage importieren</label><select id="add-tpl-sel"><option value="">— Manuell zeichnen —</option></select></div>
            <div class="field"><label>Name der Route</label><input type="text" id="add-route-name" placeholder="z.B. Quartier Nord"></div>
            <div class="field"><label>Farbe</label><div class="color-row" id="add-route-colors"></div></div>
            <button class="btn g" onclick="addRouteToCollection()" style="width:100%;margin-top:4px">+ Route hinzufügen</button>
          </div>
          <div>
            <div class="snap-bar">
              <button class="btn s" id="add-snap-btn" onclick="toggleAddSnap()">🧲 Snap: AUS</button>
              <button class="btn s" id="add-route-btn" onclick="toggleAddRoute()">🛣 Strassen: AUS</button>
              <span class="snap-loading" id="add-snap-loading">⏳ Snap...</span>
            </div>
            <div class="map-hint">Klicke auf Karte um Punkte zu setzen:</div>
            <div class="map-ed" id="map-editor-add"></div>
            <div class="flex-row" style="margin-top:6px">
              <span id="pt-count-add" class="text-muted">0 Punkte</span>
              <button class="btn s" onclick="undoAddPoint()">↩ Undo</button>
              <button class="btn s d" onclick="clearAddPoints()">✕ Leeren</button>
            </div>
          </div>
        </div>
      </div>
      <table><thead><tr><th></th><th>Name</th><th>Status</th><th>Punkte</th><th>Vorlage</th><th>Aktionen</th></tr></thead>
      <tbody id="cr-tbody"></tbody></table>
    </div>
  </div>

  <!-- ROUTEN-VORLAGEN -->
  <div class="page" id="page-vorlagen">
    <h2>🗺 Routen-Vorlagen</h2>
    <div class="grid2">
      <div class="card">
        <h3>Neue Vorlage erstellen</h3>
        <div class="field"><label>Name</label><input type="text" id="tpl-name" placeholder="z.B. Route Dorfzentrum"></div>
        <div class="field"><label>Beschreibung (optional)</label><textarea id="tpl-desc" placeholder="Kurzbeschreibung..."></textarea></div>
        <div class="field"><label>Farbe</label><div class="color-row" id="tpl-colors"></div></div>
        <div class="snap-bar">
          <button class="btn s" id="tpl-snap-btn" onclick="toggleTplSnap()">🧲 Snap: AUS</button>
          <button class="btn s" id="tpl-route-btn" onclick="toggleTplRoute()">🛣 Strassen: AUS</button>
          <span class="snap-loading" id="tpl-snap-loading">⏳ Snap...</span>
        </div>
        <div class="map-hint">Klicke auf Karte um Wegpunkte zu setzen:</div>
        <div class="map-ed" id="map-editor" style="height:320px"></div>
        <div class="flex-row" style="margin:8px 0">
          <span id="pt-count" class="text-muted">0 Punkte</span>
          <button class="btn s" onclick="undoPoint()">↩ Undo</button>
          <button class="btn s d" onclick="clearPoints()">✕ Leeren</button>
        </div>
        <button class="btn p" onclick="saveTemplate()" style="width:100%">💾 Vorlage speichern</button>
      </div>
      <div class="card">
        <h3>Gespeicherte Vorlagen</h3>
        <div id="tpl-list" class="text-muted">Lade...</div>
      </div>
    </div>
  </div>

  <!-- BENUTZER -->
  <div class="page" id="page-benutzer">
    <h2>👥 Benutzerverwaltung</h2>
    <div class="grid2">
      <div class="card">
        <h3>Neuen Benutzer anlegen</h3>
        <div class="field"><label>Benutzername</label><input type="text" id="new-uname" placeholder="benutzername"></div>
        <div class="field"><label>Passwort (min. 6 Zeichen)</label><input type="password" id="new-upass" placeholder="••••••"></div>
        <div class="field"><label>Rolle</label>
          <select id="new-urole">
            <option value="user">User – kann Routen abfahren</option>
            <option value="admin">Admin – voller Zugriff</option>
          </select>
        </div>
        <button class="btn p" onclick="createUser()">+ Benutzer erstellen</button>
      </div>
      <div class="card">
        <h3>Passwort ändern</h3>
        <div class="field"><label>Benutzer</label><select id="pw-uid"></select></div>
        <div class="field"><label>Neues Passwort</label><input type="password" id="pw-new" placeholder="Neues Passwort..."></div>
        <button class="btn" onclick="changePassword()">🔑 Passwort ändern</button>
      </div>
    </div>
    <div class="card">
      <h3>Alle Benutzer</h3>
      <table><thead><tr><th>Benutzername</th><th>Rolle</th><th>Erstellt</th><th>Aktionen</th></tr></thead>
      <tbody id="user-tbody"></tbody></table>
    </div>
  </div>

</main>
</div>
<div id="toast"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const API    = 'api.php';
const OSRM   = 'https://router.project-osrm.org';
const COLORS = ['#00d4ff','#a8ff3e','#ff6b35','#ff3e9d','#ffd700','#c084fc','#fb923c','#34d399'];

// ── Navigation ────────────────────────────────────────────────────────────────
document.querySelectorAll('.nl').forEach(el=>{
  el.addEventListener('click',()=>{
    document.querySelectorAll('.nl').forEach(n=>n.classList.remove('active'));
    document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('page-'+el.dataset.page).classList.add('active');
    if(el.dataset.page==='sammlungen') loadCollections();
    if(el.dataset.page==='vorlagen'){loadTemplates();initTplMap();}
    if(el.dataset.page==='benutzer') loadUsers();
  });
});

// ── API ───────────────────────────────────────────────────────────────────────
async function api(action,body={}){
  const r=await fetch(`${API}?action=${action}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
  return r.json();
}
async function apiGet(action,params={}){
  const qs=new URLSearchParams({action,...params}).toString();
  return (await fetch(`${API}?${qs}`)).json();
}
function toast(msg,type=''){
  const el=document.createElement('div'); el.className='tmsg '+(type||'');
  el.textContent=msg; document.getElementById('toast').appendChild(el);
  setTimeout(()=>el.remove(),3200);
}
function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function slabel(s){return{pending:'Ausstehend',active:'Aktiv',completed:'Erledigt',paused:'Pausiert',draft:'Entwurf',user:'User',admin:'Admin'}[s]||s;}

// ── OSRM Snap-Helfer ──────────────────────────────────────────────────────────
async function osrmNearest(lat, lng) {
  try {
    const r = await fetch(`${OSRM}/nearest/v1/driving/${lng},${lat}?number=1`);
    const d = await r.json();
    if (d.code==='Ok' && d.waypoints?.[0]) {
      const [sLng, sLat] = d.waypoints[0].location;
      return [sLat, sLng];
    }
  } catch(e) {}
  return [lat, lng]; // Fallback: Originalpunkt
}

async function osrmRoute(from, to) {
  // from/to: [lat,lng] – gibt Strassenfolge-Koordinaten zurück
  try {
    const url = `${OSRM}/route/v1/driving/${from[1]},${from[0]};${to[1]},${to[0]}?overview=full&geometries=geojson`;
    const r = await fetch(url);
    const d = await r.json();
    if (d.code==='Ok' && d.routes?.[0]) {
      return d.routes[0].geometry.coordinates.map(c=>[c[1],c[0]]);
    }
  } catch(e) {}
  return [from, to]; // Fallback: Direktlinie
}

// ── Color Swatches ────────────────────────────────────────────────────────────
let selColor='#00d4ff', selAddColor='#00d4ff';
function buildColors(cid, onSelect, def){
  const c=document.getElementById(cid);
  COLORS.forEach(col=>{
    const sw=document.createElement('div');
    sw.className='color-sw'+(col===def?' sel':'');
    sw.style.background=col;
    sw.addEventListener('click',()=>{c.querySelectorAll('.color-sw').forEach(s=>s.classList.remove('sel'));sw.classList.add('sel');onSelect(col);});
    c.appendChild(sw);
  });
  const cp=document.createElement('input'); cp.type='color'; cp.value=def;
  cp.addEventListener('input',e=>{c.querySelectorAll('.color-sw').forEach(s=>s.classList.remove('sel'));onSelect(e.target.value);});
  c.appendChild(cp);
}
buildColors('tpl-colors',   c=>selColor=c,    '#00d4ff');
buildColors('add-route-colors',c=>selAddColor=c,'#00d4ff');

// ══════════════════════════════════════════════════════════════════════════════
// SAMMLUNGEN
// ══════════════════════════════════════════════════════════════════════════════
let currentColId=null;

// ── Collection Route Editor mit Snap ─────────────────────────────────────────
let addMap=null, addSnapEnabled=false, addRouteFollow=false, addLoading=false;
let addWaypoints=[]; // Benutzer-Klicks (nach Snap)
let addSegments=[];  // Strassenfolge-Segmente [[lat,lng],...]
let addMarkers=[];   // Leaflet-Marker für Waypoints
let addLine=null;    // Polyline

function toggleAddSnap(){
  addSnapEnabled=!addSnapEnabled;
  const btn=document.getElementById('add-snap-btn');
  btn.textContent=`🧲 Snap: ${addSnapEnabled?'EIN':'AUS'}`;
  btn.classList.toggle('active-snap',addSnapEnabled);
}
function toggleAddRoute(){
  addRouteFollow=!addRouteFollow;
  const btn=document.getElementById('add-route-btn');
  btn.textContent=`🛣 Strassen: ${addRouteFollow?'EIN':'AUS'}`;
  btn.classList.toggle('active-snap',addRouteFollow);
}

function getAddAllCoords(){
  if(!addSegments.length) return [];
  const all=[];
  addSegments.forEach((seg,i)=>{ if(i===0) all.push(...seg); else all.push(...seg.slice(1)); });
  return all;
}
function updateAddPolyline(){
  if(addLine) addMap.removeLayer(addLine);
  const coords=getAddAllCoords();
  addLine = coords.length>1 ? L.polyline(coords,{color:selAddColor,weight:3,opacity:.9}).addTo(addMap) : null;
}

async function addMapPoint(clickLat, clickLng){
  if(addLoading) return;
  addLoading=true;
  document.getElementById('add-snap-loading').style.display='block';
  try {
    let pt=[clickLat,clickLng];
    if(addSnapEnabled||addRouteFollow) pt=await osrmNearest(clickLat,clickLng);
    let seg;
    if(addRouteFollow&&addWaypoints.length>0){
      seg=await osrmRoute(addWaypoints[addWaypoints.length-1],pt);
    } else {
      seg=[pt];
    }
    addWaypoints.push(pt);
    addSegments.push(seg);
    const n=addWaypoints.length;
    const m=L.circleMarker(pt,{radius:6,fillColor:selAddColor,color:'#fff',weight:2,fillOpacity:1})
      .bindTooltip(String(n),{permanent:true,className:'pt-lbl',direction:'top'}).addTo(addMap);
    addMarkers.push(m);
    updateAddPolyline();
    document.getElementById('pt-count-add').textContent=`${n} Punkte (${getAddAllCoords().length} Koordinaten)`;
  } catch(e){ toast('OSRM Fehler','e'); }
  addLoading=false;
  document.getElementById('add-snap-loading').style.display='none';
}

function undoAddPoint(){
  if(!addWaypoints.length) return;
  addWaypoints.pop(); addSegments.pop();
  if(addMarkers.length) addMap.removeLayer(addMarkers.pop());
  updateAddPolyline();
  const n=addWaypoints.length;
  document.getElementById('pt-count-add').textContent=`${n} Punkte (${getAddAllCoords().length} Koordinaten)`;
}
function clearAddPoints(){
  addWaypoints=[]; addSegments=[];
  addMarkers.forEach(m=>addMap.removeLayer(m)); addMarkers=[];
  if(addLine){addMap.removeLayer(addLine);addLine=null;}
  document.getElementById('pt-count-add').textContent='0 Punkte';
}

function initAddMap(){
  if(addMap){setTimeout(()=>addMap.invalidateSize(),100);return;}
  addMap=L.map('map-editor-add',{center:[47.3769,8.5417],zoom:13});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OSM',maxZoom:19}).addTo(addMap);
  addMap.on('click',e=>addMapPoint(e.latlng.lat,e.latlng.lng));
  setTimeout(()=>addMap.invalidateSize(),200);
}

async function loadCollections(){
  const rows=await apiGet('collections_all');
  const tb=document.getElementById('col-tbody');
  if(!Array.isArray(rows)||!rows.length){tb.innerHTML='<tr><td colspan="5" class="text-muted" style="padding:12px">Noch keine Sammlungen</td></tr>';return;}
  tb.innerHTML=rows.map(c=>{
    const d=new Date(c.collection_date+'T00:00:00').toLocaleDateString('de-CH',{day:'2-digit',month:'2-digit',year:'numeric'});
    return `<tr>
      <td style="font-weight:700">${esc(c.name)}</td>
      <td style="font-family:var(--font-mono)">${d}</td>
      <td><span class="badge ${c.status}">${slabel(c.status)}</span></td>
      <td style="font-family:var(--font-mono)">${c.route_count||0}</td>
      <td><div style="display:flex;gap:5px;flex-wrap:wrap">
        <button class="btn s" onclick="showColDetail('${c.id}','${esc(c.name)}')">🗺 Routen</button>
        ${c.status!=='active'?`<button class="btn s g" onclick="setColStatus('${c.id}','active')">▶ Aktivieren</button>`:''}
        ${c.status==='active'?`<button class="btn s" onclick="setColStatus('${c.id}','completed')">✓ Abschliessen</button>`:''}
        ${c.status==='completed'?`<button class="btn s" onclick="setColStatus('${c.id}','draft')">↺ Entwurf</button>`:''}
        <button class="btn s d" onclick="deleteCollection('${c.id}','${esc(c.name)}')">✕</button>
      </div></td></tr>`;
  }).join('');
}
async function createCollection(){
  const name=document.getElementById('new-col-name').value.trim();
  const date=document.getElementById('new-col-date').value;
  if(!name||!date){toast('Name und Datum erforderlich','e');return;}
  const r=await api('collection_create',{name,date});
  if(r.error){toast(r.error,'e');return;}
  toast('Sammlung erstellt','g');
  document.getElementById('new-col-name').value=''; document.getElementById('new-col-date').value='';
  loadCollections();
}
async function setColStatus(id,status){
  await api('collection_update',{id,status}); toast('Status aktualisiert','g'); loadCollections();
}
async function deleteCollection(id,name){
  if(!confirm(`Sammlung "${name}" löschen?`)) return;
  await api('collection_delete',{id}); toast('Gelöscht'); loadCollections();
  if(currentColId===id) hideColDetail();
}
async function showColDetail(id,name){
  currentColId=id;
  document.getElementById('col-detail').style.display='block';
  document.getElementById('col-detail-title').textContent='Routen: '+name;
  document.getElementById('col-detail').scrollIntoView({behavior:'smooth'});
  await loadTemplatesIntoSelect(); await loadColRoutes(); initAddMap();
}
function hideColDetail(){document.getElementById('col-detail').style.display='none';currentColId=null;}

async function loadColRoutes(){
  if(!currentColId) return;
  const rows=await apiGet('col_routes_list',{collection_id:currentColId});
  const tb=document.getElementById('cr-tbody');
  if(!rows.length){tb.innerHTML='<tr><td colspan="6" class="text-muted" style="padding:12px">Noch keine Routen</td></tr>';return;}
  tb.innerHTML=rows.map(r=>`<tr>
    <td><div class="dot-c" style="background:${r.color};box-shadow:0 0 4px ${r.color}"></div></td>
    <td style="font-weight:600">${esc(r.name)}</td>
    <td><span class="badge ${r.status}">${slabel(r.status)}</span></td>
    <td style="font-family:var(--font-mono)">${r.coordinates?.length||0}</td>
    <td style="color:var(--muted);font-size:11px;font-family:var(--font-mono)">${esc(r.template_name||'—')}</td>
    <td><button class="btn s d" onclick="deleteColRoute('${r.id}')">✕ Löschen</button></td>
  </tr>`).join('');
}
async function loadTemplatesIntoSelect(){
  const tpls=await apiGet('templates_list');
  const sel=document.getElementById('add-tpl-sel');
  sel.innerHTML='<option value="">— Manuell zeichnen —</option>';
  if(Array.isArray(tpls)) tpls.forEach(t=>{
    const o=document.createElement('option'); o.value=t.id;
    o.textContent=t.name+(t.point_count?` (${t.point_count} Pkt)`:''); sel.appendChild(o);
  });
  sel.onchange=async()=>{
    if(!sel.value) return;
    const tpl=await apiGet('template_detail',{id:sel.value});
    if(tpl.name) document.getElementById('add-route-name').value=tpl.name;
    if(tpl.color) selAddColor=tpl.color;
    if(tpl.coordinates&&addMap){
      clearAddPoints();
      // Vorlage-Koordinaten als einzelne Waypoints laden (ohne Route-Follow)
      for(const p of tpl.coordinates){
        addWaypoints.push(p); addSegments.push([p]);
        const m=L.circleMarker(p,{radius:5,fillColor:selAddColor,color:'#fff',weight:2,fillOpacity:1}).addTo(addMap);
        addMarkers.push(m);
      }
      updateAddPolyline();
      const n=addWaypoints.length;
      document.getElementById('pt-count-add').textContent=`${n} Punkte`;
      if(n>0) addMap.fitBounds(L.latLngBounds(addWaypoints),{padding:[30,30]});
    }
  };
}
async function addRouteToCollection(){
  if(!currentColId){toast('Keine Sammlung','e');return;}
  const name=document.getElementById('add-route-name').value.trim();
  const tid=document.getElementById('add-tpl-sel').value||null;
  if(!name){toast('Bitte Namen eingeben','e');return;}
  const coords=getAddAllCoords();
  if(coords.length<2){toast('Mindestens 2 Punkte setzen','e');return;}
  const r=await api('col_route_add',{collection_id:currentColId,template_id:tid,name,color:selAddColor,coordinates:coords});
  if(r.error){toast(r.error,'e');return;}
  toast('Route hinzugefügt','g'); clearAddPoints();
  document.getElementById('add-route-name').value=''; document.getElementById('add-tpl-sel').value='';
  loadColRoutes();
}
async function deleteColRoute(id){
  if(!confirm('Route löschen?')) return;
  await api('col_route_delete',{id}); toast('Route gelöscht'); loadColRoutes();
}

// ══════════════════════════════════════════════════════════════════════════════
// ROUTEN-VORLAGEN mit Snap
// ══════════════════════════════════════════════════════════════════════════════
let tplMap=null, tplSnapEnabled=false, tplRouteFollow=false, tplLoading=false;
let tplWaypoints=[];  // Benutzer-Waypoints
let tplSegments=[];   // Strassenfolge-Segmente
let tplMarkers=[];    // Waypoint-Marker
let tplLine=null;     // Polyline

function toggleTplSnap(){
  tplSnapEnabled=!tplSnapEnabled;
  const btn=document.getElementById('tpl-snap-btn');
  btn.textContent=`🧲 Snap: ${tplSnapEnabled?'EIN':'AUS'}`;
  btn.classList.toggle('active-snap',tplSnapEnabled);
}
function toggleTplRoute(){
  tplRouteFollow=!tplRouteFollow;
  const btn=document.getElementById('tpl-route-btn');
  btn.textContent=`🛣 Strassen: ${tplRouteFollow?'EIN':'AUS'}`;
  btn.classList.toggle('active-snap',tplRouteFollow);
}

function getTplAllCoords(){
  if(!tplSegments.length) return [];
  const all=[];
  tplSegments.forEach((seg,i)=>{ if(i===0) all.push(...seg); else all.push(...seg.slice(1)); });
  return all;
}
function updateTplPolyline(){
  if(tplLine) tplMap.removeLayer(tplLine);
  const coords=getTplAllCoords();
  tplLine = coords.length>1 ? L.polyline(coords,{color:selColor,weight:3,opacity:.9}).addTo(tplMap) : null;
}

async function addPoint(clickLat, clickLng){
  if(tplLoading) return;
  tplLoading=true;
  document.getElementById('tpl-snap-loading').style.display='block';
  try {
    let pt=[clickLat,clickLng];
    if(tplSnapEnabled||tplRouteFollow) pt=await osrmNearest(clickLat,clickLng);
    let seg;
    if(tplRouteFollow&&tplWaypoints.length>0){
      seg=await osrmRoute(tplWaypoints[tplWaypoints.length-1],pt);
    } else {
      seg=[pt];
    }
    tplWaypoints.push(pt); tplSegments.push(seg);
    const n=tplWaypoints.length;
    const m=L.circleMarker(pt,{radius:7,fillColor:selColor,color:'#fff',weight:2,fillOpacity:1})
      .bindTooltip(String(n),{permanent:true,className:'pt-lbl',direction:'top'}).addTo(tplMap);
    tplMarkers.push(m);
    updateTplPolyline();
    document.getElementById('pt-count').textContent=`${n} Punkte (${getTplAllCoords().length} Koordinaten)`;
  } catch(e){ toast('OSRM Fehler – Direktlinie verwendet','e'); }
  tplLoading=false;
  document.getElementById('tpl-snap-loading').style.display='none';
}

function undoPoint(){
  if(!tplWaypoints.length) return;
  tplWaypoints.pop(); tplSegments.pop();
  if(tplMarkers.length) tplMap.removeLayer(tplMarkers.pop());
  updateTplPolyline();
  const n=tplWaypoints.length;
  document.getElementById('pt-count').textContent=`${n} Punkte (${getTplAllCoords().length} Koordinaten)`;
}
function clearPoints(){
  tplWaypoints=[]; tplSegments=[];
  tplMarkers.forEach(m=>tplMap.removeLayer(m)); tplMarkers=[];
  if(tplLine){tplMap.removeLayer(tplLine);tplLine=null;}
  document.getElementById('pt-count').textContent='0 Punkte';
}
function initTplMap(){
  if(tplMap){setTimeout(()=>tplMap.invalidateSize(),100);return;}
  tplMap=L.map('map-editor',{center:[47.3769,8.5417],zoom:13});
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OSM',maxZoom:19}).addTo(tplMap);
  tplMap.on('click',e=>addPoint(e.latlng.lat,e.latlng.lng));
  setTimeout(()=>tplMap.invalidateSize(),200);
}
async function saveTemplate(){
  const name=document.getElementById('tpl-name').value.trim();
  const desc=document.getElementById('tpl-desc').value.trim();
  if(!name){toast('Bitte Namen eingeben','e');return;}
  const coords=getTplAllCoords();
  if(coords.length<2){toast('Mindestens 2 Punkte setzen','e');return;}
  const r=await api('template_create',{name,description:desc,color:selColor,coordinates:coords});
  if(r.error){toast(r.error,'e');return;}
  toast('Vorlage gespeichert ✓','g');
  document.getElementById('tpl-name').value=''; document.getElementById('tpl-desc').value='';
  clearPoints(); loadTemplates();
}
async function loadTemplates(){
  const rows=await apiGet('templates_list');
  const c=document.getElementById('tpl-list');
  if(!Array.isArray(rows)||!rows.length){c.innerHTML='<p>Noch keine Vorlagen erstellt</p>';return;}
  c.innerHTML=rows.map(t=>`
    <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border)">
      <div class="dot-c" style="background:${t.color};box-shadow:0 0 4px ${t.color}"></div>
      <div style="flex:1">
        <div style="font-weight:700;font-size:14px">${esc(t.name)}</div>
        ${t.description?`<div class="text-muted">${esc(t.description)}</div>`:''}
        <div class="text-muted">${t.point_count||0} Koordinaten</div>
      </div>
      <button class="btn s" onclick="editTemplate('${t.id}')">✏️</button>
      <button class="btn s d" onclick="deleteTemplate('${t.id}','${esc(t.name)}')">✕</button>
    </div>`).join('');
}
async function editTemplate(id){
  const t=await apiGet('template_detail',{id});
  if(!t||!t.id){toast('Fehler','e');return;}
  document.getElementById('tpl-name').value=t.name;
  document.getElementById('tpl-desc').value=t.description||'';
  selColor=t.color; clearPoints();
  // Koordinaten als Einzelwaypoints laden (Snap/Route-Follow abschalten)
  if(t.coordinates){
    t.coordinates.forEach(p=>{ tplWaypoints.push(p); tplSegments.push([p]); const m=L.circleMarker(p,{radius:5,fillColor:selColor,color:'#fff',weight:2,fillOpacity:1}).addTo(tplMap); tplMarkers.push(m); });
    updateTplPolyline();
    if(tplWaypoints.length) tplMap.fitBounds(L.latLngBounds(tplWaypoints),{padding:[30,30]});
  }
  document.getElementById('pt-count').textContent=`${tplWaypoints.length} Punkte`;
  toast('Vorlage geladen – bearbeiten und speichern');
}
async function deleteTemplate(id,name){
  if(!confirm(`Vorlage "${name}" löschen?`)) return;
  await api('template_delete',{id}); toast('Vorlage gelöscht'); loadTemplates();
}

// ══════════════════════════════════════════════════════════════════════════════
// BENUTZER
// ══════════════════════════════════════════════════════════════════════════════
async function loadUsers(){
  const rows=await apiGet('users_list');
  const tb=document.getElementById('user-tbody');
  const sel=document.getElementById('pw-uid');
  if(!Array.isArray(rows)){tb.innerHTML='<tr><td colspan="4" style="color:var(--orange)">Fehler</td></tr>';return;}
  tb.innerHTML=rows.map(u=>`<tr>
    <td style="font-weight:700">${esc(u.username)}</td>
    <td><span class="badge ${u.role}">${u.role}</span></td>
    <td style="font-family:var(--font-mono);font-size:11px;color:var(--muted)">${new Date(u.created_at).toLocaleDateString('de-CH')}</td>
    <td><button class="btn s d" onclick="deleteUser(${u.id},'${esc(u.username)}')">✕ Löschen</button></td>
  </tr>`).join('');
  sel.innerHTML=rows.map(u=>`<option value="${u.id}">${u.username} (${u.role})</option>`).join('');
}
async function createUser(){
  const username=document.getElementById('new-uname').value.trim();
  const password=document.getElementById('new-upass').value;
  const role=document.getElementById('new-urole').value;
  if(!username||!password){toast('Alle Felder ausfüllen','e');return;}
  const r=await api('user_create',{username,password,role});
  if(r.error){toast(r.error,'e');return;}
  toast(`Benutzer "${username}" erstellt`,'g');
  document.getElementById('new-uname').value=''; document.getElementById('new-upass').value='';
  loadUsers();
}
async function deleteUser(id,name){
  if(!confirm(`Benutzer "${name}" löschen?`)) return;
  const r=await api('user_delete',{id});
  if(r.error){toast(r.error,'e');return;}
  toast('Benutzer gelöscht'); loadUsers();
}
async function changePassword(){
  const id=parseInt(document.getElementById('pw-uid').value);
  const password=document.getElementById('pw-new').value;
  if(!password){toast('Passwort eingeben','e');return;}
  const r=await api('user_change_password',{id,password});
  if(r.error){toast(r.error,'e');return;}
  toast('Passwort geändert ✓','g'); document.getElementById('pw-new').value='';
}

loadCollections();
</script>
<style>
.pt-lbl{background:rgba(10,12,15,.85)!important;border:none!important;color:#fff!important;font-size:10px!important;font-family:'JetBrains Mono',monospace!important;padding:2px 5px!important;box-shadow:none!important}
.pt-lbl::before{display:none!important}
.leaflet-tile{filter:brightness(.72) saturate(.6) contrast(1.1)}
.leaflet-container{background:#0a0c0f!important}
</style>
</body></html>
