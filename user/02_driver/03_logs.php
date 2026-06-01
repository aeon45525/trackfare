<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/fare.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId   = (int) $_SESSION['user_id'];
$driverName = trim($_SESSION['full_name'] ?? 'Driver');

function formatTripDateTime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y · g:i A', $ts) : '—';
}

$activeTrip            = null;
$activePassengers      = [];
$completedTrips        = [];
$activePassengerCount  = 0;
$completedTripCount    = 0;
$activeRouteDisplay    = 'No active route';
$currentStop           = '—';
$activeTripStartedLabel = '—';

if ($stmt = $conn->prepare(
    'SELECT t.trip_id, t.route_id, t.current_stop_index, t.start_time,
            r.display_name
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
    $activeRouteDisplay = $activeTrip['display_name'];
    $routeId            = (int) $activeTrip['route_id'];
    $tripId             = (int) $activeTrip['trip_id'];
    $activeTripStartedLabel = formatTripDateTime($activeTrip['start_time'] ?? null);

    $routeStops = [];
    if ($stmt = $conn->prepare(
        'SELECT s.stop_name
         FROM route_stops rs
         JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $routeStops[] = $row['stop_name'];
        }
        $stmt->close();
    }

    if (!empty($routeStops)) {
        $idx = max(0, min(count($routeStops) - 1, (int) ($activeTrip['current_stop_index'] ?? 0)));
        $currentStop = $routeStops[$idx];
    }

    if ($stmt = $conn->prepare(
        'SELECT ap.user_id, ap.boarding_stop_id, u.full_name, st.stop_name AS boarding_stop
         FROM active_passengers ap
         JOIN users u ON ap.user_id = u.user_id
         JOIN stops st ON ap.boarding_stop_id = st.stop_id
         WHERE ap.trip_id = ?
         ORDER BY u.full_name'
    )) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activePassengers[] = $row;
        }
        $stmt->close();
    }

    $routeStopIds = [];
    if ($stmt = $conn->prepare(
        'SELECT s.stop_id FROM route_stops rs JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ? ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $routeStopIds[] = (int) $row['stop_id'];
        }
        $stmt->close();
    }
    $stopCount = count($routeStopIds);
    if ($stopCount > 0) {
        $idx = max(0, min($stopCount - 1, (int) ($activeTrip['current_stop_index'] ?? 0)));
        $currentStopIdFare = $routeStopIds[$idx];
        $lastStopIdFare    = $routeStopIds[$stopCount - 1];
        foreach ($activePassengers as &$paxRow) {
            $boardId = (int) $paxRow['boarding_stop_id'];
            $nowEst  = fare_estimate_for_active_passenger($conn, $routeId, $boardId, $currentStopIdFare);
            $maxEst  = fare_estimate_for_active_passenger($conn, $routeId, $boardId, $lastStopIdFare);
            $paxRow['fare_now'] = $nowEst['fare'];
            $paxRow['fare_max'] = $maxEst['fare'];
        }
        unset($paxRow);
    }

    $activePassengerCount = count($activePassengers);
}

if ($stmt = $conn->prepare(
    'SELECT COUNT(*) AS trip_count
     FROM trips
     WHERE driver_id = ? AND status = ?'
)) {
    $status = 'completed';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $completedTripCount = (int) ($stmt->get_result()->fetch_assoc()['trip_count'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT t.trip_id, r.display_name, t.start_time, t.end_time,
            (SELECT COUNT(*) FROM trip_transactions tt WHERE tt.trip_id = t.trip_id) AS pax_count,
            (SELECT COALESCE(SUM(tt.fare_amount), 0) FROM trip_transactions tt WHERE tt.trip_id = t.trip_id) AS fare_total
     FROM trips t
     JOIN routes r ON t.route_id = r.route_id
     WHERE t.driver_id = ? AND t.status = ?
     ORDER BY COALESCE(t.end_time, t.start_time) DESC'
)) {
    $status = 'completed';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $completedTrips[] = [
            'trip_id'      => (int) $row['trip_id'],
            'route_label'  => $row['display_name'],
            'start_time'   => $row['start_time'],
            'end_time'     => $row['end_time'],
            'pax_count'    => (int) $row['pax_count'],
            'fare_total'   => (float) $row['fare_total'],
            'passengers'   => [],
        ];
    }
    $stmt->close();
}

