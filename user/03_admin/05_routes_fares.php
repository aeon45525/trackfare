<?php
require_once '../../config/db.php';
require_once '../../config/fare.php';

function formatCurrency(float $value): string
{
    return '₱' . number_format($value, 2);
}

function routeDirectionLabel(string $routeName): string
{
    if (strpos($routeName, '-') !== false) {
        return str_replace('-', ' → ', $routeName);
    }
    return $routeName;
}

function fetchRoutesWithStops(mysqli $conn): array
{
    $routes = [];
    $sql = "SELECT r.route_id, r.route_name, r.display_name,
                   rs.stop_order, s.stop_name, s.lat, s.lng
            FROM routes r
            LEFT JOIN route_stops rs ON rs.route_id = r.route_id
            LEFT JOIN stops s ON rs.stop_id = s.stop_id
            ORDER BY r.route_id ASC, rs.stop_order ASC";

    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $routeId = (int)$row['route_id'];
            if (!isset($routes[$routeId])) {
                $routes[$routeId] = [
                    'route_id' => $routeId,
                    'route_name' => $row['route_name'] ?? '',
                    'display_name' => $row['display_name'] ?? '',
                    'stops' => [],
                ];
            }
            if ($row['stop_name'] !== null) {
                $routes[$routeId]['stops'][] = [
                    'stop_name' => $row['stop_name'],
                    'lat' => (float)$row['lat'],
                    'lng' => (float)$row['lng'],
                ];
            }
        }
        $result->free();
    }
    return array_values($routes);
}

function getStopIdsByNames(mysqli $conn, array $stopNames): array
{
    $cleanNames = array_unique(array_map(static fn($name) => mb_strtolower(trim($name)), $stopNames));
    $escaped = array_map(static fn($name) => "'" . $conn->real_escape_string($name) . "'", $cleanNames);
    $placeholders = implode(',', $escaped);
    $sql = "SELECT stop_id, stop_name FROM stops WHERE LOWER(stop_name) IN ($placeholders)";

    $result = $conn->query($sql);
    $found = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $found[mb_strtolower($row['stop_name'])] = (int)$row['stop_id'];
        }
        $result->free();
    }
    return $found;
}

