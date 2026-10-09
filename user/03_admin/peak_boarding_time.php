<?php
session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}
require_once __DIR__ . '/../../config/db.php';

// Validate the selected reporting window and optional route, bus, and stop filters.
function peakDate(string $value): ?string
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
    $startDate = peakDate(is_string($startInput) ? $startInput : '') ?? $startDate;
    $endDate = peakDate(is_string($endInput) ? $endInput : '') ?? $today;
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
}
$routeId = filter_input(INPUT_GET, 'route_id', FILTER_VALIDATE_INT);
$routeId = $routeId && $routeId > 0 ? $routeId : 0;
$busId = filter_input(INPUT_GET, 'bus_id', FILTER_VALIDATE_INT);
$busId = $busId && $busId > 0 ? $busId : 0;
$stopId = filter_input(INPUT_GET, 'stop_id', FILTER_VALIDATE_INT);
$stopId = $stopId && $stopId > 0 ? $stopId : 0;
$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

// Read filter options from existing routes, buses, and stops tables.
$routes = [];
$buses = [];
$stops = [];
foreach ([
    ['SELECT route_id AS id, display_name AS label FROM routes ORDER BY display_name', 'routes'],
    ['SELECT bus_id AS id, bus_number AS label FROM buses ORDER BY bus_number', 'buses'],
    ['SELECT stop_id AS id, stop_name AS label FROM stops ORDER BY stop_name', 'stops'],
] as [$optionSql, $optionName]) {
    $optionStmt = $conn->prepare($optionSql);
    if (!$optionStmt || !$optionStmt->execute()) {
        error_log('Peak boarding filter query failed: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load filter options.');
    }
    $optionResult = $optionStmt->get_result();
    while ($option = $optionResult->fetch_assoc()) {
        ${$optionName}[] = $option;
    }
    $optionStmt->close();
}
$routeIds = array_map(static function (array $option): int {
    return (int) $option['id'];
}, $routes);
$busIds = array_map(static function (array $option): int {
    return (int) $option['id'];
}, $buses);
$stopIds = array_map(static function (array $option): int {
    return (int) $option['id'];
}, $stops);
if ($routeId > 0 && !in_array($routeId, $routeIds, true)) {
    $routeId = 0;
}
if ($busId > 0 && !in_array($busId, $busIds, true)) {
    $busId = 0;
}
if ($stopId > 0 && !in_array($stopId, $stopIds, true)) {
    $stopId = 0;
}

// Shared filter predicates keep hourly and weekday counts aligned.
$boardingTimeSql = 'COALESCE(tt.boarding_time, t.start_time)';
$where = [$boardingTimeSql . ' >= ?', $boardingTimeSql . ' < ?'];
$types = 'ss';
$params = [$startDateTime, $endDateTime];
if ($routeId > 0) {
    $where[] = 't.route_id = ?';
    $types .= 'i';
    $params[] = $routeId;
}
if ($busId > 0) {
    $where[] = 't.bus_id = ?';
    $types .= 'i';
    $params[] = $busId;
}
if ($stopId > 0) {
    $where[] = 'tt.boarding_stop_id = ?';
    $types .= 'i';
    $params[] = $stopId;
}
$whereSql = implode(' AND ', $where);

// Bind optional predicates by reference for mysqli's variadic bind_param API.
function bindPeakParams(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $arguments = [$types];
    foreach ($values as &$value) {
        $arguments[] = &$value;
    }
    return call_user_func_array([$stmt, 'bind_param'], $arguments);
}

// Use the passenger's recorded tap-in time, falling back to legacy trip start times.
$hourlySql = 'SELECT HOUR(' . $boardingTimeSql . ') AS hour_of_day, COUNT(*) AS total
              FROM trip_transactions tt
              JOIN trips t ON t.trip_id = tt.trip_id
              WHERE ' . $whereSql . '
              GROUP BY HOUR(' . $boardingTimeSql . ')';
$hourlyStmt = $conn->prepare($hourlySql);
if (!$hourlyStmt) {
    error_log('Peak boarding hourly query prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load boarding-time report.');
}
if (!bindPeakParams($hourlyStmt, $types, $params)) {
    error_log('Peak boarding hourly parameter binding failed.');
    http_response_code(500);
    exit('Unable to load boarding-time report.');
}
if (!$hourlyStmt->execute()) {
    error_log('Peak boarding hourly query failed: ' . $hourlyStmt->error);
    http_response_code(500);
    exit('Unable to load boarding-time report.');
}
$hourly = array_fill(0, 24, 0);
$hourlyResult = $hourlyStmt->get_result();
while ($row = $hourlyResult->fetch_assoc()) {
    $hourly[(int) $row['hour_of_day']] = (int) $row['total'];
}
$hourlyStmt->close();

