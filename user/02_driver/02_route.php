<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/fare.php';

$activeTripCollected   = 0.0;
$onboardPassengerCount = 0;

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId   = (int) $_SESSION['user_id'];
$driverName = trim($_SESSION['full_name'] ?? 'Driver');

$activeTrip            = null;
$routeStops            = [];
$routeName             = 'No active route';
$routeDisplay          = 'No route details available';
$totalRouteDistance    = 0.0;
$averageSpeed          = 30;
$currentStopIndex      = 0;
$routeProgressPercent  = 0;
$progressMilestones    = [];
$mapCenterLat          = 14.821028;
$mapCenterLng          = 120.902972;
$tripDistanceLabel     = '0.0 km';
$tripSpeedLabel        = 'N/A';
$tripArrivalLabel      = 'N/A';

if ($stmt = $conn->prepare(
    'SELECT t.trip_id, t.route_id, t.current_stop_index,
            r.route_name, r.display_name
     FROM trips t
     JOIN routes r ON t.route_id = r.route_id
     WHERE t.driver_id = ? AND t.status = ?
     LIMIT 1'
)) {
    $status = 'active';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $activeTrip = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if ($activeTrip) {
    $routeName    = $activeTrip['route_name'];
    $routeDisplay = $activeTrip['display_name'];
    $routeId      = (int) $activeTrip['route_id'];
    $tripId       = (int) $activeTrip['trip_id'];

    if ($stmt = $conn->prepare(
        'SELECT rs.stop_order, s.stop_id, s.stop_name, s.lat, s.lng
         FROM route_stops rs
         JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $routeStops[] = [
                'stop_id'   => (int) $row['stop_id'],
                'stop_name' => $row['stop_name'],
                'lat'       => (float) $row['lat'],
                'lng'       => (float) $row['lng'],
            ];
        }
        $stmt->close();
    }

    $routeStopCount   = count($routeStops);
    $currentStopIndex = max(0, min($routeStopCount - 1, (int) ($activeTrip['current_stop_index'] ?? 0)));

    if ($routeStopCount > 0) {
        $mapCenterLat = $routeStops[0]['lat'];
        $mapCenterLng = $routeStops[0]['lng'];
    }

    if ($stmt = $conn->prepare('SELECT COUNT(*) AS c FROM active_passengers WHERE trip_id = ?')) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $onboardPassengerCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
    if ($stmt = $conn->prepare(
        'SELECT COALESCE(SUM(fare_amount), 0) AS total FROM trip_transactions WHERE trip_id = ?'
    )) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $activeTripCollected = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }

    if ($routeStopCount > 1) {
        $cumulativeDistances = build_cumulative_from_route_stops($routeStops);
        $totalRouteDistance   = end($cumulativeDistances);
        $routeProgressPercent = round(($currentStopIndex / ($routeStopCount - 1)) * 100);
        $estHours             = max(0.1, ($routeStopCount - 1) * 0.06);
        $averageSpeed         = $totalRouteDistance > 0
            ? (int) round($totalRouteDistance / $estHours)
            : 30;
        $tripDistanceLabel    = sprintf('%.1f km', $totalRouteDistance);
        $tripSpeedLabel       = sprintf('%d km/h', $averageSpeed);
        $remain               = $totalRouteDistance > 0
            ? $totalRouteDistance * (1 - $currentStopIndex / max(1, $routeStopCount - 1))
            : 0;
        $etaSec               = $averageSpeed > 0 ? ($remain / $averageSpeed) * 3600 : 0;
        $tripArrivalLabel     = $etaSec > 0
            ? date('g:i A', time() + (int) round($etaSec))
            : 'N/A';

        $indices = [0];
        if ($routeStopCount > 4) {
            $indices[] = (int) floor(($routeStopCount - 1) / 4);
            $indices[] = (int) floor(($routeStopCount - 1) / 2);
            $indices[] = (int) floor(3 * ($routeStopCount - 1) / 4);
        }
        $indices[] = $routeStopCount - 1;
        $indices   = array_values(array_unique($indices));
        sort($indices);
        foreach ($indices as $index) {
            $progressMilestones[] = [
                'index' => $index,
                'name'  => $routeStops[$index]['stop_name'],
            ];
        }
    }
}

