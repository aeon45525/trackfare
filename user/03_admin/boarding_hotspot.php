<?php
session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}
require_once __DIR__ . '/../../config/db.php';

// Validate dates and filter selections before binding them into report queries.
function hotspotDate(string $value): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date
        && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
        && $date->format('Y-m-d') === $value
        ? $value
        : null;
}

$rangeInput = $_GET['range'] ?? '7';
$range = is_string($rangeInput) ? $rangeInput : '';
if (!in_array($range, ['today', '7', '30', 'custom'], true)) {
    $range = '7';
}
$today = date('Y-m-d');
$startDate = $range === 'today'
    ? $today
    : ($range === '30' ? date('Y-m-d', strtotime('-29 days')) : date('Y-m-d', strtotime('-6 days')));
$endDate = $today;
if ($range === 'custom') {
    $startInput = $_GET['start_date'] ?? '';
    $endInput = $_GET['end_date'] ?? '';
    $startDate = hotspotDate(is_string($startInput) ? $startInput : '') ?? $startDate;
    $endDate = hotspotDate(is_string($endInput) ? $endInput : '') ?? $today;
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
}
$routeId = filter_input(INPUT_GET, 'route_id', FILTER_VALIDATE_INT);
$routeId = $routeId && $routeId > 0 ? $routeId : 0;
$viewInput = $_GET['view'] ?? 'both';
$view = is_string($viewInput) ? $viewInput : '';
if (!in_array($view, ['in', 'off', 'both'], true)) {
    $view = 'both';
}
$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';

// Populate route choices using the project's existing routes table.
$routes = [];
$routesStmt = $conn->prepare('SELECT route_id, display_name FROM routes ORDER BY display_name');
if (!$routesStmt || !$routesStmt->execute()) {
    error_log('Boarding hotspot routes query failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load route filters.');
}
$routesResult = $routesStmt->get_result();
while ($route = $routesResult->fetch_assoc()) {
    $routes[] = $route;
}
$routesStmt->close();
$validRouteIds = array_map(static function (array $route): int {
    return (int) $route['route_id'];
}, $routes);
if ($routeId > 0 && !in_array($routeId, $validRouteIds, true)) {
    $routeId = 0;
}

// Filter each UNION branch before combining so timestamp indexes can be used.
$sql = 'SELECT s.stop_id, s.stop_name, s.municipality, s.lat, s.lng,
               COALESCE(SUM(events.is_boarding), 0) AS in_count,
               COALESCE(SUM(events.is_alighting), 0) AS off_count
        FROM (
            SELECT tt.boarding_stop_id AS stop_id, t.route_id,
                   1 AS is_boarding, 0 AS is_alighting
            FROM trip_transactions tt
            JOIN trips t ON t.trip_id = tt.trip_id
            WHERE ((tt.boarding_time >= ? AND tt.boarding_time < ?)
                OR (tt.boarding_time IS NULL AND t.start_time >= ? AND t.start_time < ?))';
if ($routeId > 0) {
    $sql .= ' AND t.route_id = ?';
}
$sql .= '
            UNION ALL
            SELECT tt.alighting_stop_id AS stop_id, t.route_id,
                   0 AS is_boarding, 1 AS is_alighting
            FROM trip_transactions tt
            JOIN trips t ON t.trip_id = tt.trip_id
            WHERE ((tt.alighting_time >= ? AND tt.alighting_time < ?)
                OR (tt.alighting_time IS NULL AND t.end_time >= ? AND t.end_time < ?)
                OR (tt.alighting_time IS NULL AND t.end_time IS NULL
                    AND t.start_time >= ? AND t.start_time < ?))';
if ($routeId > 0) {
    $sql .= ' AND t.route_id = ?';
}
$sql .= ') events
        JOIN stops s ON s.stop_id = events.stop_id';
$rankOrder = $view === 'in'
    ? 'SUM(events.is_boarding)'
    : ($view === 'off'
        ? 'SUM(events.is_alighting)'
        : 'SUM(events.is_boarding) + SUM(events.is_alighting)');
$sql .= ' GROUP BY s.stop_id, s.stop_name, s.municipality, s.lat, s.lng
          ORDER BY ' . $rankOrder . ' DESC, s.stop_name ASC';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('Boarding hotspot query prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load hotspot report.');
}
$types = 'ssss' . ($routeId > 0 ? 'i' : '') . 'ssssss' . ($routeId > 0 ? 'i' : '');
$params = [$startDateTime, $endDateTime, $startDateTime, $endDateTime];
if ($routeId > 0) {
    $params[] = $routeId;
}
array_push($params, $startDateTime, $endDateTime, $startDateTime, $endDateTime, $startDateTime, $endDateTime);
if ($routeId > 0) {
    $params[] = $routeId;
}
$bindings = [$types];
foreach ($params as &$param) {
    $bindings[] = &$param;
}
unset($param);
if (!call_user_func_array([$stmt, 'bind_param'], $bindings)) {
    error_log('Boarding hotspot parameter binding failed.');
    http_response_code(500);
    exit('Unable to load hotspot report.');
}
if (!$stmt->execute()) {
    error_log('Boarding hotspot query failed: ' . $stmt->error);
    http_response_code(500);
    exit('Unable to load hotspot report.');
}
$hotspots = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $row['in_count'] = (int) $row['in_count'];
    $row['off_count'] = (int) $row['off_count'];
    $row['total'] = $row['in_count'] + $row['off_count'];
    $hotspots[] = $row;
}
$stmt->close();