$weekdaySql = 'SELECT DAYOFWEEK(' . $boardingTimeSql . ') AS weekday_number,
                      HOUR(' . $boardingTimeSql . ') AS hour_of_day, COUNT(*) AS total
               FROM trip_transactions tt
               JOIN trips t ON t.trip_id = tt.trip_id
               WHERE ' . $whereSql . '
               GROUP BY DAYOFWEEK(' . $boardingTimeSql . '), HOUR(' . $boardingTimeSql . ')';
$weekdayStmt = $conn->prepare($weekdaySql);
if (!$weekdayStmt) {
    error_log('Peak boarding weekday query prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load boarding-time report.');
}
if (!bindPeakParams($weekdayStmt, $types, $params)) {
    error_log('Peak boarding weekday parameter binding failed.');
    http_response_code(500);
    exit('Unable to load boarding-time report.');
}
if (!$weekdayStmt->execute()) {
    error_log('Peak boarding weekday query failed: ' . $weekdayStmt->error);
    http_response_code(500);
    exit('Unable to load boarding-time report.');
}
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$heatmap = array_fill(0, 7, array_fill(0, 24, 0));
$dayTotals = array_fill(0, 7, 0);
$weekdayResult = $weekdayStmt->get_result();
while ($row = $weekdayResult->fetch_assoc()) {
    $dayIndex = (int) $row['weekday_number'] - 1;
    $hourIndex = (int) $row['hour_of_day'];
    $count = (int) $row['total'];
    $heatmap[$dayIndex][$hourIndex] = $count;
    $dayTotals[$dayIndex] += $count;
}
$weekdayStmt->close();