function insertRoute(mysqli $conn, string $routeName, string $displayName, array $stopIds): bool
{
    if (!$conn->begin_transaction()) {
        return false;
    }

    try {
        $insertRoute = $conn->prepare('INSERT INTO routes (route_name, display_name) VALUES (?, ?)');
        $insertRoute->bind_param('ss', $routeName, $displayName);
        $insertRoute->execute();
        $routeId = $conn->insert_id;
        $insertRoute->close();

        $insertStop = $conn->prepare('INSERT INTO route_stops (route_id, stop_id, stop_order) VALUES (?, ?, ?)');
        foreach ($stopIds as $order => $stopId) {
            $idx = $order + 1;
            $insertStop->bind_param('iii', $routeId, $stopId, $idx);
            $insertStop->execute();
        }
        $insertStop->close();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function createFareRows(int $start, int $end): array
{
    $rows = [];
    for ($km = $start; $km <= $end; $km++) {
        $rows[] = [
            'distance' => sprintf('%d km', $km),
            'fare' => calculateFare((float)$km),
        ];
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_route') {
    header('Content-Type: application/json');
    $routeName = trim($_POST['route_name'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $stopsText = trim($_POST['stops'] ?? '');
    $stopNames = array_filter(array_map('trim', explode(',', $stopsText)), static fn($value) => $value !== '');

    if ($routeName === '' || $displayName === '' || count($stopNames) < 2) {
        echo json_encode(['success' => false, 'message' => 'Route name, display name, and at least two stop names are required.']);
        exit;
    }

    $stopIds = getStopIdsByNames($conn, $stopNames);
    $missing = [];
    foreach ($stopNames as $name) {
        $lookup = mb_strtolower($name);
        if (!isset($stopIds[$lookup])) {
            $missing[] = $name;
        }
    }

    if ($missing !== []) {
        echo json_encode(['success' => false, 'message' => 'Unknown stop names: ' . implode(', ', $missing) . '. Use existing stop names.']);
        exit;
    }

    $orderedStopIds = array_map(static fn($name) => $stopIds[mb_strtolower($name)], $stopNames);
    if (!insertRoute($conn, $routeName, $displayName, $orderedStopIds)) {
        echo json_encode(['success' => false, 'message' => 'Unable to save the new route.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Route created successfully.']);
    exit;
}

$routes = fetchRoutesWithStops($conn);
$fareRowsFirst = array_merge([
    ['distance' => '1–5 km', 'fare' => calculateFare(5.0)],
], createFareRows(6, 15));
$fareRowsSecond = createFareRows(16, 26);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Routes & Fares - TrackFare Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
      href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap"
      rel="stylesheet"
    />
    <link
      href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap"
      rel="stylesheet"
    />
    <link rel="icon" href="../../images/logo.png" type="image/png">
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
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        z-index: 50;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
      }
      .modal-backdrop.open {
        display: flex;
      }
      .modal-panel {
        width: 100%;
        max-width: 34rem;
        background: #ffffff;
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.2);
      }
    </style>
  </head>
  <body class="bg-background text-on-background font-body antialiased">
    <div class="flex min-h-screen">
      <aside
        class="fixed left-0 top-0 h-full w-72 bg-slate-50 border-r border-slate-200 z-50 flex flex-col"
      >
        <div class="px-6 py-8 border-b border-slate-200">
          <span
            class="text-2xl font-black tracking-tight text-blue-900 font-headline"
            >TrackFare</span
          >
          <p class="mt-2 text-sm text-slate-500">Fleet Manager Portal</p>
        </div>
        <nav class="flex-1 px-3 py-6 space-y-1">
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="01_dashboard.php"
          >
            <span class="material-symbols-outlined">dashboard</span>
            <span>Dashboard</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="02_passengers.php"
          >
            <span class="material-symbols-outlined">group</span>
            <span>Passengers</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="03_drivers.php"
          >
            <span class="material-symbols-outlined">badge</span>
            <span>Drivers</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="04_fleet.php"
          >
            <span class="material-symbols-outlined">local_shipping</span>
            <span>Fleet</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full bg-blue-50 text-blue-700 font-semibold border-r-4 border-blue-700 transition"
            href="05_routes_fares.php"
          >
            <span class="material-symbols-outlined">alt_route</span>
            <span>Routes &amp; Fares</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="06_transactions.php"
          >
            <span class="material-symbols-outlined">payments</span>
            <span>Transactions</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="07_analytics.php"
          >
            <span class="material-symbols-outlined">insights</span>
            <span>Analytics</span>
          </a>
          <a
            class="flex items-center gap-3 px-5 py-3 rounded-r-full text-slate-600 hover:bg-slate-100 hover:text-blue-700 transition"
            href="08_profile.php"
          >
            <span class="material-symbols-outlined">person</span>
            <span>Profile</span>
          </a>
        </nav>
        <div class="px-6 py-6 border-t border-slate-200">
          <div class="flex items-center gap-3">
            <div
              class="w-12 h-12 rounded-2xl overflow-hidden border border-slate-200"
            >
              <img
                src="../../images/pfp.png"
                alt="Fleet Manager"
                class="w-full h-full object-cover"
              />
            </div>
            <div>
              <p class="text-sm font-semibold text-slate-900">Fleet Manager</p>
              <p class="text-xs text-slate-500">Admin</p>
            </div>
          </div>
          <button
            type="button"
            onclick="alert('Logout action not implemented yet.');"
            class="mt-5 w-full inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
          >
            <span class="material-symbols-outlined">logout</span>
            Logout
          </button>
        </div>
      </aside>
      <main class="flex-1 ml-72 p-8 min-h-screen">
        <header class="flex flex-col gap-6 mb-8">
          <div
            class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
          >
            <div>
              <h1
                class="text-4xl font-extrabold tracking-tight text-on-surface font-headline"
              >
                Routes &amp; Fares
              </h1>
              <p class="mt-2 text-slate-600">
                Manage transit routes, stops, and fare pricing.
              </p>
            </div>
            <div class="flex items-center gap-3">
              <div class="relative w-full max-w-md">
                <span
                  class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xl"
                  >search</span
                >
                <input
                  id="route-search"
                  class="w-full bg-surface-container-low border-none rounded-xl py-3 pl-11 pr-4 text-sm focus:ring-2 focus:ring-primary/20 transition-all"
                  placeholder="Search routes or fares"
                  type="text"
                />
              </div>
              <button
                type="button"
                onclick="showNotification()"
                class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-surface-container-lowest shadow-sm text-on-surface hover:bg-surface-container transition"
              >
                <span class="material-symbols-outlined">notifications</span>
              </button>
            </div>
          </div>
        </header>

        <div class="grid gap-6 xl:grid-cols-2 mb-6">
          <!-- Routes Section -->
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div class="flex flex-col gap-4 mb-6">
              <div>
                <h2 class="text-lg font-bold text-on-surface">Route List</h2>
                <p class="text-sm text-slate-600">
                  Active transit routes with assigned stops.
                </p>
              </div>
              <button
                type="button"
                onclick="openAddRouteModal()"
                class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700 transition w-fit"
              >
                <span class="material-symbols-outlined">add</span>
                Add Route
              </button>
            </div>

            <div id="route-list" class="space-y-4">
              <?php if (count($routes) === 0): ?>
                <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 text-slate-600">
                  No routes available. Use Add Route to create one.
                </div>
              <?php endif; ?>

              <?php foreach ($routes as $index => $route): ?>
                <?php
                  $stopNames = array_column($route['stops'], 'stop_name');
                  $routeLabel = 'Route ' . ($index + 1);
                  $summary = $route['display_name'] ?: $route['route_name'];
                  $firstStop = $stopNames[0] ?? 'Unknown stop';
                  $lastStop = end($stopNames) ?: 'Unknown stop';
                  $distanceSteps = build_cumulative_from_route_stops($route['stops']);
                  $distanceLabel = $distanceSteps ? number_format(end($distanceSteps), 2) . ' km' : 'N/A';
                  $stopDataAttr = htmlspecialchars(strtolower($summary . ' ' . implode(' ', $stopNames)), ENT_QUOTES, 'UTF-8');
                ?>
                <div
                  class="route-card p-4 border border-slate-200 rounded-xl hover:border-blue-300 transition"
                  data-route-name="<?= htmlspecialchars(strtolower($summary), ENT_QUOTES, 'UTF-8'); ?>"
                  data-route-stops="<?= $stopDataAttr; ?>"
                >
                  <div class="flex flex-col gap-3 mb-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <h3 class="text-xl font-semibold text-on-surface"><?= htmlspecialchars($summary); ?></h3>
                      <p class="mt-1 text-sm text-slate-500">
                        <?= htmlspecialchars(routeDirectionLabel($route['route_name'])); ?> • <?= htmlspecialchars($distanceLabel); ?>
                      </p>
                    </div>
                    <div class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                      <?= htmlspecialchars($routeLabel); ?>
                    </div>
                  </div>
                  <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-3">
                    Stops
                  </p>
                  <div class="overflow-x-auto">
                    <div class="flex items-center gap-2 whitespace-nowrap text-xs font-semibold text-slate-700">
                      <?php if (count($stopNames) === 0): ?>
                        <span class="rounded-full bg-slate-100 px-3 py-1">No stops configured</span>
                      <?php else: ?>
                        <?php foreach ($stopNames as $stopIndex => $stopName): ?>
                          <span class="rounded-full bg-slate-100 px-3 py-1"><?= htmlspecialchars($stopName); ?></span>
                          <?php if ($stopIndex < count($stopNames) - 1): ?>
                            <span class="text-slate-400">→</span>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <!-- Fare Matrix Section -->
          <section
            class="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-200"
          >
            <div class="flex flex-col gap-4 mb-6">
              <div>
                <h2 class="text-lg font-bold text-on-surface">
                  Kilometer Fare Matrix
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                  Fares are calculated with ₱15 for the first 5 km and ₱2.50 per succeeding kilometer.
                </p>
              </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
              <div
                class="overflow-x-auto rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4"
              >
                <table class="w-full divide-y divide-slate-200 text-left text-sm">
                  <thead>
                    <tr>
                      <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Distance (km)</th>
                      <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Regular Fare</th>
                    </tr>
                  </thead>
                  <tbody class="bg-white">
                    <?php foreach ($fareRowsFirst as $row): ?>
                      <tr class="odd:bg-slate-50 hover:bg-slate-100 transition-colors">
                        <td class="px-4 py-4 font-semibold text-slate-900"><?= htmlspecialchars($row['distance']); ?></td>
                        <td class="px-4 py-4 text-lg font-semibold text-slate-900"><?= htmlspecialchars(formatCurrency($row['fare'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div
                class="overflow-x-auto rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4"
              >
                <table class="w-full divide-y divide-slate-200 text-left text-sm">
                  <thead>
                    <tr>
                      <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Distance (km)</th>
                      <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Regular Fare</th>
                    </tr>
                  </thead>
                  <tbody class="bg-white">
                    <?php foreach ($fareRowsSecond as $row): ?>
                      <tr class="odd:bg-slate-50 hover:bg-slate-100 transition-colors">
                        <td class="px-4 py-4 font-semibold text-slate-900"><?= htmlspecialchars($row['distance']); ?></td>
                        <td class="px-4 py-4 text-lg font-semibold text-slate-900"><?= htmlspecialchars(formatCurrency($row['fare'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        </div>
      </main>
    </div>

    <div id="route-modal" class="modal-backdrop" hidden>
      <div class="modal-panel p-6">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 pb-4">
          <h2 class="text-lg font-bold text-slate-900">Add New Route</h2>
          <button type="button" onclick="closeAddRouteModal()" class="rounded-full bg-slate-100 p-2 text-slate-700 hover:bg-slate-200">✕</button>
        </div>
        <form id="add-route-form" class="mt-4 space-y-4 text-sm text-slate-700">
          <div>
            <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Route Name</label>
            <input name="route_name" type="text" required class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="e.g. Balagtas-Monumento" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Display Name</label>
            <input name="display_name" type="text" required class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="e.g. Balagtas → Monumento" />
          </div>
          <div>
            <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Stops</label>
            <textarea name="stops" rows="4" required class="mt-2 w-full rounded-2xl border border-slate-200 bg-surface-container-low px-4 py-3 text-sm text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20" placeholder="Enter stop names separated by commas"></textarea>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" onclick="closeAddRouteModal()" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Cancel</button>
            <button type="submit" class="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Create Route</button>
          </div>
        </form>
      </div>
    </div>

    <script>
      function showNotification() {
        alert('No new notifications yet.');
      }

      function openAddRouteModal() {
        const modal = document.getElementById('route-modal');
        if (!modal) return;
        modal.hidden = false;
        modal.classList.add('open');
      }

      function closeAddRouteModal() {
        const modal = document.getElementById('route-modal');
        if (!modal) return;
        modal.hidden = true;
        modal.classList.remove('open');
      }

      document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('route-search');
        const routeCards = Array.from(document.querySelectorAll('.route-card'));

        if (searchInput) {
          searchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            routeCards.forEach(function (card) {
              const name = card.dataset.routeName || '';
              const stops = card.dataset.routeStops || '';
              card.style.display = name.includes(query) || stops.includes(query) ? '' : 'none';
            });
          });
        }

        const addRouteForm = document.getElementById('add-route-form');
        if (addRouteForm) {
          addRouteForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const formData = new FormData(addRouteForm);
            formData.append('action', 'add_route');

            try {
              const response = await fetch('05_routes_fares.php', {
                method: 'POST',
                body: new URLSearchParams(formData),
              });
              const result = await response.json();
              if (result.success) {
                alert(result.message);
                window.location.reload();
              } else {
                alert(result.message || 'Unable to create route.');
              }
            } catch (error) {
              alert('Failed to submit route.');
            }
          });
        }
      });
    </script>
  </body>
</html>
