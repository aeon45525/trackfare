<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId = (int) $_SESSION['user_id'];
$activeTrip = null;
$activePassengers = [];
$completedTrips = [];
$activePassengerCount = 0;
$completedTripCount = 0;
$currentStop = 'N/A';
$activeRouteDisplay = 'No active route';
$activeTripStatusLabel = 'Currently On Trip • 0 passengers';
$completedTripsLabel = '0 completed trips';

if ($stmt = $conn->prepare(
    'SELECT t.trip_id, t.route_id, r.display_name
     FROM trips t
     JOIN routes r ON t.route_id = r.route_id
     WHERE t.driver_id = ? AND t.status = ?
     LIMIT 1'
)) {
    $status = 'active';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $activeTrip = $result->fetch_assoc() ?: null;
    $stmt->close();
}

$routeStops = [];
if ($activeTrip) {
    $activeRouteDisplay = $activeTrip['display_name'];
    $routeId = (int) $activeTrip['route_id'];
    $tripId = (int) $activeTrip['trip_id'];

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
        $middleIndex = (int) floor((count($routeStops) - 1) / 2);
        $currentStop = $routeStops[$middleIndex];
    }

    if ($stmt = $conn->prepare(
        'SELECT ap.user_id, u.full_name, ap.card_id, st.stop_name AS boarding_stop
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
            $activePassengers[] = [
                'user_id' => $row['user_id'],
                'full_name' => $row['full_name'],
                'boarding_stop' => $row['boarding_stop'],
                'card_id' => $row['card_id'],
            ];
        }
        $stmt->close();
    }

    $activePassengerCount = count($activePassengers);
    $activeTripStatusLabel = 'Currently On Trip • ' . $activePassengerCount . ' passenger' . ($activePassengerCount === 1 ? '' : 's');
}

if ($stmt = $conn->prepare(
    'SELECT COUNT(*) AS trip_count
     FROM trips
     WHERE driver_id = ? AND status = ?'
)) {
    $status = 'completed';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $completedTripCount = (int) ($row['trip_count'] ?? 0);
    $completedTripsLabel = $completedTripCount . ' completed trip' . ($completedTripCount === 1 ? '' : 's');
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT t.trip_id, r.display_name
     FROM trips t
     JOIN routes r ON t.route_id = r.route_id
     WHERE t.driver_id = ? AND t.status = ?
     ORDER BY t.trip_id DESC
     LIMIT 2'
)) {
    $status = 'completed';
    $stmt->bind_param('is', $driverId, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $completedTrips[] = [
            'trip_id' => (int) $row['trip_id'],
            'route_label' => $row['display_name'],
            'passengers' => [],
        ];
    }
    $stmt->close();
}

