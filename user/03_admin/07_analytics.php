<?php
session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}
require_once __DIR__ . '/../../config/db.php';

function analyticsDate(string $value): ?string
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

function analyticsRows(
    mysqli $conn,
    string $sql,
    string $types,
    array $params,
    string &$error,
    string $label
): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log($label . ' prepare failed: ' . $conn->error);
        $error = 'Analytics data could not be loaded. Please check the database connection and try again.';
        return [];
    }
    if ($types !== '') {
        $bindings = [$types];
        foreach ($params as &$value) {
            $bindings[] = &$value;
        }
        unset($value);
        if (!call_user_func_array([$stmt, 'bind_param'], $bindings)) {
            error_log($label . ' parameter binding failed.');
            $error = 'Analytics data could not be loaded. Please check the database connection and try again.';
            $stmt->close();
            return [];
        }
    }
    if (!$stmt->execute()) {
        error_log($label . ' query failed: ' . $stmt->error);
        $error = 'Analytics data could not be loaded. Please check the database connection and try again.';
        $stmt->close();
        return [];
    }
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

$today = date('Y-m-d');
$rangeInput = $_GET['range'] ?? '7';
$range = is_string($rangeInput) ? $rangeInput : '';
if (!in_array($range, ['today', '7', '30', 'custom'], true)) {
    $range = '7';
}
$startDate = $range === 'today'
    ? $today
    : ($range === '30' ? date('Y-m-d', strtotime('-29 days')) : date('Y-m-d', strtotime('-6 days')));
$endDate = $today;
if ($range === 'custom') {
    $startInput = $_GET['start_date'] ?? '';
    $endInput = $_GET['end_date'] ?? '';
    $startDate = analyticsDate(is_string($startInput) ? $startInput : '') ?? date('Y-m-d', strtotime('-6 days'));
    $endDate = analyticsDate(is_string($endInput) ? $endInput : '') ?? $today;
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
}
$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';
$routeValue = filter_var($_GET['route_id'] ?? '0', FILTER_VALIDATE_INT);
$routeId = $routeValue !== false && $routeValue > 0 ? $routeValue : 0;
$analyticsError = '';

$routes = analyticsRows(
    $conn,
    'SELECT route_id, display_name FROM routes ORDER BY display_name',
    '',
    [],
    $analyticsError,
    'Analytics route options'
);
$validRouteIds = array_map(static function (array $route): int {
    return (int) $route['route_id'];
}, $routes);
if ($routeId > 0 && !in_array($routeId, $validRouteIds, true)) {
    $routeId = 0;
}
$routeSql = $routeId > 0 ? ' AND t.route_id = ?' : '';
$routeTypes = $routeId > 0 ? 'i' : '';
$routeParams = $routeId > 0 ? [$routeId] : [];

$boardingTimeSql = 'COALESCE(tt.boarding_time, t.start_time)';
$revenueTimeSql = 'COALESCE(tt.alighting_time, t.end_time, t.start_time)';
$boardingFilterSql = '((tt.boarding_time >= ? AND tt.boarding_time < ?)
    OR (tt.boarding_time IS NULL AND t.start_time >= ? AND t.start_time < ?))';
$boardingFilterParams = [
    $startDateTime, $endDateTime, $startDateTime, $endDateTime,
];
$revenueFilterSql = '((tt.alighting_time >= ? AND tt.alighting_time < ?)
    OR (tt.alighting_time IS NULL AND t.end_time >= ? AND t.end_time < ?)
    OR (tt.alighting_time IS NULL AND t.end_time IS NULL AND t.start_time >= ? AND t.start_time < ?))';
$revenueFilterParams = [
    $startDateTime, $endDateTime, $startDateTime, $endDateTime, $startDateTime, $endDateTime,
];