$routeStopsForMap = array_map(static fn($s) => [
    'stop_id' => $s['stop_id'],
    'name'    => $s['stop_name'],
    'lat'     => $s['lat'],
    'lng'     => $s['lng'],
], $routeStops);
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Driver - Live Tracking</title>
    <link rel="icon" type="image/png" href="../../images/logo.png" />
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect" />
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect" />
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <!-- Icons -->
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "secondary-fixed-dim": "#afcae2",
              "tertiary-fixed-dim": "#ffb783",
              "on-primary-fixed-variant": "#0040a1",
              background: "#f8f9fa",
              "on-secondary-container": "#4e677c",
              tertiary: "#713700",
              "surface-dim": "#d9dadb",
              primary: "#0040a1",
              "on-tertiary-container": "#ffd0b0",
              "surface-tint": "#0056d2",
              "on-background": "#191c1d",
              "surface-container-low": "#f3f4f5",
              "on-primary-fixed": "#001847",
              "inverse-primary": "#b2c5ff",
              "surface-container-high": "#e7e8e9",
              "outline-variant": "#c3c6d6",
              "primary-fixed-dim": "#b2c5ff",
              "primary-container": "#0056d2",
              "primary-fixed": "#dae2ff",
              "on-secondary-fixed-variant": "#30495d",
              "on-primary": "#ffffff",
              "inverse-surface": "#2e3132",
              "on-tertiary": "#ffffff",
              "on-error-container": "#93000a",
              "surface-variant": "#e1e3e4",
              "inverse-on-surface": "#f0f1f2",
              outline: "#737785",
              "on-secondary-fixed": "#001e30",
              "surface-bright": "#f8f9fa",
              "on-primary-container": "#ccd8ff",
              "on-error": "#ffffff",
              secondary: "#486176",
              "on-surface": "#191c1d",
              "surface-container-lowest": "#ffffff",
              surface: "#f8f9fa",
              "secondary-fixed": "#cbe6ff",
              "on-tertiary-fixed": "#301400",
              "on-tertiary-fixed-variant": "#713700",
              "tertiary-fixed": "#ffdcc5",
              "on-secondary": "#ffffff",
              "tertiary-container": "#944b00",
              "error-container": "#ffdad6",
            },
            fontFamily: {
              headline: ["Manrope"],
              body: ["Inter"],
              label: ["Inter"],
            },
            borderRadius: {
              DEFAULT: "0.125rem",
              lg: "0.25rem",
              xl: "0.5rem",
              full: "0.75rem",
            },
          },
        },
      };
    </script>
    <style>
      .material-symbols-outlined {
        font-variation-settings:
          "FILL" 0,
          "wght" 400,
          "GRAD" 0,
          "opsz" 24;
        vertical-align: middle;
      }
      .tonal-layering-no-lines {
        border: none !important;
      }
      #live-map {
        width: 100%;
        height: 440px;
        min-height: 320px;
        border-radius: 1rem;
        overflow: hidden;
        position: relative;
        z-index: 0;
        background: #e2e8f0;
      }
      #status-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.25rem 0.85rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        transition: all 0.3s;
      }
      #status-chip.idle { background: #f1f5f9; color: #64748b; }
      #status-chip.running { background: #dcfce7; color: #15803d; }
      #status-chip.paused { background: #dbeafe; color: #1d4ed8; }
      #status-chip.ended { background: #fef9c3; color: #854d0e; }
      .chipdot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
      }
      .route-pct-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 3rem;
        padding: 0.2rem 0.75rem;
        border-radius: 9999px;
        background: #0040a1;
        color: #fff;
        font-weight: 700;
        font-size: 0.85rem;
      }
      .route-progress .current {
        border-color: #bfdbfe;
        background: #fff;
      }
      /* Progress bar styles */
      .progress-bar {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        overflow-x: auto;
        padding-bottom: 6px;
        scrollbar-width: none;
        -ms-overflow-style: none;
      }
      .progress-bar::-webkit-scrollbar {
        display: none;
      }
      .map-controls-grid {
        display: grid;
        grid-template-columns: 1fr minmax(168px, 200px);
        gap: 1rem;
        margin-top: 1rem;
        align-items: stretch;
      }
      @media (max-width: 960px) {
        .map-controls-grid {
          grid-template-columns: 1fr;
        }
      }
      .map-area {
        min-width: 0;
      }
      .map-area #live-map {
        height: 480px;
      }
      .map-card-header h2 {
        font-size: 1.35rem;
        line-height: 1.25;
      }
      .map-progress-row {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.5rem;
      }
      .trip-controls-panel {
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
        padding: 1rem;
        border-radius: 1rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        min-height: 100%;
      }
      .trip-metrics-panel {
        margin-top: auto;
        padding-top: 0.85rem;
        border-top: 1px solid #e2e8f0;
      }
      .trip-metrics-rows {
        margin-top: 0.5rem;
        font-size: 0.8rem;
        color: #334155;
      }
      .trip-metrics-rows .metric-row {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.35rem 0;
      }
      .trip-metrics-rows .metric-row strong {
        color: #0f172a;
        font-weight: 700;
        text-align: right;
      }
      .trip-controls-panel .panel-title {
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.25rem;
      }
      .tb {
        border-radius: 0.85rem;
        padding: 0.7rem 0.5rem;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        cursor: pointer;
        transition: opacity 0.15s;
        width: 100%;
        text-align: center;
      }
      .tb:disabled {
        opacity: 0.35;
        cursor: not-allowed;
      }
      .progress-segment {
        display: flex;
        flex-direction: column;
        align-items: center;
        flex: 0 0 76px;
        min-width: 76px;
        max-width: 100px;
      }
      .progress-segment .dot {
        width: 26px;
        height: 26px;
        border-radius: 9999px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
      }
      .progress-segment.completed .dot {
        background: #10b981;
        color: #fff;
      }
      .progress-segment.current .dot {
        background: #0040a1;
        color: #fff;
      }
      .progress-segment.upcoming .dot {
        background: #fff;
        border: 2px solid #cbd5e1;
        color: #6b7280;
      }
      .progress-connector {
        height: 6px;
        border-radius: 9999px;
        flex: 1 1 40px;
        min-width: 40px;
      }
      .progress-connector.completed {
        background: linear-gradient(90deg, #10b981, #60a5fa);
      }
      .progress-connector.upcoming {
        background: #e6eef8;
      }
      .progress-label {
        margin-top: 6px;
        font-size: 0.65rem;
        line-height: 1.25;
        color: #374151;
        text-align: center;
        white-space: normal;
        overflow-wrap: anywhere;
        max-width: 100%;
      }
      .progress-segment.current .progress-label {
        color: #0040a1;
        font-weight: 700;
      }
      .progress-segment.completed .progress-label {
        color: #15803d;
      }
      .stops-progress-wrap {
        margin-top: 1rem;
        padding-top: 0.5rem;
        border-top: 1px solid #e2e8f0;
      }
      .stops-progress-wrap .progress-bar {
        gap: 8px;
        padding-bottom: 10px;
      }
      /* Current stop card emphasis */
      .current-card {
        border: 2px solid #0040a1;
      }
      .badge-active {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 9999px;
        background: #0040a1;
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
      }
    </style>
  </head>
  <body class="bg-slate-100 font-body text-slate-900 antialiased">
    <div class="flex min-h-screen">
      <aside
        class="fixed left-0 top-0 h-screen w-[260px] bg-white border-r border-slate-200 shadow-sm"
      >
        <div class="flex h-full flex-col">
          <div class="px-6 py-8 border-b border-slate-200">
            <span
              class="text-2xl font-black tracking-tight text-primary font-headline"
              >TrackFare</span
            >
            <p class="mt-2 text-sm text-slate-500">Driver Panel</p>
          </div>
          <nav class="flex-1 px-4 py-6 space-y-1">
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="01_dashboard.php"
            >
              <span class="material-symbols-outlined">dashboard</span>
              <span>Dashboard</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold transition"
              href="02_route.php"
            >
              <span class="material-symbols-outlined">alt_route</span>
              <span>Route</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="03_logs.php"
            >
              <span class="material-symbols-outlined">receipt_long</span>
              <span>Logs</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="04_profile.php"
            >
              <span class="material-symbols-outlined">person</span>
              <span>Profile</span>
            </a>
          </nav>
          <div class="mt-auto px-6 py-6 border-t border-slate-200">
            <div class="flex items-center gap-3">
              <div
                class="w-12 h-12 rounded-2xl overflow-hidden border border-slate-200"
              >
                <img
                  src="../../images/pfp.png"
                  alt="Driver profile"
                  class="w-full h-full object-cover"
                />
              </div>
              <div>
                <p class="text-sm font-semibold text-slate-900">
                  <?php echo htmlspecialchars($driverName, ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <p class="text-xs text-slate-500">Driver</p>
              </div>
            </div>
            <button
              class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
              onclick="window.location.href='../../auth/logout.php'"
              title="Logout"
            >
              <span class="material-symbols-outlined">logout</span>
              Logout
            </button>
          </div>
        </div>
      </aside>
      <main class="ml-[260px] flex-1 min-h-screen bg-slate-100 p-10">
        <div class="max-w-full">
          <header class="mb-10">
            <h1
              class="text-4xl font-extrabold tracking-tight text-slate-900 font-headline"
            >
              Route Management
            </h1>
            <p class="mt-2 text-sm text-slate-600">
              Manage the current stop and route progress.
            </p>
          </header>
          <div class="grid grid-cols-12 gap-8">
            <!-- Live Map (full width) -->
            <section class="col-span-12">
              <article
                class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200"
              >
                <div class="map-card-header flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <h2 id="route-display-title" class="font-black text-slate-900 font-headline truncate">
                      <?php echo htmlspecialchars($routeDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </h2>
                    <div class="map-progress-row">
                      <span class="text-xs uppercase tracking-[0.15em] text-slate-500 font-semibold">Route progress</span>
                      <span id="route-pct-label" class="route-pct-badge"><?php echo (int) $routeProgressPercent; ?>%</span>
                    </div>
                  </div>
                  <span id="status-chip" class="idle shrink-0"
                    ><span class="chipdot"></span
                    ><span id="chip-label">Syncing…</span></span
                  >
                </div>
                <div class="map-controls-grid">
                  <div class="map-area">
                    <div id="live-map" role="application" aria-label="Live route map"></div>
                  </div>
                  <aside class="trip-controls-panel" aria-label="Trip controls">
                    <p class="panel-title">Trip Controls</p>
                    <button type="button" id="btn-start" class="tb bg-emerald-600 text-white">&#9654; Start</button>
                    <button type="button" id="btn-arrive" class="tb bg-sky-600 text-white" disabled>&#9646; Arrive</button>
                    <button type="button" id="btn-depart" class="tb bg-indigo-600 text-white" disabled>&#9654; Depart</button>
                    <button type="button" id="btn-end" class="tb bg-rose-600 text-white" disabled>&#9632; End Trip</button>
                    <div class="trip-metrics-panel">
                      <p class="panel-title">Trip Metrics</p>
                      <div class="trip-metrics-rows">
                        <div class="metric-row">
                          <span>Route Progress</span>
                          <strong id="m-progress-pct"><?php echo (int) $routeProgressPercent; ?>%</strong>
                        </div>
                        <div class="metric-row">
                          <span>Onboard</span>
                          <strong><?php echo $onboardPassengerCount; ?></strong>
                        </div>
                        <div class="metric-row">
                          <span>Fares collected</span>
                          <strong>&#8369;<?php echo number_format($activeTripCollected, 2); ?></strong>
                        </div>
                        <div class="metric-row">
                          <span>Distance Traveled</span>
                          <strong id="m-dist"><?php echo htmlspecialchars($tripDistanceLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="metric-row">
                          <span>Average Speed</span>
                          <strong id="m-speed"><?php echo htmlspecialchars($tripSpeedLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="metric-row">
                          <span>Est. Arrival</span>
                          <strong id="m-eta"><?php echo htmlspecialchars($tripArrivalLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                      </div>
                    </div>
                  </aside>
                </div>
                <div class="stops-progress-wrap">
                  <p class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold mb-2">All stops</p>
                  <div
                    id="route-progress-bar"
                    class="progress-bar"
                    role="list"
                    aria-label="Route progress by stop"
                  ></div>
                </div>
              </article>
            </section>
          </div>
        </div>
      </main>
    </div>
    <script>
    (function () {
      'use strict';

      var MAP_CFG_URL = '../../api/map.php';
      var GPS_URL     = '../../config/gps.php';
      var STOPS_FALLBACK = <?php echo json_encode($routeStopsForMap, JSON_UNESCAPED_UNICODE); ?>;
      var TOTAL_KM    = <?php echo (float) $totalRouteDistance; ?>;
      var ROUTE_ID    = <?php echo (int) ($activeTrip['route_id'] ?? 1); ?>;
      var AVG_SPEED   = <?php echo max(1, (int) $averageSpeed); ?>;
      var INIT_IDX    = <?php echo (int) $currentStopIndex; ?>;
      var MAP_CENTER  = { lat: <?php echo $mapCenterLat; ?>, lng: <?php echo $mapCenterLng; ?> };
      var OSRM_URL    = 'https://router.project-osrm.org/route/v1/driving';
      var LEG_MS      = 2000;
      var TICK_MS     = 16;
      var OV_ZOOM     = 12;
      var NAV_ZOOM    = 17;

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
      var legRoutesCache = {};
      var traveledTrail = [];
      var currentLegPath = null;
      var lastGpsSave = 0;

      var chipEl    = document.getElementById('status-chip');
      var chipLbl   = document.getElementById('chip-label');
      var btnStart  = document.getElementById('btn-start');
      var btnArrive = document.getElementById('btn-arrive');
      var btnDepart = document.getElementById('btn-depart');
      var btnEnd    = document.getElementById('btn-end');
      var routeTitle = document.getElementById('route-display-title');
      var routePct   = document.getElementById('route-pct-label');
      var mDist      = document.getElementById('m-dist');
      var mSpeed     = document.getElementById('m-speed');
      var mEta       = document.getElementById('m-eta');
      var mProgPct   = document.getElementById('m-progress-pct');

      function lerp(a, b, t) { return a + (b - a) * t; }
      function ease(t) {
        return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
      }

      function latLng(lat, lng) { return new google.maps.LatLng(lat, lng); }

      function bearing(lat1, lng1, lat2, lng2) {
        var R = Math.PI / 180, p1 = lat1 * R, p2 = lat2 * R, dl = (lng2 - lng1) * R;
        var y = Math.sin(dl) * Math.cos(p2);
        var x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
      }

      function haversineKm(a, b) {
        var R = 6371, dLat = (b.lat - a.lat) * Math.PI / 180, dLng = (b.lng - a.lng) * Math.PI / 180;
        var p1 = a.lat * Math.PI / 180, p2 = b.lat * Math.PI / 180;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
          + Math.cos(p1) * Math.cos(p2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
      }

      function legCacheKey(a, b) { return a + '-' + b; }
      function straightPath(from, to) {
        return [{ lat: from.lat, lng: from.lng }, { lat: to.lat, lng: to.lng }];
      }

      function fetchOsrmRoute(fromIdx, toIdx) {
        var key = legCacheKey(fromIdx, toIdx);
        if (legRoutesCache[key]) return Promise.resolve(legRoutesCache[key]);
        var a = stops[fromIdx], b = stops[toIdx];
        var url = OSRM_URL + '/' + a.lng + ',' + a.lat + ';' + b.lng + ',' + b.lat + '?overview=full&geometries=geojson';
        return fetch(url, { headers: { Accept: 'application/json' } })
          .then(function (r) { if (!r.ok) throw new Error('OSRM'); return r.json(); })
          .then(function (data) {
            var path;
            if (data.code === 'Ok' && data.routes && data.routes[0] && data.routes[0].geometry) {
              path = data.routes[0].geometry.coordinates.map(function (c) { return { lat: c[1], lng: c[0] }; });
            } else path = straightPath(a, b);
            legRoutesCache[key] = path;
            return path;
          })
          .catch(function () {
            var path = straightPath(a, b);
            legRoutesCache[key] = path;
            return path;
          });
      }

      function prefetchLegRoute(fromIdx, toIdx) {
        if (fromIdx < 0 || toIdx >= stops.length) return;
        fetchOsrmRoute(fromIdx, toIdx).catch(function () {});
      }

      function prefetchAllLegRoutes() {
        var i = 0;
        function next() {
          if (i >= stops.length - 1) return;
          fetchOsrmRoute(i, i + 1).finally(function () {
            i += 1;
            setTimeout(next, 150);
          });
        }
        next();
      }

      function appendPathPoints(base, extra) {
        if (!extra || !extra.length) return base.slice();
        var out = base.slice(), start = out.length ? 1 : 0;
        for (var i = start; i < extra.length; i++) out.push(extra[i]);
        return out;
      }

      function pathToLatLngs(pts) { return pts.map(function (p) { return latLng(p.lat, p.lng); }); }

      function buildCumulativeDistances(path) {
        var cum = [0];
        for (var i = 1; i < path.length; i++) cum.push(cum[i - 1] + haversineKm(path[i - 1], path[i]));
        return cum;
      }

      function positionAtFraction(path, cumDist, t) {
        if (!path.length) return { point: { lat: 0, lng: 0 }, index: 0 };
        if (path.length === 1 || t <= 0) return { point: path[0], index: 0 };
        var total = cumDist[cumDist.length - 1];
        if (t >= 1 || total <= 0) {
          return { point: path[path.length - 1], index: path.length - 1 };
        }
        var target = t * total;
        for (var i = 1; i < cumDist.length; i++) {
          if (cumDist[i] >= target) {
            var segLen = cumDist[i] - cumDist[i - 1];
            var segT = segLen > 0 ? (target - cumDist[i - 1]) / segLen : 0;
            return {
              point: {
                lat: path[i - 1].lat + (path[i].lat - path[i - 1].lat) * segT,
                lng: path[i - 1].lng + (path[i].lng - path[i - 1].lng) * segT,
              },
              index: i - 1,
            };
          }
        }
        return { point: path[path.length - 1], index: path.length - 1 };
      }

      function partialPathAlong(path, cumDist, t) {
        var pos = positionAtFraction(path, cumDist, t);
        var out = path.slice(0, pos.index + 1);
        out.push(pos.point);
        return out;
      }

      function remainderPathFrom(path, cumDist, t) {
        var pos = positionAtFraction(path, cumDist, t);
        var out = [pos.point];
        for (var i = pos.index + 1; i < path.length; i++) out.push(path[i]);
        return out;
      }

      function headingOnPath(path, cumDist, t) {
        var pos = positionAtFraction(path, cumDist, t);
        var ahead = positionAtFraction(path, cumDist, Math.min(1, t + 0.03));
        return bearing(pos.point.lat, pos.point.lng, ahead.point.lat, ahead.point.lng);
      }

      function recalcRouteMetrics() {
        if (stops.length < 2) return;
        var cum = 0;
        for (var i = 1; i < stops.length; i++) cum += haversineKm(stops[i - 1], stops[i]);
        TOTAL_KM = cum;
      }

      function fmtTime(ms) {
        return new Date(Date.now() + ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
      }

      function setChip(cls, txt) {
        if (!chipEl || !chipLbl) return;
        chipEl.className = cls;
        chipLbl.textContent = txt;
      }

      function updateRouteHeader(data) {
        if (data.displayName && routeTitle) routeTitle.textContent = data.displayName;
        if (typeof data.totalKm === 'number' && data.totalKm > 0) TOTAL_KM = data.totalKm;
        else recalcRouteMetrics();
        if (typeof data.routeId === 'number') ROUTE_ID = data.routeId;
      }

      function applyGpsRoute(data) {
        if (data.stops && data.stops.length) {
          stops = data.stops;
          STOPS_FALLBACK = data.stops;
          MAP_CENTER = { lat: stops[0].lat, lng: stops[0].lng };
        }
        updateRouteHeader(data);
        legRoutesCache = {};
        curIdx = 0;
      }

      function renderRouteProgressBar(fromIdx, fraction) {
        var bar = document.getElementById('route-progress-bar');
        if (!bar || !stops.length) return;

        var prog = fromIdx + (fraction || 0);
        var pct = stops.length > 1
          ? Math.round((prog / (stops.length - 1)) * 100)
          : 0;
        if (routePct) routePct.textContent = pct + '%';
        if (mProgPct) mProgPct.textContent = pct + '%';

        bar.innerHTML = '';
        stops.forEach(function (stop, i) {
          var segState;
          if (prog >= i + 1 || (i === stops.length - 1 && prog >= i)) {
            segState = 'completed';
          } else if (prog >= i && prog < i + 1) {
            segState = 'current';
          } else {
            segState = 'upcoming';
          }

          var dot = segState === 'completed' ? '\u2714' : (segState === 'current' ? '\u25cf' : '\u25cb');
          var seg = document.createElement('div');
          seg.className = 'progress-segment ' + segState;
          seg.setAttribute('role', 'listitem');
          seg.innerHTML = '<div class="dot">' + dot + '</div>'
            + '<div class="progress-label">' + stop.name + '</div>';
          bar.appendChild(seg);

          if (i < stops.length - 1) {
            var connClass = prog >= i + 1 ? 'completed' : 'upcoming';
            var conn = document.createElement('div');
            conn.className = 'progress-connector ' + connClass;
            conn.setAttribute('aria-hidden', 'true');
            bar.appendChild(conn);
          }
        });

        var curEl = bar.querySelector('.progress-segment.current');
        if (curEl) curEl.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
      }

      function updateUI(fraction, fromIdx, toIdx) {
        renderRouteProgressBar(fromIdx, fraction);
        var total = stops.length;
        if (!total) return;
        var segN = Math.max(1, total - 1);
        var doneKm = TOTAL_KM > 0
          ? ((fromIdx + (fraction || 0)) / segN) * TOTAL_KM
          : 0;
        var remain = Math.max(0, TOTAL_KM - doneKm);
        var etaMs = AVG_SPEED > 0 ? (remain / AVG_SPEED) * 3600000 : 0;
        if (mDist) mDist.textContent = doneKm.toFixed(1) + ' km';
        if (mSpeed) mSpeed.textContent = AVG_SPEED + ' km/h';
        if (mEta) mEta.textContent = etaMs > 0 ? fmtTime(etaMs) : 'N/A';
      }

      function setBtns(s) {
        if (!btnStart) return;
        btnStart.disabled  = s !== 'idle';
        btnArrive.disabled = s !== 'running';
        btnDepart.disabled = s !== 'paused';
        btnEnd.disabled    = s === 'idle';
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

      function persistGps(lat, lng, from, to, progress) {
        var now = Date.now();
        if (now - lastGpsSave < 350) return;
        lastGpsSave = now;
        gpsCall('update', {
          lat: lat,
          lng: lng,
          index: from,
          legFrom: from,
          legTo: to,
          legProgress: progress,
          status: 'running',
        }).catch(function () {});
      }

      function followBus(lat, lng) {
        map.setCenter(latLng(lat, lng));
        map.setZoom(NAV_ZOOM);
        map.setHeading(0);
        map.setTilt(0);
      }

      function initGoogleMap() {
        map = new google.maps.Map(document.getElementById('live-map'), {
          center: MAP_CENTER,
          zoom: OV_ZOOM,
          mapTypeId: 'roadmap',
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
            if (window.google && window.google.maps) { resolve(); return; }
            var cb = '__trackfareRouteMapsInit';
            window[cb] = function () { delete window[cb]; resolve(); };
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key='
              + encodeURIComponent(cfg.apiKey) + '&callback=' + cb;
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
        if (!busMkr) busMkr = mkBusMarker(pos, heading);
        else {
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
          var cur = i === curIdx;
          var nxt = i === nextIdx;
          var col = done ? '#16a34a' : cur ? '#0040a1' : nxt ? '#2563eb' : '#94a3b8';
          var sz = (cur || nxt) ? 13 : 9;
          stopMkrs.push(new google.maps.Marker({
            position: latLng(stop.lat, stop.lng),
            map: map,
            title: stop.name,
            icon: stopDotIcon(col, sz),
            zIndex: cur ? 600 : nxt ? 500 : done ? 100 : 200,
          }));
        });
      }

      function rebuildLines(activePartial, legRemainder, nextLegFrom) {
        if (lineTaken) { lineTaken.setMap(null); lineTaken = null; }
        if (lineAhead) { lineAhead.setMap(null); lineAhead = null; }
        if (!stops.length) return;

        var takenPts = appendPathPoints(traveledTrail, activePartial || []);
        if (takenPts.length > 1) {
          lineTaken = new google.maps.Polyline({
            path: pathToLatLngs(takenPts),
            strokeColor: '#16a34a',
            strokeOpacity: 0.92,
            strokeWeight: 6,
            map: map,
            zIndex: 10,
          });
        }

        var aheadPts = legRemainder ? legRemainder.slice() : [];
        var fromLeg = typeof nextLegFrom === 'number' ? nextLegFrom : curIdx + 1;
        for (var j = fromLeg; j < stops.length - 1; j++) {
          var key = legCacheKey(j, j + 1);
          aheadPts = appendPathPoints(aheadPts, legRoutesCache[key] || straightPath(stops[j], stops[j + 1]));
        }
        if (aheadPts.length > 1) {
          lineAhead = new google.maps.Polyline({
            path: pathToLatLngs(aheadPts),
            strokeColor: '#60a5fa',
            strokeOpacity: 0,
            strokeWeight: 5,
            icons: [{
              icon: { path: 'M 0,-1 0,1', strokeOpacity: 0.85, strokeColor: '#60a5fa', scale: 4 },
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
      }

      function buildTraveledTrailTo(stopIndex) {
        traveledTrail = [{ lat: stops[0].lat, lng: stops[0].lng }];
        if (stopIndex <= 0) return Promise.resolve();
        var chain = Promise.resolve();
        for (var i = 0; i < stopIndex; i++) {
          (function (leg) {
            chain = chain.then(function () {
              return fetchOsrmRoute(leg, leg + 1).then(function (path) {
                traveledTrail = appendPathPoints(traveledTrail, path);
              });
            });
          })(i);
        }
        return chain;
      }

      function stopLeg() {
        if (legTimer) { clearInterval(legTimer); legTimer = null; }
      }

      function startLeg(from, to, resumeAt) {
        stopLeg();
        if (!stops[from] || !stops[to]) return;

        legFrom = from;
        legTo   = to;
        state   = 'running';
        setBtns('running');

        var st = stops[to];
        var resumeT = Math.max(0, Math.min(1, resumeAt || 0));
        setChip('running', 'Routing \u2192 ' + st.name);
        refreshStops(to);
        prefetchLegRoute(to, to + 1);
        if (resumeT <= 0) gpsCall('depart').catch(function () {});

        fetchOsrmRoute(from, to).then(function (path) {
          if (legFrom !== from || legTo !== to) return;

          currentLegPath = path;
          var cumDist = buildCumulativeDistances(path);
          legStartTs = Date.now() - resumeT * LEG_MS;
          setChip('running', 'En route \u2192 ' + st.name);

          legTimer = setInterval(function () {
            var elapsed = Date.now() - legStartTs;
            var raw     = Math.min(1, elapsed / LEG_MS);
            var t       = ease(raw);
            var pos     = positionAtFraction(path, cumDist, t);
            var deg     = headingOnPath(path, cumDist, t);
            var partial = partialPathAlong(path, cumDist, t);
            var remain  = remainderPathFrom(path, cumDist, t);

            setBusPosition(pos.point.lat, pos.point.lng, deg);
            followBus(pos.point.lat, pos.point.lng);
            rebuildLines(partial, remain, to);
            updateUI(raw, from, to);
            if (raw < 1) persistGps(pos.point.lat, pos.point.lng, from, to, raw);

            if (raw >= 1) {
              stopLeg();
              curIdx = to;
              traveledTrail = appendPathPoints(traveledTrail, path);
              currentLegPath = null;

              var endPos = stops[curIdx];
              var endDeg = curIdx < stops.length - 1
                ? headingOnPath(path, cumDist, 1)
                : deg;

              setBusPosition(endPos.lat, endPos.lng, endDeg);
              followBus(endPos.lat, endPos.lng);
              rebuildLines(null, null, curIdx + 1);
              updateUI(0, curIdx, Math.min(curIdx + 1, stops.length - 1));
              refreshStops(curIdx + 1);
              gpsCall('arrive', { index: curIdx }).catch(function () {});

              if (curIdx >= stops.length - 1) {
                state = 'ended';
                setBtns('paused');
                btnDepart.disabled = true;
                setChip('ended', 'Route complete \u2713');
                gpsCall('update', {
                  lat: endPos.lat,
                  lng: endPos.lng,
                  index: curIdx,
                  status: 'ended',
                }).catch(function () {});
              } else {
                startLeg(curIdx, curIdx + 1);
              }
            }
          }, TICK_MS);
        });
      }

      function restoreTripView(data) {
        curIdx = data.currentStopIndex || 0;
        var bus = data.busPosition || { lat: stops[0].lat, lng: stops[0].lng };
        var fromLeg = data.legFrom;
        var toLeg = data.legTo;
        var prog = parseFloat(data.legProgress) || 0;
        var midLeg = fromLeg != null && toLeg != null && toLeg > fromLeg && prog > 0 && prog < 1;

        return buildTraveledTrailTo(curIdx).then(function () {
          prefetchAllLegRoutes();

          function finishRestore(pos, heading, aheadFrom) {
            setBusPosition(pos.lat, pos.lng, heading);
            followBus(pos.lat, pos.lng);
            rebuildLines(null, null, aheadFrom);
            updateUI(0, curIdx, Math.min(curIdx + 1, stops.length - 1));
            refreshStops(Math.min(curIdx + 1, stops.length - 1));
          }

          if (midLeg) {
            return fetchOsrmRoute(fromLeg, toLeg).then(function (path) {
              var cum = buildCumulativeDistances(path);
              var partial = partialPathAlong(path, cum, prog);
              var pos = positionAtFraction(path, cum, prog);
              var deg = headingOnPath(path, cum, prog);
              var remain = remainderPathFrom(path, cum, prog);

              setBusPosition(pos.point.lat, pos.point.lng, deg);
              followBus(pos.point.lat, pos.point.lng);
              rebuildLines(partial, remain, toLeg);
              updateUI(prog, fromLeg, toLeg);
              refreshStops(toLeg);

              if (data.status === 'running') {
                state = 'running';
                setBtns('running');
                setChip('running', 'En route \u2192 ' + stops[toLeg].name);
                startLeg(fromLeg, toLeg, prog);
              } else {
                state = 'paused';
                setBtns('paused');
                setChip('paused', 'At ' + stops[fromLeg].name);
              }
            });
          }

          var nxt = stops[Math.min(curIdx + 1, stops.length - 1)];
          var h = nxt && curIdx < stops.length - 1
            ? bearing(bus.lat, bus.lng, nxt.lat, nxt.lng)
            : 0;
          finishRestore(bus, h, curIdx + 1);
        });
      }

      function startTrip() {
        if (!mapsReady || stops.length < 2) return;
        if (state === 'paused') {
          departStop();
          return;
        }
        if (state !== 'idle') return;

        gpsCall('start').catch(function () {});
        curIdx = 0;
        state  = 'running';
        setBtns('running');
        traveledTrail = [{ lat: stops[0].lat, lng: stops[0].lng }];
        currentLegPath = null;
        legRoutesCache = {};

        var p0 = stops[0];
        setBusPosition(p0.lat, p0.lng, 0);
        rebuildLines(null, null, 1);
        followBus(p0.lat, p0.lng);
        prefetchAllLegRoutes();
        startLeg(0, 1);
      }

      function endTrip() {
        stopLeg();
        traveledTrail = [];
        currentLegPath = null;

        gpsCall('end', { flip: 1 }).then(function (data) {
          if (data.error) throw new Error(data.error);
          window.location.reload();
        }).catch(function () {
          state  = 'idle';
          curIdx = 0;
          drawOverview();
          setBtns('idle');
          setChip('idle', 'Trip ended');
        });
      }

      function arriveStop() {
        if (state !== 'running') return;
        stopLeg();
        if (currentLegPath) {
          traveledTrail = appendPathPoints(traveledTrail, currentLegPath);
          currentLegPath = null;
        }
        curIdx = legTo;
        var pos = stops[curIdx];
        var nxt = stops[Math.min(curIdx + 1, stops.length - 1)];
        var h   = nxt ? bearing(pos.lat, pos.lng, nxt.lat, nxt.lng) : busHeading;
        setBusPosition(pos.lat, pos.lng, h);
        followBus(pos.lat, pos.lng);
        rebuildLines(null, null, curIdx + 1);
        updateUI(0, curIdx, Math.min(curIdx + 1, stops.length - 1));
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
        traveledTrail = [];
        currentLegPath = null;
        var p0 = stops[0];
        setBusPosition(p0.lat, p0.lng, 0);
        rebuildLines(null, null, 1);
        fitOverview();
        refreshStops(1);
        updateUI(0, 0, 1);
        if (stops.length > 1) prefetchLegRoute(0, 1);
      }

      function bootTripState() {
        return gpsCall().then(function (data) {
          stops = (data.stops && data.stops.length) ? data.stops : STOPS_FALLBACK;
          if (data.routeId && data.routeId !== ROUTE_ID) applyGpsRoute(data);
          else updateRouteHeader(data);

          if (!data.status || data.status === 'idle') {
            state = 'idle';
            curIdx = data.currentStopIndex || INIT_IDX;
            drawOverview();
            setChip('idle', 'Trip not started');
            setBtns('idle');
            return;
          }

          if (data.status === 'ended') {
            state = 'ended';
            setBtns('paused');
            btnDepart.disabled = true;
            setChip('ended', 'Route complete \u2713');
            return restoreTripView(data);
          }

          state = data.status === 'running' ? 'running' : 'paused';
          return restoreTripView(data).then(function () {
            if (data.status === 'running') {
              setBtns('running');
            } else {
              setBtns('paused');
              var stopName = stops[data.currentStopIndex]
                ? stops[data.currentStopIndex].name
                : 'stop';
              setChip('paused', 'At ' + stopName);
            }
          });
        }).catch(function () {
          stops  = STOPS_FALLBACK.slice();
          curIdx = INIT_IDX;
          state  = 'idle';
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

      if (btnStart) btnStart.addEventListener('click', startTrip);
      if (btnArrive) btnArrive.addEventListener('click', arriveStop);
      if (btnDepart) btnDepart.addEventListener('click', departStop);
      if (btnEnd) btnEnd.addEventListener('click', endTrip);

      document.addEventListener('DOMContentLoaded', boot);
    })();
    </script>
  </body>
</html>
