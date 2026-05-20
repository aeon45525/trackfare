<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'passenger') {
    header('Location: ../../auth/login.php');
    exit;
}

$routeId = 1;
$routeName = 'Balagtas → Monumento';
$routeStops = [];

if ($stmt = $conn->prepare(
    'SELECT s.stop_id, s.stop_name, s.municipality
     FROM route_stops rs
     JOIN stops s ON rs.stop_id = s.stop_id
     WHERE rs.route_id = ?
     ORDER BY rs.stop_order'
)) {
    $stmt->bind_param('i', $routeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $routeStops[] = $row;
    }
    $stmt->close();
}

// Estimated consecutive distances between stops in kilometers
$segmentDistances = [
    1.2, 0.9, 0.9, 0.8, 0.9, 1.1, 1.1, 0.9, 0.8, 1.0,
    1.0, 1.1, 1.0, 1.2, 1.4, 1.3, 1.2, 1.0, 0.9
];

if (count($routeStops) === 0) {
    $routeStops = [
        ['stop_name' => 'ULTRA MEGA', 'municipality' => 'Balagtas, Bulacan'],
        ['stop_name' => 'BALAGTAS ARENA', 'municipality' => 'Balagtas, Bulacan'],
        ['stop_name' => 'GOLDEN CITY', 'municipality' => 'Bocaue, Bulacan'],
        ['stop_name' => 'DR. YANGA’S COLLEGE', 'municipality' => 'Bocaue, Bulacan'],
        ['stop_name' => 'BOCAUE MARKET', 'municipality' => 'Bocaue, Bulacan'],
        ['stop_name' => 'BUNLO JIL', 'municipality' => 'Bocaue, Bulacan'],
        ['stop_name' => 'JONERS LOLOMBOY', 'municipality' => 'Bocaue, Bulacan'],
        ['stop_name' => 'TOWN IN COUNTRY', 'municipality' => 'Bocaue, Bulacan'],
        ['stop_name' => 'MARILAO TULAY', 'municipality' => 'Marilao, Bulacan'],
        ['stop_name' => 'LIAS', 'municipality' => 'Marilao, Bulacan'],
        ['stop_name' => 'SM MARILAO', 'municipality' => 'Marilao, Bulacan'],
        ['stop_name' => 'MEDALLION HOMES', 'municipality' => 'Meycauayan, Bulacan'],
        ['stop_name' => 'MALHACAN', 'municipality' => 'Meycauayan, Bulacan'],
        ['stop_name' => 'BANGCAL', 'municipality' => 'Meycauayan, Bulacan'],
        ['stop_name' => 'MALANDAY', 'municipality' => 'Valenzuela City'],
        ['stop_name' => 'DALANDANAN', 'municipality' => 'Valenzuela City'],
        ['stop_name' => 'BALUBARAN', 'municipality' => 'Valenzuela City'],
        ['stop_name' => 'MALINTA', 'municipality' => 'Valenzuela City'],
        ['stop_name' => 'KARUHATAN', 'municipality' => 'Valenzuela City'],
        ['stop_name' => 'VICTONICA MONUMENTO', 'municipality' => 'Caloocan City'],
    ];
}

$stopNames = array_column($routeStops, 'stop_name');
$stopCount = count($stopNames);

$cumulativeDistances = [0.0];
for ($i = 0; $i < count($segmentDistances); $i++) {
    $cumulativeDistances[] = round($cumulativeDistances[$i] + $segmentDistances[$i], 2);
}

function calculateFare(float $distance): float
{
    if ($distance <= 5.0) {
        return 13.00;
    }

    $extraKm = (int) ceil($distance - 5.0);
    return 13.00 + ($extraKm * 2.25);
}

function getDistanceBetweenStops(int $from, int $to, array $cumulativeDistances): float
{
    return round(abs($cumulativeDistances[$to] - $cumulativeDistances[$from]), 2);
}

