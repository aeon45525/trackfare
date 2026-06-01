<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/fare.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId   = (int)$_SESSION['user_id'];
$driverName = trim($_SESSION['full_name'] ?? 'Driver');

$activeTrip            = null;
$routeStops            = [];
$activePassengers      = [];
$activeTripCollected   = 0.0;
$driverTotalEarnings   = 0.0;
$totalRouteDistance    = 0.0;
$averageSpeed          = 0;
$routeProgressPercent  = 0;
$currentStopIndex      = 0;
$cumulativeDistances   = [0.0];
$mapCenterLat          = 14.821028;
$mapCenterLng          = 120.902972;

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R    = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/* active trip */
if ($stmt = $conn->prepare(
    'SELECT t.trip_id, t.route_id, t.current_stop_index,
            r.route_name, r.display_name, b.bus_number, b.plate_number
     FROM trips t
     JOIN routes r ON t.route_id = r.route_id
     JOIN buses  b ON t.bus_id   = b.bus_id
     WHERE t.driver_id = ? AND t.status = ? LIMIT 1'
)) {
    $s = 'active';
    $stmt->bind_param('is', $driverId, $s);
    $stmt->execute();
    $activeTrip = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if ($activeTrip) {
    $routeId = (int)$activeTrip['route_id'];
    $tripId  = (int)$activeTrip['trip_id'];

    if ($stmt = $conn->prepare(
        'SELECT rs.stop_order, s.stop_id, s.stop_name, s.lat, s.lng
         FROM route_stops rs JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ? ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $routeStops[] = [
                'stop_order' => (int)  $row['stop_order'],
                'stop_id'    => (int)  $row['stop_id'],
                'stop_name'  =>        $row['stop_name'],
                'lat'        => (float)$row['lat'],
                'lng'        => (float)$row['lng'],
            ];
        }
        $stmt->close();
    }

    $routeStopCount   = count($routeStops);
    $currentStopIndex = max(0, min($routeStopCount - 1, (int)($activeTrip['current_stop_index'] ?? 0)));

    if ($routeStopCount > 0) {
        $mapCenterLat = $routeStops[0]['lat'];
        $mapCenterLng = $routeStops[0]['lng'];
    }

    $cumulativeDistances = [0.0];
    for ($i = 1; $i < $routeStopCount; $i++) {
        $prev = $routeStops[$i - 1];
        $curr = $routeStops[$i];
        $cumulativeDistances[] = round($cumulativeDistances[$i - 1]
            + haversine_km($prev['lat'], $prev['lng'], $curr['lat'], $curr['lng']), 4);
    }
    $totalRouteDistance   = end($cumulativeDistances);
    $routeProgressPercent = $routeStopCount > 1
        ? round(($currentStopIndex / ($routeStopCount - 1)) * 100)
        : 0;
    $estHours    = max(0.1, ($routeStopCount - 1) * 0.06);
    $averageSpeed = $totalRouteDistance > 0 ? round($totalRouteDistance / $estHours) : 30;

    /* passengers */
    if ($stmt = $conn->prepare(
        'SELECT ap.user_id, u.full_name, ap.card_id, ap.boarding_stop_id, st.stop_name AS boarding_stop
         FROM active_passengers ap
         JOIN users u  ON ap.user_id         = u.user_id
         JOIN stops st ON ap.boarding_stop_id = st.stop_id
         WHERE ap.trip_id = ? ORDER BY u.full_name'
    )) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $activePassengers[] = $row;
        }
        $stmt->close();
    }

    /* earnings */
    if ($stmt = $conn->prepare(
        'SELECT COALESCE(SUM(fare_amount),0) AS total FROM trip_transactions WHERE trip_id = ?'
    )) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $activeTripCollected = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }

    if ($stmt = $conn->prepare(
        'SELECT COALESCE(SUM(tt.fare_amount),0) AS total
         FROM trip_transactions tt JOIN trips t ON tt.trip_id = t.trip_id
         WHERE t.driver_id = ?'
    )) {
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $driverTotalEarnings = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}