// Apply the selected display toggle while retaining both counts in each row.
$visibleHotspots = array_values(array_filter($hotspots, static function (array $row) use ($view): bool {
    if ($view === 'in') {
        return $row['in_count'] > 0;
    }
    if ($view === 'off') {
        return $row['off_count'] > 0;
    }
    return $row['total'] > 0;
}));
$chartHotspots = array_slice($visibleHotspots, 0, 10);
$mapHotspots = array_values(array_filter($visibleHotspots, static function (array $row): bool {
    return $row['lat'] !== null && $row['lng'] !== null;
}));
$hotspotVolume = $view === 'in' ? 'in_count' : ($view === 'off' ? 'off_count' : 'total');
$maxVolume = $visibleHotspots ? max(array_column($visibleHotspots, $hotspotVolume)) : 0;
$chartDatasets = [];
if ($view !== 'off') {
    $chartDatasets[] = ['label' => 'Boarding In', 'data' => array_column($chartHotspots, 'in_count'), 'color' => '#16a34a'];
}
if ($view !== 'in') {
    $chartDatasets[] = ['label' => 'Getting Off', 'data' => array_column($chartHotspots, 'off_count'), 'color' => '#0040a1'];
}
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$exportInput = $_GET['export'] ?? '';
$export = is_string($exportInput) ? $exportInput : '';
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="boarding-hotspots-' . $startDate . '-to-' . $endDate . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Stop ID', 'Stop', 'Municipality', 'Boardings', 'Alightings', 'Total']);
    foreach ($visibleHotspots as $row) {
        fputcsv($output, [
            $row['stop_id'],
            $row['stop_name'],
            $row['municipality'],
            $row['in_count'],
            $row['off_count'],
            $row['total'],
        ]);
    }
    fclose($output);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Boarding Hotspots · TrackFare Admin</title>
  <link rel="icon" href="../../images/logo.png" type="image/png">
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
  <style>#hotspot-map{height:340px;border-radius:1rem}</style>
