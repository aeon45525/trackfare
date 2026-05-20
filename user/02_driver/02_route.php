<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'driver') {
    header('Location: ../../auth/login.php');
    exit;
}

$driverId = (int) $_SESSION['user_id'];
$routeName = 'No active route';
$routeDisplay = 'No route details available';
$routeStops = [];
$totalRouteDistance = 0.0;
$averageSpeed = 0;
$estimatedArrival = 'N/A';
$tripDurationLabel = 'N/A';
$tripDistanceLabel = 'N/A';
$tripSpeedLabel = 'N/A';
$tripArrivalLabel = 'N/A';
$routeProgressStops = ['Bocaue', 'Marilao', 'Meycauayan'];
$routeProgressCurrent = 'Marilao';

if ($stmt = $conn->prepare(
    'SELECT t.route_id, r.route_name, r.display_name
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

    if ($activeTrip) {
        $routeName = $activeTrip['route_name'];
        $routeDisplay = $activeTrip['display_name'];
        $routeId = (int) $activeTrip['route_id'];

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
    }
}

$segmentDistances = [
    1.2, 0.9, 0.9, 0.8, 0.9, 1.1, 1.1, 0.9, 0.8, 1.0,
    1.0, 1.1, 1.0, 1.2, 1.4, 1.3, 1.2, 1.0, 0.9,
];

if (count($routeStops) > 1) {
    $needed = count($routeStops) - 1;
    $segmentDistances = array_slice($segmentDistances, 0, $needed);
    while (count($segmentDistances) < $needed) {
        $segmentDistances[] = 1.0;
    }

    $cumulativeDistances = [0.0];
    for ($i = 0; $i < count($segmentDistances); $i++) {
        $cumulativeDistances[] = round($cumulativeDistances[$i] + $segmentDistances[$i], 2);
    }
    $totalRouteDistance = end($cumulativeDistances);
    $averageSpeed = (int) max(20, min(60, round($totalRouteDistance * 2.2)));
    $hours = $averageSpeed > 0 ? $totalRouteDistance / $averageSpeed : 0;
    $minutes = (int) round($hours * 60);
    $tripDurationLabel = sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    $tripDistanceLabel = sprintf('%.1f km', $totalRouteDistance);
    $tripSpeedLabel = sprintf('%d km/h', $averageSpeed);

    $arrivalTimestamp = time() + (int) round($hours * 3600);
    $estimatedArrival = date('g:i A', $arrivalTimestamp);
    $tripArrivalLabel = $estimatedArrival;

    $stopCount = count($routeStops);
    $middleIndex = (int) floor(($stopCount - 1) / 2);
    $routeProgressCurrent = $routeStops[$middleIndex];
    $indices = [0];
    if ($stopCount > 4) {
        $indices[] = (int) floor(($stopCount - 1) / 4);
        $indices[] = $middleIndex;
        $indices[] = (int) floor(3 * ($stopCount - 1) / 4);
    }
    $indices[] = $stopCount - 1;
    $indices = array_unique($indices);
    sort($indices);
    $routeProgressStops = [];
    foreach ($indices as $index) {
        $routeProgressStops[] = $routeStops[$index];
    }
}
?>

<!doctype html>

<html class="light" lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>TrackFare Driver - Live Tracking</title>
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
    <!-- Leaflet CSS -->
    <link
      rel="stylesheet"
      href="https://unpkg.com/leaflet@1.10.0/dist/leaflet.css"
      integrity="sha256-b48gk2+23GPlCGSO44BXlrwYMy9UFj+sZh1QmP4lw5w="
      crossorigin=""
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
      /* Route map */
      #route-map {
        width: 100%;
        height: 440px;
        min-height: 320px;
        border-radius: 1rem;
        overflow: hidden;
        position: relative;
      }
      #route-map iframe {
        width: 100%;
        height: 100%;
        border: none;
        border-radius: inherit;
        display: block;
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
      .progress-segment {
        display: flex;
        flex-direction: column;
        align-items: center;
        flex: 1 1 120px;
        min-width: 0;
        max-width: 140px;
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
        margin-top: 8px;
        font-size: 0.85rem;
        color: #374151;
        text-align: center;
        white-space: normal;
        overflow-wrap: anywhere;
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
                <p
                  class="text-xs uppercase tracking-[0.25em] text-slate-500 font-semibold"
                >
                  Live Map
                </p>
                <div class="mt-4">
                  <div id="route-map">
                    <iframe
                      id="route-map-iframe"
                      src="https://www.openstreetmap.org/export/embed.html?bbox=120.9500%2C14.7800%2C121.0800%2C14.8900&layer=mapnik&marker=14.8400%2C121.0167"
                      title="Route map"
                      loading="lazy"
                    ></iframe>
                  </div>
                </div>
              </article>
            </section>
            <section class="col-span-12 lg:col-span-5 xl:col-span-6 space-y-6">
              <article
                class="rounded-[1.5rem] bg-white p-8 shadow-sm border border-slate-200"
              >
                <div class="flex items-center justify-between">
                  <div>
                    <p
                      class="text-xs uppercase tracking-[0.3em] text-slate-500 font-semibold"
                    >
                      Route Progress
                    </p>
                    <h2 class="mt-3 text-2xl font-black text-slate-900">
                      <?php echo htmlspecialchars($routeDisplay); ?>
                    </h2>
                  </div>
                </div>
                <div class="mt-6">
                  <div
                    class="progress-bar"
                    role="list"
                    aria-label="Route progress"
                  >
                    <?php foreach ($routeProgressStops as $index => $stop): ?>
                      <?php
                        $state = $index === 0 ? 'completed' : ($index === 1 ? 'current' : 'upcoming');
                        $dot = $index === 0 ? '✔' : ($index === 1 ? '●' : '○');
                        $connectorClass = $index < 1 ? 'completed' : 'upcoming';
                      ?>
                      <div class="progress-segment <?php echo $state; ?>" role="listitem">
                        <div class="dot"><?php echo $dot; ?></div>
                        <div class="progress-label"><?php echo htmlspecialchars($stop); ?></div>
                      </div>
                      <?php if ($index < count($routeProgressStops) - 1): ?>
                        <div class="progress-connector <?php echo $connectorClass; ?>" aria-hidden></div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              </article>
            </section>
            <section class="col-span-12 lg:col-span-5 xl:col-span-4 space-y-6">
              <article
                class="rounded-[1.5rem] bg-white p-6 shadow-sm border border-slate-200"
              >
                <p
                  class="text-xs uppercase tracking-[0.2em] text-slate-500 font-semibold"
                >
                  Trip Metrics
                </p>
                <div class="mt-3 text-sm text-slate-700 space-y-2">
                  <div class="flex justify-between">
                    <span>Trip Duration</span><strong><?php echo htmlspecialchars($tripDurationLabel); ?></strong>
                  </div>
                  <div class="flex justify-between">
                    <span>Distance Traveled</span><strong><?php echo htmlspecialchars($tripDistanceLabel); ?></strong>
                  </div>
                  <div class="flex justify-between">
                    <span>Average Speed</span><strong><?php echo htmlspecialchars($tripSpeedLabel); ?></strong>
                  </div>
                  <div class="flex justify-between">
                    <span>Est. Arrival</span><strong><?php echo htmlspecialchars($tripArrivalLabel); ?></strong>
                  </div>
                </div>
              </article>
            </section>
          </div>
        </div>
      </main>
    </div>
    <!-- Leaflet JS and initialization -->
    <script
      src="https://unpkg.com/leaflet@1.10.0/dist/leaflet.js"
      integrity="sha256-gZOGcR9PGyS3zT8aqfD7VG4HFSYx4M0Qv2eMCyMaC5k="
      crossorigin=""
    ></script>
    <script>
      document.addEventListener("DOMContentLoaded", function () {
        const stops = [
          { name: "Bocaue", latlng: [14.7983, 120.9742] },
          { name: "Marilao", latlng: [14.757, 120.9588] },
          { name: "Meycauayan", latlng: [14.735, 120.9604] },
        ];

        try {
          const iframe = document.getElementById("route-map-iframe");
          if (iframe) iframe.style.display = "none";

          const mapEl = document.getElementById("route-map");
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
              radius: 8,
              fillColor: i === 1 ? "#0040a1" : "#22c55e",
              color: "#fff",
              weight: 2,
              fillOpacity: 1,
            })
              .addTo(map)
              .bindPopup("<b>" + s.name + "</b>");
          });

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
          console.warn("Route map init failed", e);
          const iframe = document.getElementById("route-map-iframe");
          if (iframe) iframe.style.display = "block";
        }
      });
    </script>
  </body>
</html>