foreach ($completedTrips as &$trip) {
    if ($stmt = $conn->prepare(
        'SELECT u.full_name, stb.stop_name AS boarding_stop, ste.stop_name AS alighting_stop,
                tt.fare_amount
         FROM trip_transactions tt
         JOIN users u ON tt.user_id = u.user_id
         JOIN stops stb ON tt.boarding_stop_id = stb.stop_id
         JOIN stops ste ON tt.alighting_stop_id = ste.stop_id
         WHERE tt.trip_id = ?
         ORDER BY tt.transaction_id ASC'
    )) {
        $stmt->bind_param('i', $trip['trip_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $trip['passengers'][] = $row;
        }
        $stmt->close();
    }
}
unset($trip);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TrackFare — Driver Logs</title>
  <link rel="icon" type="image/png" href="../../images/logo.png"/>
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script id="twcfg">
    tailwind.config = {
      darkMode: 'class',
      safelist: [
        'bg-blue-50', 'text-blue-700', 'border-r-4', 'border-blue-700', 'font-semibold',
        'text-slate-600', 'hover:bg-slate-100', 'hover:text-blue-700', 'transition',
        'flex', 'items-center', 'gap-3', 'px-5', 'py-3', 'rounded-r-full',
      ],
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
    .material-symbols-outlined { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; vertical-align:middle; }
    .log-row { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; padding:0.85rem 0; border-bottom:1px solid #f1f5f9; }
    .log-row:last-child { border-bottom:none; }
    .badge { display:inline-flex; align-items:center; padding:0.2rem 0.65rem; font-size:0.7rem; font-weight:700; border-radius:9999px; }
    .badge-live { background:#dcfce7; color:#15803d; }
    .badge-done { background:#f1f5f9; color:#475569; }
    .badge-in { background:#dbeafe; color:#1d4ed8; }
    .trip-card-extra { display: none; }
    .trip-card-extra.is-visible { display: block; }
    .trip-txn-panel { display: none; }
    .trip-txn-panel.is-open { display: block; }
    .btn-ghost {
      font-size: 0.75rem;
      font-weight: 600;
      color: #0040a1;
      padding: 0.35rem 0.75rem;
      border-radius: 0.5rem;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      cursor: pointer;
    }
    .btn-ghost:hover { background: #dbeafe; }
    .btn-show-more {
      margin-top: 1rem;
      width: 100%;
      padding: 0.65rem 1rem;
      font-size: 0.875rem;
      font-weight: 700;
      color: #0040a1;
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 0.75rem;
      cursor: pointer;
    }
    .btn-show-more:hover { background: #eff6ff; }
  </style>
</head>
<body class="bg-slate-100 text-on-surface font-body antialiased">
<div class="flex min-h-screen">

  <aside class="fixed left-0 top-0 h-screen w-[260px] bg-white border-r border-slate-200 shadow-sm z-20">
    <div class="flex h-full flex-col">
      <div class="px-6 py-8 border-b border-slate-200">
        <span class="text-2xl font-black tracking-tight text-primary font-headline">TrackFare</span>
        <p class="mt-1 text-sm text-slate-500">Driver Panel</p>
      </div>
      <nav class="flex-1 px-4 py-6 space-y-1">
        <a href="01_dashboard.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition">
          <span class="material-symbols-outlined">dashboard</span><span>Dashboard</span>
        </a>
        <a href="02_route.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition">
          <span class="material-symbols-outlined">alt_route</span><span>Route</span>
        </a>
        <a href="03_logs.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold" aria-current="page">
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
            <p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($driverName, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="text-xs text-slate-500">Driver</p>
          </div>
        </div>
        <button type="button" onclick="window.location.href='../../auth/logout.php'"
          class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition">
          <span class="material-symbols-outlined">logout</span>Logout
        </button>
      </div>
    </div>
  </aside>

  <main class="ml-[260px] flex-1 min-h-screen bg-slate-100 p-10">
    <div class="max-w-full">
      <header class="mb-10">
        <h1 class="text-4xl font-extrabold tracking-tight text-slate-900 font-headline">Passenger Logs</h1>
        <p class="mt-2 text-sm text-slate-500">Onboard passengers and completed trips from your database records.</p>
      </header>

      <div class="grid grid-cols-12 gap-6">
        <div class="col-span-12 sm:col-span-6">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Onboard now</p>
            <p class="mt-2 text-3xl font-black text-slate-900"><?php echo $activePassengerCount; ?></p>
            <p class="mt-1 text-sm text-slate-500">Active passengers on current trip</p>
          </article>
        </div>
        <div class="col-span-12 sm:col-span-6">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Completed trips</p>
            <p class="mt-2 text-3xl font-black text-slate-900"><?php echo $completedTripCount; ?></p>
            <p class="mt-1 text-sm text-slate-500">Finished legs saved in trips table</p>
          </article>
        </div>

        <!-- Active trip -->
        <div class="col-span-12">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Current trip</p>
                <h2 class="mt-2 text-xl font-black text-slate-900"><?php echo htmlspecialchars($activeRouteDisplay, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="mt-1 text-sm text-slate-600">
                  Stop: <strong><?php echo htmlspecialchars($currentStop, ENT_QUOTES, 'UTF-8'); ?></strong>
                  · Started <?php echo htmlspecialchars($activeTripStartedLabel, ENT_QUOTES, 'UTF-8'); ?>
                </p>
              </div>
              <?php if ($activeTrip): ?>
                <span class="badge badge-live">Active</span>
              <?php else: ?>
                <span class="badge badge-done">No active trip</span>
              <?php endif; ?>
            </div>

            <?php if (!empty($activePassengers)): ?>
              <div class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200">
                <?php foreach ($activePassengers as $p): ?>
                  <div class="log-row px-4">
                    <div>
                      <p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <p class="text-xs text-slate-500 mt-0.5">Boarded at <?php echo htmlspecialchars($p['boarding_stop'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <?php if (isset($p['fare_now'], $p['fare_max'])): ?>
                      <p class="text-xs text-slate-600 mt-1">Est. tap-out now: <strong>&#8369;<?php echo number_format((float) $p['fare_now'], 2); ?></strong></p>
                      <p class="text-xs text-amber-700">If trip ends without tap-out: <strong>&#8369;<?php echo number_format((float) $p['fare_max'], 2); ?></strong></p>
                      <?php endif; ?>
                    </div>
                    <span class="badge badge-in shrink-0">Onboard</span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="mt-4 text-sm text-slate-500">No passengers tapped in on this trip yet.</p>
            <?php endif; ?>
          </article>
        </div>

        <!-- Completed history -->
        <div class="col-span-12">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Trip history</p>
            <h2 class="mt-2 text-xl font-black text-slate-900">Completed trips</h2>

            <?php
              $completedTripTotal = count($completedTrips);
              $initialTripLimit   = 5;
            ?>
            <?php if (!empty($completedTrips)): ?>
              <p class="mt-2 text-xs text-slate-500">
                Showing latest <?php echo min($initialTripLimit, $completedTripTotal); ?> of <?php echo $completedTripTotal; ?> trip<?php echo $completedTripTotal === 1 ? '' : 's'; ?>
              </p>
              <div id="trip-history-list" class="mt-4 space-y-3">
                <?php foreach ($completedTrips as $index => $trip): ?>
                  <div class="trip-history-card rounded-xl border border-slate-200 overflow-hidden<?php echo $index >= $initialTripLimit ? ' trip-card-extra' : ''; ?>">
                    <div class="bg-slate-50 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                      <div class="min-w-0">
                        <p class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($trip['route_label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-xs text-slate-500 mt-0.5">
                          <?php echo htmlspecialchars(formatTripDateTime($trip['start_time']), ENT_QUOTES, 'UTF-8'); ?>
                          → <?php echo htmlspecialchars(formatTripDateTime($trip['end_time']), ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                      </div>
                      <div class="text-right text-xs text-slate-600 shrink-0">
                        <p><strong><?php echo $trip['pax_count']; ?></strong> passenger<?php echo $trip['pax_count'] === 1 ? '' : 's'; ?></p>
                        <p class="text-emerald-700 font-semibold">₱<?php echo number_format($trip['fare_total'], 2); ?></p>
                      </div>
                    </div>
                    <div class="px-4 py-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2">
                      <?php if (!empty($trip['passengers'])): ?>
                        <button type="button" class="btn-toggle-txn btn-ghost" data-trip-id="<?php echo (int) $trip['trip_id']; ?>" aria-expanded="false">
                          Show transactions (<?php echo count($trip['passengers']); ?>)
                        </button>
                      <?php else: ?>
                        <span class="text-xs text-slate-500">No transactions</span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($trip['passengers'])): ?>
                      <div id="txn-panel-<?php echo (int) $trip['trip_id']; ?>" class="trip-txn-panel border-t border-slate-100 px-4 py-2 bg-white">
                        <?php foreach ($trip['passengers'] as $p): ?>
                          <div class="log-row">
                            <div>
                              <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                              <p class="text-xs text-slate-500"><?php echo htmlspecialchars($p['boarding_stop'], ENT_QUOTES, 'UTF-8'); ?> → <?php echo htmlspecialchars($p['alighting_stop'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <span class="text-xs font-semibold text-slate-700 shrink-0">₱<?php echo number_format((float) $p['fare_amount'], 2); ?></span>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($completedTripTotal > $initialTripLimit): ?>
                <button type="button" id="btn-show-more-trips" class="btn-show-more" data-remaining="<?php echo $completedTripTotal - $initialTripLimit; ?>">
                  Show more (<?php echo $completedTripTotal - $initialTripLimit; ?> more trip<?php echo ($completedTripTotal - $initialTripLimit) === 1 ? '' : 's'; ?>)
                </button>
              <?php endif; ?>
            <?php else: ?>
              <p class="mt-4 text-sm text-slate-500">End a trip on the Dashboard or Route page to see completed legs here.</p>
            <?php endif; ?>
          </article>
        </div>
      </div>
    </div>
  </main>
</div>
<script>
(function () {
  var showMoreBtn = document.getElementById('btn-show-more-trips');
  if (showMoreBtn) {
    showMoreBtn.addEventListener('click', function () {
      document.querySelectorAll('.trip-card-extra').forEach(function (el) {
        el.classList.add('is-visible');
      });
      showMoreBtn.remove();
      var note = document.querySelector('#trip-history-list') && document.querySelector('#trip-history-list').previousElementSibling;
      if (note && note.tagName === 'P') {
        var total = document.querySelectorAll('.trip-history-card').length;
        note.textContent = 'Showing all ' + total + ' trip' + (total === 1 ? '' : 's');
      }
    });
  }

  document.querySelectorAll('.btn-toggle-txn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tripId = btn.getAttribute('data-trip-id');
      var panel = document.getElementById('txn-panel-' + tripId);
      if (!panel) return;
      var open = panel.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      var countMatch = btn.textContent.match(/\((\d+)\)/);
      var count = countMatch ? countMatch[1] : '';
      btn.textContent = open
        ? 'Hide transactions' + (count ? ' (' + count + ')' : '')
        : 'Show transactions' + (count ? ' (' + count + ')' : '');
    });
  });
})();
</script>
</body>
</html>
