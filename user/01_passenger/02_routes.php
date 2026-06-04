<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/fare.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    header('Location: ../../auth/login.php');
    exit;
}

$userLat = null;
$userLng = null;
$userFullName = '';
$userId = (int) $_SESSION['user_id'];
$hasActiveTrip = false;
$activeTripData = null;

if ($stmt = $conn->prepare('SELECT full_name, lat, lng FROM users WHERE user_id = ? LIMIT 1')) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($dbName, $dbLat, $dbLng);
    if ($stmt->fetch()) {
        $userFullName = $dbName;
        $userLat = $dbLat !== null ? (float) $dbLat : null;
        $userLng = $dbLng !== null ? (float) $dbLng : null;
    }
    $stmt->close();
}

// Check if passenger has an active trip
if ($stmt = $conn->prepare(
    'SELECT ap.lat, ap.lng, ap.boarding_stop_id, t.trip_id, t.route_id, t.current_stop_index, bs.stop_name AS boarding_stop
     FROM active_passengers ap
     JOIN trips t ON ap.trip_id = t.trip_id
     LEFT JOIN stops bs ON ap.boarding_stop_id = bs.stop_id
     WHERE ap.user_id = ? AND t.status = ?'
)) {
    $status = 'active';
    $stmt->bind_param('is', $userId, $status);
    $stmt->execute();
    $activeTripData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($activeTripData) {
    $hasActiveTrip = true;
    // Use passenger's current position from active_passengers if available
    if ($activeTripData['lat'] !== null && $activeTripData['lng'] !== null) {
        $userLat = (float) $activeTripData['lat'];
        $userLng = (float) $activeTripData['lng'];
    }
}

function loadRouteStops(mysqli $conn, int $routeId): array
{
    $routeStops = [];

    if ($stmt = $conn->prepare(
        'SELECT s.stop_id, s.stop_name, s.municipality, s.lat, s.lng
         FROM route_stops rs
         JOIN stops s ON rs.stop_id = s.stop_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    )) {
        $stmt->bind_param('i', $routeId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $routeStops[] = [
                'stop_id'      => (int) $row['stop_id'],
                'stop_name'    => $row['stop_name'],
                'municipality' => $row['municipality'],
                'lat'          => (float) $row['lat'],
                'lng'          => (float) $row['lng'],
            ];
        }
        $stmt->close();
    }

    return $routeStops;
}

$routeId = 1;
$routeStops = loadRouteStops($conn, $routeId);

if (count($routeStops) === 0) {
    $routeStops = [
        ['stop_id' => 1, 'stop_name' => 'ULTRA MEGA', 'municipality' => 'Balagtas, Bulacan', 'lat' => 14.82005556, 'lng' => 120.90252778],
        ['stop_id' => 2, 'stop_name' => 'BALAGTAS ARENA', 'municipality' => 'Balagtas, Bulacan', 'lat' => 14.812972, 'lng' => 120.912889],
        ['stop_id' => 20, 'stop_name' => 'VICTONICA MONUMENTO', 'municipality' => 'Caloocan City', 'lat' => 14.65872222, 'lng' => 120.98447222],
    ];
}

$routesCatalog = [];
$dbRoutes = [];

if ($stmt = $conn->prepare('SELECT route_id, route_name, display_name FROM routes ORDER BY route_id ASC')) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dbRoutes[] = $row;
    }
    $stmt->close();
}

if (count($dbRoutes) === 0) {
    $dbRoutes[] = [
        'route_id'     => 1,
        'route_name'   => 'Balagtas-Monumento',
        'display_name' => 'Balagtas → Monumento',
    ];
}

foreach ($dbRoutes as $routeRow) {
    $rid = (int) $routeRow['route_id'];
    $stops = loadRouteStops($conn, $rid);
    if (count($stops) === 0 && $rid === 1) {
        $stops = $routeStops;
    }
    if (count($stops) === 0) {
        continue;
    }

    $routesCatalog[$rid] = [
        'routeId'     => $rid,
        'routeName'   => $routeRow['route_name'],
        'displayName' => $routeRow['display_name'],
        'stops'       => array_map(static fn($stop) => [
            'stop_id'      => (int) ($stop['stop_id'] ?? 0),
            'name'         => $stop['stop_name'],
            'municipality' => $stop['municipality'],
            'lat'          => (float) $stop['lat'],
            'lng'          => (float) $stop['lng'],
        ], $stops),
    ];
}

$routeOptions = [];
foreach ($routesCatalog as $catalogRoute) {
    $routeOptions[] = [
        'route_id' => (int) $catalogRoute['routeId'],
        'label'    => $catalogRoute['displayName'] ?: $catalogRoute['routeName'],
    ];
}

$coordsForFare = array_map(static fn($s) => [
    'lat' => $s['lat'] ?? 0.0,
    'lng' => $s['lng'] ?? 0.0,
], $routeStops);
$cumulativeDistances = build_cumulative_from_route_stops($coordsForFare);

