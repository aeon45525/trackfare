<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId   = (int) $_SESSION['user_id'];
$driverName = trim($_SESSION['full_name'] ?? 'Driver');
$driverEmail = '';

function formatTripDateTime(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y · g:i A', $ts) : '—';
}

$busNumber          = '—';
$plateNumber        = '—';
$unitStatus         = 'No active unit';
$activeRouteDisplay = 'No active route';
$currentStop        = '—';
$tripsCompletedToday = 0;
$tripsCompletedAll   = 0;
$passengersHandled   = 0;
$earnings            = 0.0;
$lastTripTime        = '—';
$lastTripRoute       = '—';
$lastStopVisited     = '—';
$lastPassengerActivity = 'No passenger transactions yet.';
$activeTripStarted   = '—';

if ($stmt = $conn->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if ($user) {
        $driverName  = trim($user['full_name'] ?: $driverName);
        $driverEmail = trim($user['email'] ?? '');
    }
    $stmt->close();
}

$activeTrip = null;
if ($stmt = $conn->prepare(
    'SELECT t.trip_id, t.route_id, t.current_stop_index, t.start_time,
            b.bus_number, b.plate_number, r.display_name
     FROM trips t
     JOIN buses b ON t.bus_id = b.bus_id
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
    $busNumber          = $activeTrip['bus_number'] ?: '—';
    $plateNumber        = $activeTrip['plate_number'] ?: '—';
    $activeRouteDisplay = $activeTrip['display_name'];
    $unitStatus         = 'Active';
    $activeTripStarted  = formatTripDateTime($activeTrip['start_time'] ?? null);

    $routeStops = [];
    if ($stmt = $conn->prepare(
        'SELECT s.stop_name
         FROM route_stops rs
         JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $activeTrip['route_id']);
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
}

if ($stmt = $conn->prepare(
    'SELECT COUNT(*) AS c FROM trips
     WHERE driver_id = ? AND status = ? AND end_time IS NOT NULL AND DATE(end_time) = CURDATE()'
)) {
    $status = 'completed';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $tripsCompletedToday = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT COUNT(*) AS c FROM trips WHERE driver_id = ? AND status = ?'
)) {
    $status = 'completed';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $tripsCompletedAll = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT COUNT(*) AS c FROM trip_transactions tt
     JOIN trips t ON tt.trip_id = t.trip_id
     WHERE t.driver_id = ?'
)) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $passengersHandled = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT COALESCE(SUM(tt.fare_amount), 0) AS total
     FROM trip_transactions tt
     JOIN trips t ON tt.trip_id = t.trip_id
     WHERE t.driver_id = ?'
)) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $earnings = (float) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT t.start_time, t.end_time, r.display_name
     FROM trips t
     JOIN routes r ON t.route_id = r.route_id
     WHERE t.driver_id = ?
     ORDER BY COALESCE(t.end_time, t.start_time) DESC
     LIMIT 1'
)) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($last) {
        $lastTripRoute = $last['display_name'];
        $lastTripTime  = $last['end_time']
            ? formatTripDateTime($last['end_time'])
            : formatTripDateTime($last['start_time']);
    }
}

if ($stmt = $conn->prepare(
    'SELECT u.full_name, stb.stop_name AS boarding_stop, ste.stop_name AS alighting_stop
     FROM trip_transactions tt
     JOIN trips t ON tt.trip_id = t.trip_id
     JOIN users u ON tt.user_id = u.user_id
     JOIN stops stb ON tt.boarding_stop_id = stb.stop_id
     JOIN stops ste ON tt.alighting_stop_id = ste.stop_id
     WHERE t.driver_id = ?
     ORDER BY tt.transaction_id DESC
     LIMIT 1'
)) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $activity = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($activity) {
        $lastPassengerActivity = sprintf(
            '%s · %s → %s',
            $activity['full_name'],
            $activity['boarding_stop'],
            $activity['alighting_stop']
        );
        $lastStopVisited = $activity['alighting_stop'];
    }
}