$hourlyRows = analyticsRows(
    $conn,
    'SELECT HOUR(' . $boardingTimeSql . ') AS hour_of_day, COUNT(*) AS total
     FROM trip_transactions tt
     JOIN trips t ON t.trip_id = tt.trip_id
     WHERE ' . $boardingFilterSql . $routeSql . '
     GROUP BY HOUR(' . $boardingTimeSql . ')',
    'ssss' . $routeTypes,
    array_merge($boardingFilterParams, $routeParams),
    $analyticsError,
    'Analytics hourly boardings'
);
$hourly = array_fill(0, 24, 0);
foreach ($hourlyRows as $row) {
    $hourly[(int) $row['hour_of_day']] = (int) $row['total'];
}
$totalBoardings = array_sum($hourly);
$peakHour = $totalBoardings > 0 ? array_search(max($hourly), $hourly, true) : null;

$dailyTripRows = analyticsRows(
    $conn,
    'SELECT DATE(' . $boardingTimeSql . ') AS activity_date, COUNT(*) AS total
     FROM trip_transactions tt
     JOIN trips t ON t.trip_id = tt.trip_id
     WHERE ' . $boardingFilterSql . $routeSql . '
     GROUP BY DATE(' . $boardingTimeSql . ')
     ORDER BY activity_date',
    'ssss' . $routeTypes,
    array_merge($boardingFilterParams, $routeParams),
    $analyticsError,
    'Analytics daily passenger trips'
);
$dailyTrips = [];
foreach ($dailyTripRows as $row) {
    $dailyTrips[] = [
        'label' => date('M j', strtotime($row['activity_date'])),
        'value' => (int) $row['total'],
    ];
}

$fareRows = analyticsRows(
    $conn,
    'SELECT COUNT(*) AS transaction_count,
            COALESCE(AVG(tt.fare_amount), 0) AS average_fare,
            COALESCE(SUM(tt.fare_amount), 0) AS total_revenue
     FROM trip_transactions tt
     JOIN trips t ON t.trip_id = tt.trip_id
     WHERE ' . $revenueFilterSql . $routeSql,
    'ssssss' . $routeTypes,
    array_merge($revenueFilterParams, $routeParams),
    $analyticsError,
    'Analytics fare summary'
);
$transactionCount = (int) ($fareRows[0]['transaction_count'] ?? 0);
$averageFare = (float) ($fareRows[0]['average_fare'] ?? 0);
$totalRevenue = (float) ($fareRows[0]['total_revenue'] ?? 0);
$dailyRevenueRows = analyticsRows(
    $conn,
    'SELECT DATE(' . $revenueTimeSql . ') AS activity_date, SUM(tt.fare_amount) AS total
     FROM trip_transactions tt
     JOIN trips t ON t.trip_id = tt.trip_id
     WHERE ' . $revenueFilterSql . $routeSql . '
     GROUP BY DATE(' . $revenueTimeSql . ')
     ORDER BY activity_date',
    'ssssss' . $routeTypes,
    array_merge($revenueFilterParams, $routeParams),
    $analyticsError,
    'Analytics daily revenue'
);
$dailyRevenue = [];
foreach ($dailyRevenueRows as $row) {
    $dailyRevenue[] = [
        'label' => date('M j', strtotime($row['activity_date'])),
        'value' => (float) $row['total'],
    ];
}

$hotspotBoardingFilter = '((tt.boarding_time >= ? AND tt.boarding_time < ?)
    OR (tt.boarding_time IS NULL AND t.start_time >= ? AND t.start_time < ?))';
$hotspotAlightingFilter = '((tt.alighting_time >= ? AND tt.alighting_time < ?)
    OR (tt.alighting_time IS NULL AND t.end_time >= ? AND t.end_time < ?)
    OR (tt.alighting_time IS NULL AND t.end_time IS NULL AND t.start_time >= ? AND t.start_time < ?))';