$totalBoardings = array_sum($hourly);
$heatmapTotal = array_sum($dayTotals);
if ($heatmapTotal !== $totalBoardings) {
    error_log('Peak boarding hourly total does not match weekday heatmap total.');
    http_response_code(500);
    exit('Unable to reconcile boarding-time report totals.');
}
$hasBoardings = $totalBoardings > 0;
$peakHour = array_search(max($hourly), $hourly, true);
$quietHour = array_search(min($hourly), $hourly, true);
$busiestDayIndex = array_search(max($dayTotals), $dayTotals, true);
$hourLabel = static function (int $hour): string {
    return date('g:00 A', mktime($hour, 0, 0)) . ' - ' .
        date('g:00 A', mktime(($hour + 1) % 24, 0, 0));
};
$maxHeat = max(1, max(array_map('max', $heatmap)));
$exportInput = $_GET['export'] ?? '';
$export = is_string($exportInput) ? $exportInput : '';
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="peak-boarding-time-' . $startDate . '-to-' . $endDate . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Day of week', 'Hour', 'Boardings']);
    foreach ($dayNames as $dayIndex => $dayName) {
        for ($hour = 0; $hour < 24; $hour++) {
            fputcsv($output, [$dayName, $hourLabel($hour), $heatmap[$dayIndex][$hour]]);
        }
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
  <title>Peak Boarding Time · TrackFare Admin</title>
  <link rel="icon" href="../../images/logo.png" type="image/png">
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
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
      <h1 class="mt-2 text-3xl font-extrabold">Peak boarding time</h1>
      <p class="mt-2 text-sm text-slate-600">Completed passenger trips grouped by COALESCE(boarding_time, trip start_time).</p>
    </header>

    <!-- Date, route, bus, and stop filters. -->
    <form method="get" class="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
      <label class="text-xs font-semibold text-slate-600">Date range
        <select name="range" id="range-select" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
          <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="7" <?= $range === '7' ? 'selected' : '' ?>>Last 7 days</option>
          <option value="30" <?= $range === '30' ? 'selected' : '' ?>>Last 30 days</option>
          <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
        </select>
      </label>
      <label class="text-xs font-semibold text-slate-600">Start date<input name="start_date" type="date" value="<?= $escape($startDate) ?>" class="custom-date mt-1 w-full rounded-xl border-slate-300 text-sm"></label>
      <label class="text-xs font-semibold text-slate-600">End date<input name="end_date" type="date" value="<?= $escape($endDate) ?>" class="custom-date mt-1 w-full rounded-xl border-slate-300 text-sm"></label>
      <label class="text-xs font-semibold text-slate-600">Route
        <select name="route_id" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="0">All routes</option><?php foreach ($routes as $option): ?><option value="<?= (int) $option['id'] ?>" <?= $routeId === (int) $option['id'] ? 'selected' : '' ?>><?= $escape($option['label']) ?></option><?php endforeach; ?></select>
      </label>
      <label class="text-xs font-semibold text-slate-600">Bus
        <select name="bus_id" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="0">All buses</option><?php foreach ($buses as $option): ?><option value="<?= (int) $option['id'] ?>" <?= $busId === (int) $option['id'] ? 'selected' : '' ?>><?= $escape($option['label']) ?></option><?php endforeach; ?></select>
      </label>
      <label class="text-xs font-semibold text-slate-600">Stop
        <select name="stop_id" class="mt-1 w-full rounded-xl border-slate-300 text-sm"><option value="0">All stops</option><?php foreach ($stops as $option): ?><option value="<?= (int) $option['id'] ?>" <?= $stopId === (int) $option['id'] ? 'selected' : '' ?>><?= $escape($option['label']) ?></option><?php endforeach; ?></select>
      </label>
      <div class="flex gap-2 lg:col-span-6">
        <button class="rounded-xl bg-blue-800 px-4 py-2.5 text-sm font-bold text-white">Apply filters</button>
        <button name="export" value="csv" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Export CSV</button>
      </div>
    </form>

    <!-- Summary metrics are derived from the filtered hourly and daily totals. -->
    <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ([
          ['Peak hour', $hasBoardings ? $hourLabel((int) $peakHour) : 'No data'],
          ['Busiest day', $hasBoardings ? $dayNames[$busiestDayIndex] : 'No data'],
          ['Total boardings', number_format($totalBoardings)],
          ['Quietest hour', $hasBoardings ? $hourLabel((int) $quietHour) : 'No data'],
      ] as [$label, $value]): ?>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-wide text-slate-500"><?= $escape($label) ?></p><p class="mt-2 text-2xl font-extrabold"><?= $escape($value) ?></p></article>
      <?php endforeach; ?>
    </section>

    <!-- Hourly line chart covers every hour, including hours with no recorded trips. -->
    <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-4 text-lg font-bold">Boardings per hour</h2>
      <canvas id="hour-chart" height="100" aria-label="Boardings per hour chart"></canvas>
    </section>

    <!-- Heatmap uses darker cells for higher weekday-and-hour trip counts. -->
    <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-1 text-lg font-bold">Day of week × hour</h2>
      <p class="mb-4 text-xs text-slate-500">Times are shown as hourly start-time windows.</p>
      <table class="min-w-[960px] border-separate border-spacing-1 text-center text-[10px]">
        <thead><tr><th class="sticky left-0 bg-white px-2 py-2 text-left">Day</th><?php for ($hour = 0; $hour < 24; $hour++): ?><th title="<?= $escape($hourLabel($hour)) ?>" class="px-1 py-2 font-medium text-slate-500"><?= $escape(date('g A', mktime($hour, 0, 0))) ?></th><?php endfor; ?></tr></thead>
        <tbody>
          <?php foreach ($dayNames as $dayIndex => $dayName): ?>
            <tr><th class="sticky left-0 bg-white px-2 py-2 text-left text-xs font-semibold"><?= $escape($dayName) ?></th>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <?php $count = $heatmap[$dayIndex][$hour]; $intensity = $count > 0 ? max(12, (int) round(($count / $maxHeat) * 100)) : 0; ?>
                <td title="<?= $escape($dayName . ' ' . $hourLabel($hour) . ': ' . $count . ' trips') ?>" class="rounded px-1 py-2" style="background-color:<?= $count > 0 ? 'rgba(0, 64, 161, ' . number_format($intensity / 100, 2, '.', '') . ')' : '#f1f5f9' ?>;color:<?= $intensity >= 55 ? '#ffffff' : '#0f172a' ?>"><?= $count ?: '' ?></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <p class="mt-4 text-xs leading-relaxed text-slate-500">Boarding time uses the passenger's saved tap-in timestamp. Older transactions without that timestamp fall back to the trip start time.</p>
      </div>
    </main>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    new Chart(document.getElementById('hour-chart'), {
      type: 'line',
      data: {
        labels: <?= json_encode(array_map($hourLabel, range(0, 23)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [{ label: 'Completed passenger trips', data: <?= json_encode(array_values($hourly)) ?>, borderColor: '#0040a1', backgroundColor: 'rgba(0,64,161,.12)', fill: true, tension: .25 }]
      },
      options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
    document.getElementById('range-select').addEventListener('change', function () {
      document.querySelectorAll('.custom-date').forEach(function (input) { input.disabled = this.value !== 'custom'; }, this);
    });
    document.querySelectorAll('.custom-date').forEach(function (input) { input.disabled = <?= $range === 'custom' ? 'false' : 'true' ?>; });
  </script>
</body>
</html>