</head>
<body class="min-h-screen bg-slate-50 font-['Inter'] text-slate-900">
  <div class="flex min-h-screen">
    <?php
    $adminNavPage = basename($_SERVER['PHP_SELF'] ?? '');
    $adminNavItems = [
        ['file' => '01_dashboard.php', 'icon' => 'dashboard', 'label' => 'Dashboard'],
        ['file' => '02_passengers.php', 'icon' => 'group', 'label' => 'Passengers'],
        ['file' => '03_drivers.php', 'icon' => 'badge', 'label' => 'Drivers'],
        ['file' => '04_fleet.php', 'icon' => 'local_shipping', 'label' => 'Fleet'],
        ['file' => '05_routes_fares.php', 'icon' => 'alt_route', 'label' => 'Routes & Fares'],
        ['file' => '06_transactions.php', 'icon' => 'payments', 'label' => 'Transactions'],
        ['file' => '07_analytics.php', 'icon' => 'monitoring', 'label' => 'Analytics'],
        ['file' => '08_profile.php', 'icon' => 'person', 'label' => 'Profile'],
    ];
    ?>
    <aside class="fixed left-0 top-0 z-50 flex h-full w-72 flex-col border-r border-slate-200 bg-slate-50">
      <div class="border-b border-slate-200 px-6 py-8">
        <a href="01_dashboard.php" class="text-2xl font-black tracking-tight text-blue-900">TrackFare</a>
        <p class="mt-2 text-sm text-slate-500">Fleet Manager Portal</p>
      </div>
      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-6" aria-label="Admin navigation">
        <?php foreach ($adminNavItems as $item): ?>
          <?php
          $isAnalyticsNavItem = $item['file'] === '07_analytics.php';
          $isActiveNavItem = $isAnalyticsNavItem
              ? in_array($adminNavPage, ['07_analytics.php', 'boarding_hotspot.php', 'peak_boarding_time.php'], true)
              : $adminNavPage === $item['file'];
          ?>
          <a class="flex items-center gap-3 rounded-r-full px-5 py-3 transition <?= $isActiveNavItem ? 'border-r-4 border-blue-700 bg-blue-50 font-semibold text-blue-700' : 'text-slate-600 hover:bg-slate-100 hover:text-blue-700' ?>" href="<?= htmlspecialchars($item['file'], ENT_QUOTES, 'UTF-8') ?>" <?= $adminNavPage === $item['file'] ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="border-t border-slate-200 px-6 py-6">
        <div class="flex items-center gap-3">
          <div class="h-12 w-12 overflow-hidden rounded-2xl border border-slate-200"><img src="../../images/pfp.png" alt="Fleet Manager" class="h-full w-full object-cover"></div>
          <div><p class="text-sm font-semibold text-slate-900">Fleet Manager</p><p class="text-xs text-slate-500">Admin</p></div>
        </div>
        <a href="../../auth/logout.php" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
          <span class="material-symbols-outlined">logout</span>Logout
        </a>
      </div>
    </aside>
    <main class="ml-72 min-h-screen flex-1 p-8">
      <div class="mx-auto max-w-7xl">
    <a href="07_analytics.php" class="text-sm font-semibold text-blue-800">&larr; Analytics</a>
    <header class="my-6">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Admin analytics</p>
      <h1 class="mt-2 text-3xl font-extrabold">Boarding &amp; alighting hotspots</h1>
      <p class="mt-2 text-sm text-slate-600">Rank stops by passenger boardings and drop-offs.</p>
    </header>

    <!-- Date, route, and boarding-direction filters. -->
    <form method="get" class="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
      <label class="text-xs font-semibold text-slate-600">Date range
        <select name="range" id="range-select" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
          <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="7" <?= $range === '7' ? 'selected' : '' ?>>Last 7 days</option>
          <option value="30" <?= $range === '30' ? 'selected' : '' ?>>Last 30 days</option>
          <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
        </select>
      </label>
      <label class="text-xs font-semibold text-slate-600">Start date
        <input name="start_date" type="date" value="<?= $escape($startDate) ?>" class="custom-date mt-1 w-full rounded-xl border-slate-300 text-sm">
      </label>
      <label class="text-xs font-semibold text-slate-600">End date
        <input name="end_date" type="date" value="<?= $escape($endDate) ?>" class="custom-date mt-1 w-full rounded-xl border-slate-300 text-sm">
      </label>
      <label class="text-xs font-semibold text-slate-600">Route
        <select name="route_id" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
          <option value="0">All routes</option>
          <?php foreach ($routes as $route): ?>
            <option value="<?= (int) $route['route_id'] ?>" <?= $routeId === (int) $route['route_id'] ? 'selected' : '' ?>><?= $escape($route['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs font-semibold text-slate-600">Show
        <select name="view" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
          <option value="both" <?= $view === 'both' ? 'selected' : '' ?>>Boarding In + Getting Off</option>
          <option value="in" <?= $view === 'in' ? 'selected' : '' ?>>Boarding In</option>
          <option value="off" <?= $view === 'off' ? 'selected' : '' ?>>Getting Off</option>
        </select>
      </label>
      <div class="self-end flex gap-2">
        <button class="rounded-xl bg-blue-800 px-4 py-2.5 text-sm font-bold text-white">Apply filters</button>
        <button name="export" value="csv" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Export CSV</button>
      </div>
    </form>

    <!-- Chart summarizes the highest-volume stops in the filtered result. -->
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-4 text-lg font-bold">Top 10 hotspots</h2>
      <?php if ($chartHotspots): ?><canvas id="hotspot-chart" height="110" aria-label="Top stop hotspots bar chart"></canvas>
      <?php else: ?><p class="text-sm text-slate-600">No completed trip records match this filter.</p><?php endif; ?>
    </section>

    <!-- Show stop coordinates on a volume-sized map when available. -->
    <?php if ($mapHotspots): ?>
      <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-lg font-bold">Hotspot map</h2>
        <div id="hotspot-map" aria-label="Map of boarding and alighting hotspots"></div>
        <p id="hotspot-map-error" class="mt-3 hidden text-sm text-red-700" role="alert"></p>
      </section>
    <?php endif; ?>

    <!-- Ranked detail table highlights the three busiest stops. -->
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-100 p-5">
        <h2 class="text-lg font-bold">Ranked stops</h2>
        <p class="mt-1 text-xs text-slate-500"><?= $escape($startDate) ?> to <?= $escape($endDate) ?> · counts are completed trip transactions</p>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Rank / stop</th><th class="px-5 py-3">Boarding In</th><th class="px-5 py-3">Getting Off</th><th class="px-5 py-3">Total</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($visibleHotspots as $index => $row): ?>
              <?php $highlight = $index < 3 ? ['bg-amber-50', 'bg-slate-100', 'bg-orange-50'][$index] : ''; ?>
              <tr class="<?= $highlight ?>">
                <td class="px-5 py-3"><span class="mr-2 font-bold text-blue-800"><?= $index + 1 ?>.</span><span class="font-semibold"><?= $escape($row['stop_name']) ?></span><span class="ml-1 text-xs text-slate-500"><?= $escape($row['municipality']) ?></span></td>
                <td class="px-5 py-3"><?= number_format($row['in_count']) ?></td>
                <td class="px-5 py-3"><?= number_format($row['off_count']) ?></td>
                <td class="px-5 py-3 font-bold"><?= number_format($row['total']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$visibleHotspots): ?><tr><td colspan="4" class="px-5 py-6 text-center text-slate-500">No hotspot data for this range.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
      </div>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <?php if ($chartHotspots): ?>
  <script>
    new Chart(document.getElementById('hotspot-chart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($chartHotspots, 'stop_name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [
          <?php foreach ($chartDatasets as $dataset): ?>
          { label: <?= json_encode($dataset['label'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, data: <?= json_encode($dataset['data']) ?>, backgroundColor: <?= json_encode($dataset['color']) ?> },
          <?php endforeach; ?>
        ]
      },
      options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
    });
  </script>
  <?php endif; ?>
  <?php if ($mapHotspots): ?>
  <script>
    (function () {
      var points = <?= json_encode(array_map(static function (array $row) use ($hotspotVolume): array {
          return ['name' => $row['stop_name'], 'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'count' => $row[$hotspotVolume]];
      }, $mapHotspots), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      var mapElement = document.getElementById('hotspot-map');
      var errorElement = document.getElementById('hotspot-map-error');
      fetch('../../api/map.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (response) {
        if (!response.ok) throw new Error('Map configuration unavailable');
        return response.json();
      }).then(function (config) {
        if (!config.apiKey) throw new Error('Missing Google Maps API key');
        return new Promise(function (resolve, reject) {
          if (window.google && window.google.maps) {
            resolve();
            return;
          }
          var callbackName = '__trackfareHotspotMapsInit';
          window[callbackName] = function () {
            delete window[callbackName];
            resolve();
          };
          var script = document.createElement('script');
          script.src = 'https://maps.googleapis.com/maps/api/js?key='
            + encodeURIComponent(config.apiKey) + '&callback=' + callbackName;
          script.async = true;
          script.defer = true;
          script.onerror = function () {
            delete window[callbackName];
            reject(new Error('Failed to load Google Maps'));
          };
          document.head.appendChild(script);
        });
      }).then(function () {
        var map = new google.maps.Map(mapElement, {
          center: { lat: points[0].lat, lng: points[0].lng },
          zoom: 12,
          mapTypeId: 'roadmap',
          streetViewControl: false,
          mapTypeControl: false,
          fullscreenControl: true
        });
        var bounds = new google.maps.LatLngBounds();
        var infoWindow = new google.maps.InfoWindow();
        points.forEach(function (point) {
          var marker = new google.maps.Marker({
            position: { lat: point.lat, lng: point.lng },
            map: map,
            title: point.name,
            icon: {
              path: google.maps.SymbolPath.CIRCLE,
              scale: 7 + (point.count / <?= max(1, (int) $maxVolume) ?>) * 13,
              fillColor: '#2563eb',
              fillOpacity: 0.7,
              strokeColor: '#0040a1',
              strokeWeight: 2
            }
          });
          marker.addListener('click', function () {
            var content = document.createElement('div');
            content.textContent = point.name + ': ' + point.count + ' ' + <?= json_encode($view === 'in' ? 'boardings' : ($view === 'off' ? 'drop-offs' : 'boardings / drop-offs')) ?>;
            infoWindow.setContent(content);
            infoWindow.open({ anchor: marker, map: map });
          });
          bounds.extend(marker.getPosition());
        });
        if (points.length > 1) {
          map.fitBounds(bounds, 24);
          google.maps.event.addListenerOnce(map, 'bounds_changed', function () {
            if (map.getZoom() > 14) map.setZoom(14);
          });
        }
      }).catch(function (error) {
        console.error('Unable to load hotspot map:', error);
        errorElement.textContent = 'Google Maps could not be loaded. Please try again later.';
        errorElement.classList.remove('hidden');
      });
    }());
  </script>
  <?php endif; ?>
  <script>
    document.getElementById('range-select').addEventListener('change', function () {
      document.querySelectorAll('.custom-date').forEach(function (input) { input.disabled = this.value !== 'custom'; }, this);
    });
    document.querySelectorAll('.custom-date').forEach(function (input) { input.disabled = <?= $range === 'custom' ? 'false' : 'true' ?>; });
  </script>
</body>
</html>