foreach ($completedTrips as &$trip) {
    if ($stmt = $conn->prepare(
        'SELECT tt.user_id, u.full_name, stb.stop_name AS boarding_stop, ste.stop_name AS alighting_stop
         FROM trip_transactions tt
         JOIN users u ON tt.user_id = u.user_id
         JOIN stops stb ON tt.boarding_stop_id = stb.stop_id
         JOIN stops ste ON tt.alighting_stop_id = ste.stop_id
         WHERE tt.trip_id = ?
         ORDER BY tt.transaction_id ASC
         LIMIT 2'
    )) {
        $stmt->bind_param('i', $trip['trip_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $trip['passengers'][] = [
                'full_name' => $row['full_name'],
                'boarding_stop' => $row['boarding_stop'],
                'alighting_stop' => $row['alighting_stop'],
            ];
        }
        $stmt->close();
    }
}
unset($trip);
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
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
      .pulse-ring {
        animation: pulseRing 2.5s ease-out infinite;
      }
      @keyframes pulseRing {
        0%,
        100% {
          box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.35);
        }
        50% {
          box-shadow: 0 0 0 16px rgba(16, 185, 129, 0);
        }
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
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="02_route.php"
            >
              <span class="material-symbols-outlined">alt_route</span>
              <span>Route</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold transition"
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
              Passenger Logs
            </h1>
            <p class="mt-2 text-sm text-slate-600">
              Monitor onboard passengers and completed trips in a clean
              dashboard view.
            </p>
          </header>
          <section
            class="bg-white rounded-[1.5rem] shadow-sm border border-slate-200 overflow-hidden"
          >
            <div
              class="px-8 py-6 border-b border-slate-200 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
            >
              <div>
                <p
                  class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                >
                  Logs
                </p>
                <h2 class="mt-2 text-2xl font-black text-slate-900">
                  Passenger Monitor
                </h2>
              </div>
              <div class="flex flex-wrap items-center gap-3">
                <span
                  class="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-3 py-2 text-xs font-semibold text-emerald-700 pulse-ring"
                >
                  <span
                    class="w-2.5 h-2.5 rounded-full bg-emerald-600 shadow-sm"
                  ></span>
                  LIVE
                </span>
                <span
                  class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600"
                >
                  <span class="material-symbols-outlined">insights</span>
                  Bus monitoring view
                </span>
              </div>
            </div>

            <div class="px-8 py-8 space-y-10">
              <div class="grid gap-4 sm:grid-cols-2">
                <div
                  class="rounded-[1.25rem] border border-emerald-200 bg-emerald-50 p-6"
                >
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-emerald-700 font-semibold"
                  >
                    Onboard Passengers
                  </p>
                  <p class="mt-3 text-4xl font-black text-slate-900"><?php echo $activePassengerCount; ?></p>
                  <p class="mt-2 text-sm text-slate-600">
                    Active passengers currently on the bus.
                  </p>
                </div>
                <div
                  class="rounded-[1.25rem] border border-slate-200 bg-slate-50 p-6"
                >
                  <p
                    class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                  >
                    Completed Trips
                  </p>
                  <p class="mt-3 text-4xl font-black text-slate-900"><?php echo $completedTripCount; ?></p>
                  <p class="mt-2 text-sm text-slate-600">
                    Trips that have finished and left the active route.
                  </p>
                </div>
              </div>

              <div
                class="rounded-[1.5rem] border border-emerald-200 bg-emerald-50/80 p-6 shadow-sm"
              >
                <div
                  class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-emerald-700 font-semibold"
                    >
                      Current Trip Group
                    </p>
                    <h3 class="mt-2 text-2xl font-black text-slate-900">
                      <?php echo htmlspecialchars($activeRouteDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </h3>
                    <p class="mt-2 text-sm text-slate-700">
                      Current Stop:
                      <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($currentStop, ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                  </div>
                  <div
                    class="rounded-full bg-emerald-700/10 px-4 py-2 text-sm font-semibold text-emerald-900"
                  >
                    <?php echo htmlspecialchars($activeTripStatusLabel, ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                </div>
                <div class="mt-6 space-y-4">
                  <?php if (!empty($activePassengers)): ?>
                    <?php foreach ($activePassengers as $passenger): ?>
                      <div class="rounded-[1.25rem] border border-emerald-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                          <div>
                            <p class="text-sm font-semibold text-slate-900">
                              <?php echo htmlspecialchars($passenger['full_name'], ENT_QUOTES, 'UTF-8'); ?> • ID <?php echo htmlspecialchars($passenger['user_id'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                              <?php echo htmlspecialchars($activeRouteDisplay, ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                          </div>
                          <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                            Tap In
                          </span>
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                          <div class="rounded-[1rem] border border-emerald-200 bg-emerald-50/80 p-4">
                            <p class="text-xs uppercase tracking-[0.3em] text-emerald-700 font-semibold">
                              Boarding
                            </p>
                            <p class="mt-2 text-sm font-semibold text-slate-900">
                              <?php echo htmlspecialchars($passenger['boarding_stop'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="mt-1 text-xs text-slate-500">In Progress</p>
                          </div>
                          <div class="rounded-[1rem] border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold">
                              Tap Out
                            </p>
                            <p class="mt-2 text-sm font-semibold text-slate-900">
                              In Progress
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                              Awaiting exit stop
                            </p>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="rounded-[1.25rem] border border-slate-200 bg-white p-4 shadow-sm">
                      <div class="flex items-center gap-4">
                        <div>
                          <p class="text-sm font-semibold text-slate-900">No onboard passengers</p>
                          <p class="mt-1 text-xs text-slate-500">All passengers checked out</p>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div
                class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-6"
              >
                <div
                  class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                    >
                      Completed Trip History
                    </p>
                    <h3 class="mt-2 text-2xl font-black text-slate-900">
                      Completed Trips
                    </h3>
                    <p class="mt-2 text-sm text-slate-600">
                      Trips finished and archived for review.
                    </p>
                  </div>
                  <div
                    class="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700"
                  >
                    <?php echo htmlspecialchars($completedTripsLabel, ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                </div>
                <div class="mt-6 space-y-6">
                  <?php if (!empty($completedTrips)): ?>
                    <?php foreach ($completedTrips as $trip): ?>
                      <div
                        class="rounded-[1.25rem] border border-slate-200 bg-white p-4 shadow-sm"
                      >
                        <div
                          class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                          <div>
                            <p class="text-sm font-semibold text-slate-900">
                              <?php echo htmlspecialchars($trip['route_label'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                              Completed trip
                            </p>
                          </div>
                          <span
                            class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"
                          >
                            Completed batch
                          </span>
                        </div>
                        <div class="mt-4 space-y-4">
                          <?php if (!empty($trip['passengers'])): ?>
                            <?php foreach ($trip['passengers'] as $passenger): ?>
                              <div
                                class="rounded-[1rem] border border-slate-200 bg-slate-50 p-4 shadow-sm"
                              >
                                <div
                                  class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                  <div>
                                    <p class="text-sm font-semibold text-slate-900">
                                      <?php echo htmlspecialchars($passenger['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                      <?php echo htmlspecialchars($trip['route_label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                  </div>
                                  <span
                                    class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700"
                                  >
                                    Tap Out
                                  </span>
                                </div>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                  <div
                                    class="rounded-[1rem] border border-emerald-200 bg-emerald-50/80 p-4"
                                  >
                                    <p
                                      class="text-xs uppercase tracking-[0.3em] text-emerald-700 font-semibold"
                                    >
                                      Boarding
                                    </p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">
                                      <?php echo htmlspecialchars($passenger['boarding_stop'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">Completed</p>
                                  </div>
                                  <div
                                    class="rounded-[1rem] border border-rose-200 bg-rose-50/80 p-4"
                                  >
                                    <p
                                      class="text-xs uppercase tracking-[0.3em] text-rose-700 font-semibold"
                                    >
                                      Exit
                                    </p>
                                    <p class="mt-2 text-sm font-semibold text-slate-900">
                                      <?php echo htmlspecialchars($passenger['alighting_stop'], ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">Completed</p>
                                  </div>
                                </div>
                              </div>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <div class="rounded-[1rem] border border-slate-200 bg-slate-50 p-4 shadow-sm">
                              <p class="text-sm font-semibold text-slate-900">No passenger details found for this trip</p>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="rounded-[1.25rem] border border-slate-200 bg-white p-4 shadow-sm">
                      <p class="text-sm font-semibold text-slate-900">No completed trips yet</p>
                      <p class="mt-1 text-xs text-slate-500">Completed trips will appear here once available.</p>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
  </body>
</html>