$farePolicyLabel = fare_policy_label();
$fareByRoute = [];
foreach (array_keys($routesCatalog) as $rid) {
    $fareData = fare_route_data($conn, (int) $rid);
    $fareByRoute[(int) $rid] = [
        'stopNames'  => array_column($fareData['stops'], 'stop_name'),
        'cumulative' => $fareData['cumulative'],
    ];
}

$pageTitle = 'Routes';
$activeNav = 'routes';
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="../../images/logo.png" />
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
      rel="stylesheet"
    />
    <script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "on-background": "#191c1d",
              "on-tertiary": "#ffffff",
              "surface-container": "#edeeef",
              "on-tertiary-container": "#ffd0b0",
              "surface-container-low": "#f3f4f5",
              tertiary: "#713700",
              "secondary-fixed": "#cbe6ff",
              background: "#f8f9fa",
              "surface-tint": "#0056d2",
              "on-secondary-fixed-variant": "#30495d",
              "inverse-on-surface": "#f0f1f2",
              "secondary-container": "#cbe6ff",
              "surface-container-lowest": "#ffffff",
              "on-secondary": "#ffffff",
              "primary-fixed": "#dae2ff",
              "surface-bright": "#f8f9fa",
              "on-primary-fixed": "#001847",
              "tertiary-container": "#944b00",
              "outline-variant": "#c3c6d6",
              "inverse-primary": "#b2c5ff",
              "surface-variant": "#e1e3e4",
              primary: "#0040a1",
              "inverse-surface": "#2e3132",
              "error-container": "#ffdad6",
              "primary-fixed-dim": "#b2c5ff",
              "on-surface": "#191c1d",
              secondary: "#486176",
              "on-primary-container": "#ccd8ff",
              "on-error-container": "#93000a",
              "surface-container-high": "#e7e8e9",
              "secondary-fixed-dim": "#afcae2",
              "on-tertiary-fixed": "#301400",
              "tertiary-fixed-dim": "#ffb783",
              surface: "#f8f9fa",
              "tertiary-fixed": "#ffdcc5",
              "on-tertiary-fixed-variant": "#713700",
              "on-error": "#ffffff",
              "surface-container-highest": "#e1e3e4",
              "on-secondary-fixed": "#001e30",
              "on-secondary-container": "#4e677c",
              "on-primary-fixed-variant": "#0040a1",
              "on-surface-variant": "#424654",
              "surface-dim": "#d9dadb",
              "primary-container": "#0056d2",
              error: "#ba1a1a",
              outline: "#737785",
              "on-primary": "#ffffff",
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
      html {
        scrollbar-gutter: stable;
      }
      body {
        margin: 0;
        min-height: max(884px, 100dvh);
        display: flex;
        justify-content: center;
        background: #f8f9fa;
        overflow-x: hidden;
        -webkit-tap-highlight-color: transparent;
      }
      #app-shell {
        width: min(100%, 420px);
        min-height: 100dvh;
        position: relative;
      }
      .nav-link {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 4.25rem;
        height: 3.25rem;
        border-radius: 1rem;
        flex-shrink: 0;
        color: rgba(66, 70, 84, 0.6);
        transition: color 0.2s, background-color 0.2s, box-shadow 0.2s;
      }
      .nav-link:hover:not(.active) {
        color: #0040a1;
      }
      .nav-link.active {
        background: #0040a1;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 64, 161, 0.25);
      }
      .map-card {
        background: #ffffff;
        border: 1px solid #e1e3e4;
        border-radius: 1.75rem;
        padding: 1rem;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      }
      #map-empty {
        width: 100%;
        min-height: 280px;
        border-radius: 1.25rem;
        background: linear-gradient(180deg, #eef2f7 0%, #e8eef4 100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        text-align: center;
        padding: 1.5rem;
      }
      #map-panel {
        display: none;
      }
      #map-panel.is-visible {
        display: block;
      }
      #route-map {
        width: 100%;
        height: min(52vw, 280px);
        min-height: 260px;
        border-radius: 1.25rem;
        overflow: hidden;
        background: #e8eef4;
      }
      .map-action-btn {
        min-height: 2.5rem;
        border-radius: 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        width: 100%;
        transition: background-color 0.2s, opacity 0.2s;
      }
      .fare-compact {
        border-radius: 1rem;
        padding: 0.75rem;
      }
      .fare-field {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
      }
      .fare-select-wrap {
        position: relative;
      }
      .fare-select-wrap .material-symbols-outlined {
        position: absolute;
        left: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        font-size: 1rem;
        color: #0040a1;
        pointer-events: none;
      }
      .fare-select {
        width: 100%;
        min-height: 2.5rem;
        padding: 0.5rem 1.75rem 0.5rem 2.15rem;
        border-radius: 0.75rem;
        border: 1px solid #c3c6d6;
        background: #ffffff;
        font-size: 0.8125rem;
        color: #191c1d;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23424654' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.65rem center;
      }
      .fare-select:disabled {
        background-color: #f3f4f5;
        color: #737785;
      }
      .fare-result-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.625rem 0.75rem;
        border-radius: 0.75rem;
        background: #dae2ff;
        font-size: 0.8125rem;
      }
      .stops-float-backdrop {
        padding: 1rem;
        align-items: center;
        justify-content: center;
      }
      .stops-float-card {
        width: min(100%, 300px);
        border-radius: 1rem;
        background: #ffffff;
        border: 1px solid #e1e3e4;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.18);
        overflow: hidden;
        animation: pop-in 0.2s ease-out;
      }
      .stops-float-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        padding: 0.625rem 0.75rem;
        border-bottom: 1px solid #eef0f2;
      }
      .stops-float-body {
        padding: 0.625rem;
      }
      .stops-select-list {
        width: 100%;
        min-height: 2.5rem;
        max-height: 220px;
        padding: 0.25rem;
        border-radius: 0.75rem;
        border: 1px solid #c3c6d6;
        background: #ffffff;
        font-size: 0.8125rem;
        color: #191c1d;
        overflow-y: auto;
      }
      .stops-select-list option {
        padding: 0.4rem 0.5rem;
        border-radius: 0.35rem;
      }
      @keyframes pop-in {
        from {
          transform: scale(0.96);
          opacity: 0;
        }
        to {
          transform: scale(1);
          opacity: 1;
        }
      }
    </style>
  </head>
  <body class="bg-surface font-body text-on-surface">
    <div id="app-shell" class="w-full">
      <header
        class="fixed inset-x-0 top-0 z-50 w-full max-w-[420px] mx-auto bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200/80"
      >
        <div class="flex items-center justify-between px-4 h-16">
          <div class="flex items-center gap-3 min-w-0 flex-1">
            <img
              src="../../images/logo.png"
              alt=""
              class="h-9 w-9 object-contain shrink-0"
              aria-hidden="true"
            />
            <div class="min-w-0">
              <h1
                class="font-headline font-extrabold text-lg leading-tight tracking-tight text-on-surface truncate"
              >
                TrackFare
              </h1>
              <p class="text-xs text-on-surface-variant truncate">
                <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
              </p>
            </div>
          </div>
          <a href="05_profile.php" class="shrink-0">
            <img
              src="../../images/pfp.png"
              alt="Profile"
              class="h-11 w-11 rounded-2xl object-cover border border-slate-200"
            />
          </a>
        </div>
      </header>

      <main class="pt-20 pb-28 min-h-screen px-4 space-y-4">
        <section
          class="rounded-[1.75rem] bg-surface-container-lowest border border-outline-variant p-5 shadow-sm"
        >
          <label
            for="route-select"
            class="block text-sm font-semibold text-on-surface-variant mb-2"
          >
            Select bus route
          </label>
          <select
            id="route-select"
            class="w-full px-4 py-3 rounded-2xl border border-outline-variant bg-white text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
          >
            <option value="" selected>Select route</option>
            <?php foreach ($routeOptions as $option): ?>
              <option value="<?= (int) $option['route_id'] ?>">
                <?= htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </section>

        <section class="map-card">
          <div id="map-empty">
            <span class="material-symbols-outlined text-4xl text-on-surface-variant/50">map</span>
            <p class="text-sm font-medium text-on-surface">No route selected</p>
            <p class="text-xs text-on-surface-variant max-w-[220px]">
              Choose a bus route above to view the map, stops, and live bus marker.
            </p>
          </div>
          <div id="map-panel" class="space-y-3">
            <div id="route-map"></div>
            <div class="grid grid-cols-2 gap-2.5">
              <button
                type="button"
                id="btn-bus-near"
                class="map-action-btn border border-outline-variant bg-white text-on-surface active:bg-surface-container-low hover:bg-surface-container-low transition-colors"
              >
                <span class="material-symbols-outlined text-[18px]">directions_bus</span>
                Bus near me
              </button>
              <button
                type="button"
                id="btn-show-stops"
                class="map-action-btn border border-outline-variant bg-white text-on-surface active:bg-surface-container-low"
              >
                <span class="material-symbols-outlined text-[16px]">list</span>
                Stops
              </button>
            </div>
          </div>
        </section>

        <section
          id="fare-section"
          class="fare-compact rounded-2xl bg-surface-container-lowest border border-outline-variant shadow-sm opacity-60 pointer-events-none transition-opacity"
        >
          <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
              <h2 class="text-sm font-bold text-on-surface leading-tight">Fare estimate</h2>
              <p class="text-[10px] text-on-surface-variant truncate mt-0.5">
                <?= htmlspecialchars($farePolicyLabel, ENT_QUOTES, 'UTF-8') ?>
              </p>
            </div>
          </div>

          <p id="fare-route-hint" class="mt-2 text-[11px] text-on-surface-variant">
            Select a route first.
          </p>

          <div class="mt-2 space-y-2 rounded-xl bg-white border border-surface-container-high p-2.5">
            <div class="fare-field">
              <label for="from-stop" class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">From</label>
              <div class="fare-select-wrap">
                <span class="material-symbols-outlined">trip_origin</span>
                <select id="from-stop" class="fare-select" disabled>
                  <option value="-1">Boarding stop</option>
                </select>
              </div>
            </div>

            <div class="fare-field">
              <label for="to-stop" class="text-[10px] font-semibold uppercase tracking-wide text-on-surface-variant">To</label>
              <div class="fare-select-wrap">
                <span class="material-symbols-outlined">location_on</span>
                <select id="to-stop" class="fare-select" disabled>
                  <option value="-1">Destination stop</option>
                </select>
              </div>
            </div>

            <div id="fare-result" class="hidden fare-result-bar">
              <span class="text-on-surface-variant"><span id="result-distance">0.00 km</span></span>
              <span id="result-fare" class="font-extrabold text-primary text-base leading-none">₱0.00</span>
            </div>
          </div>
        </section>
      </main>

      <nav
        class="fixed inset-x-0 bottom-0 z-50 mx-auto w-full max-w-[420px] flex items-center justify-around px-1 h-20 bg-white/95 backdrop-blur-md rounded-t-3xl border-t border-slate-200 shadow-[0_-4px_12px_rgba(0,0,0,0.05)]"
      >
        <?php
        foreach (
            [
                ['home', '01_home.php', 'home', 'Home'],
                ['routes', '02_routes.php', 'directions_bus', 'Routes'],
                ['wallet', '03_wallet.php', 'account_balance_wallet', 'Wallet'],
                ['trips', '04_trips.php', 'history', 'Trips'],
                ['profile', '05_profile.php', 'person', 'Profile'],
            ] as [$navKey, $navHref, $navIcon, $navLabel]
        ):
            $navActive = $activeNav === $navKey;
            ?>
        <a
          href="<?= htmlspecialchars($navHref, ENT_QUOTES, 'UTF-8') ?>"
          class="nav-link<?= $navActive ? ' active' : '' ?>"
        >
          <span class="material-symbols-outlined text-[22px] leading-none"><?= htmlspecialchars($navIcon, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="font-label font-medium text-[10px] uppercase tracking-wider mt-1 leading-none"><?= htmlspecialchars($navLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div id="stops-modal" class="hidden fixed inset-0 z-[60] flex stops-float-backdrop bg-black/30 backdrop-blur-sm">
      <div class="stops-float-card" role="dialog" aria-labelledby="stops-modal-title">
        <div class="stops-float-head">
          <h2 id="stops-modal-title" class="text-sm font-bold text-on-surface leading-tight truncate flex-1 min-w-0">Route stops</h2>
          <button type="button" onclick="closeStopsModal()" class="h-7 w-7 shrink-0 rounded-full bg-surface-container-low flex items-center justify-center text-on-surface-variant" aria-label="Close">
            <span class="material-symbols-outlined text-[16px]">close</span>
          </button>
        </div>
        <div class="stops-float-body">
          <select id="stops-list-select" class="stops-select-list" size="8" aria-label="Route stops list"></select>
        </div>
      </div>
    </div>

    <script>
      const FARE_BY_ROUTE = <?= json_encode($fareByRoute, JSON_UNESCAPED_UNICODE) ?>;
      const FARE_FIRST_KM = <?= FARE_FIRST_KM_PHP ?>;
      const FARE_PER_KM_AFTER = <?= FARE_PER_KM_AFTER_PHP ?>;
      const FARE_INCLUDED_KM = <?= FARE_INCLUDED_KM ?>;

      let activeFareRouteId = null;
      let activeCumulative = [];

      function escapeHtml(value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      function calculateFare(distanceKm) {
        if (distanceKm <= 0) return 0;
        if (distanceKm <= FARE_INCLUDED_KM) return FARE_FIRST_KM;
        return Math.round((FARE_FIRST_KM + ((distanceKm - FARE_INCLUDED_KM) * FARE_PER_KM_AFTER)) * 100) / 100;
      }

      function getDistance(fromIndex, toIndex) {
        if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex || !activeCumulative.length) {
          return 0;
        }
        const max = activeCumulative.length - 1;
        const from = Math.max(0, Math.min(max, fromIndex));
        const to = Math.max(0, Math.min(max, toIndex));
        return Math.round(Math.abs(activeCumulative[to] - activeCumulative[from]) * 10000) / 10000;
      }

      function populateFareStops(routeId) {
        const fareData = routeId ? FARE_BY_ROUTE[routeId] : null;
        const fromSelect = document.getElementById('from-stop');
        const toSelect = document.getElementById('to-stop');
        const fareSection = document.getElementById('fare-section');
        const fareHint = document.getElementById('fare-route-hint');
        const fareResult = document.getElementById('fare-result');

        fromSelect.innerHTML = '<option value="-1">Boarding stop</option>';
        toSelect.innerHTML = '<option value="-1">Destination stop</option>';
        fareResult.classList.add('hidden');

        if (!fareData || !fareData.stopNames || !fareData.stopNames.length) {
          activeFareRouteId = null;
          activeCumulative = [];
          fromSelect.disabled = true;
          toSelect.disabled = true;
          fareSection.classList.add('opacity-60', 'pointer-events-none');
          fareHint.classList.remove('hidden');
          fareHint.textContent = 'Select a route first.';
          return;
        }

        activeFareRouteId = routeId;
        activeCumulative = fareData.cumulative.slice();

        fareData.stopNames.forEach(function (name, index) {
          const label = escapeHtml(name);
          fromSelect.insertAdjacentHTML('beforeend', '<option value="' + index + '">' + label + '</option>');
          toSelect.insertAdjacentHTML('beforeend', '<option value="' + index + '">' + label + '</option>');
        });

        fromSelect.disabled = false;
        toSelect.disabled = false;
        fareSection.classList.remove('opacity-60', 'pointer-events-none');
        fareHint.classList.add('hidden');
      }

      function updateFare() {
        const fromIndex = parseInt(document.getElementById('from-stop').value, 10);
        const toIndex = parseInt(document.getElementById('to-stop').value, 10);
        const resultDiv = document.getElementById('fare-result');

        if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
          resultDiv.classList.add('hidden');
          return;
        }

        const distance = getDistance(fromIndex, toIndex);
        const fare = distance > 0 ? calculateFare(distance) : 0;

        document.getElementById('result-distance').textContent = distance.toFixed(2) + ' km';
        document.getElementById('result-fare').textContent = '₱' + fare.toFixed(2);
        resultDiv.classList.remove('hidden');
      }

      document.getElementById('from-stop').addEventListener('change', updateFare);
      document.getElementById('to-stop').addEventListener('change', updateFare);

      window.populateFareStops = populateFareStops;

      function openStopsModal() {
        document.getElementById('stops-modal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
      }

      function closeStopsModal() {
        document.getElementById('stops-modal').classList.add('hidden');
        document.body.style.overflow = '';
      }

      document.getElementById('stops-modal').addEventListener('click', function (e) {
        if (e.target === this) {
          closeStopsModal();
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          closeStopsModal();
        }
      });
    </script>

    <script>
    (function () {
      'use strict';

      var MAP_CFG_URL = '../../api/map.php';
      var GPS_URL = '../../config/gps.php';
      var ROUTES = <?= json_encode($routesCatalog, JSON_UNESCAPED_UNICODE) ?>;
      var OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';
      var OV_ZOOM = 12;
      var BUS_POLL_MS = 350;
      var BUS_ANIM_MS = 320;
      var USER_LAT = <?= json_encode($userLat) ?>;
      var USER_LNG = <?= json_encode($userLng) ?>;
      var HAS_ACTIVE_TRIP = <?= $hasActiveTrip ? 'true' : 'false' ?>;
      var ACTIVE_TRIP_ID = <?= (int)($activeTripData['trip_id'] ?? 0) ?>;
      var LAST_PAX_UPDATE = 0;

      var routeSelect = document.getElementById('route-select');
      var mapEmpty = document.getElementById('map-empty');
      var defaultMapEmptyHtml = mapEmpty ? mapEmpty.innerHTML : '';
      var mapPanel = document.getElementById('map-panel');
      var mapEl = document.getElementById('route-map');
      var stopsModalTitle = document.getElementById('stops-modal-title');
      var stopsListSelect = document.getElementById('stops-list-select');
      var btnShowStops = document.getElementById('btn-show-stops');
      var btnBusNear = document.getElementById('btn-bus-near');

      var map = null;
      var mapsReady = false;
      var mapInitialized = false;
      var routeLine = null;
      var stopMkrs = [];
      var busMkr = null;
      var humanMkr = null;
      var busPollTimer = null;
      var currentRouteId = null;
      var currentStops = [];
      var legRoutesCache = {};
      var legRoutesPending = {};
      var googleMapsPromise = null;
      var hasCenteredOnBus = false;
      var busAnimFrame = null;
      var lastBusPoint = null;
      var busPollInFlight = false;

      function latLng(lat, lng) {
        return new google.maps.LatLng(lat, lng);
      }

      function escapeHtml(value) {
        return String(value)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      }

      function legCacheKey(from, to) {
        return from + '-' + to;
      }

      function straightPath(from, to) {
        return [
          { lat: from.lat, lng: from.lng },
          { lat: to.lat, lng: to.lng },
        ];
      }

      function pathToLatLngs(pts) {
        return pts.map(function (p) { return latLng(p.lat, p.lng); });
      }

      function appendPathPoints(base, extra) {
        if (!extra || !extra.length) return base.slice();
        var out = base.slice();
        var start = out.length ? 1 : 0;
        for (var i = start; i < extra.length; i++) out.push(extra[i]);
        return out;
      }

      function fetchOsrmRoute(fromIdx, toIdx) {
        var key = legCacheKey(fromIdx, toIdx);
        if (legRoutesCache[key]) {
          return Promise.resolve(legRoutesCache[key]);
        }
        if (legRoutesPending[key]) {
          return legRoutesPending[key];
        }

        var a = currentStops[fromIdx];
        var b = currentStops[toIdx];
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
            return path;
          })
          .catch(function () {
            var path = straightPath(a, b);
            legRoutesCache[key] = path;
            return path;
          })
          .finally(function () {
            delete legRoutesPending[key];
          });

        return legRoutesPending[key];
      }

      function prefetchAllLegRoutes() {
        var i = 0;
        function next() {
          if (i >= currentStops.length - 1) {
            drawRouteLine();
            return;
          }
          fetchOsrmRoute(i, i + 1).finally(function () {
            i += 1;
            setTimeout(next, 120);
          });
        }
        next();
      }

      function drawRouteLine() {
        if (!mapsReady || currentStops.length < 2) return;

        if (routeLine) {
          routeLine.setMap(null);
          routeLine = null;
        }

        var pts = [];
        for (var j = 0; j < currentStops.length - 1; j++) {
          var key = legCacheKey(j, j + 1);
          if (legRoutesCache[key]) {
            pts = appendPathPoints(pts, legRoutesCache[key]);
          }
        }

        if (pts.length < 2) {
          pts = currentStops.map(function (s) { return { lat: s.lat, lng: s.lng }; });
        }

        routeLine = new google.maps.Polyline({
          path: pathToLatLngs(pts),
          geodesic: false,
          strokeColor: '#0040a1',
          strokeOpacity: 0.92,
          strokeWeight: 5,
          map: map,
          zIndex: 10,
        });
      }

      function stopDotIcon(color, size) {
        return {
          path: google.maps.SymbolPath.CIRCLE,
          fillColor: color,
          fillOpacity: 1,
          strokeColor: '#ffffff',
          strokeWeight: 2,
          scale: size,
        };
      }

      function humanMarkerIcon() {
        var svg = [
          '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44">',
          '<circle cx="22" cy="22" r="20" fill="#e11d48" stroke="#ffffff" stroke-width="3"/>',
          '<path transform="translate(10 10)" fill="#ffffff" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>',
          '</svg>',
        ].join('');
        return {
          url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
          scaledSize: new google.maps.Size(44, 44),
          anchor: new google.maps.Point(22, 22),
        };
      }

      function showHumanMarker() {
        if (!mapsReady || !map || USER_LAT === null || USER_LNG === null) return;
        var pos = latLng(USER_LAT, USER_LNG);
        if (!humanMkr) {
          humanMkr = new google.maps.Marker({
            position: pos,
            map: map,
            title: HAS_ACTIVE_TRIP ? 'Your Position on Bus' : 'Your Location',
            zIndex: 1000,
            icon: humanMarkerIcon(),
          });
        } else {
          humanMkr.setPosition(pos);
          humanMkr.setMap(map);
          humanMkr.setTitle(HAS_ACTIVE_TRIP ? 'Your Position on Bus' : 'Your Location');
        }
      }

      function updatePassengerPosition() {
        if (!HAS_ACTIVE_TRIP || ACTIVE_TRIP_ID === 0) return;

        var now = Date.now();
        if (now - LAST_PAX_UPDATE < 2000) return;
        LAST_PAX_UPDATE = now;

        fetch('../../api/get_passenger_fare.php', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        })
        .then(function (r) {
          if (!r.ok) throw new Error('API error');
          return r.json();
        })
        .then(function (data) {
          if (data.ok && humanMkr) {
            // The passenger position is updated in the database by the driver dashboard
            // We can show a visual indicator that they're on the bus
            humanMkr.setTitle('On Bus - Fare: ₱' + parseFloat(data.fare_now).toFixed(2));
          }
        })
        .catch(function () {});
      }
      function busMarkerIcon() {
        var svg = [
          '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44">',
          '<circle cx="22" cy="22" r="20" fill="#0040a1" stroke="#ffffff" stroke-width="3"/>',
          '<path transform="translate(10 10)" fill="#ffffff" d="M4 16c0 .88.39 1.67 1 2.22V20c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h8v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1.78c.61-.55 1-1.34 1-2.22V6c0-3.5-3.58-4-8-4S4 2.5 4 6v10Zm3.5 1C6.67 17 6 16.33 6 15.5S6.67 14 7.5 14 9 14.67 9 15.5 8.33 17 7.5 17Zm9 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5ZM18 11H6V6h12v5Z"/>',
          '</svg>',
        ].join('');
        return {
          url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
          scaledSize: new google.maps.Size(44, 44),
          anchor: new google.maps.Point(22, 22),
        };
      }

      function mkBusMarker(position) {
        return new google.maps.Marker({
          position: position,
          map: map,
          title: 'Active bus',
          zIndex: 2000,
          icon: busMarkerIcon(),
        });
      }

      function setBusPosition(lat, lng, panToBus) {
        var pos = latLng(lat, lng);
        if (!busMkr) {
          busMkr = mkBusMarker(pos);
        } else {
          busMkr.setPosition(pos);
          busMkr.setIcon(busMarkerIcon());
        }
        lastBusPoint = { lat: lat, lng: lng };
        if (map && panToBus !== false) {
          map.panTo(pos);
          if (!hasCenteredOnBus && map.getZoom() < 14) {
            map.setZoom(14);
          }
          hasCenteredOnBus = true;
        }
      }

      function stopBusAnimation() {
        if (busAnimFrame) {
          cancelAnimationFrame(busAnimFrame);
          busAnimFrame = null;
        }
      }

      function animateBusPosition(lat, lng) {
        if (!mapsReady || !map) return;

        var target = { lat: lat, lng: lng };
        if (!lastBusPoint || !busMkr) {
          stopBusAnimation();
          setBusPosition(target.lat, target.lng, true);
          return;
        }

        var start = { lat: lastBusPoint.lat, lng: lastBusPoint.lng };
        var distance = Math.abs(start.lat - target.lat) + Math.abs(start.lng - target.lng);
        if (distance < 0.0000001) return;

        var startedAt = performance.now();
        stopBusAnimation();

        function step(now) {
          var t = Math.min(1, (now - startedAt) / BUS_ANIM_MS);
          var eased = 1 - Math.pow(1 - t, 3);
          var nextLat = start.lat + ((target.lat - start.lat) * eased);
          var nextLng = start.lng + ((target.lng - start.lng) * eased);

          setBusPosition(nextLat, nextLng, true);

          if (t < 1) {
            busAnimFrame = requestAnimationFrame(step);
          } else {
            busAnimFrame = null;
            setBusPosition(target.lat, target.lng, true);
          }
        }

        busAnimFrame = requestAnimationFrame(step);
      }

      function clearStopMarkers() {
        stopMkrs.forEach(function (m) { m.setMap(null); });
        stopMkrs = [];
      }

      function refreshStopMarkers() {
        clearStopMarkers();
        if (!mapsReady || !currentStops.length) return;

        currentStops.forEach(function (stop, i) {
          var isFirst = i === 0;
          var isLast = i === currentStops.length - 1;
          var color = isFirst ? '#16a34a' : isLast ? '#ef4444' : '#0040a1';
          var size = isFirst || isLast ? 8 : 6;

          stopMkrs.push(new google.maps.Marker({
            position: latLng(stop.lat, stop.lng),
            map: map,
            title: stop.name,
            icon: stopDotIcon(color, size),
            zIndex: isFirst || isLast ? 500 : 200,
          }));
        });
      }

      function fitOverview() {
        if (!mapsReady) return;
        var bounds = new google.maps.LatLngBounds();
        var hasPoints = false;
        if (currentStops && currentStops.length) {
          currentStops.forEach(function (s) { bounds.extend(latLng(s.lat, s.lng)); });
          hasPoints = true;
        }
        if (busMkr) {
          bounds.extend(busMkr.getPosition());
          hasPoints = true;
        }
        if (humanMkr) {
          bounds.extend(humanMkr.getPosition());
          hasPoints = true;
        }
        if (hasPoints) {
          map.fitBounds(bounds, 48);
          map.setHeading(0);
          map.setTilt(0);
        }
      }

      function renderStopsModal() {
        if (!currentStops.length || !stopsListSelect) return;

        var route = ROUTES[currentRouteId] || {};
        var routeLabel = route.displayName || 'Balagtas ↔ Monumento';
        stopsModalTitle.textContent = currentStops.length + ' stops · ' + routeLabel;

        stopsListSelect.innerHTML = currentStops.map(function (stop, index) {
          return '<option value="' + index + '">' + (index + 1) + '. ' + escapeHtml(stop.name) + '</option>';
        }).join('');

        stopsListSelect.size = Math.min(8, Math.max(4, currentStops.length));
      }

      function clearMapLayers() {
        if (routeLine) {
          routeLine.setMap(null);
          routeLine = null;
        }
        if (busMkr) {
          busMkr.setMap(null);
          busMkr = null;
        }
        stopBusAnimation();
        clearStopMarkers();
        legRoutesCache = {};
        legRoutesPending = {};
        hasCenteredOnBus = false;
        lastBusPoint = null;
      }

      function stopBusPolling() {
        if (busPollTimer) {
          clearInterval(busPollTimer);
          busPollTimer = null;
        }
      }

      function updateBusFromGps(data) {
        if (!data || !data.busPosition || !mapsReady) return;
        if (String(data.routeId) !== String(currentRouteId)) return;

        var lat = data.busPosition.lat;
        var lng = data.busPosition.lng;
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        animateBusPosition(lat, lng);
      }

      function fetchBusPosition() {
        if (!currentRouteId) return Promise.resolve();
        if (busPollInFlight) return Promise.resolve();
        busPollInFlight = true;

        var params = new URLSearchParams();
        params.set('route_id', currentRouteId);
        params.set('_', Date.now());

        return fetch(GPS_URL + '?' + params.toString(), {
          cache: 'no-store',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        })
          .then(function (r) {
            if (!r.ok) throw new Error('GPS unavailable');
            return r.json();
          })
          .then(function (data) {
            updateBusFromGps(data);
            updatePassengerPosition();
          })
          .catch(function () {})
          .finally(function () {
            busPollInFlight = false;
          });
      }

      function startBusPolling() {
        stopBusPolling();
        fetchBusPosition();
        busPollTimer = setInterval(fetchBusPosition, BUS_POLL_MS);
      }

      function showMapPanel(show) {
        if (show) {
          mapEmpty.style.display = 'none';
          mapPanel.classList.add('is-visible');
        } else {
          mapEmpty.style.display = 'flex';
          mapPanel.classList.remove('is-visible');
        }
      }

      function loadGoogleMaps() {
        if (googleMapsPromise) return googleMapsPromise;

        googleMapsPromise = fetch(MAP_CFG_URL, {
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
            var cb = '__trackfarePassengerMapsInit';
            window[cb] = function () {
              delete window[cb];
              resolve();
            };
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key='
              + encodeURIComponent(cfg.apiKey) + '&callback=' + cb;
            s.async = true;
            s.defer = true;
            s.onerror = function () { reject(new Error('Failed to load Google Maps')); };
            document.head.appendChild(s);
          });
        });

        return googleMapsPromise;
      }

      function initGoogleMap() {
        var center = currentStops.length
          ? { lat: currentStops[0].lat, lng: currentStops[0].lng }
          : (USER_LAT !== null && USER_LNG !== null
             ? { lat: USER_LAT, lng: USER_LNG }
             : { lat: 14.75, lng: 120.95 });

        if (mapInitialized) {
          map.setCenter(center);
          map.setZoom(OV_ZOOM);
          return;
        }

        map = new google.maps.Map(mapEl, {
          center: center,
          zoom: OV_ZOOM,
          mapTypeId: 'roadmap',
          streetViewControl: false,
          mapTypeControl: false,
          fullscreenControl: true,
          gestureHandling: 'greedy',
        });

        mapsReady = true;
        mapInitialized = true;
      }

      function drawSelectedRoute(routeId) {
        var route = ROUTES[routeId];
        if (!route || !route.stops || !route.stops.length) {
          return Promise.resolve();
        }

        currentRouteId = routeId;
        currentStops = route.stops.slice();
        renderStopsModal();
        showMapPanel(true);

        if (window.populateFareStops) {
          window.populateFareStops(routeId);
        }

        return loadGoogleMaps().then(function () {
          initGoogleMap();
          clearMapLayers();
          showHumanMarker();
          refreshStopMarkers();
          fitOverview();
          prefetchAllLegRoutes();
          startBusPolling();

          google.maps.event.trigger(map, 'resize');
          fitOverview();
        });
      }

      function resetRouteView() {
        currentRouteId = null;
        currentStops = [];
        stopBusPolling();
        if (mapsReady) {
          clearMapLayers();
        }
        if (mapEmpty && defaultMapEmptyHtml) {
          mapEmpty.innerHTML = defaultMapEmptyHtml;
        }
        showMapPanel(false);

        if (window.populateFareStops) {
          window.populateFareStops(null);
        }
      }

      function onRouteChange() {
        var routeId = parseInt(routeSelect.value, 10);
        if (!routeId || !ROUTES[routeId]) {
          resetRouteView();
          return;
        }
        drawSelectedRoute(routeId).catch(function () {
          mapEmpty.innerHTML = '<p class="text-sm text-on-surface-variant px-4">Could not load the map. Check your connection and try again.</p>';
          showMapPanel(false);
        });
      }

      routeSelect.addEventListener('change', onRouteChange);
      if (btnShowStops) {
        btnShowStops.addEventListener('click', openStopsModal);
      }

      if (btnBusNear) {
        btnBusNear.addEventListener('click', function () {
          if (currentRouteId) {
            if (busMkr) {
              map.panTo(busMkr.getPosition());
              map.setZoom(15);
            } else {
              fetchBusPosition().then(function() {
                if (busMkr) {
                  map.panTo(busMkr.getPosition());
                  map.setZoom(15);
                } else {
                  alert('No active bus found on this route.');
                }
              });
            }
          } else {
            btnBusNear.disabled = true;
            fetch(GPS_URL + '?_' + Date.now(), {
              cache: 'no-store',
              credentials: 'same-origin',
              headers: { Accept: 'application/json' },
            })
            .then(function (r) {
              if (!r.ok) throw new Error('GPS unavailable');
              return r.json();
            })
            .then(function (data) {
              btnBusNear.disabled = false;
              if (data && data.routeId) {
                routeSelect.value = data.routeId;
                drawSelectedRoute(data.routeId).then(function() {
                  fetchBusPosition().then(function() {
                    if (busMkr) {
                      map.panTo(busMkr.getPosition());
                      map.setZoom(15);
                    }
                  });
                });
              } else {
                alert('No active buses found.');
              }
            })
            .catch(function () {
              btnBusNear.disabled = false;
              alert('Could not locate nearby buses. Please try again.');
            });
          }
        });
      }

      routeSelect.value = '';
      if (USER_LAT !== null && USER_LNG !== null) {
        showMapPanel(true);
        loadGoogleMaps().then(function () {
          initGoogleMap();
          showHumanMarker();
          map.setZoom(14);
        }).catch(function () {
          resetRouteView();
        });
      } else {
        resetRouteView();
      }
    })();
    </script>
  </body>
</html>
