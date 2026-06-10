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

$farePolicyLabel = fare_policy_label();

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

    $cumulativeDistances = build_cumulative_from_route_stops($routeStops);
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

    if ($routeStopCount > 0) {
        $lastStopId         = (int) $routeStops[$routeStopCount - 1]['stop_id'];
        $currentStopIdFare  = (int) $routeStops[$currentStopIndex]['stop_id'];
        foreach ($activePassengers as &$paxRow) {
            $boardId = (int) $paxRow['boarding_stop_id'];
            $nowEst  = fare_estimate_for_active_passenger($conn, $routeId, $boardId, $currentStopIdFare);
            $maxEst  = fare_estimate_for_active_passenger($conn, $routeId, $boardId, $lastStopId);
            $paxRow['fare_now'] = $nowEst['fare'];
            $paxRow['fare_max'] = $maxEst['fare'];
            $paxRow['km_now']   = $nowEst['distance_km'];
            $paxRow['km_max']   = $maxEst['distance_km'];
        }
        unset($paxRow);
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
$paxPreviewLimit  = 3;
$paxPreview       = array_slice($activePassengers, 0, $paxPreviewLimit);
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
  <link rel="icon" type="image/png" href="../../images/logo.png"/>
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

    #live-map{width:100%;height:100%;min-height:280px;border-radius:.75rem;overflow:hidden;position:relative;z-index:0;background:#e2e8f0}

    /* progress */
    .pbar-outer{height:6px;border-radius:9999px;background:#e2e8f0;overflow:hidden}
    .pbar-inner{height:100%;border-radius:9999px;background:#0040a1;transition:width .35s ease}
    .pbadge{display:inline-flex;align-items:center;justify-content:center;min-width:2.5rem;padding:.1rem .5rem;border-radius:9999px;background:#0040a1;color:#fff;font-weight:700;font-size:.75rem}
    .pcard{border:1px solid #e2e8f0;border-radius:.75rem;background:#f8fafc;padding:.5rem .65rem;min-height:0}

    /* stop list */
    .stop-row{display:flex;align-items:center;gap:.45rem;padding:.2rem 0;font-size:.75rem;color:#64748b;border-bottom:1px solid #f1f5f9}
    .stop-row:last-child{border:none}
    .sdot{width:8px;height:8px;border-radius:50%;background:#cbd5e1;flex-shrink:0}
    .stop-row.done .sdot{background:#16a34a}
    .stop-row.done{color:#16a34a}
    .stop-row.cur .sdot{background:#0040a1;box-shadow:0 0 0 2px rgba(0,64,161,.2)}
    .stop-row.cur{color:#0040a1;font-weight:700}
    .stop-row.nxt .sdot{background:#2563eb}
    .stop-row.nxt{color:#2563eb;font-weight:600}

    /* passenger preview */
    .pax-row{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.35rem .5rem;border-radius:.5rem;background:#f8fafc;font-size:.75rem}
    .pax-row + .pax-row{margin-top:.25rem}

    /* modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:50;display:none;align-items:center;justify-content:center;padding:1.5rem}
    .modal-backdrop.open{display:flex}
    .modal-panel{width:100%;max-width:32rem;max-height:min(85vh,720px);background:#fff;border-radius:1rem;border:1px solid #e2e8f0;box-shadow:0 25px 50px -12px rgba(0,0,0,.2);display:flex;flex-direction:column}
    .modal-body{overflow-y:auto;padding:0 1rem 1rem}
    .modal-pax-row{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;padding:.65rem 0;border-bottom:1px solid #f1f5f9;font-size:.8125rem}
    .modal-pax-row:last-child{border:none}

    /* trip control buttons */
    .tb{border-radius:.75rem;padding:.4rem 0;font-size:.75rem;font-weight:700;letter-spacing:.02em;cursor:pointer;transition:opacity .15s}
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
  <main class="ml-[260px] flex-1 h-screen overflow-hidden bg-slate-100 p-5 flex flex-col">
    <header class="shrink-0 mb-3 flex items-baseline justify-between gap-4">
      <div>
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 font-headline">Dashboard</h1>
        <p class="text-xs text-slate-500">Route &amp; onboard passengers</p>
      </div>
      <span id="status-chip" class="idle shrink-0"><span class="chipdot"></span><span id="chip-label">Trip not started</span></span>
    </header>

    <div class="grid grid-cols-12 gap-4 flex-1 min-h-0">

      <!-- LEFT: map + controls -->
      <section class="col-span-12 xl:col-span-7 flex flex-col gap-3 min-h-0">
        <article class="rounded-xl bg-white p-4 shadow-sm border border-slate-200 flex flex-col flex-1 min-h-0">
          <p class="text-[10px] uppercase tracking-[.2em] text-slate-500 font-semibold mb-2 shrink-0">Live Map</p>
          <div id="live-map" class="flex-1 min-h-[280px]" role="application" aria-label="Live route map"></div>
        </article>
        <article class="rounded-xl bg-white px-4 py-3 shadow-sm border border-slate-200 shrink-0">
          <div class="grid grid-cols-4 gap-2">
            <button id="btn-start"  class="tb bg-emerald-600 text-white">&#9654; Start</button>
            <button id="btn-arrive" class="tb bg-sky-600    text-white" disabled>&#9646; Arrive</button>
            <button id="btn-depart" class="tb bg-indigo-600 text-white" disabled>&#9654; Depart</button>
            <button id="btn-end"    class="tb bg-rose-600   text-white" disabled>&#9632; End Trip</button>
          </div>
        </article>
      </section>

      <!-- RIGHT: route, passengers, metrics -->
      <section class="col-span-12 xl:col-span-5 flex flex-col gap-3 min-h-0 overflow-y-auto pr-0.5">

        <article class="rounded-xl bg-white p-4 shadow-sm border border-slate-200 shrink-0">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-[10px] uppercase tracking-[.2em] text-slate-500 font-semibold">Active Route</p>
              <h2 id="ui-route-name" class="mt-1 text-lg font-black text-slate-900 truncate">
                <?php echo htmlspecialchars($activeTrip['route_name'] ?? 'No active route'); ?>
              </h2>
              <p id="ui-route-display" class="text-xs text-slate-500 truncate">
                <?php echo htmlspecialchars($activeTrip['display_name'] ?? ''); ?>
              </p>
            </div>
            <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 whitespace-nowrap">On Route</span>
          </div>
          <div class="mt-3">
            <div class="pbar-outer">
              <div id="pbar" class="pbar-inner" style="width:<?php echo $routeProgressPercent; ?>%"></div>
            </div>
            <div class="mt-1 flex items-center justify-between text-[10px] text-slate-500">
              <span>Progress</span>
              <span id="plabel" class="pbadge"><?php echo $routeProgressPercent; ?>%</span>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2 mt-2">
            <div class="pcard">
              <p class="text-[10px] uppercase tracking-[.15em] text-slate-500 font-semibold">Current</p>
              <p id="ui-cur" class="mt-0.5 text-xs font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($routeProgressCurrent); ?></p>
            </div>
            <div class="pcard">
              <p class="text-[10px] uppercase tracking-[.15em] text-slate-500 font-semibold">Next</p>
              <p id="ui-nxt" class="mt-0.5 text-xs font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($routeNextStop); ?></p>
            </div>
          </div>
          <ul id="stop-list" class="mt-2 max-h-24 overflow-y-auto pr-1"></ul>
        </article>

        <article class="rounded-xl bg-white p-4 shadow-sm border border-slate-200 shrink-0">
          <div class="flex items-center justify-between gap-2 mb-1">
            <p class="text-[10px] uppercase tracking-[.2em] text-slate-500 font-semibold">Onboard</p>
            <span id="pax-count-badge" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600"><?php echo $passengerCount; ?> onboard</span>
          </div>
          <p class="text-[10px] text-slate-400 mb-2"><?php echo htmlspecialchars($farePolicyLabel); ?> · tap out at stop, or charged to last stop if trip ends</p>
          <div id="pax-list">
            <?php if (!empty($activePassengers)): ?>
              <?php foreach ($paxPreview as $p): ?>
                <div class="pax-row">
                  <div class="min-w-0">
                    <p class="font-semibold text-slate-900 truncate"><?php echo htmlspecialchars($p['full_name']); ?></p>
                    <p class="text-[10px] text-slate-500 truncate"><?php echo htmlspecialchars($p['boarding_stop']); ?></p>
                    <?php if (isset($p['fare_now'])): ?>
                    <p class="text-[10px] text-primary font-semibold mt-0.5">Est. now &#8369;<?php echo number_format((float) $p['fare_now'], 2); ?></p>
                    <?php endif; ?>
                  </div>
                  <span class="text-[10px] font-semibold text-emerald-600 shrink-0">Onboard</span>
                </div>
              <?php endforeach; ?>
              <?php if ($passengerCount > $paxPreviewLimit): ?>
                <p class="mt-1.5 text-[10px] text-slate-400">+<?php echo $passengerCount - $paxPreviewLimit; ?> more not shown</p>
              <?php endif; ?>
            <?php else: ?>
              <p class="text-xs text-slate-500">No passengers onboard</p>
            <?php endif; ?>
          </div>
          <button
            type="button"
            id="btn-pax-modal"
            class="mt-2 w-full rounded-lg border border-slate-200 bg-slate-50 py-1.5 text-xs font-semibold text-primary hover:bg-blue-50 transition"
            <?php echo $passengerCount === 0 ? 'hidden' : ''; ?>
          >
            View all passengers (<?php echo $passengerCount; ?>)
          </button>
        </article>

        <div class="grid grid-cols-2 gap-3 shrink-0">
          <article class="rounded-xl bg-white p-3 shadow-sm border border-slate-200">
            <p class="text-[10px] uppercase tracking-[.15em] text-slate-500 font-semibold mb-2">Trip Metrics</p>
            <div class="space-y-1 text-xs text-slate-700">
              <div class="flex justify-between gap-1"><span>Passengers</span><strong id="m-pax" class="text-right"><?php echo $passengerCount; ?></strong></div>
              <div class="flex justify-between gap-1"><span>Distance</span><strong id="m-dist" class="text-right"><?php echo number_format($totalRouteDistance, 1); ?> km</strong></div>
              <div class="flex justify-between gap-1"><span>Avg Speed</span><strong id="m-speed" class="text-right"><?php echo $averageSpeed > 0 ? $averageSpeed . ' km/h' : 'N/A'; ?></strong></div>
              <div class="flex justify-between gap-1"><span>Est. ETA</span><strong id="m-eta" class="text-right">N/A</strong></div>
            </div>
          </article>
          <article class="rounded-xl bg-white p-3 shadow-sm border border-slate-200">
            <p class="text-[10px] uppercase tracking-[.15em] text-slate-500 font-semibold mb-2">Earnings</p>
            <div class="space-y-1 text-xs text-slate-700">
              <div class="flex justify-between gap-1"><span>This trip</span><strong>&#8369;<?php echo number_format($activeTripCollected, 2); ?></strong></div>
              <div class="flex justify-between gap-1"><span>All-time</span><strong>&#8369;<?php echo number_format($driverTotalEarnings, 2); ?></strong></div>
            </div>
            <p class="text-[10px] text-slate-400 mt-2 pt-2 border-t border-slate-100"><?php echo htmlspecialchars($farePolicyLabel); ?></p>
          </article>
        </div>

      </section>
    </div>
  </main>
</div>

<!-- All passengers modal -->
<div id="pax-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="pax-modal-title" hidden>
  <div class="modal-panel">
    <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-100 shrink-0">
      <h2 id="pax-modal-title" class="text-base font-bold text-slate-900">Passengers on board</h2>
      <button type="button" id="pax-modal-close" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" aria-label="Close">
        <span class="material-symbols-outlined text-xl">close</span>
      </button>
    </div>
    <div class="modal-body">
      <?php if (!empty($activePassengers)): ?>
        <?php foreach ($activePassengers as $p): ?>
          <div class="modal-pax-row">
            <div>
              <p class="font-semibold text-slate-900">
                <?php echo htmlspecialchars($p['full_name']); ?>
                <span class="text-slate-400 font-normal">#P<?php echo (int)$p['user_id']; ?></span>
              </p>
              <p class="text-xs text-slate-500 mt-0.5">Boarded at <?php echo htmlspecialchars($p['boarding_stop']); ?></p>
              <p class="text-xs text-slate-400 mt-0.5">Card #<?php echo (int)$p['card_id']; ?></p>
              <?php if (isset($p['fare_now'], $p['fare_max'])): ?>
              <p class="text-xs text-slate-600 mt-1">
                If alight now: <strong>&#8369;<?php echo number_format((float) $p['fare_now'], 2); ?></strong>
                (<?php echo number_format((float) $p['km_now'], 2); ?> km)
              </p>
              <p class="text-xs text-amber-700 mt-0.5">
                If no tap-out (trip end): <strong>&#8369;<?php echo number_format((float) $p['fare_max'], 2); ?></strong>
                (<?php echo number_format((float) $p['km_max'], 2); ?> km to terminus)
              </p>
              <?php endif; ?>
            </div>
            <span class="text-xs font-semibold text-emerald-600 shrink-0">Onboard</span>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="py-4 text-sm text-slate-500 text-center">No passengers onboard</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* ── PHP-injected data ── */
  var MAP_CFG_URL    = '../../api/map.php';
  var GPS_URL        = '../../config/gps.php';
  var STOPS_FALLBACK = <?php echo json_encode($routeStopsForMap, JSON_UNESCAPED_UNICODE); ?>;
  var TOTAL_KM       = <?php echo (float)$totalRouteDistance; ?>;
  var ROUTE_ID       = <?php echo (int)($activeTrip['route_id'] ?? 1); ?>;
  var AVG_SPEED      = <?php echo max(1, (int)$averageSpeed); ?>;
  var INIT_IDX       = <?php echo (int)$currentStopIndex; ?>;
  var MAP_CENTER     = { lat: <?php echo $mapCenterLat; ?>, lng: <?php echo $mapCenterLng; ?> };

  /* ── constants ── */
  var LEG_MS   = 2000;
  var TICK_MS  = 16;
  var NAV_ZOOM = 17;
  var OV_ZOOM  = 12;
  var OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';

  /* ── state ── */
  var stops = [];
  var map, busMkr, lineTaken, lineAhead;
  var stopMkrs = [];
  var paxMkrs = [];
  var state = 'idle';
  var curIdx = 0;
  var legTimer = null;
  var legFrom = 0;
  var legTo = 0;
  var legStartTs = 0;
  var busHeading = 0;
  var mapsReady = false;
  var legRoutesCache = {};
  var legRoutesPending = {};
  var traveledTrail = [];
  var currentLegPath = null;
  var lastGpsSave = 0;
  var lastLineRebuild = { activePartial: null, legRemainder: null, nextLegFrom: 0 };
  var lastPaxUpdate = 0;

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

  function haversineKm(a, b) {
    var R = 6371;
    var dLat = (b.lat - a.lat) * Math.PI / 180;
    var dLng = (b.lng - a.lng) * Math.PI / 180;
    var p1 = a.lat * Math.PI / 180;
    var p2 = b.lat * Math.PI / 180;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(p1) * Math.cos(p2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
  }

  function legCacheKey(from, to) { return from + '-' + to; }

  function straightPath(from, to) {
    return [
      { lat: from.lat, lng: from.lng },
      { lat: to.lat, lng: to.lng },
    ];
  }

  function fetchOsrmRoute(fromIdx, toIdx) {
    var key = legCacheKey(fromIdx, toIdx);
    if (legRoutesCache[key]) {
      return Promise.resolve(legRoutesCache[key]);
    }
    if (legRoutesPending[key]) {
      return legRoutesPending[key];
    }
    var a = stops[fromIdx];
    var b = stops[toIdx];
    var url = OSRM_URL + '/'
      + a.lng + ',' + a.lat + ';' + b.lng + ',' + b.lat
      + '?overview=full&geometries=geojson';

    legRoutesPending[key] = fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (!r.ok) throw new Error('OSRM HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        var path;
        if (data.code === 'Ok' && data.routes && data.routes[0] && data.routes[0].geometry) {
          path = data.routes[0].geometry.coordinates.map(function (c) {
            return { lat: c[1], lng: c[0] };
          });
        } else {
          path = straightPath(a, b);
        }
        legRoutesCache[key] = path;
        refreshLinesFromCache();
        return path;
      })
      .catch(function () {
        var path = straightPath(a, b);
        legRoutesCache[key] = path;
        refreshLinesFromCache();
        return path;
      })
      .finally(function () {
        delete legRoutesPending[key];
      });

    return legRoutesPending[key];
  }

  function refreshLinesFromCache() {
    if (!mapsReady || !stops.length || !lastLineRebuild) return;
    rebuildLines(
      lastLineRebuild.activePartial,
      lastLineRebuild.legRemainder,
      lastLineRebuild.nextLegFrom
    );
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

  function buildCumulativeDistances(path) {
    var cum = [0];
    for (var i = 1; i < path.length; i++) {
      cum.push(cum[i - 1] + haversineKm(path[i - 1], path[i]));
    }
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
            lat: lerp(path[i - 1].lat, path[i].lat, segT),
            lng: lerp(path[i - 1].lng, path[i].lng, segT),
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

  function appendPathPoints(base, extra) {
    if (!extra || !extra.length) return base.slice();
    var out = base.slice();
    var start = out.length ? 1 : 0;
    for (var i = start; i < extra.length; i++) out.push(extra[i]);
    return out;
  }

  function pathToLatLngs(pts) {
    return pts.map(function (p) { return latLng(p.lat, p.lng); });
  }

  function fmtTime(ms) {
    return new Date(Date.now() + ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function recalcRouteMetrics() {
    if (stops.length < 2) return;
    var cum = 0;
    for (var i = 1; i < stops.length; i++) {
      cum += haversineKm(stops[i - 1], stops[i]);
    }
    TOTAL_KM = cum;
  }

  function updateRouteHeader(data) {
    if (data.routeName) {
      var el = document.getElementById('ui-route-name');
      if (el) el.textContent = data.routeName;
    }
    if (data.displayName) {
      var el2 = document.getElementById('ui-route-display');
      if (el2) el2.textContent = data.displayName;
    }
    if (typeof data.totalKm === 'number' && data.totalKm > 0) {
      TOTAL_KM = data.totalKm;
    } else {
      recalcRouteMetrics();
    }
    if (typeof data.routeId === 'number') ROUTE_ID = data.routeId;
    if (mDist) mDist.textContent = TOTAL_KM.toFixed(1) + ' km';
    if (stops.length) {
      if (uiCur) uiCur.textContent = stops[0].name;
      if (uiNxt) {
        uiNxt.textContent = stops.length > 1 ? stops[1].name : 'End of route';
      }
    }
  }

  function applyGpsRoute(data) {
    if (data.stops && data.stops.length) {
      stops = data.stops;
      STOPS_FALLBACK = data.stops;
      MAP_CENTER = { lat: stops[0].lat, lng: stops[0].lng };
    }
    updateRouteHeader(data);
    legRoutesCache = {};
    legRoutesPending = {};
    curIdx = 0;
    if (pbar) pbar.style.width = '0%';
    if (plabel) plabel.textContent = '0%';
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

  function passengerMarkerIcon() {
    return {
      path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z',
      fillColor: '#e11d48',
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
      scale: 1.2,
      anchor: new google.maps.Point(12, 24),
    };
  }

  function updatePassengerMarkers(lat, lng) {
    if (!mapsReady || !map) return;

    var now = Date.now();
    if (now - lastPaxUpdate < 500) return;
    lastPaxUpdate = now;

    // Clear existing passenger markers
    paxMkrs.forEach(function (m) { m.setMap(null); });
    paxMkrs = [];

    // Update passenger positions in database
    var formData = new FormData();
    formData.append('trip_id', ROUTE_ID);
    formData.append('lat', lat);
    formData.append('lng', lng);

    fetch('../../api/update_passenger_positions.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
    }).catch(function () {});

    // Add a single passenger marker at bus position to represent all passengers
    var m = new google.maps.Marker({
      position: latLng(lat, lng),
      map: map,
      title: 'Passengers on board',
      zIndex: 1500,
      icon: passengerMarkerIcon(),
    });
    paxMkrs.push(m);
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

  function rebuildLines(activePartial, legRemainder, nextLegFrom) {
    lastLineRebuild = {
      activePartial: activePartial,
      legRemainder: legRemainder ? legRemainder.slice() : null,
      nextLegFrom: typeof nextLegFrom === 'number' ? nextLegFrom : curIdx,
    };

    if (lineTaken) { lineTaken.setMap(null); lineTaken = null; }
    if (lineAhead) { lineAhead.setMap(null); lineAhead = null; }
    if (!stops.length) return;

    var takenPts = appendPathPoints(traveledTrail, activePartial || []);

    if (takenPts.length > 1) {
      lineTaken = new google.maps.Polyline({
        path: pathToLatLngs(takenPts),
        geodesic: false,
        strokeColor: '#16a34a',
        strokeOpacity: 0.92,
        strokeWeight: 6,
        map: map,
        zIndex: 10,
      });
    }

    var aheadPts = legRemainder ? legRemainder.slice() : [];
    var fromLeg = typeof nextLegFrom === 'number' ? nextLegFrom : curIdx;
    for (var j = fromLeg; j < stops.length - 1; j++) {
      var key = legCacheKey(j, j + 1);
      if (legRoutesCache[key]) {
        aheadPts = appendPathPoints(aheadPts, legRoutesCache[key]);
      } else {
        fetchOsrmRoute(j, j + 1);
      }
    }

    if (aheadPts.length > 1) {
      lineAhead = new google.maps.Polyline({
        path: pathToLatLngs(aheadPts),
        geodesic: false,
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
    renderStopList(to);
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
        updatePassengerMarkers(pos.point.lat, pos.point.lng);
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
          rebuildLines(null, null, curIdx);
          updateUI(0, curIdx, Math.min(curIdx + 1, stops.length - 1));
          renderStopList(curIdx + 1);
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
        renderStopList(Math.min(curIdx + 1, stops.length - 1));
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
          renderStopList(toLeg);
          refreshStops(toLeg);

          if (data.status === 'running') {
            state = 'running';
            setBtns('running');
            setChip('running', 'En route \u2192 ' + stops[toLeg].name);
            startLeg(fromLeg, toLeg, prog);
          } else {
            state = 'paused';
            setBtns('paused');
            setChip('paused', 'En route \u2192 ' + stops[toLeg].name);
          }
        });
      }

      var nxt = stops[Math.min(curIdx + 1, stops.length - 1)];
      var h = nxt && curIdx < stops.length - 1
        ? bearing(bus.lat, bus.lng, nxt.lat, nxt.lng)
        : 0;
        finishRestore(bus, h, curIdx);
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
    legRoutesPending = {};

    var p0 = stops[0];
    setBusPosition(p0.lat, p0.lng, 0);
    rebuildLines(null, null, 0);
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
    rebuildLines(null, null, curIdx);
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
    traveledTrail = [];
    currentLegPath = null;
    var p0 = stops[0];
    setBusPosition(p0.lat, p0.lng, 0);
    rebuildLines(null, null, 0);
    fitOverview();
    refreshStops(1);
    renderStopList(1);
    updateUI(0, 0, 1);
    if (stops.length > 1) prefetchAllLegRoutes();
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
      stops  = STOPS_FALLBACK;
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

  (function initPaxModal() {
    var backdrop = document.getElementById('pax-modal');
    var openBtn  = document.getElementById('btn-pax-modal');
    var closeBtn = document.getElementById('pax-modal-close');
    if (!backdrop) return;

    function openModal() {
      backdrop.hidden = false;
      backdrop.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
    function closeModal() {
      backdrop.classList.remove('open');
      backdrop.hidden = true;
      document.body.style.overflow = '';
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && backdrop.classList.contains('open')) closeModal();
    });
  })();

  function renderDriverPassengerPanel(data) {
    if (!data || !data.ok) return;
    var count = parseInt(data.passenger_count || 0, 10);
    var badge = document.getElementById('pax-count-badge');
    var countMetric = document.getElementById('m-pax');
    var list = document.getElementById('pax-list');
    var modalBtn = document.getElementById('btn-pax-modal');

    if (badge) {
      badge.textContent = count + ' onboard';
    }
    if (countMetric) {
      countMetric.textContent = count;
    }
    if (modalBtn) {
      modalBtn.hidden = count === 0;
      modalBtn.textContent = 'View all passengers (' + count + ')';
    }
    if (!list) return;

    if (count === 0) {
      list.innerHTML = '<p class="text-xs text-slate-500">No passengers onboard</p>';
      return;
    }

    var preview = data.passengers || [];
    var previewCount = Math.min(3, preview.length);
    var html = '';

    for (var i = 0; i < previewCount; i++) {
      var pax = preview[i] || {};
      html += '<div class="pax-row">'
        + '<div class="min-w-0">'
        + '<p class="font-semibold text-slate-900 truncate">' + (pax.full_name || 'Passenger') + '</p>'
        + '<p class="text-[10px] text-slate-500 truncate">' + (pax.boarding_stop || 'Unknown') + '</p>';
      if (pax.fare_now != null) {
        html += '<p class="text-[10px] text-primary font-semibold mt-0.5">Est. now ₱' + parseFloat(pax.fare_now).toFixed(2) + '</p>';
      }
      html += '</div>'
        + '<span class="text-[10px] font-semibold text-emerald-600 shrink-0">Onboard</span>'
        + '</div>';
    }
    if (count > previewCount) {
      html += '<p class="mt-1.5 text-[10px] text-slate-400">+' + (count - previewCount) + ' more not shown</p>';
    }
    list.innerHTML = html;
  }

  function refreshDriverPassengerPanel() {
    fetch('../../config/gps.php?action=driver_passengers&_' + Date.now(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    .then(function (r) {
      if (!r.ok) throw new Error('Network error');
      return r.json();
    })
    .then(function (data) {
      renderDriverPassengerPanel(data);
    })
    .catch(function () {
      // ignore polling failures
    });
  }

  refreshDriverPassengerPanel();
  setInterval(refreshDriverPassengerPanel, 3000);

  btnStart .addEventListener('click', startTrip);
  btnArrive.addEventListener('click', arriveStop);
  btnDepart.addEventListener('click', departStop);
  btnEnd   .addEventListener('click', endTrip);

  document.addEventListener('DOMContentLoaded', boot);
})();
</script>
</body>
</html>