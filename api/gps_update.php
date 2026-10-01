<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/gps_device.php';

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

/* ---------- GET: ESP32 asks for the active trip id (used for NFC taps) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $trip = null;
    if ($stmt = $conn->prepare(
        'SELECT trip_id, route_id
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
    gps_device_reply([
        'ok' => true,
        'trip_id' => (int)$trip['trip_id'],
        'route_id' => (int)$trip['route_id'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gps_device_reply(['ok' => false, 'message' => 'GET or POST required'], 405);
}

/* ---------- POST: live GPS position -> config/gps_live.json ---------- */
$lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || $lat === null || $lng === null
    || !is_finite((float)$lat) || !is_finite((float)$lng)
    || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180
    || ((float)$lat == 0 && (float)$lng == 0)
) {
    gps_device_reply(['ok' => false, 'message' => 'Invalid coordinates'], 422);
}

$ok = file_put_contents(__DIR__ . '/../config/gps_live.json', json_encode([
    'fix'    => true,
    'lat'    => (float)$lat,
    'lng'    => (float)$lng,
    'sats'   => (int)($_POST['sats'] ?? 0),
    'speed'  => (float)($_POST['speed'] ?? 0),
    'bus_id' => (int)$busId,
    'ts'     => time(),
]), LOCK_EX);

if ($ok === false) {
    gps_device_reply(['ok' => false, 'message' => 'Could not save GPS (check config folder permissions)'], 500);
}

gps_device_reply(['ok' => true]);