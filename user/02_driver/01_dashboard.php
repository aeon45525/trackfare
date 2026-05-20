<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/fare.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId = (int) $_SESSION['user_id'];
$driverName = trim($_SESSION['full_name'] ?? 'Driver');
$activeTrip = null;
$routeStops = [];
$activePassengers = [];
$activeTripCollected = 0.0;
$activeTripTransactions = 0;
$driverTotalEarnings = 0.0;
$totalRouteDistance = 0.0;
$averageSpeed = 0;
$estimatedArrival = 'N/A';
$routeProgressPercent = 0;

if ($stmt = $conn->prepare('SELECT t.trip_id, t.route_id, t.current_stop_index, t.start_time, t.end_time, r.route_name, r.display_name, b.bus_number, b.plate_number FROM trips t JOIN routes r ON t.route_id = r.route_id JOIN buses b ON t.bus_id = b.bus_id WHERE t.driver_id = ? AND t.status = ? LIMIT 1')) {
    $status = 'active';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $activeTrip = $result->fetch_assoc() ?: null;
    $stmt->close();
}

if ($activeTrip) {
    $routeId = (int) $activeTrip['route_id'];
    $tripId = (int) $activeTrip['trip_id'];

    if ($stmt = $conn->prepare('SELECT rs.stop_order, s.stop_id, s.stop_name FROM route_stops rs JOIN stops s ON rs.stop_id = s.stop_id WHERE rs.route_id = ? ORDER BY rs.stop_order')) {
      $stmt->bind_param('i', $routeId);
      $stmt->execute();
      $result = $stmt->get_result();
      while ($row = $result->fetch_assoc()) {
        $routeStops[] = [
          'stop_order' => (int) $row['stop_order'],
          'stop_id' => (int) $row['stop_id'],
          'stop_name' => $row['stop_name'],
        ];
      }
      $stmt->close();
    }

    $routeStopCount = count($routeStops);
    $currentStopIndex = max(0, min($routeStopCount - 1, (int) ($activeTrip['current_stop_index'] ?? 0)));

    $segmentDistances = [
        1.2, 0.9, 0.9, 0.8, 0.9, 1.1, 1.1, 0.9, 0.8, 1.0,
        1.0, 1.1, 1.0, 1.2, 1.4, 1.3, 1.2, 1.0, 0.9,
    ];

    $neededSegments = max(0, count($routeStops) - 1);
    $segmentDistances = array_slice($segmentDistances, 0, $neededSegments);
    while (count($segmentDistances) < $neededSegments) {
        $segmentDistances[] = 1.0;
    }

    $cumulativeDistances = [0.0];
    for ($i = 0; $i < count($segmentDistances); $i++) {
        $cumulativeDistances[] = round($cumulativeDistances[$i] + $segmentDistances[$i], 2);
    }

    $totalRouteDistance = count($cumulativeDistances) ? end($cumulativeDistances) : 0.0;
    $routeProgressPercent = $routeStopCount > 1 ? round(($currentStopIndex / ($routeStopCount - 1)) * 100) : 0;
    $estimatedTravelHours = max(0.1, ($routeStopCount - 1) * 0.06);
    $averageSpeed = $totalRouteDistance > 0 ? round($totalRouteDistance / $estimatedTravelHours) : 0;
    $remainingDistance = max(0.0, $totalRouteDistance * (100 - $routeProgressPercent) / 100);
    $arrivalTimestamp = time() + (int) round(($remainingDistance / max(1, $averageSpeed)) * 3600);
    $estimatedArrival = date('g:i A', $arrivalTimestamp);

    if ($stmt = $conn->prepare('SELECT ap.user_id, u.full_name, ap.card_id, ap.boarding_stop_id, st.stop_name AS boarding_stop FROM active_passengers ap JOIN users u ON ap.user_id = u.user_id JOIN stops st ON ap.boarding_stop_id = st.stop_id WHERE ap.trip_id = ? ORDER BY u.full_name')) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
        $boardingStopId = (int) $row['boarding_stop_id'];
        $boardingOrder = null;
        foreach ($routeStops as $r) {
          if ($r['stop_id'] === $boardingStopId) {
            $boardingOrder = $r['stop_order'];
            break;
          }
        }
        $distanceOnboard = null;
        if ($boardingOrder !== null) {
          $distanceOnboard = getDistanceBetweenIndices($boardingOrder, $currentStopIndex, $cumulativeDistances);
        }

        $activePassengers[] = [
          'user_id' => $row['user_id'],
          'full_name' => $row['full_name'],
          'boarding_stop' => $row['boarding_stop'],
          'boarding_stop_id' => $boardingStopId,
          'distance_km' => $distanceOnboard !== null ? $distanceOnboard : 0.0,
          'status_label' => 'Onboard',
          'footer' => 'Card ID: #' . $row['card_id'],
        ];
        }
        $stmt->close();
    }

    if ($stmt = $conn->prepare('SELECT COUNT(*) AS trip_count, COALESCE(SUM(fare_amount), 0) AS total_earnings FROM trip_transactions WHERE trip_id = ?')) {
        $stmt->bind_param('i', $tripId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $activeTripTransactions = (int) ($row['trip_count'] ?? 0);
        $activeTripCollected = (float) ($row['total_earnings'] ?? 0);
        $stmt->close();
    }

    if ($stmt = $conn->prepare('SELECT COALESCE(SUM(tt.fare_amount), 0) AS total_earnings FROM trip_transactions tt JOIN trips t ON tt.trip_id = t.trip_id WHERE t.driver_id = ?')) {
        $stmt->bind_param('i', $driverId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $driverTotalEarnings = (float) ($row['total_earnings'] ?? 0);
        $stmt->close();
    }
}

$routeProgressStops = [];
$routeProgressCurrent = 'N/A';
$routeNextStop = 'N/A';
if (!empty($routeStops)) {
    $routeStopCount = count($routeStops);
    $routeProgressCurrent = ($routeStops[$currentStopIndex]['stop_name'] ?? $routeStops[0]['stop_name']);
    $routeNextStop = $currentStopIndex < $routeStopCount - 1 ? $routeStops[$currentStopIndex + 1]['stop_name'] : 'End of route';

    $indices = [0];
    if ($routeStopCount > 4) {
        $indices[] = (int) floor(($routeStopCount - 1) / 4);
        $indices[] = $middleIndex;
        $indices[] = (int) floor(3 * ($routeStopCount - 1) / 4);
    }
    $indices[] = $routeStopCount - 1;
    $indices = array_unique($indices);
    sort($indices);
    foreach ($indices as $index) {
      $routeProgressStops[] = $routeStops[$index]['stop_name'];
    }
}

$passengerCount = count($activePassengers);
$routeStopCount = count($routeStops);
$tripMetricDuration = $passengerCount > 0 ? $passengerCount . ' onboard' : 'No passengers';
$tripMetricDistance = $routeStopCount > 0 ? number_format($totalRouteDistance, 1) . ' km' : 'No route data';
$tripMetricSpeed = $averageSpeed > 0 ? $averageSpeed . ' km/h' : 'N/A';
$tripMetricArrival = $estimatedArrival;
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Driver Dashboard</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <!-- Leaflet for live map -->
    <link
      rel="stylesheet"
      href="https://unpkg.com/leaflet@1.10.0/dist/leaflet.css"
      integrity="sha256-b48gk2+23GPlCGSO44BXlrwYMy9UFj+sZh1QmP4lw5w="
      crossorigin=""
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "secondary-fixed-dim": "#afcae2",
              "tertiary-fixed-dim": "#ffb783",
              "surface-container": "#edeeef",
              error: "#ba1a1a",
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
              "surface-container-highest": "#e1e3e4",
              "secondary-container": "#cbe6ff",
              "on-surface-variant": "#424654",
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
      .glass-nav {
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(20px);
      }
      .signature-gradient {
        background: linear-gradient(135deg, #0040a1 0%, #0056d2 100%);
      }
      /* Route progress tracker */
      .route-track {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
      }
      .stop-dot {
        width: 12px;
        height: 12px;
        border-radius: 9999px;
        background: #cbd5e1;
        border: 2px solid #fff;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
      }
      .stop-complete {
        background: #22c55e;
      }
      .stop-current {
        background: #0040a1;
      }
      .track-line {
        height: 4px;
        background: linear-gradient(90deg, #cbd5e1, #cbd5e1);
        flex: 1;
        border-radius: 9999px;
      }
      .route-progress-bar {
        height: 10px;
        border-radius: 9999px;
        background: #e2e8f0;
        overflow: hidden;
      }
      .route-progress-fill {
        height: 100%;
        border-radius: 9999px;
        background: #0040a1;
        transition: width 0.3s ease;
      }
      .progress-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 3rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        background: #0040a1;
        color: #ffffff;
        font-weight: 700;
      }
      .progress-card {
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        background: #f8fafc;
        padding: 1rem;
        min-height: 84px;
      }

      /* Passenger list accordion */
      .passenger-item {
        border-radius: 12px;
        padding: 0.75rem;
        background: transparent;
        border: 1px solid transparent;
      }
      .passenger-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
      }
      .passenger-body {
        padding-top: 0.5rem;
        color: #475569;
      }
      .accordion-open {
        border-color: #e2e8f0;
        background: #ffffff;
      }

      /* Live map card */
      #live-map {
        width: 100%;
        height: 520px;
        min-height: 420px;
        border-radius: 1rem;
        overflow: hidden;
        position: relative;
      }
      #live-map iframe {
        width: 100%;
        height: 100%;
        border: none;
        border-radius: inherit;
        display: block;
      }
    </style>
  </head>
  <body class="bg-slate-100 text-on-background font-body antialiased">
    <!-- Layout Wrapper -->
    <div class="flex min-h-screen">
      <!-- SideNavBar -->
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
              class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold transition"
              href="01_dashboard.php"
            >
              <span class="material-symbols-outlined">dashboard</span>
              <span>Dashboard</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
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
                  Juan Dela Cruz
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
      <!-- Main Content -->
      <main class="ml-[260px] flex-1 min-h-screen bg-slate-100 p-10">
        <div class="max-w-full">
          <header class="mb-10">
            <h1
              class="text-4xl font-extrabold tracking-tight text-slate-900 font-headline"
            >
              Dashboard
            </h1>
            <p class="mt-2 text-sm text-slate-600">
              Driver overview for the current route and onboard passengers.
            </p>
          </header>
          <div class="grid grid-cols-12 gap-8">
            <section class="col-span-12 xl:col-span-7 space-y-6">
              <!-- Live Map -->
              <article
                class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200"
              >
                <p
                  class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                >
                  Live Map
                </p>
                <div class="mt-4">
                  <div id="live-map">
                    <iframe
                      id="live-map-iframe"
                      src="https://www.openstreetmap.org/export/embed.html?bbox=120.9500%2C14.7800%2C121.0800%2C14.8900&layer=mapnik&marker=14.8400%2C121.0167"
                      title="Live route map"
                      loading="lazy"
                    ></iframe>
                  </div>
                </div>
              </article>
              <!-- Live Trip Controls -->
              <article
                class="rounded-[1.5rem] bg-white p-4 shadow-sm border border-slate-200"
              >
                <p
                  class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                >
                  Live Trip Controls
                </p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                  <button
                    id="start-trip-btn"
                    class="rounded-2xl bg-emerald-600 text-white py-2 text-xs font-semibold"
                  >
                    Start Trip
                  </button>
                  <button
                    id="arrive-stop-btn"
                    class="rounded-2xl bg-sky-600 text-white py-2 text-xs font-semibold"
                    disabled
                  >
                    Arrive at Stop
                  </button>
                  <button
                    id="depart-stop-btn"
                    class="rounded-2xl bg-indigo-600 text-white py-2 text-xs font-semibold"
                    disabled
                  >
                    Depart Stop
                  </button>
                  <button
                    id="end-trip-btn"
                    class="rounded-2xl bg-rose-600 text-white py-2 text-xs font-semibold"
                    disabled
                  >
                    End Trip
                  </button>
                </div>
              </article>
            </section>
            <section class="col-span-12 xl:col-span-5 space-y-6">
              <article
                class="rounded-[1.5rem] bg-white p-8 shadow-sm border border-slate-200"
              >
                <div class="flex items-start justify-between gap-6">
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                    >
                      Active Route
                    </p>
                    <h2 class="mt-3 text-3xl font-black text-slate-900">
                      <?php echo htmlspecialchars($activeTrip['route_name'] ?? 'No active route'); ?>
                    </h2>
                    <p class="mt-2 text-sm text-slate-600">
                      <?php echo htmlspecialchars($activeTrip['display_name'] ?? 'No route details available'); ?>
                    </p>
                  </div>
                  <span
                    class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700"
                    >On Route</span
                  >
                </div>
                <div class="mt-6">
                  <p
                    class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                  >
                    Route Progress
                  </p>
                  <div class="mt-3 route-track">
                    <div class="route-progress-bar">
                      <div class="route-progress-fill" style="width: <?php echo $routeProgressPercent; ?>%;"></div>
                    </div>
                    <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                      <span>Progress</span>
                      <span id="route-progress-label" class="progress-badge"><?php echo $routeProgressPercent; ?>%</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 mt-4">
                      <div class="progress-card">
                        <p class="text-[0.65rem] uppercase tracking-[0.25em] text-slate-500 font-semibold">Current Stop</p>
                        <p id="route-current-stop" class="mt-2 text-base font-semibold text-slate-900"><?php echo htmlspecialchars($routeProgressCurrent); ?></p>
                      </div>
                      <div class="progress-card">
                        <p class="text-[0.65rem] uppercase tracking-[0.25em] text-slate-500 font-semibold">Next Stop</p>
                        <p id="route-next-stop" class="mt-2 text-base font-semibold text-slate-900"><?php echo htmlspecialchars($routeNextStop); ?></p>
                      </div>
                    </div>
                    <div id="route-status" class="mt-4 text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold">
                      Trip not started
                    </div>
                  </div>
                </div>
              </article>
              <!-- Onboard Passenger List (accordion) -->
              <article
                class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200"
              >
                <div class="flex items-center justify-between">
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                    >
                      Onboard Passengers
                    </p>
                    <h2 class="mt-3 text-2xl font-black text-slate-900">
                      Passenger List
                    </h2>
                  </div>
                  <span
                    class="inline-flex items-center rounded-full bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-700"
                    >Updated now</span
                  >
                </div>
                <div class="mt-6 space-y-3" id="passenger-list">
                  <?php if (!empty($activePassengers)): ?>
                    <?php foreach ($activePassengers as $index => $passenger): ?>
                      <div class="passenger-item <?php echo $index === 0 ? 'accordion-open' : ''; ?>">
                        <div class="passenger-header" data-toggle>
                          <div>
                            <div class="text-sm font-semibold text-slate-900">
                              <?php echo htmlspecialchars($passenger['full_name']); ?>
                              <span class="text-xs text-slate-500">#P<?php echo htmlspecialchars($passenger['user_id']); ?></span>
                            </div>
                            <div class="text-xs text-slate-500">
                              Boarding: <?php echo htmlspecialchars($passenger['boarding_stop']); ?>
                            </div>
                          </div>
                          <div class="text-sm text-slate-900 font-semibold">
                            <?php echo htmlspecialchars($passenger['status_label']); ?>
                          </div>
                        </div>
                        <?php if (!empty($passenger['footer'])): ?>
                          <div class="passenger-body"><?php echo htmlspecialchars($passenger['footer']); ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="passenger-item accordion-open">
                      <div class="passenger-header">
                        <div>
                          <div class="text-sm font-semibold text-slate-900">No onboard passengers</div>
                          <div class="text-xs text-slate-500">All passengers checked out</div>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </article>

              <!-- Trip Metrics & Earnings -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <article
                  class="rounded-[1.5rem] bg-white p-4 shadow-sm border border-slate-200"
                >
                  <p
                    class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold"
                  >
                    Trip Metrics
                  </p>
                  <div class="mt-3 text-sm text-slate-700 space-y-2">
                    <div class="flex justify-between">
                      <span>Trip Duration</span><strong id="metric-duration"><?php echo htmlspecialchars($tripMetricDuration); ?></strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Distance Traveled</span><strong id="metric-distance"><?php echo htmlspecialchars($tripMetricDistance); ?></strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Average Speed</span><strong id="metric-speed"><?php echo htmlspecialchars($tripMetricSpeed); ?></strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Est. Arrival</span><strong id="metric-arrival"><?php echo htmlspecialchars($tripMetricArrival); ?></strong>
                    </div>
                  </div>
                </article>

                <article
                  class="rounded-[1.5rem] bg-white p-4 shadow-sm border border-slate-200"
                >
                  <p
                    class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold"
                  >
                    Earnings
                  </p>
                  <div class="mt-3 text-sm text-slate-700 space-y-2">
                    <div class="flex justify-between">
                      <span>Current Trip</span><strong>₱<?php echo number_format($activeTripCollected, 2); ?></strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Collected Fares</span><strong>₱<?php echo number_format($activeTripCollected, 2); ?></strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Estimated Today</span><strong>₱<?php echo number_format($driverTotalEarnings, 2); ?></strong>
                    </div>
                  </div>
                </article>
              </div>
            </section>
          </div>
        </div>
      </main>
    </div>
    <!-- Leaflet JS and UI scripts -->
    <script
      src="https://unpkg.com/leaflet@1.10.0/dist/leaflet.js"
      integrity="sha256-gZOGcR9PGyS3zT8aqfD7VG4HFSYx4M0Qv2eMCyMaC5k="
      crossorigin=""
    ></script>
    <script>
      // Passenger accordion
      document.addEventListener("DOMContentLoaded", function () {
        document
          .querySelectorAll("#passenger-list .passenger-item")
          .forEach(function (item) {
            const body = item.querySelector(".passenger-body");
            const hdr = item.querySelector("[data-toggle]");
            if (!item.classList.contains("accordion-open")) {
              if (body) body.style.display = "none";
            }
            if (hdr) {
              hdr.style.cursor = "pointer";
              hdr.addEventListener("click", function () {
                const open = item.classList.toggle("accordion-open");
                if (body) body.style.display = open ? "block" : "none";
              });
            }
          });

        // Initialize Leaflet map for Bulacan route
        const stops = [
          { name: "Bocaue", latlng: [14.7983, 120.9742] },
          { name: "Marilao", latlng: [14.757, 120.9588] },
          { name: "Meycauayan", latlng: [14.735, 120.9604] },
        ];

        try {
          const iframe = document.getElementById("live-map-iframe");
          if (iframe) {
            iframe.style.display = "none";
          }

          const mapEl = document.getElementById("live-map");
          if (!mapEl) return;

          const map = L.map(mapEl, {
            attributionControl: false,
            zoomControl: true,
          }).setView([14.77, 120.965], 12);
          L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
          }).addTo(map);
          const routeCoords = stops.map((s) => s.latlng);
          L.polyline(routeCoords, {
            color: "#0040a1",
            weight: 4,
            opacity: 0.85,
          }).addTo(map);
          stops.forEach((s, i) => {
            L.circleMarker(s.latlng, {
              radius: 7,
              fillColor: i === 1 ? "#0040a1" : "#22c55e",
              color: "#fff",
              weight: 2,
              fillOpacity: 1,
            })
              .addTo(map)
              .bindPopup("<b>" + s.name + "</b>");
          });
          // Static bus marker
          const busIcon = L.divIcon({
            className: "",
            html: '<div style="width:28px;height:28px;border-radius:14px;background:#0040a1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px">🚌</div>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
          });
          L.marker([14.778, 120.966], { icon: busIcon })
            .addTo(map)
            .bindPopup("<b>Your Bus</b>");
          map.fitBounds(L.latLngBounds(routeCoords).pad(0.2));
        } catch (e) {
          console.warn("Leaflet init failed", e);
          const iframe = document.getElementById("live-map-iframe");
          if (iframe) {
            iframe.style.display = "block";
          }
        }

        // Trip progress simulation
        const routeStopNames = <?php echo json_encode(array_column($routeStops, 'stop_name')); ?>;
        const routeCumulativeDistances = <?php echo json_encode($cumulativeDistances ?? [0.0]); ?>;
        const routeTotalDistance = <?php echo $totalRouteDistance; ?>;
        const avgSpeedStatic = <?php echo $averageSpeed; ?>;
        const onboardCount = <?php echo (int) $passengerCount; ?>;
        const startBtn = document.getElementById('start-trip-btn');
        const arriveBtn = document.getElementById('arrive-stop-btn');
        const departBtn = document.getElementById('depart-stop-btn');
        const endBtn = document.getElementById('end-trip-btn');
        const statusEl = document.getElementById('route-status');
        const currentStopEl = document.getElementById('route-current-stop');
        const nextStopEl = document.getElementById('route-next-stop');
        const progressFill = document.querySelector('.route-progress-fill');
        const progressLabelEl = document.getElementById('route-progress-label');
        const metricDurationEl = document.getElementById('metric-duration');
        const metricDistanceEl = document.getElementById('metric-distance');
        const metricSpeedEl = document.getElementById('metric-speed');
        const metricArrivalEl = document.getElementById('metric-arrival');

        let tripState = 'idle';
        let currentStopIndex = <?php echo max(0, (int)($currentStopIndex ?? 0)); ?>;
        let tripTimer = null;
        const stepMs = 3000;

        const totalStops = routeStopNames.length;

        function setStatus(text) {
          if (statusEl) statusEl.textContent = text;
        }

        function formatDistance(value) {
          return `${value.toFixed(1)} km`;
        }

        function formatTime(date) {
          const options = { hour: 'numeric', minute: '2-digit' };
          return date.toLocaleTimeString([], options);
        }

        function updateProgress() {
          if (!progressFill) return;
          const percent = totalStops > 1 ? Math.round((currentStopIndex / (totalStops - 1)) * 100) : 0;
          progressFill.style.width = percent + '%';
          if (progressLabelEl) {
            progressLabelEl.textContent = percent + '%';
          }
          if (currentStopEl) {
            currentStopEl.textContent = routeStopNames[currentStopIndex] || 'N/A';
          }
          if (nextStopEl) {
            nextStopEl.textContent = currentStopIndex < totalStops - 1 ? routeStopNames[currentStopIndex + 1] : 'End of route';
          }

          const currentDistance = routeCumulativeDistances[currentStopIndex] || 0;
          const remainingDistance = Math.max(0, routeTotalDistance - currentDistance);
          const estimatedArrival = avgSpeedStatic > 0
            ? new Date(Date.now() + (remainingDistance / avgSpeedStatic) * 3600 * 1000)
            : null;

          if (metricDistanceEl) {
            metricDistanceEl.textContent = formatDistance(currentDistance);
          }
          if (metricSpeedEl) {
            metricSpeedEl.textContent = `${avgSpeedStatic} km/h`;
          }
          if (metricArrivalEl) {
            metricArrivalEl.textContent = estimatedArrival ? formatTime(estimatedArrival) : 'N/A';
          }
          if (metricDurationEl) {
            metricDurationEl.textContent = onboardCount > 0 ? `${onboardCount} onboard` : 'No passengers';
          }
        }

        function clearTimer() {
          if (tripTimer) {
            clearInterval(tripTimer);
            tripTimer = null;
          }
        }

        function startTimer() {
          clearTimer();
          tripTimer = setInterval(() => {
            if (currentStopIndex < totalStops - 1) {
              currentStopIndex += 1;
              updateProgress();
              setStatus(`Arrived at ${routeStopNames[currentStopIndex]}`);
              if (currentStopIndex >= totalStops - 1) {
                finishTrip();
              }
            }
          }, stepMs);
        }

        function setButtonState(state) {
          tripState = state;
          startBtn.disabled = state !== 'idle';
          arriveBtn.disabled = state !== 'running';
          departBtn.disabled = state !== 'paused';
          endBtn.disabled = state === 'idle' || state === 'complete';
        }

        function beginTrip() {
          if (totalStops < 2 || tripState === 'running' || tripState === 'complete') return;
          currentStopIndex = 0;
          updateProgress();
          setStatus(`Departing from ${routeStopNames[0]}`);
          setButtonState('running');
          startTimer();
        }

        function arriveAtStop() {
          if (tripState !== 'running') return;
          clearTimer();
          setStatus(`Arrived at ${routeStopNames[currentStopIndex]}`);
          setButtonState('paused');
        }

        function departFromStop() {
          if (tripState !== 'paused') return;
          if (currentStopIndex >= totalStops - 1) {
            finishTrip();
            return;
          }
          setStatus(`Departing from ${routeStopNames[currentStopIndex]}`);
          setButtonState('running');
          startTimer();
        }

        function finishTrip() {
          clearTimer();
          currentStopIndex = totalStops - 1;
          updateProgress();
          setStatus(`Trip complete at ${routeStopNames[currentStopIndex]}`);
          setButtonState('complete');
        }

        if (totalStops < 2) {
          setStatus('No route stops available');
          if (startBtn) startBtn.disabled = true;
        } else {
          updateProgress();
          if (currentStopIndex > 0 && currentStopIndex < totalStops - 1) {
            setStatus(`Ready at ${routeStopNames[currentStopIndex]}`);
            setButtonState('paused');
          } else if (currentStopIndex >= totalStops - 1) {
            setStatus(`Trip complete at ${routeStopNames[totalStops - 1]}`);
            setButtonState('complete');
          } else {
            setStatus('Trip not started');
            setButtonState('idle');
          }
        }

        if (startBtn) startBtn.addEventListener('click', beginTrip);
        if (arriveBtn) arriveBtn.addEventListener('click', arriveAtStop);
        if (departBtn) departBtn.addEventListener('click', departFromStop);
        if (endBtn) endBtn.addEventListener('click', finishTrip);
      });
    </script>
  </body>
</html>
