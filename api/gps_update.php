<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gps_device.php';
require_once __DIR__ . '/../config/fare.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function gps_device_reply(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
if (!hash_equals(TRACKFARE_GPS_DEVICE_TOKEN, $token)) {
    gps_device_reply(['ok' => false, 'message' => 'Unauthorized'], 401);
}

$busId = filter_var($_POST['bus_id'] ?? $_GET['bus_id'] ?? null, FILTER_VALIDATE_INT);
if (!$busId || $busId < 1) {
    gps_device_reply(['ok' => false, 'message' => 'Invalid bus ID'], 422);
}

$trip = null;
if ($stmt = $conn->prepare(
    'SELECT trip_id, route_id, current_stop_index
     FROM trips
     WHERE bus_id = ? AND status = ?
     ORDER BY start_time DESC, trip_id DESC
     LIMIT 1'
)) {
    $active = 'active';
    $stmt->bind_param('is', $busId, $active);
    $stmt->execute();
    $trip = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$trip) {
    gps_device_reply(['ok' => false, 'message' => 'No active trip for this bus'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    gps_device_reply([
        'ok' => true,
        'trip_id' => (int)$trip['trip_id'],
        'route_id' => (int)$trip['route_id'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gps_device_reply(['ok' => false, 'message' => 'GET or POST required'], 405);
}

$lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat === null || $lng === null
    || !is_finite((float)$lat) || !is_finite((float)$lng)
    || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
) {
    gps_device_reply(['ok' => false, 'message' => 'Invalid coordinates'], 422);
}

$tripId = (int)$trip['trip_id'];
$routeId = (int)$trip['route_id'];
$currentStopIndex = (int)$trip['current_stop_index'];
$nearestDistance = INF;
$stopIndex = 0;
$index = 0;
if ($stmt = $conn->prepare(
    'SELECT s.lat, s.lng
     FROM route_stops rs
     JOIN stops s ON s.stop_id = rs.stop_id
     WHERE rs.route_id = ?
     ORDER BY rs.stop_order'
)) {
    $stmt->bind_param('i', $routeId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($stop = $result->fetch_assoc()) {
        $distance = haversine_km((float)$lat, (float)$lng, (float)$stop['lat'], (float)$stop['lng']);
        if ($distance < $nearestDistance) {
            $nearestDistance = $distance;
            $stopIndex = $index;
        }
        $index++;
    }
    $stmt->close();
    if ($index > 0) {
        $currentStopIndex = $stopIndex;
        if ($update = $conn->prepare('UPDATE trips SET current_stop_index = ? WHERE trip_id = ? AND status = ?')) {
            $active = 'active';
            $update->bind_param('iis', $currentStopIndex, $tripId, $active);
            $update->execute();
            $update->close();
        }
    }
}

$state = [
    'routeId' => $routeId,
    'tripId' => $tripId,
    'busId' => (int)$busId,
    'status' => 'running',
    'currentStopIndex' => $currentStopIndex,
    'busPosition' => ['lat' => (float)$lat, 'lng' => (float)$lng],
    'legFrom' => null,
    'legTo' => null,
    'legProgress' => 0.0,
    'source' => 'device',
    'updatedAt' => time(),
];
$json = json_encode($state, JSON_UNESCAPED_UNICODE);
$stateFile = __DIR__ . '/../config/gps_state.json';
$file = fopen($stateFile, 'c');
if ($json === false || !$file) {
    gps_device_reply(['ok' => false, 'message' => 'Could not save GPS state'], 500);
}
flock($file, LOCK_EX);
ftruncate($file, 0);
rewind($file);
fwrite($file, $json);
fflush($file);
flock($file, LOCK_UN);
fclose($file);

gps_device_reply([
    'ok' => true,
    'trip_id' => $tripId,
    'route_id' => $routeId,
    'current_stop_index' => $currentStopIndex,
]);