$fareMatrix = [];
for ($from = 0; $from < $stopCount; $from++) {
    for ($to = 0; $to < $stopCount; $to++) {
        $distance = getDistanceBetweenStops($from, $to, $cumulativeDistances);
        $fareMatrix[$from][$to] = [
            'distance' => $distance,
            'fare' => $distance > 0 ? calculateFare($distance) : 0.00,
        ];
    }
}

$totalRouteDistance = end($cumulativeDistances);
$totalStops = $stopCount;
?>

<!doctype html>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare - Routes</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
      rel="stylesheet"
    />
    <link
      rel="stylesheet"
      href="https://unpkg.com/leaflet@1.10.0/dist/leaflet.css"
      integrity="sha256-b48gk2+23GPlCGSO44BXlrwYMy9UFj+sZh1QmP4lw5w="
      crossorigin=""
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
      body {
        margin: 0;
        min-height: max(884px, 100dvh);
        display: flex;
        justify-content: center;
        background: #f8f9fa;
        -webkit-tap-highlight-color: transparent;
      }
      #app-shell {
        width: min(100%, 420px);
        min-height: 100dvh;
        position: relative;
      }
      .map-card {
        display: grid;
        gap: 1rem;
        background: #ffffff;
        border: 1px solid #e1e3e4;
        border-radius: 1.75rem;
        padding: 1.25rem;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      }
      #map {
        width: 100%;
        height: 300px;
        border-radius: 1.25rem;
        overflow: hidden;
        position: relative;
        background: #e8eef4;
      }
      /* Fallback iframe map fills container */
      #map-iframe {
        width: 100%;
        height: 100%;
        border: none;
        border-radius: 1.25rem;
        display: block;
      }
      /* Leaflet container */
      .leaflet-container {
        width: 100%;
        height: 100%;
        border-radius: inherit;
      }
      /* Bus pulse marker */
      @keyframes pulse-ring {
        0% {
          transform: scale(1);
          opacity: 0.7;
        }
        100% {
          transform: scale(2.4);
          opacity: 0;
        }
      }
      .bus-pulse-outer {
        position: absolute;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: rgba(0, 64, 161, 0.4);
        animation: pulse-ring 1.6s ease-out infinite;
      }
      .bus-marker-wrap {
        position: relative;
        width: 20px;
        height: 20px;
      }
    </style>
  </head>
  <body
    class="bg-background text-on-surface font-body selection:bg-primary-fixed selection:text-on-primary-fixed"
  >
    <div id="app-shell" class="w-full">
      <!-- TopAppBar -->
      <header
        class="fixed inset-x-0 top-0 z-50 w-full max-w-[420px] mx-auto bg-white/95 backdrop-blur-md shadow-sm border-b border-slate-200/80"
      >
        <div class="flex items-center justify-between px-4 h-16">
          <div>
            <p
              class="text-xs uppercase tracking-[0.25em] text-on-surface-variant"
            >
              TrackFare
            </p>
            <h1
              class="font-manrope font-bold text-xl tracking-tight text-[#0040a1]"
            >
              Routes
            </h1>
          </div>
          <img
            src="../../images/pfp.png"
            alt="Profile photo"
            class="h-11 w-11 rounded-2xl object-cover border border-slate-200"
          />
        </div>
      </header>

      <main class="pt-20 pb-28 px-4 min-h-screen space-y-6">
        <!-- Route Info -->
        <section
          class="rounded-[1.75rem] bg-surface-container-lowest border border-outline-variant p-5 shadow-sm"
        >
          <p class="text-sm font-semibold text-on-surface-variant">
            Route information
          </p>
          <h2 class="mt-3 text-2xl font-bold text-on-surface">
            <?= htmlspecialchars($routeName, ENT_QUOTES, 'UTF-8') ?>
          </h2>
          <div class="mt-4 text-sm text-on-surface-variant">
            <p><?= $totalStops ?> stops · Estimated route distance <?= number_format($totalRouteDistance, 2) ?> km</p>
            <p class="mt-2">Fare model: ₱13 minimum for 0–5 km, then ₱2.25 per additional km.</p>
          </div>
          <div
            class="mt-6 rounded-[1.75rem] bg-white p-4 border border-surface-container-high shadow-sm"
          >
            <p
              class="text-[11px] uppercase tracking-[0.2em] text-on-surface-variant"
            >
              Estimated km between stops
            </p>
            <div class="mt-4 divide-y divide-surface-container-high text-sm text-on-surface">
              <?php foreach ($routeStops as $index => $stop):
                if ($index >= 5) break;
                $nextStop = $routeStops[$index + 1]['stop_name'] ?? null;
                $segmentDistance = $segmentDistances[$index] ?? 0.0;
                $cumulative = $cumulativeDistances[$index];
              ?>
                <div class="py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p class="font-semibold"><?= htmlspecialchars($stop['stop_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-xs text-on-surface-variant"><?= htmlspecialchars($stop['municipality'], ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                  <div class="text-sm text-on-surface-variant text-right">
                    <p>KM from start: <?= number_format($cumulative, 2) ?></p>
                    <?php if ($nextStop !== null): ?>
                      <p>Next to <?= htmlspecialchars($nextStop, ENT_QUOTES, 'UTF-8') ?> · <?= number_format($segmentDistance, 2) ?> km</p>
                    <?php else: ?>
                      <p>Terminus stop</p>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($stopCount > 5): ?>
              <button
                id="toggle-stops-btn"
                class="mt-4 w-full rounded-2xl bg-primary-container text-white px-4 py-3 text-sm font-semibold hover:opacity-90 transition-opacity"
                onclick="toggleStops()"
              >
                View All Stops
              </button>
            <?php endif; ?>
          </div>
        </section>

        <!-- Map Card -->
        <section class="map-card">
          <div>
            <p class="text-sm font-semibold text-on-surface-variant">
              Live Bus Location
            </p>
            <p class="mt-2 text-sm text-on-surface-variant">
              View your assigned bus in real time.
            </p>
          </div>
          <!-- Map container: Leaflet renders here; iframe is the fallback -->
          <div id="map">
            <iframe
              id="map-iframe"
              src="https://www.openstreetmap.org/export/embed.html?bbox=120.9500%2C14.7800%2C121.0800%2C14.8900&layer=mapnik&marker=14.8400%2C121.0167"
              title="Route map"
              loading="lazy"
            ></iframe>
          </div>
        </section>

        <!-- Fare Estimator -->
        <section
          class="rounded-3xl bg-surface-container-lowest border border-outline-variant p-5 shadow-sm"
        >
          <h2 class="text-lg font-bold text-on-surface">Fare estimator</h2>
          <p class="mt-3 text-sm text-on-surface-variant">
            Select your boarding and destination stops to see the estimated fare.
          </p>

          <div class="mt-5 space-y-4 rounded-3xl bg-white border border-surface-container-high p-5">
            <div>
              <label class="block text-sm font-semibold text-on-surface mb-2">From</label>
              <select
                id="from-stop"
                class="w-full px-4 py-3 rounded-2xl border border-outline-variant bg-surface-container-lowest text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                onchange="updateFare()"
              >
                <option value="-1">Select boarding stop</option>
                <?php foreach ($stopNames as $index => $name): ?>
                  <option value="<?= $index ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block text-sm font-semibold text-on-surface mb-2">To</label>
              <select
                id="to-stop"
                class="w-full px-4 py-3 rounded-2xl border border-outline-variant bg-surface-container-lowest text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
                onchange="updateFare()"
              >
                <option value="-1">Select destination stop</option>
                <?php foreach ($stopNames as $index => $name): ?>
                  <option value="<?= $index ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div id="fare-result" class="hidden mt-5 rounded-2xl bg-primary-fixed p-4">
              <p class="text-sm text-on-surface-variant mb-3">Estimated trip details</p>
              <div class="space-y-2">
                <div class="flex justify-between items-center">
                  <span class="text-sm text-on-surface-variant">Distance</span>
                  <span id="result-distance" class="font-semibold text-on-surface">0.00 km</span>
                </div>
                <div class="flex justify-between items-center pt-3 border-t border-primary-container">
                  <span class="font-semibold text-on-surface">Estimated Fare</span>
                  <span id="result-fare" class="text-2xl font-bold text-primary">₱0.00</span>
                </div>
              </div>
            </div>
          </div>
        </section>
      </main>

      <!-- BottomNavBar -->
      <nav
        class="fixed bottom-0 left-0 w-full flex justify-around items-center px-2 pb-safe h-20 bg-white/95 backdrop-blur-md rounded-t-3xl z-50 border-t border-slate-200 shadow-[0_-4px_12px_rgba(0,0,0,0.05)]"
      >
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="01_home.php"
        >
          <span class="material-symbols-outlined">home</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Home</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-white bg-primary rounded-3xl px-4 py-2 shadow-lg"
          href="02_routes.php"
        >
          <span class="material-symbols-outlined">directions_bus</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Routes</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="03_wallet.php"
        >
          <span class="material-symbols-outlined">account_balance_wallet</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Wallet</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="04_trips.php"
        >
          <span class="material-symbols-outlined">history</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Trips</span
          >
        </a>
        <a
          class="flex flex-col items-center justify-center text-on-surface-variant/60 hover:text-primary transition-all duration-200"
          href="05_profile.php"
        >
          <span class="material-symbols-outlined">person</span>
          <span
            class="font-['Inter'] font-medium text-[10px] uppercase tracking-wider mt-1"
            >Profile</span
          >
        </a>
      </nav>
    </div>

    <!-- All Stops Modal -->
    <div id="stops-modal" class="hidden fixed inset-0 z-50 flex items-end bg-black/30 backdrop-blur-sm">
      <div class="w-full max-w-[420px] mx-auto bg-white rounded-t-3xl shadow-lg max-h-[80dvh] overflow-y-auto animate-slide-up">
        <div class="sticky top-0 bg-white border-b border-outline-variant px-4 py-4 flex items-center justify-between">
          <h2 class="text-lg font-bold text-on-surface">All <?= $stopCount ?> Stops</h2>
          <button onclick="closeStopsModal()" class="text-on-surface-variant hover:text-on-surface transition-colors">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>
        <div class="px-4 py-4 divide-y divide-surface-container-high text-sm text-on-surface space-y-0">
          <?php foreach ($routeStops as $index => $stop):
            $nextStop = $routeStops[$index + 1]['stop_name'] ?? null;
            $segmentDistance = $segmentDistances[$index] ?? 0.0;
            $cumulative = $cumulativeDistances[$index];
          ?>
            <div class="py-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-semibold"><?= htmlspecialchars($stop['stop_name'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-xs text-on-surface-variant"><?= htmlspecialchars($stop['municipality'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
              <div class="text-sm text-on-surface-variant text-right">
                <p>KM from start: <?= number_format($cumulative, 2) ?></p>
                <?php if ($nextStop !== null): ?>
                  <p>Next to <?= htmlspecialchars($nextStop, ENT_QUOTES, 'UTF-8') ?> · <?= number_format($segmentDistance, 2) ?> km</p>
                <?php else: ?>
                  <p>Terminus stop</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <style>
      @keyframes slide-up {
        from {
          transform: translateY(100%);
          opacity: 0;
        }
        to {
          transform: translateY(0);
          opacity: 1;
        }
      }
      .animate-slide-up {
        animation: slide-up 0.3s ease-out;
      }
    </style>

    <script>
      // Fare calculation data from PHP
      const stopNames = <?= json_encode($stopNames) ?>;
      const cumulativeDistances = <?= json_encode($cumulativeDistances) ?>;

      function calculateFare(distance) {
        if (distance <= 5.0) {
          return 13.00;
        }
        const extraKm = Math.ceil(distance - 5.0);
        return 13.00 + (extraKm * 2.25);
      }

      function getDistance(fromIndex, toIndex) {
        if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
          return 0.0;
        }
        return Math.round(Math.abs(cumulativeDistances[toIndex] - cumulativeDistances[fromIndex]) * 100) / 100;
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
        const fare = distance > 0 ? calculateFare(distance) : 0.0;

        document.getElementById('result-distance').textContent = distance.toFixed(2) + ' km';
        document.getElementById('result-fare').textContent = '₱' + fare.toFixed(2);
        resultDiv.classList.remove('hidden');
      }

      function toggleStops() {
        const modal = document.getElementById('stops-modal');
        modal.classList.remove('hidden');
      }

      function closeStopsModal() {
        const modal = document.getElementById('stops-modal');
        modal.classList.add('hidden');
      }

      // Close modal when clicking outside
      document.getElementById('stops-modal').addEventListener('click', function(e) {
        if (e.target === this) {
          closeStopsModal();
        }
      });
    </script>

    <!-- Leaflet JS -->
    <script>
      src = "https://unpkg.com/leaflet@1.10.0/dist/leaflet.js";
      integrity = "sha256-gZOGcR9PGyS3zT8aqfD7VG4HFSYx4M0Qv2eMCyMaC5k=";
      crossorigin = "";

      // Route stops coordinates
      const stops = [
        { name: "Bocaue", latlng: [14.7983, 120.9742] },
        { name: "Marilao", latlng: [14.757, 120.9588] }, // corrected approx
        { name: "Meycauayan", latlng: [14.735, 120.9604] },
      ];

      // Simulated bus position (between Bocaue and Marilao)
      const busPosition = [14.778, 120.966];

      function initLeaflet() {
        const iframe = document.getElementById("map-iframe");
        const mapEl = document.getElementById("map");

        // Hide the fallback iframe
        if (iframe) iframe.style.display = "none";

        const map = L.map(mapEl, {
          scrollWheelZoom: false,
          attributionControl: false,
          zoomControl: true,
        }).setView([14.77, 120.965], 13);

        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
          maxZoom: 19,
        }).addTo(map);

        // Draw route polyline
        const routeCoords = stops.map((s) => s.latlng);
        L.polyline(routeCoords, {
          color: "#0040a1",
          weight: 4,
          opacity: 0.75,
          dashArray: "8 6",
        }).addTo(map);

        // Stop markers
        stops.forEach((stop, i) => {
          const isFirst = i === 0;
          const isLast = i === stops.length - 1;
          const color = isFirst ? "#22c55e" : isLast ? "#ef4444" : "#0040a1";

          L.circleMarker(stop.latlng, {
            radius: 8,
            fillColor: color,
            color: "#ffffff",
            weight: 2,
            fillOpacity: 1,
          })
            .addTo(map)
            .bindPopup(`<b>${stop.name}</b>`);
        });

        // Animated bus marker using a DivIcon
        const busIcon = L.divIcon({
          className: "",
          html: `
            <div style="position:relative;width:28px;height:28px;">
              <div style="
                position:absolute;inset:0;border-radius:50%;
                background:rgba(0,64,161,0.3);
                animation:pulse-ring 1.6s ease-out infinite;
              "></div>
              <div style="
                position:absolute;inset:4px;border-radius:50%;
                background:#0040a1;border:2px solid #fff;
                display:flex;align-items:center;justify-content:center;
                font-size:10px;color:#fff;
              ">🚌</div>
            </div>`,
          iconSize: [28, 28],
          iconAnchor: [14, 14],
        });

        L.marker(busPosition, { icon: busIcon })
          .addTo(map)
          .bindPopup("<b>Your Bus</b><br>Currently en route")
          .openPopup();

        // Fit map to show full route
        map.fitBounds(L.latLngBounds(routeCoords).pad(0.2));
      }

      // Try Leaflet first; fall back to iframe if Leaflet failed to load
      if (typeof L !== "undefined") {
        document.addEventListener("DOMContentLoaded", initLeaflet);
      } else {
        window.addEventListener("load", function () {
          if (typeof L !== "undefined") {
            initLeaflet();
          }
          // else: iframe fallback is already visible
        });
      }
    </script>
  </body>
</html>