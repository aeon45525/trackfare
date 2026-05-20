<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId = (int) $_SESSION['user_id'];
$driverName = trim($_SESSION['full_name'] ?? 'Driver');
$driverEmail = trim($_SESSION['email'] ?? '');
$busNumber = 'N/A';
$unitStatus = 'Inactive Unit';
$activeRouteDisplay = 'No active route';
$currentStop = 'N/A';
$tripsToday = 0;
$passengersHandled = 0;
$earnings = 0.0;
$lastTripTime = 'N/A';
$lastStopVisited = 'N/A';
$lastPassengerActivity = 'No activity recorded';

if ($stmt = $conn->prepare('SELECT full_name, email FROM users WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    if ($user) {
        $driverName = trim($user['full_name'] ?: $driverName);
        $driverEmail = trim($user['email'] ?: $driverEmail);
    }
    $stmt->close();
}

$activeTrip = null;
if ($stmt = $conn->prepare(
    'SELECT t.trip_id, t.route_id, b.bus_number, r.display_name
     FROM trips t
     JOIN buses b ON t.bus_id = b.bus_id
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

if ($activeTrip) {
    $busNumber = $activeTrip['bus_number'];
    $activeRouteDisplay = $activeTrip['display_name'];
    $unitStatus = 'Active Unit';
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
        $middleIndex = (int) floor((count($routeStops) - 1) / 2);
        $currentStop = $routeStops[$middleIndex];
        $lastStopVisited = $currentStop;
        $lastTripTime = 'Active now';
    }

    $tripId = (int) $activeTrip['trip_id'];
}

if ($stmt = $conn->prepare('SELECT COUNT(*) AS count FROM trip_transactions tt JOIN trips t ON tt.trip_id = t.trip_id WHERE t.driver_id = ?')) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $passengersHandled = (int) ($row['count'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT COUNT(*) AS total_trips, COALESCE(SUM(tt.fare_amount), 0) AS total_earnings
     FROM trips t
     LEFT JOIN trip_transactions tt ON t.trip_id = tt.trip_id
     WHERE t.driver_id = ?'
)) {
    $stmt->bind_param('i', $driverId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $tripsToday = (int) ($row['total_trips'] ?? 0);
        $earnings = (float) ($row['total_earnings'] ?? 0.0);
    }
    $stmt->close();
}

if ($stmt = $conn->prepare(
    'SELECT tt.user_id, u.full_name, stb.stop_name AS boarding_stop, ste.stop_name AS alighting_stop
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
    $result = $stmt->get_result();
    $activity = $result->fetch_assoc();
    if ($activity) {
        $lastPassengerActivity = sprintf(
            '%s boarded at %s and headed to %s.',
            $activity['full_name'],
            $activity['boarding_stop'],
            $activity['alighting_stop']
        );
        if ($lastStopVisited === 'N/A') {
            $lastStopVisited = $activity['boarding_stop'];
        }
    }
    $stmt->close();
}

if ($passengersHandled === 0 && $activeTrip) {
    $passengersHandled = (int) ($passengersHandled);
}

$earningsFormatted = '₱' . number_format($earnings, 2);
?>

<!doctype html>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Driver Profile</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
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
      }
      .glass-effect {
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
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
              class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
              href="03_logs.php"
            >
              <span class="material-symbols-outlined">receipt_long</span>
              <span>Logs</span>
            </a>
            <a
              class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 border-r-4 border-blue-700 font-semibold transition"
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
              Profile
            </h1>
            <p class="mt-2 text-sm text-slate-600">Driver account overview</p>
          </header>

          <section
            class="bg-white rounded-[1.5rem] shadow-sm border border-slate-200 overflow-hidden p-6 space-y-6"
          >
            <div
              class="rounded-[1.5rem] border border-blue-200 bg-blue-50 p-6 shadow-sm"
            >
              <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
              >
                <div>
                  <p
                    class="text-sm uppercase tracking-[0.2em] text-blue-700 font-semibold"
                  >
                    Driver Status
                  </p>
                  <p class="mt-3 text-3xl font-bold text-slate-900">On Route</p>
                </div>
                <div
                  class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900"
                >
                  <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                  Live route active
                </div>
              </div>
              <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div class="rounded-3xl border border-slate-200 bg-white p-4">
                  <p class="text-sm text-slate-500">Current Route</p>
                  <p class="mt-2 text-base font-semibold text-slate-900">
                    <?php echo htmlspecialchars($activeRouteDisplay, ENT_QUOTES, 'UTF-8'); ?>
                  </p>
                </div>
                <div class="rounded-3xl border border-slate-200 bg-white p-4">
                  <p class="text-sm text-slate-500">Current Stop</p>
                  <p class="mt-2 text-base font-semibold text-slate-900">
                    <?php echo htmlspecialchars($currentStop, ENT_QUOTES, 'UTF-8'); ?>
                  </p>
                </div>
              </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1.3fr_0.9fr]">
              <div class="rounded-[1.5rem] border border-slate-200 p-6">
                <h2 class="font-bold text-lg text-slate-900 mb-4">
                  Operational Assignment
                </h2>
                <div class="grid gap-4 text-sm text-slate-700">
                  <div
                    class="rounded-3xl border border-slate-200 bg-slate-50 p-4"
                  >
                    <p class="text-sm font-semibold text-slate-700">
                      Bus Number
                    </p>
                    <p class="mt-2 text-xl font-bold text-slate-900"><?php echo htmlspecialchars($busNumber, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div
                    class="rounded-3xl border border-slate-200 bg-slate-50 p-4"
                  >
                    <p class="text-sm font-semibold text-slate-700">
                      Unit Status
                    </p>
                    <p class="mt-2 text-xl font-bold text-slate-900">
                      <?php echo htmlspecialchars($unitStatus, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                  </div>
                  <div
                    class="rounded-3xl border border-slate-200 bg-slate-50 p-4"
                  >
                    <p class="text-sm font-semibold text-slate-700">
                      Assigned Route
                    </p>
                    <p class="mt-2 text-base font-semibold text-slate-900">
                      <?php echo htmlspecialchars($activeRouteDisplay, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                  </div>
                </div>
              </div>

              <div class="rounded-[1.5rem] border border-slate-200 p-6">
                <h2 class="font-bold text-lg text-slate-900 mb-4">
                  Driver Information
                </h2>
                <div class="grid gap-4 text-sm text-slate-700">
                  <div>
                    <p class="font-semibold text-slate-900">Driver Name</p>
                    <p class="mt-1"><?php echo htmlspecialchars($driverName, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-900">Email</p>
                    <p class="mt-1"><?php echo htmlspecialchars($driverEmail, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-900">Role</p>
                    <p class="mt-1">Driver</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[1.4fr_0.9fr]">
              <div class="rounded-[1.5rem] border border-slate-200 p-6">
                <h2 class="font-bold text-lg text-slate-900 mb-4">
                  Activity Overview
                </h2>
                <div class="grid gap-4 sm:grid-cols-3 text-sm text-slate-700">
                  <div
                    class="rounded-3xl border border-slate-200 bg-slate-50 p-4 text-center"
                  >
                    <p
                      class="text-xs uppercase tracking-[0.2em] text-slate-500"
                    >
                      Trips Today
                    </p>
                    <p class="mt-3 text-2xl font-bold text-slate-900"><?php echo htmlspecialchars($tripsToday, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div
                    class="rounded-3xl border border-slate-200 bg-slate-50 p-4 text-center"
                  >
                    <p
                      class="text-xs uppercase tracking-[0.2em] text-slate-500"
                    >
                      Passengers Handled
                    </p>
                    <p class="mt-3 text-2xl font-bold text-slate-900"><?php echo htmlspecialchars($passengersHandled, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div
                    class="rounded-3xl border border-slate-200 bg-slate-50 p-4 text-center"
                  >
                    <p
                      class="text-xs uppercase tracking-[0.2em] text-slate-500"
                    >
                      Earnings
                    </p>
                    <p class="mt-3 text-2xl font-bold text-slate-900"><?php echo htmlspecialchars($earningsFormatted, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                </div>
              </div>

              <div class="rounded-[1.5rem] border border-slate-200 p-6">
                <h2 class="font-bold text-lg text-slate-900 mb-4">
                  Last Activity
                </h2>
                <div class="grid gap-4 text-sm text-slate-700">
                  <div>
                    <p class="font-semibold text-slate-900">Last Trip Time</p>
                    <p class="mt-1"><?php echo htmlspecialchars($lastTripTime, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-900">
                      Last Stop Visited
                    </p>
                    <p class="mt-1"><?php echo htmlspecialchars($lastStopVisited, ENT_QUOTES, 'UTF-8'); ?></p>
                  </div>
                  <div>
                    <p class="font-semibold text-slate-900">
                      Last Passenger Activity
                    </p>
                    <p class="mt-1 text-slate-600">
                      <?php echo htmlspecialchars($lastPassengerActivity, ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>
  </body>
</html>
