<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

$isPassenger = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'passenger';
$isJsonRequest = in_array(($_GET['action'] ?? ''), ['get', 'locations'], true);

if (!$isPassenger) {
    if ($isJsonRequest) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../auth/login.php');
    exit;
}

// Return active-trip occupancy as JSON for both the map and this page.
if ($isJsonRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'locations') {
        // Use the last route stop as a location fallback, then prefer a fresh
        // simulation, driver-browser, or bus-device GPS fix for that bus.
        $stmt = $conn->prepare(
            'SELECT t.trip_id, t.route_id, t.bus_id, t.driver_id,
                    t.current_stop_index, t.start_time,
                    r.display_name AS route_name, b.bus_number, b.capacity,
                    u.full_name AS driver_name, s.stop_name AS current_stop,
                    s.lat, s.lng, COALESCE(pax.current_passengers, 0) AS current_passengers
             FROM trips t
             JOIN routes r ON r.route_id = t.route_id
             JOIN buses b ON b.bus_id = t.bus_id
             JOIN users u ON u.user_id = t.driver_id
             LEFT JOIN route_stops rs
               ON rs.route_id = t.route_id AND rs.stop_order = t.current_stop_index + 1
             LEFT JOIN stops s ON s.stop_id = rs.stop_id
             LEFT JOIN (
                 SELECT trip_id, COUNT(*) AS current_passengers
                 FROM active_passengers
                 WHERE tap_state = ?
                 GROUP BY trip_id
             ) pax ON pax.trip_id = t.trip_id
             WHERE t.status = ? AND u.lat IS NOT NULL AND u.lng IS NOT NULL
             ORDER BY t.start_time DESC, t.trip_id DESC'
        );
        if (!$stmt) {
            error_log('Bus location query prepare failed: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['error' => 'Unable to load bus locations']);
            exit;
        }
        $tapState = 'in';
        $tripStatus = 'active';
        $stmt->bind_param('ss', $tapState, $tripStatus);
        if (!$stmt->execute()) {
            error_log('Bus location query failed: ' . $stmt->error);
            $stmt->close();
            http_response_code(500);
            echo json_encode(['error' => 'Unable to load bus locations']);
            exit;
        }
        $locations = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if ($row['lat'] === null || $row['lng'] === null) {
                continue;
            }
            $locations[(int) $row['trip_id']] = [
                'trip_id' => (int) $row['trip_id'],
                'route_id' => (int) $row['route_id'],
                'route_name' => $row['route_name'],
                'bus_id' => (int) $row['bus_id'],
                'bus_number' => $row['bus_number'],
                'driver_name' => $row['driver_name'],
                'current_stop' => $row['current_stop'],
                'current_passengers' => (int) $row['current_passengers'],
                'capacity' => (int) $row['capacity'] > 0 ? (int) $row['capacity'] : null,
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
                'location_source' => empty($row['start_time']) ? 'Waiting at stop' : 'Last reported stop',
            ];
        }
        $stmt->close();

        $gpsSources = [
            [__DIR__ . '/../../config/gps_simulation_state.json', 'simulation'],
            [__DIR__ . '/../../config/gps_state.json', 'shared'],
            [__DIR__ . '/../../config/gps_live.json', 'device'],
        ];
        foreach ($gpsSources as [$path, $sourceType]) {
            if (!is_file($path)) {
                continue;
            }
            $json = file_get_contents($path);
            $state = $json === false ? null : json_decode($json, true);
            if (!is_array($state)) {
                continue;
            }
            $updatedAt = (int) ($state['updatedAt'] ?? $state['ts'] ?? 0);
            if ($updatedAt < time() - 20 || $updatedAt > time() + 5) {
                continue;
            }
            $tripId = (int) ($state['tripId'] ?? 0);
            $busId = (int) ($state['busId'] ?? $state['bus_id'] ?? 0);
            if ($sourceType === 'device') {
                foreach ($locations as $id => $location) {
                    if ($location['bus_id'] === $busId) {
                        $tripId = $id;
                        break;
                    }
                }
            }
            if ($tripId < 1 || !isset($locations[$tripId])) {
                continue;
            }
            $position = $state['busPosition'] ?? $state;
            if (!is_array($position)
                || !isset($position['lat'], $position['lng'])
                || !is_numeric($position['lat'])
                || !is_numeric($position['lng'])
                || !is_finite((float) $position['lat'])
                || !is_finite((float) $position['lng'])
                || (float) $position['lat'] < -90 || (float) $position['lat'] > 90
                || (float) $position['lng'] < -180 || (float) $position['lng'] > 180
            ) {
                continue;
            }
            $locations[$tripId]['lat'] = (float) $position['lat'];
            $locations[$tripId]['lng'] = (float) $position['lng'];
            $locations[$tripId]['location_source'] = $sourceType === 'device'
                ? 'Live bus GPS'
                : ($sourceType === 'shared' ? 'Live driver GPS' : 'Simulated GPS');
        }

        echo json_encode(['buses' => array_values($locations)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $routeId = filter_input(INPUT_GET, 'route_id', FILTER_VALIDATE_INT);
    if ($routeId === false || ($routeId !== null && $routeId < 0)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid route']);
        exit;
    }
    $routeId = $routeId ?: 0;

    $stmt = $conn->prepare(
        'SELECT t.trip_id, t.route_id, r.display_name AS route_name,
                b.bus_id, b.bus_number, b.capacity,
                COUNT(ap.active_id) AS current_passengers
         FROM trips t
         JOIN routes r ON r.route_id = t.route_id
         JOIN buses b ON b.bus_id = t.bus_id
         LEFT JOIN active_passengers ap
           ON ap.trip_id = t.trip_id AND ap.tap_state = ?
         WHERE t.status = ? AND (? = 0 OR t.route_id = ?)
         GROUP BY t.trip_id, t.route_id, r.display_name, b.bus_id, b.bus_number, b.capacity
         ORDER BY t.start_time DESC, t.trip_id DESC'
    );
    if (!$stmt) {
        error_log('Bus capacity query prepare failed: ' . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load capacity']);
        exit;
    }

    $tapState = 'in';
    $tripStatus = 'active';
    $stmt->bind_param('ssii', $tapState, $tripStatus, $routeId, $routeId);
    if (!$stmt->execute()) {
        error_log('Bus capacity query failed: ' . $stmt->error);
        $stmt->close();
        http_response_code(500);
        echo json_encode(['error' => 'Unable to load capacity']);
        exit;
    }

    $buses = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $capacity = (int) $row['capacity'];
        $passengers = (int) $row['current_passengers'];
        $percent = $capacity > 0 ? round(($passengers / $capacity) * 100, 1) : null;
        $status = 'no_data';
        if ($percent !== null) {
            $status = $percent >= 90 ? 'full' : ($percent >= 60 ? 'filling_up' : 'available');
        }
        $buses[] = [
            'trip_id' => (int) $row['trip_id'],
            'route_id' => (int) $row['route_id'],
            'route_name' => $row['route_name'],
            'bus_id' => (int) $row['bus_id'],
            'bus_number' => $row['bus_number'],
            'current_passengers' => $passengers,
            'capacity' => $capacity > 0 ? $capacity : null,
            'percent' => $percent,
            'status' => $status,
        ];
    }
    $stmt->close();
    echo json_encode(['buses' => $buses], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bus Capacity · TrackFare</title>
  <link rel="icon" href="../../images/logo.png" type="image/png">
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-50 font-body text-slate-900">
  <main class="mx-auto min-h-screen max-w-xl px-4 pb-10 pt-6">
    <a href="02_routes.php" class="text-sm font-semibold text-blue-800">&larr; Back to live map</a>
    <header class="my-6">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Passenger information</p>
      <h1 class="mt-2 text-3xl font-extrabold">Bus capacity</h1>
      <p class="mt-2 text-sm text-slate-600">Current onboard passengers compared with each active bus's capacity.</p>
    </header>
    <p id="capacity-updated" class="mb-3 text-right text-xs text-slate-500" aria-live="polite">Loading capacity…</p>
    <section id="capacity-list" class="space-y-3" aria-live="polite"></section>
  </main>
  <script>
    (function () {
      var list = document.getElementById('capacity-list');
      var updated = document.getElementById('capacity-updated');
      var labels = {
        available: { text: 'Available', color: 'text-emerald-800', bar: 'bg-emerald-500' },
        filling_up: { text: 'Filling up', color: 'text-amber-800', bar: 'bg-amber-500' },
        full: { text: 'Full', color: 'text-red-800', bar: 'bg-red-500' },
        no_data: { text: 'No data', color: 'text-slate-600', bar: 'bg-slate-300' }
      };

      function render(buses) {
        if (!buses.length) {
          list.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">No active buses right now.</div>';
          return;
        }
        list.replaceChildren();
        buses.forEach(function (bus) {
          var status = labels[bus.status] || labels.no_data;
          var card = document.createElement('article');
          card.className = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm';
          var heading = document.createElement('div');
          heading.className = 'flex items-start justify-between gap-3';
          var title = document.createElement('div');
          var busName = document.createElement('h2');
          busName.className = 'text-lg font-extrabold';
          busName.textContent = bus.bus_number;
          var route = document.createElement('p');
          route.className = 'mt-1 text-sm text-slate-600';
          route.textContent = bus.route_name;
          title.append(busName, route);
          var badge = document.createElement('span');
          badge.className = 'shrink-0 rounded-full bg-slate-50 px-3 py-1 text-xs font-bold ' + status.color;
          badge.textContent = status.text;
          heading.append(title, badge);

          var amount = document.createElement('p');
          amount.className = 'mt-5 text-2xl font-extrabold';
          amount.textContent = bus.capacity === null
            ? 'No data'
            : bus.current_passengers + '/' + bus.capacity;
          var detail = document.createElement('p');
          detail.className = 'mt-1 text-sm text-slate-600';
          detail.textContent = bus.percent === null ? 'Bus capacity has not been set.' : bus.percent + '% occupied';

          var track = document.createElement('div');
          track.className = 'mt-3 h-2 overflow-hidden rounded-full bg-slate-100';
          var fill = document.createElement('div');
          fill.className = 'h-full rounded-full transition-all ' + status.bar;
          fill.style.width = bus.percent === null ? '0%' : Math.min(100, bus.percent) + '%';
          track.appendChild(fill);
          card.append(heading, amount, detail, track);
          list.appendChild(card);
        });
      }

      function refresh() {
        fetch('bus_capacity.php?action=get&_=' + Date.now(), {
          cache: 'no-store',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        })
          .then(function (response) {
            if (!response.ok) throw new Error('Capacity request failed (' + response.status + ')');
            return response.json();
          })
          .then(function (data) {
            if (!Array.isArray(data.buses)) throw new Error(data.error || 'Invalid capacity response');
            render(data.buses);
            updated.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + ' · refreshes every 12 seconds';
          })
          .catch(function () {
            updated.textContent = 'Capacity is temporarily unavailable. Retrying…';
          });
      }

      refresh();
      window.setInterval(refresh, 12000);
    }());
  </script>
</body>
</html>