$hotspotSql = 'SELECT s.stop_id, s.stop_name, s.municipality, s.lat, s.lng,
                      SUM(events.is_boarding) AS in_count,
                      SUM(events.is_alighting) AS off_count,
                      SUM(events.is_boarding + events.is_alighting) AS total_count
               FROM (
                   SELECT tt.boarding_stop_id AS stop_id, 1 AS is_boarding, 0 AS is_alighting
                   FROM trip_transactions tt
                   JOIN trips t ON t.trip_id = tt.trip_id
                   WHERE ' . $hotspotBoardingFilter . $routeSql . '
                   UNION ALL
                   SELECT tt.alighting_stop_id AS stop_id, 0 AS is_boarding, 1 AS is_alighting
                   FROM trip_transactions tt
                   JOIN trips t ON t.trip_id = tt.trip_id
                   WHERE ' . $hotspotAlightingFilter . $routeSql . '
               ) events
               JOIN stops s ON s.stop_id = events.stop_id
               GROUP BY s.stop_id, s.stop_name, s.municipality, s.lat, s.lng
               ORDER BY total_count DESC, s.stop_name ASC';
$hotspotParams = array_merge(
    $boardingFilterParams,
    $routeParams,
    $revenueFilterParams,
    $routeParams
);
$hotspotTypes = 'ssss' . $routeTypes . 'ssssss' . $routeTypes;
$hotspotRows = analyticsRows(
    $conn,
    $hotspotSql,
    $hotspotTypes,
    $hotspotParams,
    $analyticsError,
    'Analytics stop activity'
);
foreach ($hotspotRows as &$hotspot) {
    $hotspot['in_count'] = (int) $hotspot['in_count'];
    $hotspot['off_count'] = (int) $hotspot['off_count'];
    $hotspot['total_count'] = (int) $hotspot['total_count'];
}
unset($hotspot);
$allHotspotRows = $hotspotRows;
$totalStopBoardings = array_sum(array_column($allHotspotRows, 'in_count'));
$totalStopAlightings = array_sum(array_column($allHotspotRows, 'off_count'));
$hotspotRows = array_slice($allHotspotRows, 0, 10);
$topHotspot = $hotspotRows[0] ?? null;

$peakStop = null;
if ($peakHour !== null) {
    $peakStopRows = analyticsRows(
        $conn,
        'SELECT s.stop_id, s.stop_name, COUNT(*) AS total
         FROM trip_transactions tt
         JOIN trips t ON t.trip_id = tt.trip_id
         JOIN stops s ON s.stop_id = tt.boarding_stop_id
         WHERE ' . $boardingFilterSql . '
           AND HOUR(' . $boardingTimeSql . ') = ?' . $routeSql . '
         GROUP BY s.stop_id, s.stop_name
         ORDER BY total DESC, s.stop_name
         LIMIT 1',
        'ssssi' . $routeTypes,
        array_merge($boardingFilterParams, [(int) $peakHour], $routeParams),
        $analyticsError,
        'Analytics peak-hour boarding stop'
    );
    $peakStop = $peakStopRows[0] ?? null;
}

$mapPoints = array_values(array_filter($allHotspotRows, static function (array $row): bool {
    return $row['lat'] !== null && $row['lng'] !== null;
}));
$mapMaxCount = $mapPoints
    ? max(array_column($mapPoints, 'total_count'))
    : 0;
$dateRangeLabel = $startDate === $endDate ? $startDate : $startDate . ' to ' . $endDate;
$peakHourLabel = $peakHour !== null
    ? date('g A', mktime((int) $peakHour, 0, 0)) . '–' . date('g A', mktime(((int) $peakHour + 1) % 24, 0, 0))
    : 'No data';
