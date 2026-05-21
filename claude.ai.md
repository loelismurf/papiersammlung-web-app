# AI Context — Papiersammlung System

## Project Overview
GPS-based paper collection platform with:
- PHP/MySQL web application
- Android mobile application
- Real-time vehicle tracking
- Segment-based route progress detection
- Offline GPS buffering
- Automatic route completion

System tracks driven route segments and updates collection progress automatically.

---

# Critical Rules

## Never Break
- NEVER overwrite `config.php`
- Coordinates format ALWAYS: `[lat,lng]`
- Always update `tmp/install.php` after DB schema changes
- ZIP exports MUST exclude `config.php`
- Always include Android project folder
- Generate ONLY changed files

## Hosting Constraints
Environment: Netcup Shared Hosting

Restrictions:
- No Node.js
- No persistent/background PHP workers
- No `JSON_LENGTH()` in MySQL
- Browser polling every `2.5s`

---

# Technology Stack

## Web
- PHP 8+
- MySQL + PDO
- Leaflet + OpenStreetMap
- Browser Geolocation API
- OSRM road snapping
- PWA (`sw.js` + `manifest.json`)
- Dark UI theme (`#0a0c0f` / `#00d4ff`)

## Android
- Kotlin
- minSdk 26
- targetSdk 34
- OSMDroid
- OkHttp 4.x
- ForegroundService + WakeLock
- SQLite offline buffer
- Bearer token authentication
- Gradle 8.2

---

# Core Architecture

## Collection Workflow
1. Routes created → immediately `active`
2. Vehicle sends GPS updates
3. Route segments matched against GPS
4. Driven segments marked `true`
5. Small GPS gaps filled automatically
6. Route auto-completes when all segments completed

Only admin can manually reset routes.

---

# Database Schema

## users
```text
id
username
password_hash
role
```

## collections
```text
id
name
collection_date
status
```

## collection_routes
```text
id
collection_id
coordinates JSON [lat,lng][]
status
progress
 driven_segments JSON bool[]
```

Statuses:
- pending
- active
- paused
- completed

## vehicles
```text
id
token
user_id
lat
lng
status
collecting
```

Vehicle statuses:
- idle
- driving
- paused
- offline

## vehicle_tracks
Stores GPS history while collecting.

## api_tokens
Bearer tokens for Android authentication.

---

# Route Tracking Logic

## vehicle_position Algorithm
Execution order:
1. Load vehicle
2. Save GPS position
3. Load all non-completed routes
4. Match GPS to segments
5. Execute `fill_small_gaps()`
6. Auto-complete route

## GPS Tolerances
- OSRM snapped: `20m`
- Raw GPS fallback: `30m`

---

# Vehicle System

Rules:
- One vehicle per user
- `vehicle_join` auto-finds or auto-creates vehicle
- `collecting=1` → status `driving`
- `collecting=0` → status `idle`
- `vehicle_ping` keeps idle vehicles visible

---

# Authentication

## Web
- PHP Session authentication

## Mobile
Token MUST be sent through ALL methods simultaneously:
1. `?auth_token=` GET parameter
2. `Authorization` header
3. `X-Auth-Token` header
4. POST parameter `auth_token`

Important:
Apache/Netcup may strip `Authorization` headers.
Primary authentication method is GET parameter.

---

# Important API Endpoints

## Collection
- `collections_active`
- `collection_create`
- `collection_update`
- `collection_delete`

## Routes
- `col_routes_list`
- `col_routes_add`
- `col_routes_delete`
- `route_reset`

## Templates
- `templates_list`
- `templates_detail`
- `templates_create`
- `templates_update`
- `templates_delete`

## Users
- `users_list`
- `users_create`
- `users_delete`
- `change_password`

## Vehicle
- `vehicle_join`
- `vehicle_position`
- `vehicle_ping`
- `vehicle_track`

## Mobile
- `mobile_login`
- `state`

---

# Android Application

## MainActivity.kt
Responsibilities:
- Load active collections
- Join vehicle session
- Start polling AFTER successful `joinVehicle()`
- Update `tv_collection_status`
- Enable `btnCollect` only after successful join

## GpsService.kt
Features:
- ForegroundService
- `START_STICKY`
- GPS every `3s` during collection
- GPS every `30s` while idle
- SQLite offline buffering
- WakeLock support

## ApiClient.kt
Requirements:
- Always append `auth_token` to GET URLs
- `getArray()` must handle direct arrays
- Logging via `Log.d/e`

## OfflineBuffer.kt
SQLite table:
```text
gps_buffer
- token
- collection_id
- lat
- lng
- speed
- recorded_at
```

Sync interval:
- Every `15s`

---

# Web Background GPS

Three-layer fallback system:
1. fetch keepalive
2. `bgPageTimer` every `6s`
3. Service Worker notifications

Purpose:
Prevent browser throttling and keep GPS updates alive.

---

# Follow Mode

## Web
- `followMode=true` by default
- Map drag disables follow mode
- Target button re-enables follow mode

## Android
Same behavior using `btn_follow`.

---

# Vehicle Tracks

## Features
- `vehicle_tracks` stores GPS history
- `vehicle_track` returns latest `800` points
- Automatic live display during collection

---

# Critical Bugs That Must Never Return

## GPS / Routing
- Wrong coordinate order `[lng,lat]`
- Missing `driven_segments`
- GPS gaps on straight roads
- Route progress not updating

## Authentication
- Lost `Authorization` headers
- Invalid token forwarding

## Android
- `startPolling()` before `joinVehicle()`
- `collections_active` array parsing issues
- Missing follow mode

## Vehicle Tracking
- Idle vehicles disappearing
- Offline buffering not syncing

---

# AI Development Instructions

## Required Behavior
- Keep code minimal and production-focused
- Avoid unnecessary abstractions
- Preserve existing architecture
- Maintain backward compatibility
- Never rewrite unrelated files
- Prefer patch-style updates
- Keep performance optimized for shared hosting

## Database Changes
When modifying schema:
1. Update migration logic
2. Update `tmp/install.php`
3. Preserve backward compatibility
4. Avoid unsupported MySQL functions

## GPS Logic
Always preserve:
- Segment-based progress tracking
- Gap-filling logic
- OSRM snapping behavior
- Offline buffering
- Vehicle visibility while idle

## Android Rules
Never break:
- ForegroundService behavior
- WakeLock handling
- Offline sync
- Token forwarding
- Polling lifecycle

---

# File Priorities

## Critical Web Files
- `config.php` → NEVER modify
- `db.php` → geo algorithms + database helpers
- `api.php` → API + authentication
- `index.php` → driver interface
- `admin.php` → admin dashboard
- `sw.js` → background GPS
- `tmp/install.php` → database schema

## Critical Android Files
- `MainActivity.kt`
- `GpsService.kt`
- `ApiClient.kt`
- `OfflineBuffer.kt`
- `AppPrefs.kt`
- `activity_main.xml`

---

# Response Format For AI

When generating updates:
- Output ONLY changed files
- Keep responses concise
- Do not explain unchanged logic
- Preserve formatting consistency
- Avoid placeholder code
- Prefer complete working implementations