$earningsFormatted = '₱' . number_format($earnings, 2);
$isOnRoute = $activeTrip !== null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>TrackFare — Driver Profile</title>
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
    .stat-card { border-radius:1rem; border:1px solid #e2e8f0; background:#f8fafc; padding:1rem; }
    .info-row { display:flex; justify-content:space-between; gap:1rem; padding:0.5rem 0; font-size:0.875rem; border-bottom:1px solid #f1f5f9; }
    .info-row:last-child { border-bottom:none; }
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
        <a href="03_logs.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition">
          <span class="material-symbols-outlined">receipt_long</span><span>Logs</span>
        </a>
        <a href="04_profile.php" class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold" aria-current="page">
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
        <h1 class="text-4xl font-extrabold tracking-tight text-slate-900 font-headline">Profile</h1>
        <p class="mt-2 text-sm text-slate-500">Your assignment and trip statistics from the database.</p>
      </header>

      <div class="grid grid-cols-12 gap-6">
        <!-- Driver identity -->
        <div class="col-span-12 lg:col-span-4">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200 h-full">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Driver</p>
            <h2 class="mt-2 text-2xl font-black text-slate-900"><?php echo htmlspecialchars($driverName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="mt-1 text-sm text-slate-600"><?php echo htmlspecialchars($driverEmail, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="mt-3 text-xs text-slate-500">Role: Driver</p>
            <div class="mt-6 pt-4 border-t border-slate-200">
              <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-bold <?php echo $isOnRoute ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'; ?>">
                <span class="w-2 h-2 rounded-full <?php echo $isOnRoute ? 'bg-emerald-500' : 'bg-slate-400'; ?>"></span>
                <?php echo $isOnRoute ? 'On active trip' : 'Off route'; ?>
              </span>
            </div>
          </article>
        </div>

        <!-- Live assignment -->
        <div class="col-span-12 lg:col-span-8">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Current assignment</p>
            <h2 class="mt-2 text-xl font-black text-slate-900"><?php echo htmlspecialchars($activeRouteDisplay, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <div class="stat-card">
                <p class="text-xs text-slate-500">Bus</p>
                <p class="mt-1 font-bold text-slate-900"><?php echo htmlspecialchars($busNumber, ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
              <div class="stat-card">
                <p class="text-xs text-slate-500">Plate</p>
                <p class="mt-1 font-bold text-slate-900"><?php echo htmlspecialchars($plateNumber, ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
              <div class="stat-card">
                <p class="text-xs text-slate-500">Current stop</p>
                <p class="mt-1 font-bold text-slate-900"><?php echo htmlspecialchars($currentStop, ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
              <div class="stat-card">
                <p class="text-xs text-slate-500">Trip started</p>
                <p class="mt-1 font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($activeTripStarted, ENT_QUOTES, 'UTF-8'); ?></p>
              </div>
            </div>
          </article>
        </div>

        <!-- Stats -->
        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
          <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.2em] text-slate-500 font-semibold">Trips today</p>
            <p class="mt-2 text-2xl font-black"><?php echo $tripsCompletedToday; ?></p>
            <p class="text-xs text-slate-500 mt-1">Completed <?php echo date('M j, Y'); ?></p>
          </article>
        </div>
        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
          <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.2em] text-slate-500 font-semibold">All trips</p>
            <p class="mt-2 text-2xl font-black"><?php echo $tripsCompletedAll; ?></p>
            <p class="text-xs text-slate-500 mt-1">Total completed legs</p>
          </article>
        </div>
        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
          <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.2em] text-slate-500 font-semibold">Passengers</p>
            <p class="mt-2 text-2xl font-black"><?php echo $passengersHandled; ?></p>
            <p class="text-xs text-slate-500 mt-1">Fare transactions</p>
          </article>
        </div>
        <div class="col-span-12 sm:col-span-6 lg:col-span-3">
          <article class="rounded-[1.5rem] bg-white p-5 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.2em] text-slate-500 font-semibold">Earnings</p>
            <p class="mt-2 text-2xl font-black text-emerald-700"><?php echo htmlspecialchars($earningsFormatted, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="text-xs text-slate-500 mt-1">All-time fares</p>
          </article>
        </div>

        <!-- Last activity -->
        <div class="col-span-12">
          <article class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200">
            <p class="text-xs uppercase tracking-[.25em] text-slate-500 font-semibold">Recent activity</p>
            <div class="mt-4 max-w-2xl">
              <div class="info-row"><span class="text-slate-500">Last trip</span><strong><?php echo htmlspecialchars($lastTripTime, ENT_QUOTES, 'UTF-8'); ?></strong></div>
              <div class="info-row"><span class="text-slate-500">Route</span><strong><?php echo htmlspecialchars($lastTripRoute, ENT_QUOTES, 'UTF-8'); ?></strong></div>
              <div class="info-row"><span class="text-slate-500">Last stop</span><strong><?php echo htmlspecialchars($lastStopVisited, ENT_QUOTES, 'UTF-8'); ?></strong></div>
              <div class="info-row"><span class="text-slate-500">Last passenger</span><strong class="text-right"><?php echo htmlspecialchars($lastPassengerActivity, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>
          </article>
        </div>
      </div>
    </div>
  </main>
</div>
</body>
</html>