$routeStopCount   = count($routeStops);
$passengerCount   = count($activePassengers);
$routeProgressCurrent = $routeStops[$currentStopIndex]['stop_name'] ?? 'N/A';
$routeNextStop = ($currentStopIndex < $routeStopCount - 1)
    ? $routeStops[$currentStopIndex + 1]['stop_name']
    : 'End of route';

$routeStopsForMap = array_map(static fn($s) => [
    'stop_id' => $s['stop_id'],
    'name'    => $s['stop_name'],
    'lat'     => $s['lat'],
    'lng'     => $s['lng'],
], $routeStops);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TrackFare — Driver Dashboard</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script id="twcfg">
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: {
        colors: {
          primary: '#0040a1', 'primary-container': '#0056d2', 'on-primary': '#ffffff',
          secondary: '#486176', surface: '#f8f9fa', 'on-surface': '#191c1d',
          'surface-container': '#edeeef', outline: '#737785', 'outline-variant': '#c3c6d6',
        },
        fontFamily: { headline: ['Manrope'], body: ['Inter'] },
        borderRadius: { DEFAULT: '0.125rem', lg: '0.25rem', xl: '0.5rem', full: '0.75rem' },
      }}
    };
  </script>
  <style>
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;vertical-align:middle}

    #live-map{width:100%;height:480px;border-radius:1rem;overflow:hidden;position:relative;z-index:0;background:#e2e8f0}

    /* progress */
    .pbar-outer{height:8px;border-radius:9999px;background:#e2e8f0;overflow:hidden}
    .pbar-inner{height:100%;border-radius:9999px;background:#0040a1;transition:width .35s ease}
    .pbadge{display:inline-flex;align-items:center;justify-content:center;min-width:3rem;padding:.2rem .75rem;border-radius:9999px;background:#0040a1;color:#fff;font-weight:700;font-size:.85rem}
    .pcard{border:1px solid #e2e8f0;border-radius:1rem;background:#f8fafc;padding:1rem;min-height:80px}

    /* stop list */
    .stop-row{display:flex;align-items:center;gap:.6rem;padding:.35rem 0;font-size:.875rem;color:#64748b;border-bottom:1px solid #f1f5f9}
    .stop-row:last-child{border:none}
    .sdot{width:10px;height:10px;border-radius:50%;background:#cbd5e1;flex-shrink:0}
    .stop-row.done .sdot{background:#16a34a}
    .stop-row.done{color:#16a34a}
    .stop-row.cur .sdot{background:#0040a1;box-shadow:0 0 0 3px rgba(0,64,161,.2)}
    .stop-row.cur{color:#0040a1;font-weight:700}
    .stop-row.nxt .sdot{background:#2563eb}
    .stop-row.nxt{color:#2563eb;font-weight:600}

    /* passenger accordion */
    .pax-item{border-radius:12px;padding:.75rem;border:1px solid transparent;transition:all .15s}
    .pax-item.open{border-color:#e2e8f0;background:#fff}
    .pax-body{padding-top:.5rem;color:#475569;display:none}
    .pax-item.open .pax-body{display:block}

    /* trip control buttons */
    .tb{border-radius:1rem;padding:.55rem 0;font-size:.8rem;font-weight:700;letter-spacing:.02em;cursor:pointer;transition:opacity .15s}
    .tb:disabled{opacity:.3;cursor:not-allowed}

    /* status chip */
    #status-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .85rem;border-radius:9999px;font-size:.72rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;transition:all .3s}
    #status-chip.idle{background:#f1f5f9;color:#64748b}
    #status-chip.running{background:#dcfce7;color:#15803d}
    #status-chip.paused{background:#dbeafe;color:#1d4ed8}
    #status-chip.ended{background:#fef9c3;color:#854d0e}
    .chipdot{width:7px;height:7px;border-radius:50%;background:currentColor}
  </style>
</head>
<body class="bg-slate-100 text-on-surface font-body antialiased">
<div class="flex min-h-screen">

  <!-- Sidebar -->
  <aside class="fixed left-0 top-0 h-screen w-[260px] bg-white border-r border-slate-200 shadow-sm z-20">
    <div class="flex h-full flex-col">
      <div class="px-6 py-8 border-b border-slate-200">
        <span class="text-2xl font-black tracking-tight text-primary font-headline">TrackFare</span>
        <p class="mt-1 text-sm text-slate-500">Driver Panel</p>
      </div>
      <nav class="flex-1 px-4 py-6 space-y-1">
        <a href="01_dashboard.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold">
          <span class="material-symbols-outlined">dashboard</span><span>Dashboard</span>
        </a>
        <a href="02_route.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition">
          <span class="material-symbols-outlined">alt_route</span><span>Route</span>
        </a>
        <a href="03_logs.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition">
          <span class="material-symbols-outlined">receipt_long</span><span>Logs</span>
        </a>
        <a href="04_profile.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition">
          <span class="material-symbols-outlined">person</span><span>Profile</span>
        </a>
      </nav>
      <div class="mt-auto px-6 py-6 border-t border-slate-200">
        <div class="flex items-center gap-3">
          <div class="w-12 h-12 rounded-2xl overflow-hidden border border-slate-200">
            <img src="../../images/pfp.png" alt="Driver" class="w-full h-full object-cover"/>
          </div>
          <div>
            <p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($driverName); ?></p>
            <p class="text-xs text-slate-500">Driver</p>
          </div>
        </div>
        <button onclick="window.location.href='../../auth/logout.php'"
          class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition">
          <span class="material-symbols-outlined">logout</span>Logout
        </button>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="ml-[260px] flex-1 min-h-screen bg-slate-100 p-10">
    <div class="max-w-full">
      <header class="mb-10">
        <h1 class="text-4xl font-extrabold tracking-tight text-slate-900 font-headline">Dashboard</h1>
        <p class="mt-2 text-sm text-slate-500">Driver overview — current route &amp; onboard passengers.</p>
      </header>

      <div class="grid grid-cols-12 gap-8">

        <!-- LEFT -->
        <section class="col-span-12 xl:col-span-7 space-y-6">

          <!-- Live Map -->
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between mb-4">
              <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Live Map</p>
              <span id="status-chip" class="idle"><span class="chipdot"></span><span id="chip-label">Trip not started</span></span>
            </div>
            <div id="live-map" role="application" aria-label="Live route map"></div>
          </article>

          <!-- Controls -->
          <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold mb-3">Trip Controls</p>
            <div class="grid grid-cols-4 gap-3">
              <button id="btn-start"  class="tb bg-emerald-600 text-white">&#9654; Start</button>
              <button id="btn-arrive" class="tb bg-sky-600    text-white" disabled>&#9646; Arrive</button>
              <button id="btn-depart" class="tb bg-indigo-600 text-white" disabled>&#9654; Depart</button>
              <button id="btn-end"    class="tb bg-rose-600   text-white" disabled>&#9632; End Trip</button>
            </div>
          </article>

        </section>

        <!-- RIGHT -->
        <section class="col-span-12 xl:col-span-5 space-y-6">

          <!-- Route card -->
          <article class="rounded-[1.5rem] bg-white p-8 shadow-sm border border-slate-200">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="text-xs uppercase tracking-[.3em] text-slate-500 font-semibold">Active Route</p>
                <h2 class="mt-3 text-2xl font-black text-slate-900">
                  <?php echo htmlspecialchars($activeTrip['route_name'] ?? 'No active route'); ?>
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                  <?php echo htmlspecialchars($activeTrip['display_name'] ?? ''); ?>
                </p>
              </div>
              <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700 whitespace-nowrap">On Route</span>
            </div>

            <div class="mt-6">
              <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold mb-2">Progress</p>
              <div class="pbar-outer">
                <div id="pbar" class="pbar-inner" style="width:<?php echo $routeProgressPercent; ?>%"></div>
              </div>
              <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                <span>Route progress</span>
                <span id="plabel" class="pbadge"><?php echo $routeProgressPercent; ?>%</span>
              </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 mt-4">
              <div class="pcard">
                <p class="text-[.65rem] uppercase tracking-[.25em] text-slate-500 font-semibold">Current Stop</p>
                <p id="ui-cur" class="mt-2 text-base font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($routeProgressCurrent); ?></p>
              </div>
              <div class="pcard">
                <p class="text-[.65rem] uppercase tracking-[.25em] text-slate-500 font-semibold">Next Stop</p>
                <p id="ui-nxt" class="mt-2 text-base font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($routeNextStop); ?></p>
              </div>
            </div>

            <ul id="stop-list" class="mt-4 max-h-56 overflow-y-auto pr-1"></ul>
          </article>

          <!-- Passengers -->
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <div class="flex items-center justify-between mb-4">
              <div>
                <p class="text-xs uppercase tracking-[.3em] text-slate-500 font-semibold">Onboard Passengers</p>
                <h2 class="mt-1 text-xl font-black text-slate-900">Passenger List</h2>
              </div>
              <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600"><?php echo $passengerCount; ?> onboard</span>
            </div>
            <div class="space-y-2" id="pax-list">
              <?php if (!empty($activePassengers)): ?>
                <?php foreach ($activePassengers as $i => $p): ?>
                  <div class="pax-item <?php echo $i === 0 ? 'open' : ''; ?>">
                    <div class="flex items-center justify-between cursor-pointer" data-toggle>
                      <div>
                        <p class="text-sm font-semibold text-slate-900">
                          <?php echo htmlspecialchars($p['full_name']); ?>
                          <span class="text-xs text-slate-400 font-normal ml-1">#P<?php echo (int)$p['user_id']; ?></span>
                        </p>
                        <p class="text-xs text-slate-500">Boarded at <?php echo htmlspecialchars($p['boarding_stop']); ?></p>
                      </div>
                      <span class="text-xs font-semibold text-emerald-600">Onboard</span>
                    </div>
                    <div class="pax-body text-xs">Card ID: #<?php echo (int)$p['card_id']; ?></div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="pax-item open">
                  <p class="text-sm font-semibold text-slate-900">No onboard passengers</p>
                  <p class="text-xs text-slate-400">All passengers checked out</p>
                </div>
              <?php endif; ?>
            </div>
          </article>

          <!-- Metrics + Earnings -->
          <div class="grid grid-cols-2 gap-4">
            <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
              <p class="text-xs uppercase tracking-[.2em] text-slate-500 font-semibold mb-3">Trip Metrics</p>
              <div class="space-y-2 text-sm text-slate-700">
                <div class="flex justify-between"><span>Passengers</span><strong id="m-pax"><?php echo $passengerCount; ?> onboard</strong></div>
                <div class="flex justify-between"><span>Distance</span><strong id="m-dist"><?php echo number_format($totalRouteDistance, 1); ?> km</strong></div>
                <div class="flex justify-between"><span>Avg Speed</span><strong id="m-speed"><?php echo $averageSpeed > 0 ? $averageSpeed . ' km/h' : 'N/A'; ?></strong></div>
                <div class="flex justify-between"><span>Est. Arrival</span><strong id="m-eta">N/A</strong></div>
              </div>
            </article>
            <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
              <p class="text-xs uppercase tracking-[.2em] text-slate-500 font-semibold mb-3">Earnings</p>
              <div class="space-y-2 text-sm text-slate-700">
                <div class="flex justify-between"><span>This Trip</span><strong>&#8369;<?php echo number_format($activeTripCollected, 2); ?></strong></div>
                <div class="flex justify-between"><span>Fares (trip)</span><strong>&#8369;<?php echo number_format($activeTripCollected, 2); ?></strong></div>
                <div class="flex justify-between"><span>All-time</span><strong>&#8369;<?php echo number_format($driverTotalEarnings, 2); ?></strong></div>
              </div>
            </article>
          </div>

        </section>
      </div>
    </div>
  </main>
</div>

<script>
(function () {
  'use strict';

  /* ── PHP-injected data ── */
  var MAP_CFG_URL    = '../../api/map.php';
  var GPS_URL        = '../../config/gps.php';
  var STOPS_FALLBACK = <?php echo json_encode($routeStopsForMap, JSON_UNESCAPED_UNICODE); ?>;
  var TOTAL_KM       = <?php echo (float)$totalRouteDistance; ?>;
  var AVG_SPEED      = <?php echo max(1, (int)$averageSpeed); ?>;
  var INIT_IDX       = <?php echo (int)$currentStopIndex; ?>;
  var MAP_CENTER     = { lat: <?php echo $mapCenterLat; ?>, lng: <?php echo $mapCenterLng; ?> };

  /* ── constants ── */
  var LEG_MS   = 2000;
  var TICK_MS  = 16;
  var NAV_ZOOM = 17;
  var OV_ZOOM  = 12;

  /* ── state ── */
  var stops = [];
  var map, busMkr, lineTaken, lineAhead;
  var stopMkrs = [];
  var state = 'idle';
  var curIdx = 0;
  var legTimer = null;
  var legFrom = 0;
  var legTo = 0;
  var legStartTs = 0;
  var busHeading = 0;
  var mapsReady = false;

  /* ── DOM ── */
  var btnStart  = document.getElementById('btn-start');
  var btnArrive = document.getElementById('btn-arrive');
  var btnDepart = document.getElementById('btn-depart');
  var btnEnd    = document.getElementById('btn-end');
  var chipEl    = document.getElementById('status-chip');
  var chipLbl   = document.getElementById('chip-label');
  var uiCur     = document.getElementById('ui-cur');
  var uiNxt     = document.getElementById('ui-nxt');
  var pbar      = document.getElementById('pbar');
  var plabel    = document.getElementById('plabel');
  var stopList  = document.getElementById('stop-list');
  var mDist     = document.getElementById('m-dist');
  var mSpeed    = document.getElementById('m-speed');
  var mEta      = document.getElementById('m-eta');

  function latLng(lat, lng) {
    return new google.maps.LatLng(lat, lng);
  }

  function bearing(lat1, lng1, lat2, lng2) {
    var R  = Math.PI / 180;
    var p1 = lat1 * R, p2 = lat2 * R;
    var dl = (lng2 - lng1) * R;
    var y  = Math.sin(dl) * Math.cos(p2);
    var x  = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
  }

  function lerp(a, b, t) { return a + (b - a) * t; }

  function ease(t) {
    return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
  }

  function fmtTime(ms) {
    return new Date(Date.now() + ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  /* ══ GOOGLE MAPS ═════════════════════════════════════ */
  function initGoogleMap() {
    map = new google.maps.Map(document.getElementById('live-map'), {
      center: MAP_CENTER,
      zoom: OV_ZOOM,
      mapTypeId: 'roadmap',
      heading: 0,
      tilt: 0,
      streetViewControl: false,
      mapTypeControl: false,
      fullscreenControl: true,
      gestureHandling: 'greedy',
    });
    mapsReady = true;
    google.maps.event.trigger(map, 'resize');
  }

  function loadGoogleMaps() {
    return fetch(MAP_CFG_URL, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (r) {
      if (!r.ok) throw new Error('Map config unavailable');
      return r.json();
    }).then(function (cfg) {
      if (!cfg.apiKey) throw new Error('Missing API key');
      return new Promise(function (resolve, reject) {
        if (window.google && window.google.maps) {
          resolve();
          return;
        }
        var libs = (cfg.libraries && cfg.libraries.length)
          ? '&libraries=' + cfg.libraries.join(',')
          : '';
        var cb = '__trackfareMapsInit';
        window[cb] = function () {
          delete window[cb];
          resolve();
        };
        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key='
          + encodeURIComponent(cfg.apiKey) + libs + '&callback=' + cb;
        s.async = true;
        s.defer = true;
        s.onerror = function () { reject(new Error('Failed to load Google Maps')); };
        document.head.appendChild(s);
      });
    });
  }

  function mkBusMarker(position, heading) {
    return new google.maps.Marker({
      position: position,
      map: map,
      title: 'Your Bus',
      zIndex: 2000,
      icon: {
        path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
        scale: 7,
        fillColor: '#0040a1',
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2,
        rotation: heading,
        anchor: new google.maps.Point(0, 2.5),
      },
    });
  }

  function setBusPosition(lat, lng, heading) {
    var pos = latLng(lat, lng);
    busHeading = heading;
    if (!busMkr) {
      busMkr = mkBusMarker(pos, heading);
    } else {
      busMkr.setPosition(pos);
      busMkr.setIcon({
        path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
        scale: 7,
        fillColor: '#0040a1',
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2,
        rotation: heading,
        anchor: new google.maps.Point(0, 2.5),
      });
    }
  }

  function followBus(lat, lng) {
    map.setCenter(latLng(lat, lng));
    map.setZoom(NAV_ZOOM);
    map.setHeading(0);
    map.setTilt(0);
  }

  function stopDotIcon(color, size) {
    return {
      path: google.maps.SymbolPath.CIRCLE,
      scale: size / 2,
      fillColor: color,
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
    };
  }

  function refreshStops(nextIdx) {
    stopMkrs.forEach(function (m) { m.setMap(null); });
    stopMkrs = [];

    stops.forEach(function (stop, i) {
      var done = i < curIdx;
      var cur  = i === curIdx;
      var nxt  = i === nextIdx;
      var col  = done ? '#16a34a' : cur ? '#0040a1' : nxt ? '#2563eb' : '#94a3b8';
      var sz   = (cur || nxt) ? 13 : 9;

      var m = new google.maps.Marker({
        position: latLng(stop.lat, stop.lng),
        map: map,
        title: stop.name,
        icon: stopDotIcon(col, sz),
        zIndex: cur ? 600 : nxt ? 500 : done ? 100 : 200,
        label: (cur || nxt) ? {
          text: stop.name,
          color: '#1e293b',
          fontSize: '9px',
          fontWeight: '700',
        } : null,
      });
      stopMkrs.push(m);
    });
  }

  function rebuildLines(busLat, busLng) {
    if (lineTaken) { lineTaken.setMap(null); lineTaken = null; }
    if (lineAhead) { lineAhead.setMap(null); lineAhead = null; }
    if (!stops.length) return;

    var taken = stops.slice(0, curIdx + 1).map(function (s) {
      return latLng(s.lat, s.lng);
    });
    if (busLat !== null) taken.push(latLng(busLat, busLng));

    if (taken.length > 1) {
      lineTaken = new google.maps.Polyline({
        path: taken,
        geodesic: true,
        strokeColor: '#16a34a',
        strokeOpacity: 0.92,
        strokeWeight: 6,
        map: map,
        zIndex: 10,
      });
    }

    var ahead = busLat !== null
      ? [latLng(busLat, busLng)]
      : [latLng(stops[curIdx].lat, stops[curIdx].lng)];
    for (var j = curIdx + 1; j < stops.length; j++) {
      ahead.push(latLng(stops[j].lat, stops[j].lng));
    }

    if (ahead.length > 1) {
      lineAhead = new google.maps.Polyline({
        path: ahead,
        geodesic: true,
        strokeColor: '#60a5fa',
        strokeOpacity: 0,
        strokeWeight: 5,
        icons: [{
          icon: {
            path: 'M 0,-1 0,1',
            strokeOpacity: 0.85,
            strokeColor: '#60a5fa',
            scale: 4,
          },
          offset: '0',
          repeat: '16px',
        }],
        map: map,
        zIndex: 5,
      });
    }
  }

  function fitOverview() {
    if (!stops.length) return;
    var bounds = new google.maps.LatLngBounds();
    stops.forEach(function (s) { bounds.extend(latLng(s.lat, s.lng)); });
    map.fitBounds(bounds, 48);
    map.setHeading(0);
    map.setTilt(0);
  }

  /* ══ UI HELPERS ══════════════════════════════════════ */
  function setChip(cls, txt) {
    chipEl.className = cls;
    chipLbl.textContent = txt;
  }

  function setBtns(s) {
    btnStart.disabled  = s !== 'idle';
    btnArrive.disabled = s !== 'running';
    btnDepart.disabled = s !== 'paused';
    btnEnd.disabled    = s === 'idle';
  }

  function renderStopList(nxtIdx) {
    if (!stopList) return;
    stopList.innerHTML = '';
    stops.forEach(function (stop, i) {
      var li  = document.createElement('li');
      var cls = 'stop-row';
      var lbl = stop.name;
      if      (i < curIdx)   { cls += ' done'; lbl += ' \u2713'; }
      else if (i === curIdx) { cls += ' cur';  lbl += ' \u2190 current'; }
      else if (i === nxtIdx) { cls += ' nxt';  lbl += ' \u2190 next'; }
      li.className = cls;
      li.innerHTML = '<span class="sdot"></span>' + lbl;
      stopList.appendChild(li);
    });
    var cur = stopList.querySelector('.cur');
    if (cur) cur.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function updateUI(fraction, fromIdx, toIdx) {
    var total = stops.length;
    if (!total) return;

    var pct = total > 1
      ? Math.round(((fromIdx + (fraction || 0)) / (total - 1)) * 100)
      : 0;
    if (pbar)   pbar.style.width   = pct + '%';
    if (plabel) plabel.textContent = pct + '%';

    var segN   = Math.max(1, total - 1);
    var doneKm = TOTAL_KM > 0
      ? ((fromIdx + (fraction || 0)) / segN) * TOTAL_KM
      : (fromIdx + (fraction || 0)) * 0.3;
    var remain = Math.max(0, TOTAL_KM - doneKm);
    var etaMs  = AVG_SPEED > 0 ? (remain / AVG_SPEED) * 3600000 : 0;

    if (mDist)  mDist.textContent  = doneKm.toFixed(1) + ' km';
    if (mSpeed) mSpeed.textContent = AVG_SPEED + ' km/h';
    if (mEta)   mEta.textContent   = etaMs > 0 ? fmtTime(etaMs) : 'N/A';

    if (uiCur) uiCur.textContent = stops[fromIdx] ? stops[fromIdx].name : 'N/A';
    if (uiNxt) uiNxt.textContent = stops[toIdx]   ? stops[toIdx].name   : 'End of route';
  }

  /* ══ LEG ANIMATION ═══════════════════════════════════ */
  function stopLeg() {
    if (legTimer) { clearInterval(legTimer); legTimer = null; }
  }

  function startLeg(from, to) {
    stopLeg();
    if (!stops[from] || !stops[to]) return;

    legFrom    = from;
    legTo      = to;
    legStartTs = Date.now();
    state      = 'running';
    setBtns('running');

    var sf  = stops[from];
    var st  = stops[to];
    var deg = bearing(sf.lat, sf.lng, st.lat, st.lng);

    setChip('running', 'En route \u2192 ' + st.name);
    renderStopList(to);
    refreshStops(to);

    legTimer = setInterval(function () {
      var elapsed = Date.now() - legStartTs;
      var raw     = Math.min(1, elapsed / LEG_MS);
      var t       = ease(raw);
      var lat     = lerp(sf.lat, st.lat, t);
      var lng     = lerp(sf.lng, st.lng, t);

      setBusPosition(lat, lng, deg);
      followBus(lat, lng);
      rebuildLines(lat, lng);
      updateUI(raw, from, to);

      if (raw >= 1) {
        stopLeg();
        curIdx = to;
        var pos = stops[curIdx];

        setBusPosition(pos.lat, pos.lng, deg);
        followBus(pos.lat, pos.lng);
        rebuildLines(pos.lat, pos.lng);
        updateUI(0, curIdx, Math.min(curIdx + 1, stops.length - 1));
        renderStopList(curIdx + 1);
        refreshStops(curIdx + 1);

        gpsCall('arrive', { index: curIdx }).catch(function () {});

        if (curIdx >= stops.length - 1) {
          state = 'ended';
          setBtns('paused');
          btnDepart.disabled = true;
          setChip('ended', 'Route complete \u2713');
        } else {
          gpsCall('depart').catch(function () {});
          startLeg(curIdx, curIdx + 1);
        }
      }
    }, TICK_MS);
  }

  function gpsCall(action, extra) {
    var params = new URLSearchParams();
    if (action) params.set('action', action);
    if (extra) {
      Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
    }
    return fetch(GPS_URL + '?' + params.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (r) {
      if (!r.ok) throw new Error('GPS error');
      return r.json();
    });
  }

  function startTrip() {
    if (!mapsReady || stops.length < 2) return;
    gpsCall('start').catch(function () {});
    curIdx = 0;
    state  = 'running';
    setBtns('running');

    var p0 = stops[0];
    var h0 = stops.length > 1
      ? bearing(p0.lat, p0.lng, stops[1].lat, stops[1].lng)
      : 0;
    setBusPosition(p0.lat, p0.lng, h0);
    rebuildLines(p0.lat, p0.lng);
    followBus(p0.lat, p0.lng);
    startLeg(0, 1);
  }

  function endTrip() {
    stopLeg();
    state  = 'idle';
    curIdx = 0;
    gpsCall('end').catch(function () {});

    if (stops[0]) {
      setBusPosition(stops[0].lat, stops[0].lng, 0);
      rebuildLines(stops[0].lat, stops[0].lng);
    }
    fitOverview();
    refreshStops(-1);
    renderStopList(-1);
    updateUI(0, 0, 1);
    setBtns('idle');
    setChip('idle', 'Trip ended');
  }

  function arriveStop() {
    if (state !== 'running') return;
    stopLeg();
    curIdx = legTo;
    var pos = stops[curIdx];
    var nxt = stops[Math.min(curIdx + 1, stops.length - 1)];
    var h   = nxt ? bearing(pos.lat, pos.lng, nxt.lat, nxt.lng) : busHeading;
    setBusPosition(pos.lat, pos.lng, h);
    followBus(pos.lat, pos.lng);
    rebuildLines(pos.lat, pos.lng);
    updateUI(0, curIdx, Math.min(curIdx + 1, stops.length - 1));
    renderStopList(curIdx + 1);
    refreshStops(curIdx + 1);
    gpsCall('arrive', { index: curIdx }).catch(function () {});
    state = 'paused';
    setBtns('paused');
    setChip('paused', 'At ' + pos.name);
  }

  function departStop() {
    if (curIdx >= stops.length - 1) return;
    gpsCall('depart').catch(function () {});
    startLeg(curIdx, curIdx + 1);
  }

  function drawOverview() {
    if (!stops.length) return;
    var p0 = stops[0];
    var h0 = stops.length > 1
      ? bearing(p0.lat, p0.lng, stops[1].lat, stops[1].lng)
      : 0;
    setBusPosition(p0.lat, p0.lng, h0);
    rebuildLines(p0.lat, p0.lng);
    fitOverview();
    refreshStops(1);
    renderStopList(1);
    updateUI(0, 0, 1);
  }

  function bootTripState() {
    return gpsCall().then(function (data) {
      stops  = (data.stops && data.stops.length) ? data.stops : STOPS_FALLBACK;
      curIdx = data.currentStopIndex || INIT_IDX;
      drawOverview();

      if (data.status === 'running' || data.status === 'paused') {
        state = 'paused';
        setChip('paused', 'Trip in progress');
        setBtns('paused');
      } else {
        state = 'idle';
        setChip('idle', 'Trip not started');
        setBtns('idle');
      }
    }).catch(function () {
      stops  = STOPS_FALLBACK;
      curIdx = INIT_IDX;
      drawOverview();
      setChip('idle', 'Trip not started');
      setBtns('idle');
    });
  }

  function boot() {
    loadGoogleMaps()
      .then(function () {
        initGoogleMap();
        return bootTripState();
      })
      .catch(function () {
        setChip('idle', 'Map unavailable');
        setBtns('idle');
        var el = document.getElementById('live-map');
        if (el) {
          el.innerHTML = '<p class="p-8 text-center text-sm text-slate-500">'
            + 'Could not load Google Maps. Check api/map.php and your API key.</p>';
        }
      });
  }

  document.querySelectorAll('#pax-list .pax-item').forEach(function (item) {
    var hdr = item.querySelector('[data-toggle]');
    if (!hdr) return;
    hdr.addEventListener('click', function () { item.classList.toggle('open'); });
  });

  btnStart .addEventListener('click', startTrip);
  btnArrive.addEventListener('click', arriveStop);
  btnDepart.addEventListener('click', departStop);
  btnEnd   .addEventListener('click', endTrip);

  document.addEventListener('DOMContentLoaded', boot);
})();
</script>
</body>
</html>