$peakWindowHours = [];
if ($peakHour !== null) {
    for ($offset = -2; $offset < 4; $offset++) {
        $hour = (((int) $peakHour + $offset) % 24 + 24) % 24;
        $peakWindowHours[] = [
            'label' => date('g A', mktime($hour, 0, 0)),
            'value' => $hourly[$hour],
        ];
    }
}
$maxHourly = $peakWindowHours ? max(1, max(array_column($peakWindowHours, 'value'))) : 1;
$maxDailyTrips = $dailyTrips ? max(1, max(array_column($dailyTrips, 'value'))) : 1;
$maxDailyRevenue = $dailyRevenue ? max(1, max(array_column($dailyRevenue, 'value'))) : 1;
$filterParams = ['range' => $range, 'start_date' => $startDate, 'end_date' => $endDate];
if ($routeId > 0) {
    $filterParams['route_id'] = $routeId;
}
$hotspotReportUrl = 'boarding_hotspot.php?' . http_build_query($filterParams);
$peakReportUrl = 'peak_boarding_time.php?' . http_build_query($filterParams);
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$exportInput = $_GET['export'] ?? '';
$export = is_string($exportInput) ? $exportInput : '';
if ($export === 'csv') {
    $filename = 'trackfare-analytics-' . $startDate . '-to-' . $endDate . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        error_log('Analytics CSV export could not open output stream.');
        http_response_code(500);
        exit('Unable to export analytics report.');
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Report', 'Period', 'Metric', 'Value', 'Details']);
    fputcsv($output, ['Summary', $dateRangeLabel, 'Passenger trips / boardings', $totalBoardings, 'COALESCE(boarding_time, start_time)']);
    fputcsv($output, ['Summary', $dateRangeLabel, 'Average fare', number_format($averageFare, 2, '.', ''), 'Fare-window passenger trips: ' . $transactionCount]);
    fputcsv($output, ['Summary', $dateRangeLabel, 'Fare revenue', number_format($totalRevenue, 2, '.', ''), 'COALESCE(alighting_time, end_time, start_time)']);
    fputcsv($output, ['Summary', $dateRangeLabel, 'Peak boarding hour', $peakHourLabel, $totalBoardings . ' boardings']);
    fputcsv($output, [
        'Summary',
        $dateRangeLabel,
        'Peak-hour boarding stop',
        $peakStop['stop_name'] ?? 'No data',
        $peakStop ? $peakStop['total'] . ' boardings during ' . $peakHourLabel : 'No boarding activity',
    ]);

    foreach ($dailyTrips as $day) {
        fputcsv($output, ['Daily passenger trips', $day['label'], 'Passenger trips', $day['value'], 'Boarding-time basis']);
    }
    foreach ($dailyRevenue as $day) {
        fputcsv($output, ['Daily fare revenue', $day['label'], 'Fare revenue', number_format($day['value'], 2, '.', ''), 'Alighting-time fallback basis']);
    }
    foreach ($hourly as $hour => $count) {
        fputcsv($output, ['Hourly boardings', sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24), 'Boardings', $count, 'Asia/Manila']);
    }
    foreach ($allHotspotRows as $stop) {
        fputcsv($output, [
            'Stop activity',
            $dateRangeLabel,
            $stop['stop_name'],
            $stop['total_count'],
            'Stop ID ' . $stop['stop_id'] . '; ' . $stop['municipality'] .
                '; boardings ' . $stop['in_count'] .
                '; alightings ' . $stop['off_count'] .
                '; coordinates ' . $stop['lat'] . ',' . $stop['lng'],
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
  <title>Analytics · TrackFare Admin</title>
  <link rel="icon" href="../../images/logo.png" type="image/png">
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
  <style>#analytics-hotspot-map{height:360px}</style>
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
  <main class="ml-72 min-h-screen flex-1 p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
      <header class="mb-6">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Admin analytics</p>
        <h1 class="mt-2 text-3xl font-extrabold">Analytics dashboard</h1>
        <p class="mt-2 text-sm text-slate-600"><?= $escape($dateRangeLabel) ?> · <?= $routeId > 0 ? 'Selected route' : 'All routes' ?></p>
      </header>

      <?php if ($analyticsError !== ''): ?>
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert"><?= $escape($analyticsError) ?></div>
      <?php endif; ?>

      <form method="get" class="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-6">
        <label class="text-xs font-semibold text-slate-600">Date range
          <select name="range" id="range-select" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
            <?php foreach (['today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'custom' => 'Custom'] as $value => $label): ?>
              <option value="<?= $escape($value) ?>" <?= $range === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="text-xs font-semibold text-slate-600">Start date
          <input class="custom-date mt-1 w-full rounded-xl border-slate-300 text-sm" name="start_date" type="date" value="<?= $escape($startDate) ?>">
        </label>
        <label class="text-xs font-semibold text-slate-600">End date
          <input class="custom-date mt-1 w-full rounded-xl border-slate-300 text-sm" name="end_date" type="date" value="<?= $escape($endDate) ?>">
        </label>
        <label class="text-xs font-semibold text-slate-600">Route
          <select name="route_id" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
            <option value="0">All routes</option>
            <?php foreach ($routes as $route): ?>
              <option value="<?= (int) $route['route_id'] ?>" <?= $routeId === (int) $route['route_id'] ? 'selected' : '' ?>><?= $escape($route['display_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="self-end flex flex-wrap gap-2 lg:col-span-2">
          <button class="rounded-xl bg-blue-800 px-4 py-2.5 text-sm font-bold text-white">Apply filters</button>
          <button name="export" value="csv" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Export all reports CSV</button>
        </div>
      </form>

      <section class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Peak Boarding</p>
          <p class="mt-3 text-2xl font-extrabold"><?= $escape($peakHourLabel) ?></p>
          <a class="mt-3 inline-block text-sm font-semibold text-blue-800 hover:underline" href="<?= $escape($peakReportUrl) ?>">View full report →</a>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Peak boarding stop</p>
          <p class="mt-3 text-2xl font-extrabold"><?= $escape($peakStop['stop_name'] ?? 'No data') ?></p>
          <p class="mt-1 text-sm text-slate-600"><?= $peakStop ? number_format((int) $peakStop['total']) . ' boardings during ' . $escape($peakHourLabel) : 'No boarding activity in this range.' ?></p>
          <a class="mt-3 inline-block text-sm font-semibold text-blue-800 hover:underline" href="<?= $escape($peakReportUrl) ?>">View full report →</a>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Passenger trips</p>
          <p class="mt-3 text-2xl font-extrabold"><?= number_format($totalBoardings) ?></p>
          <p class="mt-1 text-sm text-slate-600">Transaction rows by boarding time</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Average fare</p>
          <p class="mt-3 text-2xl font-extrabold"><?= $transactionCount ? '₱' . number_format($averageFare, 2) : 'No data' ?></p>
          <p class="mt-1 text-sm text-slate-600">Revenue-window passenger trips</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Fare revenue</p>
          <p class="mt-3 text-2xl font-extrabold"><?= $transactionCount ? '₱' . number_format($totalRevenue, 2) : 'No data' ?></p>
          <p class="mt-1 text-sm text-slate-600">Grouped by alighting time</p>
        </article>
      </section>

      <section class="mb-6 grid gap-4 xl:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="text-lg font-bold">Passenger trips by day</h2>
          <p class="mb-4 text-xs text-slate-500">Counts use COALESCE(boarding_time, start_time).</p>
          <?php if ($dailyTrips): ?>
            <div class="flex h-56 items-end gap-1 overflow-x-auto border-b border-slate-200 pb-1">
              <?php foreach ($dailyTrips as $day): ?>
                <?php $height = $day['value'] > 0 ? max(3, (int) round($day['value'] / $maxDailyTrips * 100)) : 0; ?>
                <div class="flex h-full min-w-8 flex-1 flex-col justify-end" title="<?= $escape($day['label'] . ': ' . $day['value'] . ' passenger trips') ?>">
                  <span class="mb-1 text-center text-[10px] font-semibold"><?= number_format($day['value']) ?></span>
                  <div class="rounded-t bg-blue-700" style="height:<?= $height ?>%"></div>
                  <span class="mt-2 whitespace-nowrap text-center text-[10px] text-slate-500"><?= $escape($day['label']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No passenger-trip activity for this date range.</p>
          <?php endif; ?>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="text-lg font-bold">Daily fare revenue</h2>
          <p class="mb-4 text-xs text-slate-500">Fare amount by COALESCE(alighting_time, end_time, start_time).</p>
          <?php if ($dailyRevenue): ?>
            <div class="flex h-56 items-end gap-1 overflow-x-auto border-b border-slate-200 pb-1">
              <?php foreach ($dailyRevenue as $day): ?>
                <?php $height = $day['value'] > 0 ? max(3, (int) round($day['value'] / $maxDailyRevenue * 100)) : 0; ?>
                <div class="flex h-full min-w-8 flex-1 flex-col justify-end" title="<?= $escape($day['label'] . ': ₱' . number_format($day['value'], 2)) ?>">
                  <span class="mb-1 whitespace-nowrap text-center text-[10px] font-semibold">₱<?= number_format($day['value'], 0) ?></span>
                  <div class="rounded-t bg-emerald-600" style="height:<?= $height ?>%"></div>
                  <span class="mt-2 whitespace-nowrap text-center text-[10px] text-slate-500"><?= $escape($day['label']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No fare revenue for this date range.</p>
          <?php endif; ?>
        </article>
      </section>

      <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold">Peak-hour boarding window</h2>
            <p class="text-xs text-slate-500">Six hours centered around the busiest boarding hour; labels wrap across midnight.</p>
          </div>
          <a class="text-sm font-semibold text-blue-800 hover:underline" href="<?= $escape($peakReportUrl) ?>">View full report →</a>
        </div>
        <?php if ($peakWindowHours): ?>
          <div class="flex h-48 items-end gap-3 border-b border-slate-200 pb-1">
            <?php foreach ($peakWindowHours as $hour): ?>
              <?php $height = $hour['value'] > 0 ? max(3, (int) round($hour['value'] / $maxHourly * 100)) : 0; ?>
              <div class="flex h-full flex-1 flex-col justify-end text-center" title="<?= $escape($hour['label'] . ': ' . $hour['value'] . ' boardings') ?>">
                <span class="mb-1 text-xs font-semibold"><?= number_format($hour['value']) ?></span>
                <div class="rounded-t bg-blue-700" style="height:<?= $height ?>%"></div>
                <span class="mt-2 text-xs text-slate-600"><?= $escape($hour['label']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No boarding activity for this date range.</p>
        <?php endif; ?>
      </section>

      <section class="mb-6 grid gap-4 xl:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="mb-4 flex items-center justify-between gap-3">
            <div>
              <h2 class="text-lg font-bold">Boarding and alighting hotspots</h2>
              <p class="text-xs text-slate-500">Top 10 of <?= count($allHotspotRows) ?> active stops · <?= number_format($totalStopBoardings) ?> boardings · <?= number_format($totalStopAlightings) ?> alightings</p>
            </div>
            <a class="shrink-0 text-sm font-semibold text-blue-800 hover:underline" href="<?= $escape($hotspotReportUrl) ?>">View full report →</a>
          </div>
          <?php if ($hotspotRows): ?>
            <div class="overflow-x-auto">
              <table class="w-full min-w-[430px] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500"><tr><th class="py-2">Stop</th><th class="px-2 py-2 text-right">Boardings</th><th class="px-2 py-2 text-right">Alightings</th><th class="py-2 text-right">Total</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                  <?php foreach ($hotspotRows as $hotspot): ?>
                    <tr>
                      <td class="py-2 font-semibold"><?= $escape($hotspot['stop_name']) ?><span class="block text-xs font-normal text-slate-500"><?= $escape($hotspot['municipality']) ?></span></td>
                      <td class="px-2 py-2 text-right text-emerald-700"><?= number_format($hotspot['in_count']) ?></td>
                      <td class="px-2 py-2 text-right text-blue-700"><?= number_format($hotspot['off_count']) ?></td>
                      <td class="py-2 text-right font-bold"><?= number_format($hotspot['total_count']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No stop activity for this date range.</p>
          <?php endif; ?>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="text-lg font-bold">Boarding hotspot map</h2>
          <p class="mb-4 text-xs text-slate-500">Marker size reflects activity relative to this result set.</p>
          <?php if ($mapPoints): ?>
            <div id="analytics-hotspot-map" class="overflow-hidden rounded-xl" aria-label="Map of boarding and alighting hotspots"></div>
            <p id="analytics-map-error" class="mt-3 hidden text-sm text-red-700" role="alert"></p>
          <?php else: ?>
            <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No stop coordinates with activity are available for this date range.</p>
          <?php endif; ?>
        </article>
      </section>

      <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-lg font-bold">Operational insights</h2>
        <div class="space-y-3 text-sm text-slate-700">
          <p><strong>Demand window:</strong>
            <?= $peakStop && $peakHour !== null
                ? 'The busiest boarding stop during the peak hour is ' . $escape($peakStop['stop_name']) . ' (' . number_format((int) $peakStop['total']) . ' boardings) during ' . $escape($peakHourLabel) . '.'
                : 'No boarding activity is available for the selected date range.' ?>
          </p>
          <p><strong>Top stop:</strong>
            <?= $topHotspot
                ? $escape($topHotspot['stop_name']) . ' has ' . number_format($topHotspot['total_count']) . ' combined boardings and alightings in this period.'
                : 'No stop activity has been recorded for the selected date range.' ?>
          </p>
          <p><strong>Time basis:</strong> Boardings and passenger-trip counts use <?= $escape($boardingTimeSql) ?>. Fare revenue uses <?= $escape($revenueTimeSql) ?>. All times are Asia/Manila.</p>
        </div>
      </section>
    </div>
  </main>
</div>
<script>
  (function () {
    var rangeSelect = document.getElementById('range-select');
    function updateCustomDates() {
      document.querySelectorAll('.custom-date').forEach(function (input) {
        input.disabled = rangeSelect.value !== 'custom';
      });
    }
    rangeSelect.addEventListener('change', updateCustomDates);
    updateCustomDates();
  })();
</script>
<?php if ($mapPoints): ?>
<script>
  (function () {
    var points = <?= json_encode(array_map(static function (array $stop): array {
        return [
            'name' => (string) $stop['stop_name'],
            'lat' => (float) $stop['lat'],
            'lng' => (float) $stop['lng'],
            'count' => (int) $stop['total_count'],
        ];
    }, $mapPoints), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var maxCount = <?= (int) $mapMaxCount ?>;
    var mapElement = document.getElementById('analytics-hotspot-map');
    var errorElement = document.getElementById('analytics-map-error');
    fetch('../../api/map.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) throw new Error('Map configuration unavailable');
        return response.json();
      })
      .then(function (config) {
        if (!config.apiKey) throw new Error('Missing Google Maps API key');
        return new Promise(function (resolve, reject) {
          if (window.google && window.google.maps) return resolve();
          var callbackName = '__trackfareAnalyticsMapInit';
          window[callbackName] = function () { delete window[callbackName]; resolve(); };
          var script = document.createElement('script');
          script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(config.apiKey) + '&callback=' + callbackName;
          script.async = true;
          script.defer = true;
          script.onerror = function () { delete window[callbackName]; reject(new Error('Failed to load Google Maps')); };
          document.head.appendChild(script);
        });
      })
      .then(function () {
        var map = new google.maps.Map(mapElement, {
          center: { lat: points[0].lat, lng: points[0].lng },
          zoom: 11,
          mapTypeId: 'roadmap',
          streetViewControl: false,
          mapTypeControl: false,
          fullscreenControl: true
        });
        var bounds = new google.maps.LatLngBounds();
        points.forEach(function (point) {
          var marker = new google.maps.Marker({
            position: { lat: point.lat, lng: point.lng },
            map: map,
            title: point.name + ' (' + point.count + ')',
            icon: {
              path: google.maps.SymbolPath.CIRCLE,
              scale: 7 + (point.count / Math.max(1, maxCount)) * 13,
              fillColor: '#2563eb',
              fillOpacity: 0.8,
              strokeColor: '#0040a1',
              strokeWeight: 2
            }
          });
          bounds.extend(marker.getPosition());
        });
        if (points.length > 1) {
          map.fitBounds(bounds, 28);
          google.maps.event.addListenerOnce(map, 'bounds_changed', function () {
            if (map.getZoom() > 13) map.setZoom(13);
          });
        }
      })
      .catch(function (error) {
        console.error('Unable to load analytics hotspot map:', error);
        errorElement.textContent = 'Google Maps could not load. Please verify the map API configuration.';
        errorElement.classList.remove('hidden');
      });
  })();
</script>
<?php endif; ?>
</body>
</html>
